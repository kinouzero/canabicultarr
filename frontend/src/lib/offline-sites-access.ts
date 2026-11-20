import { ApiError, apiRequest, apiResponse } from '../api/auth';
import {
  clearOfflineDirectory, clearOfflineLibrary, clearOfflineSites, decodeOfflineBytes, encodeOfflineBytes, readOfflineState, saveOfflineSites, unlockOfflineSites,
  type OfflineDraft, type OfflineFileInfo, type OfflineFileSelection, type OfflineJournalChange, type OfflineJournalEntry, type OfflinePlantCatalog, type OfflineSitesSnapshot,
} from './offline-sites.js';

function withOfflineDeadline<T>(work: (signal: AbortSignal) => Promise<T>, parent?: AbortSignal, timeout = 20_000): Promise<T> {
  const controller = new AbortController();
  const abort = () => controller.abort();
  if (parent?.aborted) abort();
  else parent?.addEventListener('abort', abort, { once: true });
  const timer = setTimeout(abort, timeout);
  return work(controller.signal).finally(() => {
    clearTimeout(timer);
    parent?.removeEventListener('abort', abort);
  });
}

function offlineRequest<T>(path: string, body?: object, signal?: AbortSignal, timeout?: number): Promise<T> {
  return withOfflineDeadline(current => apiRequest<T>(path, body, { signal: current }), signal, timeout);
}

export function fetchOfflineSites(signal?: AbortSignal) {
  return offlineRequest<OfflineSitesSnapshot>('account/mobile-devices/offline-sites', undefined, signal);
}

export async function fetchOfflinePlants(version: string, signal?: AbortSignal) {
  const query = new URLSearchParams({ version });
  const catalog = await offlineRequest<OfflinePlantCatalog>('account/mobile-devices/offline-plants?' + query, undefined, signal);
  if (!Array.isArray(catalog?.plants) || !catalog.version || typeof catalog.truncated !== 'boolean') {
    throw new Error('Incomplete offline plant catalog');
  }
  return catalog;
}

const offlineFiles = 'account/mobile-devices/offline-files';

export function listOfflineFiles(search: string, signal: AbortSignal) {
  return offlineRequest<OfflineFileInfo[]>(offlineFiles + '?' + new URLSearchParams({ search }), undefined, signal);
}

async function validateOfflineFiles(ticket: string, signal?: AbortSignal) {
  const result = await offlineRequest<{ valid: boolean }>(offlineFiles + '/selection', { ticket }, signal);
  if (result?.valid !== true) throw new Error('Missing offline file authorization');
}

export async function downloadOfflineFiles(ids: string[], signal?: AbortSignal): Promise<OfflineFileSelection> {
  const selection = await offlineRequest<{ ticket: string; expiresAt: string; files: OfflineFileInfo[] }>(offlineFiles + '/selection', { ids }, signal);
  if (!selection?.ticket || selection.files?.length !== ids.length) throw new Error('Incomplete file selection');
  const files = [];
  for (const file of selection.files) {
    const bytes = await withOfflineDeadline(async current => {
      const response = await apiResponse(offlineFiles + '/' + encodeURIComponent(file.id), { headers: { Accept: 'application/json' }, signal: current });
      return response.arrayBuffer();
    }, signal, 60_000);
    const digest = [...new Uint8Array(await crypto.subtle.digest('SHA-256', bytes))]
      .map(byte => byte.toString(16).padStart(2, '0')).join('');
    if (bytes.byteLength !== file.size || digest !== file.sha256) throw new Error('Incomplete offline file download');
    files.push({ ...file, base64: encodeOfflineBytes(bytes) });
  }
  await validateOfflineFiles(selection.ticket, signal);
  return { ...selection, files };
}

async function fetchJournal(version: string, cursor?: string, signal?: AbortSignal) {
  const query = new URLSearchParams({ version });
  if (cursor) query.set('cursor', cursor);
  const page = await offlineRequest<{ changes: OfflineJournalChange[]; cursor: string; hasMore: boolean }>(
    'account/mobile-devices/offline-journal?' + query, undefined, signal,
  );
  if (!Array.isArray(page?.changes) || !page.cursor || typeof page.hasMore !== 'boolean') {
    throw new Error('Incomplete offline journal response');
  }
  return page;
}

export async function refreshOfflineJournal(snapshot: OfflineSitesSnapshot, passphrase: string, signal?: AbortSignal) {
  const { metadata } = await readOfflineState();
  const reusable = metadata?.accountId === snapshot.accountId && metadata.deviceId === snapshot.deviceId
    && metadata.version === snapshot.version;
  const previous = reusable ? await unlockOfflineSites(passphrase, false) : null;
  const notes = new Map<string, OfflineJournalEntry>();
  const apply = (changes: OfflineJournalChange[]) => {
    for (const change of changes) {
      if (change.type === 'remove') notes.delete(change.id);
      else notes.set(change.id, change);
    }
  };

  let cursor = previous?.journalCursor;
  if (cursor) {
    for (const note of previous?.journal ?? []) notes.set(note.id, note);
    try {
      for (let i = 0; i < 5; i++) {
        const page = await fetchJournal(snapshot.version, cursor, signal);
        apply(page.changes);
        cursor = page.cursor;
        if (!page.hasMore) return journalProjection(notes, cursor);
      }
    } catch (cause) {
      if (!(cause instanceof ApiError) || cause.status !== 409) throw cause;
    }
  }

  const initial = await fetchJournal(snapshot.version, undefined, signal);
  notes.clear();
  apply(initial.changes);
  return journalProjection(notes, initial.cursor);
}

export async function refreshOfflineCopy(secret: string, files: string[] = [], signal?: AbortSignal, existing?: OfflineSitesSnapshot) {
  const state = await readOfflineState();
  const snapshot = existing ?? await fetchOfflineSites(signal);
  let revision = state.revision;
  if (state.library && !files.length) {
    try {
      await validateOfflineFiles(state.library.ticket, signal);
    } catch (cause) {
      if (!(cause instanceof ApiError) || cause.status !== 409) throw cause;
      if (!await clearOfflineLibrary(state.revision)) throw new Error('Directory changed while refreshing');
      revision++;
    }
  }
  const [journal, plantCatalog, selection] = await Promise.all([
    refreshOfflineJournal(snapshot, secret, signal),
    fetchOfflinePlants(snapshot.version, signal),
    files.length ? downloadOfflineFiles(files, signal) : undefined,
  ]);
  if (!signal?.aborted) await saveOfflineSites({ ...snapshot, ...journal, plantCatalog }, secret, revision, selection);
}

function journalProjection(notes: Map<string, OfflineJournalEntry>, cursor: string) {
  const journal = [...notes.values()].sort((left, right) =>
    right.occurred_at.localeCompare(left.occurred_at) || right.id.localeCompare(left.id),
  ).slice(0, 50);
  return { journal, journalCursor: cursor };
}

export async function uploadOfflineDraft(draft: OfflineDraft, scope: { accountId: string; deviceId: string }, signal?: AbortSignal) {
  const binding = { account_id: scope.accountId, device_id: scope.deviceId };
  const prefix = 'account/mobile-devices/';
  let path: string;
  let payload: object;
  if ('attachment' in draft && draft.attachment) {
    const attachment = draft.attachment;
    const upload = new FormData();
    upload.set('account_id', binding.account_id);
    upload.set('device_id', binding.device_id);
    upload.set('upload_id', attachment.id);
    upload.set('version', String(draft.version));
    if (draft.kind === 'photo') upload.set('gallery', '1');
    upload.set('file', new Blob([decodeOfflineBytes(attachment.base64)]), attachment.name);
    const result = await offlineRequest<{ id: string; gallery?: boolean }>(prefix + 'offline-attachments/' + encodeURIComponent(draft.plant_id), upload, signal, 60_000);
    if (typeof result?.id !== 'string' || !result.id) throw new Error('Missing offline attachment receipt');
    if (draft.kind === 'photo') {
      if (result.gallery !== true) throw new Error('Missing offline gallery receipt');
      return;
    }
  }
  switch (draft.kind) {
    case 'photo':
      throw new Error('Missing offline photo');
    case 'maintenance':
    case 'watering': {
      const { plant_id, operation_id, version, comment, occurred_at, attachment } = draft;
      path = 'offline-' + draft.kind + '/' + encodeURIComponent(plant_id);
      payload = { ...binding, request_id: operation_id, version, comment, occurred_at, attachment_id: attachment?.id,
        ...(draft.kind === 'maintenance' ? { type: draft.type } : { with_products: Boolean(draft.fertilizers?.length), water_liters: draft.water_liters,
          fertilizers: draft.fertilizers?.map(({ inventory_item_id, balance_id, dose_per_liter, unit }) => ({
            inventory_item_id, balance_id, dose_per_liter, unit,
          })) }) };
      break;
    }
    case 'creation':
      path = 'offline-plants';
      payload = { ...binding, request_id: draft.operation_id, name: draft.name, strain_id: draft.strain_id,
        location: draft.location, planned: true };
      break;
    default: {
      path = 'journal-notes';
      const { operation_id, site_id, title, body, occurred_at } = draft;
      payload = { ...binding, operation_id, site_id, title, body, occurred_at };
    }
  }
  const receipt = await offlineRequest<{ id: string }>(prefix + path, payload, signal);
  if (typeof receipt?.id !== 'string' || !receipt.id || ('plant_id' in draft && receipt.id !== draft.plant_id)) {
    throw new Error('Missing offline operation receipt');
  }
}

export async function reconcileOfflineSites(accountId: string) {
  if (!window.isSecureContext || typeof indexedDB === 'undefined') return;
  try {
    const { revision, metadata, outbox, library } = await readOfflineState();
    const local = metadata ?? outbox;
    if (!local) return;
    if (local.accountId !== accountId) {
      await clearOfflineSites(revision);
      return;
    }
    try {
      const latest = await fetchOfflineSites();
      if (latest.deviceId !== local.deviceId) {
        await clearOfflineSites(revision);
        return;
      }
      if (metadata && latest.version !== metadata.version) {
        await clearOfflineDirectory(revision);
        return;
      }
      if (metadata?.plantVersion) {
        const query = new URLSearchParams({ version: latest.version, check: '1' });
        const current = await offlineRequest<Pick<OfflinePlantCatalog, 'version'>>('account/mobile-devices/offline-plants?' + query);
        if (current?.version && current.version !== metadata.plantVersion) {
          await clearOfflineDirectory(revision);
          return;
        }
      }
      if (library) {
        try {
          await validateOfflineFiles(library.ticket);
        } catch (cause) {
          if (cause instanceof ApiError && cause.status === 409) await clearOfflineLibrary(revision);
          else throw cause;
        }
      }
    } catch (cause) {
      if (cause instanceof ApiError && [401, 403].includes(cause.status)) await clearOfflineSites(revision);
      else if (cause instanceof ApiError && cause.status === 409) await clearOfflineDirectory(revision);
    }
  } catch {
    // Local storage can be unavailable in private browser sessions.
  }
}
