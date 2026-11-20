const DATABASE = 'canabicultarr-offline-sites';
const STORE = 'directory';
const RECORD = 'current';
const TTL = 24 * 60 * 60 * 1000;
const ITERATIONS = 600_000;

async function openDirectory() {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open(DATABASE, 1);
    request.onupgradeneeded = () => request.result.createObjectStore(STORE);
    request.onerror = () => reject(request.error);
    request.onsuccess = () => resolve(request.result);
  });
}

async function directory(mode, change) {
  const database = await openDirectory();
  try {
    return await new Promise((resolve, reject) => {
      const transaction = database.transaction(STORE, mode);
      const store = transaction.objectStore(STORE);
      const request = store.get(RECORD);
      let result;
      request.onsuccess = () => {
        const state = request.result ?? { revision: 0, snapshot: null };
        try {
          if (mode === 'readwrite') store.put(change(state), RECORD);
          result = state;
        } catch (error) {
          reject(error);
          transaction.abort();
        }
      };
      transaction.oncomplete = () => resolve(result);
      transaction.onabort = () => reject(transaction.error);
      transaction.onerror = event => reject(event.target.error ?? transaction.error);
    });
  } finally {
    database.close();
  }
}

async function encryptionKey(passphrase, salt) {
  const material = await crypto.subtle.importKey('raw', new TextEncoder().encode(passphrase), 'PBKDF2', false, ['deriveKey']);
  return crypto.subtle.deriveKey(
    { name: 'PBKDF2', salt, iterations: ITERATIONS, hash: 'SHA-256' },
    material, { name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt'],
  );
}

function notifyDirectoryChanged() {
  if (typeof BroadcastChannel === 'undefined') return;
  const channel = new BroadcastChannel(DATABASE);
  channel.postMessage('change');
  channel.close();
}

async function seal(value, passphrase) {
  const salt = crypto.getRandomValues(new Uint8Array(16));
  const iv = crypto.getRandomValues(new Uint8Array(12));
  const key = await encryptionKey(passphrase, salt);
  const payload = new TextEncoder().encode(JSON.stringify(value));
  const ciphertext = await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, key, payload);
  return { salt, iv, ciphertext };
}

async function unseal(record, passphrase) {
  if (passphrase.length < 12 || passphrase.length > 256) throw new Error('Invalid passphrase');
  const key = await encryptionKey(passphrase, record.salt);
  const plaintext = await crypto.subtle.decrypt({ name: 'AES-GCM', iv: record.iv }, key, record.ciphertext);
  return JSON.parse(new TextDecoder().decode(plaintext));
}

function metadataFor(record) {
  if (!record) return null;
  const { salt: _salt, iv: _iv, ciphertext: _ciphertext, ...metadata } = record;
  return metadata;
}

async function replace(change, revision) {
  const state = await directory('readwrite', current => revision === undefined || current.revision === revision
    ? { ...current, ...change, revision: current.revision + 1 }
    : current);
  if (revision !== undefined && state.revision !== revision) return false;
  notifyDirectoryChanged();
  return true;
}

export async function readOfflineState() {
  const state = await directory('readonly');
  const metadata = metadataFor(state.snapshot);
  if (metadata && metadata.expiresAt <= Date.now()) {
    await clearOfflineDirectory(state.revision);
    return readOfflineState();
  }

  const library = metadataFor(state.library);
  if (library && library.expiresAt <= Date.now()) {
    await clearOfflineLibrary(state.revision);
    return readOfflineState();
  }
  return { revision: state.revision, metadata, outbox: metadataFor(state.outbox), library };
}

export async function saveOfflineSites(snapshot, passphrase, revision, selection) {
  if (passphrase.length < 12 || passphrase.length > 256) throw new Error('Invalid passphrase');
  const issuedAt = Date.parse(snapshot.savedAt);
  const expiresAt = Math.min(Date.parse(snapshot.expiresAt), Date.now() + TTL);
  if (!Number.isFinite(issuedAt) || !Number.isFinite(expiresAt) || expiresAt <= Date.now()) throw new Error('Invalid snapshot deadline');
  const current = await directory('readonly');
  if (current.revision !== revision) throw new Error('Directory changed while saving');
  if (current.outbox) {
    if (current.outbox.accountId !== snapshot.accountId || current.outbox.deviceId !== snapshot.deviceId) throw new Error('Offline account changed');
    await unseal(current.outbox, passphrase);
  }
  const recorded = {
    accountId: snapshot.accountId, deviceId: snapshot.deviceId, version: snapshot.version, expiresAt,
    plantVersion: snapshot.plantCatalog?.version,
    ...await seal({ ...snapshot, expiresAt }, passphrase),
  };
  let library = null;
  if (selection) {
    const filesExpireAt = Math.min(Date.parse(selection.expiresAt), expiresAt);
    if (!Number.isFinite(filesExpireAt) || filesExpireAt <= Date.now()) throw new Error('Invalid offline files deadline');
    library = {
      accountId: snapshot.accountId, deviceId: snapshot.deviceId, expiresAt: filesExpireAt,
      ticket: selection.ticket, count: selection.files.length,
      ...await seal(selection.files, passphrase),
    };
  } else if (current.library?.accountId === snapshot.accountId && current.library.deviceId === snapshot.deviceId) {
    await unseal(current.library, passphrase);
    library = current.library;
  }
  if (!await replace({ snapshot: recorded, library }, revision)) throw new Error('Directory changed while saving');
}

export async function clearOfflineSites(revision) {
  await replace({ snapshot: null, library: null, outbox: null }, revision);
}

export async function clearOfflineDirectory(revision) {
  await replace({ snapshot: null, library: null }, revision);
}

export async function clearOfflineLibrary(revision) {
  return replace({ library: null }, revision);
}

export async function unlockOfflineSites(passphrase, includeFiles = true) {
  const { revision, snapshot, library } = await directory('readonly');
  if (!snapshot || snapshot.expiresAt <= Date.now()) return null;
  const result = await unseal(snapshot, passphrase);
  const files = includeFiles && library?.accountId === result.accountId && library.deviceId === result.deviceId && library.expiresAt > Date.now()
    ? await unseal(library, passphrase) : [];
  const state = await directory('readonly');
  return state.revision === revision && result.expiresAt > Date.now()
    ? {
      ...result, files: library?.expiresAt > Date.now() ? files : [], filesExpireAt: library?.expiresAt, revision,
      pendingCount: state.outbox?.accountId === result.accountId && state.outbox.deviceId === result.deviceId ? state.outbox.count : 0,
    } : null;
}

const OFFLINE_FILE_LIMIT = 2 * 1024 * 1024;
const OFFLINE_TOTAL_FILE_LIMIT = 4 * 1024 * 1024;

async function stageOfflineAttachment(file, gallery = false) {
  if (!file || !file.name) return undefined;
  const formats = gallery ? /\.(jpe?g|png|webp)$/i : /\.(jpe?g|png|webp|pdf|txt|csv)$/i;
  if (file.size < 1 || file.size > OFFLINE_FILE_LIMIT || !formats.test(file.name)) {
    throw new DOMException('Unsupported offline attachment', gallery ? 'OfflinePhotoInvalid' : 'OfflineAttachmentInvalid');
  }
  return { id: crypto.randomUUID(), name: file.name, size: file.size, base64: encodeOfflineBytes(await file.arrayBuffer()) };
}

export function encodeOfflineBytes(buffer) {
  const bytes = new Uint8Array(buffer);
  const parts = [];
  for (let offset = 0; offset < bytes.length; offset += 0x6000) {
    parts.push(btoa(String.fromCharCode(...bytes.subarray(offset, offset + 0x6000))));
  }
  return parts.join('');
}

export function decodeOfflineBytes(encoded) {
  return Uint8Array.from(atob(encoded), byte => byte.charCodeAt(0));
}

export async function addOfflineDraft(draft, passphrase, revision) {
  const state = await directory('readonly');
  if (state.revision !== revision || !state.snapshot || state.snapshot.expiresAt <= Date.now()) throw new Error('Directory no longer available');
  const { snapshot, outbox } = state;
  const drafts = outbox ? await unseal(outbox, passphrase) : [];
  if (drafts.length >= 20) throw new DOMException('Offline queue full', 'OfflineQueueFull');
  if (draft.kind !== 'photo' && draft.plant_id && drafts.some(item => item.kind !== 'photo' && item.plant_id === draft.plant_id)) {
    throw new Error('An action for this plant is already pending');
  }
  if (draft.attachment && drafts.reduce((size, item) => size + (item.attachment?.size ?? 0), draft.attachment.size) > OFFLINE_TOTAL_FILE_LIMIT) {
    throw new DOMException('Offline attachment quota exceeded', 'OfflineAttachmentLimit');
  }
  // Gallery uploads preserve the plant version. Send them before care actions that may advance it.
  const firstCare = draft.kind === 'photo' ? drafts.findIndex(item => item.plant_id === draft.plant_id && item.kind !== 'photo') : -1;
  if (firstCare === -1) drafts.push(draft);
  else drafts.splice(firstCare, 0, draft);
  const recorded = {
    accountId: snapshot.accountId, deviceId: snapshot.deviceId, count: drafts.length,
    ...await seal(drafts, passphrase),
  };
  if (!await replace({ outbox: recorded }, revision)) throw new Error('Directory changed while saving');
}

export async function readOfflineDrafts(passphrase) {
  const { revision, outbox } = await directory('readonly');
  if (!outbox) return null;
  const drafts = await unseal(outbox, passphrase);
  if ((await directory('readonly')).revision !== revision) throw new Error('Offline queue changed while opening');
  return { accountId: outbox.accountId, deviceId: outbox.deviceId, drafts };
}

export async function updateOfflineDrafts(ids, passphrase, rejected = new Map(), signal) {
  const { revision, outbox } = await directory('readonly');
  if (!outbox || signal?.aborted) return;
  const drafts = (await unseal(outbox, passphrase))
    .filter(draft => !ids.includes(draft.operation_id))
    .map(draft => {
      const rejection = rejected.get(draft.operation_id);
      return rejection ? { ...draft, rejection } : draft;
    });
  const updated = drafts.length ? {
    accountId: outbox.accountId, deviceId: outbox.deviceId, count: drafts.length,
    ...await seal(drafts, passphrase),
  } : null;
  if (!signal?.aborted && !await replace({ outbox: updated }, revision)) throw new Error('Offline queue changed while saving');
}

export function watchOfflineSitesChange(listener) {
  if (typeof BroadcastChannel === 'undefined') return () => {};
  const channel = new BroadcastChannel(DATABASE);
  channel.onmessage = listener;
  return () => channel.close();
}
