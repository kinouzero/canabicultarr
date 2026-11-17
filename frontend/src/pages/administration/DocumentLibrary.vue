<script setup lang="ts">
import AppSwitch from '../../components/AppSwitch.vue';
import { useSubjectQuery, resolveDocumentSubject } from '../../lib/subject-query';
import { useResolvedRecordQuery } from '../../lib/route-query';
import { toRef } from 'vue';
import DisclosureSummary from '../../components/DisclosureSummary.vue';
import UploadSelection from '../../components/UploadSelection.vue';
import { photoThumbnailUrl } from '../../lib/photo-urls';
import DiscardChangesDialog from '../../components/DiscardChangesDialog.vue';
import { useUnsavedChanges } from '../../lib/unsaved-changes';
import { useFieldErrors } from '../../lib/field-errors';
import DocumentStorageSelect from '../../components/DocumentStorageSelect.vue';
import { useCollectionPageSize } from '../../lib/collection-page-size';
import CollectionPagination from '../../components/CollectionPagination.vue';
import { useDisclosureState, useRememberDisclosure } from '../../lib/disclosure-preferences';
import ResetFilters from '../../components/ResetFilters.vue';
import { useCollectionSort } from '../../lib/collection-sorting';
import PageTitle from '../../components/PageTitle.vue';
import { NTree, type TreeOption } from 'naive-ui';
import { h } from 'vue';
import RecordBatchAction from '../../components/RecordBatchAction.vue';
import CollectionTable from '../../components/CollectionTable.vue';

import FolderSelect from '../../components/FolderSelect.vue';
import { useFolderBrowser } from '../../lib/folder-browser';
import { folderAncestors } from '../../lib/document-folders';
import { queryField, useCollectionQuery } from '../../lib/collection-query';
import AppIcon from '../../components/AppIcon.vue';
import { NTag as Tag } from 'naive-ui';
import { locationLabel } from '../../lib/location';
import NotesEditor from '../../components/NotesEditor.vue';
import ListActionButton from '../../components/ListActionButton.vue';
import { formatDateTime } from '../../lib/date-time';
import FormDialog from '../../components/FormDialog.vue';
import FilePreview from '../../components/FilePreview.vue';
import { nextTick, computed, useId, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { NButton as Button } from 'naive-ui';
import { NCard as Card } from 'naive-ui';
import { NInput as InputText } from 'naive-ui';
import Message from '../../components/AppMessage.vue';
import SubjectSelect from '../../components/SubjectSelect.vue';
import en from '../../locales/en/documents.json';
import fr from '../../locales/fr/documents.json';
import { ApiError } from '../../api/auth';
import { documentsApi, documentFoldersApi, type DocumentFolder, type LibraryDocument, type DocumentSubject, type DocumentInput } from '../../api/documents';
const pageSize = useCollectionPageSize('files');
const vRememberDisclosure = useRememberDisclosure('DocumentLibrary');
defineProps<{ embedded?: boolean }>();
const emit = defineEmits<{ close: []; accessLost: [cause: ApiError] }>();
const { t, te, locale, mergeLocaleMessage } = useI18n({ useScope: 'global' });
mergeLocaleMessage('en', { documents: en }); mergeLocaleMessage('fr', { documents: fr });
const entries = ref<LibraryDocument[]>([]); const page = ref(1); const lastPage = ref(1); const total = ref(0);
const previewRoute = useResolvedRecordQuery('file', documentsApi.show, showError);
const preview = previewRoute.record;
const failedThumbnails = ref(new Set<string>());
function thumbnailKey(entry: LibraryDocument) { return `${entry.id}:${entry.version}`; }
function showThumbnail(entry: LibraryDocument) { return entry.format === 'image' && ['image/jpeg', 'image/png', 'image/webp'].includes(entry.mediaType) && !failedThumbnails.value.has(thumbnailKey(entry)); }
const loading = ref(false); const saving = ref(false); const downloading = ref<string | null>(null);
const error = ref(''); const success = ref('');
const sort = useCollectionSort('files', ['name', 'newest', 'oldest', 'size'] as const);
const filters = reactive({ search: '', state: 'active', format: '', group: 'none', sort }); const filterSubject = ref<DocumentSubject | null>(null);
const subjectQuery = useSubjectQuery(filterSubject, resolveDocumentSubject);
const formOpen = ref(false); const editing = ref<LibraryDocument | null>(null); const file = ref<File | null>(null);
const form = reactive({ title: '', description: '', group: 'none', archived: false, folderId: '' }); const subject = ref<DocumentSubject | null>(null);
type PickKind = 'location' | 'asset' | 'sensor' | 'event';
const kinds: PickKind[] = ['location', 'asset', 'sensor', 'event'];
const folderBrowser = useFolderBrowser(); const folders = folderBrowser.entries; const currentFolder = ref('root'); const foldersLoading = ref(false);
const folderExpansion = useDisclosureState('file-tree');
const expandedKeys = computed<Record<string, boolean>>({ get: () => ({ root: true, ...folderExpansion.states.value }), set: value => folderExpansion.replace({ root: false, ...value }) });
const selectedKeys = computed(() => [currentFolder.value]);
const expandedList = computed({ get: () => Object.keys(expandedKeys.value).filter(key => expandedKeys.value[key]), set: (keys: (string | number)[]) => { expandedKeys.value = Object.fromEntries(keys.map(key => [key, true])); } });
function folderLabel({ option }: { option: TreeOption }) { return h('span', { class: 'folder-tree-label', title: String(option.label ?? '') }, [h(AppIcon, { name: String(option.key).startsWith('more:') ? 'arrow' : option.key === 'all' ? 'file' : 'folder' }), String(option.label ?? '')]); }
const breadcrumbs = computed(() => folderAncestors(currentFolder.value, folderBrowser.folders));
const folderPage = computed(() => folderBrowser.pages.get(currentFolder.value));
const children = computed(() => currentFolder.value === 'all' ? [] : folderPage.value?.data ?? []);
const tree = computed<TreeOption[]>(() => {
 const grouped = new Map<string | null, DocumentFolder[]>();
 for (const folder of folders.value) { const siblings = grouped.get(folder.parentId) ?? []; siblings.push(folder); grouped.set(folder.parentId, siblings); }
 function nodes(parent: string | null, seen = new Set<string>()): TreeOption[] {
  if (parent && seen.has(parent)) return [];
  const next = new Set(seen); if (parent) next.add(parent);
  const result: TreeOption[] = (grouped.get(parent) ?? []).map(folder => ({ key: folder.id, label: folder.name, isLeaf: !folder.hasChildren && !grouped.has(folder.id), children: folderBrowser.pages.has(folder.id) ? nodes(folder.id, next) : undefined }));
  const page = folderBrowser.pages.get(parent ?? 'root');
  if (page && page.meta.current_page < page.meta.last_page) result.push({ key: `more:${parent ?? 'root'}`, label: t('documents.moreFolders'), isLeaf: true });
  return result;
 }
 return [{ key: 'all', label: t('documents.allFiles'), isLeaf: true }, { key: 'root', label: t('documents.root'), children: nodes(null) }];
});
const folderOpen = ref(false); const editingFolder = ref<DocumentFolder | null>(null);
const folderForm = reactive({ name: '', parentId: '' }); const deletingFolder = ref<DocumentFolder | null>(null);
const moveFolderSelect = ref<InstanceType<typeof FolderSelect> | null>(null);
const moving = ref<LibraryDocument | null>(null); const moveDestination = ref('');
const batchFolder = ref('');
const moveBatchFile = (row: LibraryDocument) => documentsApi.update(row.id, { version: row.version, folder_id: batchFolder.value || null });
const archiveBatchFile = (row: LibraryDocument) => documentsApi.update(row.id, { version: row.version, is_archived: true });
const moveFolderOpen = ref(false); const moveFolderName = ref('');
let sequence = 0; let alive = true;
const uploadDisk = ref(''); const fileSelection = ref(0); const reloading = ref(false); const conflict = ref(false);
const busy = computed(() => saving.value || reloading.value);
const baseline = ref('');
function snapshot() { return JSON.stringify(formOpen.value ? [form, subject.value?.type, subject.value?.id, uploadDisk.value] : folderOpen.value ? folderForm : moving.value ? [moveDestination.value, moveFolderName.value] : null); }
const dirty = computed(() => busy.value || (formOpen.value || folderOpen.value || !!moving.value) && (snapshot() !== baseline.value || !!file.value));
const unsaved = useUnsavedChanges(dirty, busy, { onNavigate: () => { clearEditors(); } });
const documentValidation = useFieldErrors(() => Object.fromEntries([
 ['disk', 'document-storage', 'storageInvalid'], ['file', 'document-file-selection', 'fileInvalid'], ['title', 'document-title', 'titleInvalid'],
 ['folder_id', 'document-folder', 'folderInvalid'], ['description', 'document-description', 'descriptionInvalid'],
 ['subject', form.group === 'none' ? 'document-context-kind' : 'documents-subject', 'subjectInvalid'], ['is_archived', 'document-archived', 'stateInvalid'], ['version', 'document-version', 'versionInvalid'],
].map(([field, id, message]) => [field, { id, message: `documents.validation.${message}` }])),
 () => ({ ...form, disk: uploadDisk.value, file: fileSelection.value, folder_id: form.folderId, is_archived: form.archived, subject: JSON.stringify([form.group, subject.value?.type, subject.value?.id]), version: editing.value?.version }));
const folderValidation = useFieldErrors(() => ({ name: { id: 'folder-name', message: 'documents.validation.folderNameInvalid' }, parent_id: { id: 'folder-parent', message: 'documents.validation.folderInvalid' }, version: { id: 'folder-version', message: 'documents.validation.versionInvalid' } }), () => ({ name: folderForm.name, parent_id: folderForm.parentId, version: editingFolder.value?.version }));
const moveValidation = useFieldErrors(() => ({ folder_id: { id: 'file-destination', message: 'documents.validation.folderInvalid' }, version: { id: 'move-version', message: 'documents.validation.versionInvalid' } }), () => ({ folder_id: moveDestination.value, version: moving.value?.version }));
const moveFolderValidation = useFieldErrors(() => ({ name: { id: 'move-folder-name', message: 'documents.validation.folderNameInvalid' }, parent_id: { id: 'file-destination', message: 'documents.validation.folderInvalid' } }), () => ({ name: moveFolderName.value, parent_id: moveDestination.value }));
const validation = computed(() => formOpen.value ? documentValidation : folderOpen.value ? folderValidation : moving.value ? moveFolderOpen.value ? moveFolderValidation : moveValidation : null);
const fields = computed(() => validation.value?.fields.value ?? {});
function clearEditors() { moveFolderOpen.value = false; moveFolderName.value = ''; moveFolderValidation.clear(); formOpen.value = folderOpen.value = false; moving.value = deletingFolder.value = null; file.value = null; conflict.value = false; documentValidation.clear(); folderValidation.clear(); moveValidation.clear(); }
async function closeEditor() { if (await unsaved.confirmDiscard()) { clearEditors(); error.value = ''; } }
async function leave() { if (await unsaved.confirmDiscard()) emit('close'); }
function invalid(validator: typeof documentValidation) { if (!Object.keys(validator.fields.value).length) return false; error.value = 'auth.errors.validationFailed'; void validator.focus(); return true; }
function navigateFolder(id: string) {
 if (id.startsWith('more:')) { const parent = id.slice(5); const page = folderBrowser.pages.get(parent)?.meta.current_page ?? 1; void loadFolderChildren(parent, page + 1); return; }
 currentFolder.value = id; filters.search = '';
}
async function loadFolderChildren(id: string, target = 1) {
 try { await folderBrowser.children(id === 'root' ? null : id, target); }
 catch (cause) { if (alive && !(cause instanceof DOMException && cause.name === 'AbortError')) showError(cause, false); }
}
async function expandFolder(node: TreeOption) {
 const id = String(node.key); if (id !== 'all' && !folderBrowser.pages.has(id)) await loadFolderChildren(id);
}
async function loadFolders() {
 foldersLoading.value = true;
 try {
  if (!folderBrowser.pages.has('root')) await folderBrowser.children(null);
  const id = currentFolder.value;
  if (!['root', 'all'].includes(id)) {
   await folderBrowser.path(id);
   if (alive && currentFolder.value === id) await folderBrowser.children(id);
  }
  if (!alive || currentFolder.value !== id) return;
  const expanded: Record<string, boolean> = { ...expandedKeys.value, root: true };
  for (const folder of folderAncestors(id, folderBrowser.folders)) expanded[folder.id] = true;
  expandedKeys.value = expanded;
 } catch (cause) { if (alive && !(cause instanceof DOMException && cause.name === 'AbortError')) { entries.value = []; showError(cause, false); } }
 finally { if (alive) foldersLoading.value = false; }
}
watch(currentFolder, () => { void loadFolders(); });
async function refresh() { folderBrowser.reset(); await Promise.all([loadFolders(), load(page.value)]); }
function replaceFolder(folder: DocumentFolder | null = null) {
 clearEditors(); editingFolder.value = folder; Object.assign(folderForm, { name: folder?.name ?? '', parentId: folder ? folder.parentId ?? '' : ['root', 'all'].includes(currentFolder.value) ? '' : currentFolder.value });
 error.value = success.value = ''; folderOpen.value = true; baseline.value = snapshot();
}
async function editFolder(folder: DocumentFolder | null = null) { if (await unsaved.confirmDiscard()) replaceFolder(folder); }
function invalidFolderName(name: string) { return !name.trim() || name.length > 120 || ['.', '..'].includes(name.trim()) || Array.from(name).some(char => char.charCodeAt(0) < 32 || [47, 92, 127].includes(char.charCodeAt(0))); }
async function saveFolder() {
 if (busy.value || conflict.value) return; error.value = ''; folderValidation.clear();
 if (invalidFolderName(folderForm.name)) folderValidation.set('name');
 if (folderForm.parentId && folderAncestors(folderForm.parentId, folderBrowser.folders).some(folder => folder.id === editingFolder.value?.id)) folderValidation.set('parent_id');
 if (invalid(folderValidation)) return;
 saving.value = true;
 try {
  const input = { name: folderForm.name, parent_id: folderForm.parentId || null, version: editingFolder.value?.version };
  const result = editingFolder.value ? await documentFoldersApi.update(editingFolder.value.id, input) : await documentFoldersApi.create(input);
  if (!alive) return; clearEditors(); saving.value = false; const sameFolder = currentFolder.value === result.id; folderBrowser.reset(); navigateFolder(result.id); if (sameFolder) void loadFolders(); success.value = 'documents.folderSaved';
 } catch (cause) { showError(cause); } finally { saving.value = false; }
}
async function askDeleteFolder(folder: DocumentFolder) { if (!await unsaved.confirmDiscard()) return; clearEditors(); error.value = ''; deletingFolder.value = folder; }
async function deleteFolder() {
 if (!deletingFolder.value || busy.value) return; saving.value = true; error.value = '';
 try { const parent = deletingFolder.value.parentId; await documentFoldersApi.remove(deletingFolder.value.id, deletingFolder.value.version); if (!alive) return; clearEditors(); currentFolder.value = parent ?? 'root'; folderBrowser.reset(); success.value = 'documents.folderDeleted'; }
 catch (cause) { showError(cause); } finally { saving.value = false; }
}
function replaceMove(entry: LibraryDocument) { clearEditors(); moving.value = entry; moveDestination.value = entry.folderId ?? ''; error.value = success.value = ''; baseline.value = snapshot(); }
async function moveFile(entry: LibraryDocument) { if (await unsaved.confirmDiscard()) replaceMove(entry); }
function cancelMoveFolder() { moveFolderOpen.value = false; moveFolderName.value = ''; moveFolderValidation.clear(); error.value = ''; }
async function openMoveFolder() {
 moveFolderOpen.value = true; error.value = ''; moveValidation.clear();
 await nextTick(); document.getElementById('move-folder-name')?.focus();
}
async function createMoveFolder() {
 if (!moving.value || busy.value || conflict.value) return;
 error.value = ''; moveFolderValidation.clear();
 if (invalidFolderName(moveFolderName.value)) moveFolderValidation.set('name');
 if (invalid(moveFolderValidation)) return;
 saving.value = true;
 try {
  const folder = await documentFoldersApi.create({ name: moveFolderName.value.trim(), parent_id: moveDestination.value || null });
  if (!alive) return;
  folderBrowser.remember([folder]); moveDestination.value = folder.id;
  cancelMoveFolder();
 } catch (cause) { showError(cause); }
 finally { saving.value = false; if (moving.value && !moveFolderOpen.value) { await moveFolderSelect.value?.focus(); } }
}
async function saveMove() {
 if (!moving.value || moveFolderOpen.value || busy.value || conflict.value) return; saving.value = true; error.value = ''; moveValidation.clear();
 try { await documentsApi.update(moving.value.id, { version: moving.value.version, folder_id: moveDestination.value || null }); if (!alive) return; clearEditors(); success.value = 'documents.fileMoved'; await load(page.value); }
 catch (cause) { showError(cause); } finally { saving.value = false; }
}
async function reloadFolder() {
 if (!editingFolder.value || !await unsaved.confirmDiscard()) return;
 const id = editingFolder.value.id; reloading.value = true;
 try { const current = await folderBrowser.path(id); if (alive && current) replaceFolder(current); }
 catch (cause) { showError(cause); } finally { reloading.value = false; }
}
function key(value: string) { return /^(auth|documents)\.(errors|validation)\.[A-Za-z]+$/.test(value) && te(value) ? value : 'auth.errors.requestFailed'; }
function showError(cause: unknown, editor = true) {
 if (!alive) return;
 error.value = key(cause instanceof ApiError ? cause.code : 'auth.errors.requestFailed');
 if (!(cause instanceof ApiError)) return;
 if ([401, 403].includes(cause.status)) { entries.value = []; preview.value = null; clearEditors(); emit('accessLost', cause); return; }
 if (!editor || !validation.value) return;
 validation.value.capture(cause);
 if (['documents.errors.conflict', 'documents.errors.folderConflict'].includes(cause.code) || cause.fields.version) conflict.value = true;
 if (cause.fields.name?.includes('documents.errors.folderNameTaken')) { validation.value.set('name', 'documents.errors.folderNameTaken'); void validation.value.focus(); }
 const field = ({ 'documents.errors.folderNameTaken': 'name', 'documents.errors.tooLarge': 'file', 'documents.validation.subjectInvalid': 'subject' } as Record<string, string>)[cause.code];
 if (field) { validation.value.set(field, cause.code); void validation.value.focus(); }
 if (cause.status === 422) error.value = 'auth.errors.validationFailed';
}
function context(value: DocumentSubject | null) {
 if (!value) return t('documents.noContext');
 return [value.name, value.deviceName, locationLabel(value.location)].filter(Boolean).join(' · ');
}
function size(bytes: number) { const unit = bytes < 1024 ? 'byte' : bytes < 1048576 ? 'kilobyte' : 'megabyte'; return new Intl.NumberFormat(locale.value, { style: 'unit', unit, maximumFractionDigits: 1 }).format(bytes / (unit === 'byte' ? 1 : unit === 'kilobyte' ? 1024 : 1048576)); }
function date(iso: string) { return formatDateTime(iso, locale.value, 'date'); }
async function load(target = 1) {
 const current = ++sequence; loading.value = true; if (!formOpen.value && !folderOpen.value && !moving.value) error.value = '';
 try {
   if (!await subjectQuery.resolve()) return;
   if (filterSubject.value && filters.group !== (['site', 'room', 'zone'].includes(filterSubject.value.type) ? 'location' : filterSubject.value.type)) throw new ApiError(422, 'auth.errors.validationFailed');
  const query: Record<string, string> = { per_page: String(pageSize.value), search: filters.search.trim(), state: filters.state, format: filters.format, sort: filters.sort };
  if (currentFolder.value !== 'all') query.folder_id = currentFolder.value === 'root' ? '' : currentFolder.value;
  if (filterSubject.value) query[filterSubject.value.type === 'event' ? 'event_entry_id' : `${filterSubject.value.type}_id`] = filterSubject.value.id;
  let result = await documentsApi.list(query, target);
  if (!alive || current !== sequence) return;
  if (target > result.meta.last_page) result = await documentsApi.list(query, result.meta.last_page);
  if (!alive || current !== sequence) return;
  if (currentFolder.value === 'all') await folderBrowser.paths(result.data.flatMap(entry => entry.folderId ? [entry.folderId] : []));
  if (!alive || current !== sequence) return;
  entries.value = result.data; failedThumbnails.value = new Set(); page.value = result.meta.current_page; lastPage.value = result.meta.last_page; total.value = result.meta.total;
 } catch (cause) { if (alive && current === sequence) { if (cause instanceof ApiError && [404, 422].includes(cause.status)) entries.value = []; showError(cause, false); } } finally { if (alive && current === sequence) loading.value = false; }
}
async function open(entry: LibraryDocument | null = null) { if (await unsaved.confirmDiscard()) replaceEditor(entry); }
function replaceEditor(entry: LibraryDocument | null = null) {
 clearEditors();
 editing.value = entry; file.value = null; uploadDisk.value = ''; subject.value = entry?.subject ?? null;
 Object.assign(form, { title: entry?.title ?? '', description: entry?.description ?? '', archived: entry?.isArchived ?? false, folderId: entry ? entry.folderId ?? '' : ['root', 'all'].includes(currentFolder.value) ? '' : currentFolder.value, group: subject.value ? ['site', 'room', 'zone'].includes(subject.value.type) ? 'location' : subject.value.type : 'none' });
 error.value = success.value = ''; formOpen.value = true; baseline.value = snapshot();
}
function selectFile(files: File[]) {
 fileSelection.value++; file.value = files[0] ?? null;
 if (file.value && !form.title) form.title = file.value.name.replace(/\.[^.]+$/, '').slice(0, 160);
}
async function save() {
 if (busy.value || conflict.value) return;
 documentValidation.clear(); error.value = success.value = '';
 if (!form.title.trim() || form.title.length > 160) documentValidation.set('title');
 if (form.description.length > 2000) documentValidation.set('description');
 if (form.group !== 'none' && !subject.value) documentValidation.set('subject');
 if (!editing.value && (!file.value || file.value.size < 1 || file.value.size > 10485760)) documentValidation.set('file', file.value && file.value.size > 10485760 ? 'documents.errors.tooLarge' : 'documents.validation.fileInvalid');
 if (invalid(documentValidation)) return;
 saving.value = true;
 try {
  const input: DocumentInput = { folder_id: form.folderId || null, title: form.title, description: form.description || null, version: editing.value?.version ?? 1, is_archived: form.archived, subject: subject.value ? { type: subject.value.type, id: subject.value.id } : null };
  if (editing.value) await documentsApi.update(editing.value.id, input);
  else {
   const data = new FormData(); if (uploadDisk.value) data.append('disk', uploadDisk.value); data.append('file', file.value!); data.append('title', input.title); data.append('description', form.description); data.append('folder_id', form.folderId);
   if (input.subject) { data.append('subject[type]', input.subject.type); data.append('subject[id]', input.subject.id); }
   await documentsApi.upload(data);
  }
  if (!alive) return; clearEditors(); success.value = editing.value ? 'documents.updated' : 'documents.uploaded'; await load(editing.value ? page.value : 1);
 } catch (cause) { showError(cause); } finally { saving.value = false; }
}
async function reloadEdit() {
 const id = moving.value?.id ?? editing.value?.id; const isMove = !!moving.value;
 if (!id || !await unsaved.confirmDiscard()) return;
 reloading.value = true;
 try { const current = await documentsApi.show(id); if (alive) { if (isMove) replaceMove(current); else replaceEditor(current); } }
 catch (cause) { showError(cause); } finally { reloading.value = false; }
}
async function download(entry: LibraryDocument) { if (downloading.value) return; downloading.value = entry.id; error.value = ''; try { await documentsApi.download(entry); } catch (cause) { showError(cause); } finally { downloading.value = null; } }
onMounted(refresh); onUnmounted(() => { alive = false; sequence++; });
const dialogId = useId();
const collectionQuery = useCollectionQuery({ paths: ['/files'], fields: { ...subjectQuery.fields, q: queryField.text(toRef(filters, 'search'), 160), state: queryField.choice(toRef(filters, 'state'), ['active', 'archived', 'all']), format: queryField.choice(toRef(filters, 'format'), ['', 'image', 'pdf', 'text']), group: queryField.text(toRef(filters, 'group'), 32), folder: queryField.identifier(currentFolder), sort: queryField.choice(sort, ['name', 'newest', 'oldest', 'size']) }, page, pageSize, load: target => Promise.all([loadFolders(), load(target)]) });
const applyFilters = collectionQuery.apply;


const selectionHeader = ref<HTMLElement | null>(null);
</script>
<template><DiscardChangesDialog v-if="unsaved.pending.value" @decision="unsaved.finish" />
 <Card class="documents-card"><template #header><div class="card-heading-block"><div class="page-heading"><div class="heading"><PageTitle icon="folder">{{ t('documents.title') }}</PageTitle><Button v-if="!embedded" :aria-label="t('documents.back')" type="default" :disabled="busy || !!downloading" @click="leave" :title="t('documents.back')" class="list-action-button"><template #icon><AppIcon name="arrowLeft" /></template></Button></div><div ref="selectionHeader" class="page-actions"><ListActionButton icon="refresh" :label="t('collection.refresh')" :loading="loading" :disabled="busy" @click="refresh" /><Button type="default" :disabled="busy || foldersLoading" @click="editFolder()" data-action-icon="folder" :aria-label="t('documents.newFolder')" :title="t('documents.newFolder')" class="list-action-button"><template #icon><AppIcon name="folder" /></template></Button><Button :aria-label="t('documents.add')" :disabled="busy" @click="open()" data-action-icon="plus" :title="t('documents.add')" class="list-action-button" type="primary"><template #icon><AppIcon name="plus" /></template></Button></div></div><div class="card-subtitle">{{ t('documents.description') }}</div></div></template>

  <template #default><Message v-if="error && !formOpen && !folderOpen && !moving && !deletingFolder" type="error" closable role="alert">{{ t(error) }}</Message>
   <Message v-if="success" type="success" closable role="status">{{ t(success) }}</Message>

   <div class="file-explorer"><aside class="folder-sidebar"><details v-remember-disclosure="'folders'" open><DisclosureSummary><AppIcon name="folder" />{{ t('documents.folders') }}</DisclosureSummary><p v-if="foldersLoading" role="status">{{ t('documents.loadingFolders') }}</p><NTree role="tree" :node-props="({ option }) => ({ role: 'treeitem', 'aria-label': String(option.label ?? '') })" :data="tree" :selected-keys="selectedKeys" v-model:expanded-keys="expandedList" :cancelable="false" block-line :aria-label="t('documents.folders')" :on-load="expandFolder" :render-label="folderLabel" @update:selected-keys="keys => keys[0] !== undefined && navigateFolder(String(keys[0]))" /></details></aside>
    <div class="explorer-content"><nav class="folder-breadcrumb" :aria-label="t('documents.folderPath')"><button type="button" @click="navigateFolder('root')"><AppIcon name="folder" />{{ t('documents.root') }}</button><template v-if="currentFolder === 'all'"><AppIcon name="arrow" /><span>{{ t('documents.allFiles') }}</span></template><template v-for="folder in breadcrumbs" :key="folder.id"><AppIcon name="arrow" /><button type="button" :aria-current="folder.id === currentFolder ? 'page' : undefined" @click="navigateFolder(folder.id)"><AppIcon name="folder" class="button-icon" />{{ folder.name }}</button></template><div v-if="breadcrumbs.at(-1)?.canEdit" class="folder-tools"><ListActionButton icon="edit" :label="t('documents.editFolder')" @click="editFolder(breadcrumbs.at(-1)!)" /><ListActionButton icon="trash" :label="t('documents.deleteFolder')" @click="askDeleteFolder(breadcrumbs.at(-1)!)" /></div></nav>
   <form class="filters search-filters" @submit.prevent="applyFilters"><div class="filter-primary"><div class="filter-field"><label for="documents-search">{{ t('documents.search') }}</label><InputText placeholder="" v-model:value="filters.search" maxlength="160" :disabled="busy" :input-props="{ 'id': 'documents-search' }"></InputText></div>
     <div class="filter-field"><label for="documents-sort">{{ t('documents.sort') }}</label><select id="documents-sort" v-model="filters.sort" v-app-select="{ filter: t('selectControl.filter'), empty: t('selectControl.empty') }"><option v-for="value in ['name','newest','oldest','size']" :key="value" :value="value">{{ t(`documents.sorts.${value}`) }}</option></select></div></div>
    <div class="filter-secondary"><div class="filter-field"><label for="documents-state">{{ t('documents.state') }}</label><select v-app-select="{ filter: $t('selectControl.filter'), empty: $t('selectControl.empty') }" id="documents-state" v-model="filters.state" :disabled="busy"><option v-for="value in ['active','archived','all']" :key="value" :value="value">{{ t(`documents.states.${value}`) }}</option></select></div>
     <div class="filter-field"><label for="documents-format">{{ t('documents.format') }}</label><select v-app-select="{ filter: $t('selectControl.filter'), empty: $t('selectControl.empty') }" id="documents-format" v-model="filters.format" :disabled="busy"><option value="">{{ t('documents.allFormats') }}</option><option v-for="value in ['pdf','image','text']" :key="value" :value="value">{{ t(`documents.formats.${value}`) }}</option></select></div>
     <div class="filter-field"><label for="documents-context-filter">{{ t('documents.context') }}</label><select v-app-select="{ filter: $t('selectControl.filter'), empty: $t('selectControl.empty') }" id="documents-context-filter" v-model="filters.group" :disabled="busy" @change="subjectQuery.clear()"><option value="none">{{ t('documents.allContexts') }}</option><option v-for="kind in kinds" :key="kind" :value="kind">{{ t(`documents.contextKinds.${kind}`) }}</option></select></div>
     <SubjectSelect v-if="filters.group !== 'none'" v-model="filterSubject" id="documents-subject-filter" :label="t('documents.chooseFilter')" :placeholder="t('documents.allContexts')" :kind="filters.group" :disabled="busy" @access-lost="showError" /></div>
    <ResetFilters @reset="Object.assign(filters, { search: '', state: 'active', format: '', group: 'none', sort: 'name' }); subjectQuery.clear()" /></form>
   <p v-if="filterSubject">{{ context(filterSubject) }}</p>
   <FormDialog icon="folder" v-if="formOpen" :visible="true" :title="t(editing ? 'documents.editTitle' : 'documents.createTitle')" :close-label="t('documents.cancel')" :busy="busy" :error="error ? t(error) : ''" @update:visible="!$event && closeEditor()"><template #feedback><div v-if="fields.version" id="document-version" tabindex="-1" v-bind="validation?.attrs('version')"><small id="document-version-error" role="alert" class="field-error">{{ t(fields.version) }}</small></div><Button v-if="conflict" :aria-label="t('documents.reloadCurrent')" type="default" :disabled="busy" @click="reloadEdit"><template #icon><AppIcon name="refresh" /></template><span>{{ t('actions.refresh') }}</span></Button></template>
<form :id="`${dialogId}-0`" class="dialog-form document-form" @submit.prevent="save" novalidate><p>{{ t(editing ? 'documents.immutableHelp' : 'documents.fileHelp') }}</p>
    <div v-if="!editing" class="field dialog-wide"><label for="document-file">{{ t('documents.file') }}</label><UploadSelection input-id="document-file" :model-value="file ? [file] : []" :disabled="busy" :state="saving ? 'uploading' : error ? 'error' : 'pending'" @update:model-value="selectFile" /><small v-if="fields.file" id="document-file-error" role="alert" class="field-error">{{ t(fields.file) }}</small></div>
    <div class="field"><label for="document-title">{{ t('documents.entryTitle') }}</label><InputText placeholder="" v-bind="documentValidation.attrs('title')" v-model:value="form.title" maxlength="160" :disabled="busy" :status="!!fields.title ? 'error' : undefined" :input-props="{ 'id': 'document-title' }"></InputText><small v-if="fields.title" id="document-title-error" role="alert" class="field-error">{{ t(fields.title) }}</small></div>
    <div class="field"><label for="document-folder">{{ t('documents.folder') }}</label><FolderSelect id="document-folder" v-bind="documentValidation.attrs('folder_id')" v-model="form.folderId" :disabled="busy || foldersLoading" /><small v-if="fields.folder_id" role="alert" class="field-error" id="document-folder-error">{{ t(fields.folder_id) }}</small></div>
<DocumentStorageSelect v-if="!editing" id="document-storage" v-model="uploadDisk" :disabled="busy" :invalid="!!fields.disk" :described-by="fields.disk ? 'document-storage-error' : undefined" /><small v-if="fields.disk" role="alert" class="field-error" id="document-storage-error">{{ t(fields.disk) }}</small>

    <div class="field"><label for="document-context-kind">{{ t('documents.context') }}</label><select v-app-select="{ filter: $t('selectControl.filter'), empty: $t('selectControl.empty') }" id="document-context-kind" v-bind="documentValidation.attrs('subject')" v-model="form.group" :disabled="busy" @change="subject = null"><option value="none">{{ t('documents.noContext') }}</option><option v-for="kind in kinds" :key="kind" :value="kind">{{ t(`documents.contextKinds.${kind}`) }}</option></select></div>
    <div v-if="form.group !== 'none'" class="context"><SubjectSelect v-model="subject" id="documents-subject" :described-by="fields.subject ? 'documents-subject-error' : undefined" :label="t('documents.chooseContext')" :placeholder="t('documents.noSubjectSelected')" :kind="form.group" :disabled="busy" :invalid="!!fields.subject" @access-lost="showError" /></div><small v-if="fields.subject" :id="form.group === 'none' ? 'document-context-kind-error' : 'documents-subject-error'" role="alert" class="field-error">{{ t(fields.subject) }}</small>



<div class="field dialog-wide"><label for="document-description">{{ t('documents.notes') }}</label><NotesEditor id="document-description" v-model="form.description" :label="t('documents.notes')" :disabled="busy" :invalid="!!fields.description" :described-by="fields.description ? 'document-description-error' : undefined" /><small v-if="fields.description" id="document-description-error" role="alert" class="field-error">{{ t(fields.description) }}</small></div>
<div v-if="editing" class="field"><AppSwitch id="document-archived" v-bind="documentValidation.attrs('is_archived')" v-model="form.archived" :disabled="busy">{{ t('documents.archived') }}</AppSwitch><small v-if="fields.is_archived" id="document-archived-error" role="alert" class="field-error">{{ t(fields.is_archived) }}</small></div></form>
<template #footer><div class="dialog-actions"><Button :aria-label="t('documents.cancel')" type="default" :disabled="busy" @click="closeEditor"><template #icon><AppIcon name="close" /></template><span>{{ t('actions.cancel') }}</span></Button><Button :form="`${dialogId}-0`" attr-type="submit" :aria-label="editing ? t('documents.save') : t('actions.upload')" :loading="saving" :disabled="reloading || conflict" type="primary"><template #icon><AppIcon name="save" /></template><span>{{ t(editing ? 'actions.save' : 'actions.upload') }}</span></Button></div></template></FormDialog>
   <div v-if="children.length" class="folder-grid"><div v-for="folder in children" :key="folder.id" class="folder-tile"><button type="button" class="folder-link" @click="navigateFolder(folder.id)"><AppIcon name="folder" /><span>{{ folder.name }}</span><AppIcon name="arrow" /></button><ListActionButton v-if="folder.canEdit" icon="edit" :label="t('documents.editFolderNamed', { name: folder.name })" @click="editFolder(folder)" /></div></div>
   <CollectionPagination v-if="currentFolder !== 'all' && folderPage && folderPage.meta.last_page > 1" :page="folderPage.meta.current_page" :last-page="folderPage.meta.last_page" :total="folderPage.meta.total" :disabled="foldersLoading || folderBrowser.pending.has(currentFolder)" @page="loadFolderChildren(currentFolder, $event)" />

   <CollectionTable :selection-target="selectionHeader" :rows="entries" :can-select="row => row.canEdit" :row-name="row => row.title" :locked="busy" :columns="[{key:'name', label:t('documents.entryTitle')},{key:'size', label:t('collection.fileSize')},{key:'date', label:t('collection.updatedAt')},{key:'actions', label:t('documents.actions')}]" :loading="loading" class="document-list">
    <template #batch-actions="{ rows: selectedFiles, clear }">
     <RecordBatchAction :rows="selectedFiles" :action="moveBatchFile" :name="row => row.title" :label="t('collection.batch.moveFiles')" icon="folder" @applied="clear($event); load(page)" @access-lost="showError">
      <template #default="{ busy: movingFiles }"><div class="field"><label for="batch-file-folder">{{ t('documents.folder') }}</label><FolderSelect id="batch-file-folder" v-model="batchFolder" :disabled="movingFiles" /></div></template>
     </RecordBatchAction>
     <RecordBatchAction :rows="selectedFiles.filter(row => !row.isArchived)" :action="archiveBatchFile" :name="row => row.title" :label="t('collection.batch.archive')" icon="archive" @applied="clear($event); load(page)" @access-lost="showError" />
    </template><template #name="{ row: entry }"><button type="button" class="file-name" :aria-label="t('filePreview.openNamed', { name: entry.originalName })" @click="previewRoute.open(entry.id)"><img v-if="showThumbnail(entry)" :key="thumbnailKey(entry)" class="file-thumbnail" :src="photoThumbnailUrl(`/api/v1/documents/${encodeURIComponent(entry.id)}/preview?version=${entry.version}`)" alt="" loading="lazy" decoding="async" width="64" height="64" @error="failedThumbnails.add(thumbnailKey(entry))" /><AppIcon v-else :name="entry.format === 'pdf' ? 'pdf' : entry.format === 'image' ? 'image' : 'file'" /><span><strong :title="entry.title">{{ entry.title }}</strong><small :title="entry.originalName">{{ entry.originalName }}</small></span></button><Tag v-if="entry.isArchived" type="default">{{ t('documents.archived') }}</Tag><small v-if="entry.subject" class="file-context">{{ context(entry.subject) }}</small><small v-if="currentFolder === 'all' && entry.folderId" class="file-context">{{ folderAncestors(entry.folderId, folderBrowser.folders).map(folder => folder.name).join(' / ') }}</small></template><template #size="{ row: entry }"><span class="file-meta">{{ size(entry.sizeBytes) }}</span><small class="file-context">{{ entry.disk === 'documents_s3' ? 'RustFS / S3' : t('documents.localStorage') }}</small></template><template #date="{ row: entry }"><span class="file-meta" :title="date(entry.updatedAt)">{{ date(entry.updatedAt) }}</span><small class="file-context" :title="entry.uploader.name ?? undefined">{{ entry.uploader.name ?? t('documents.authorUnavailable') }}</small></template><template #actions="{ row: entry }"><div class="file-actions"><ListActionButton icon="download" :label="t('documents.downloadNamed', { title: entry.title })" :loading="downloading === entry.id" :disabled="!!downloading || saving" @click="download(entry)" /><ListActionButton v-if="entry.canEdit" icon="folder" :label="t('documents.moveFileNamed', { title: entry.title })" :disabled="busy || foldersLoading" @click="moveFile(entry)" /><ListActionButton v-if="entry.canEdit" icon="edit" :label="t('documents.editNamed', { title: entry.title })" :disabled="busy" @click="open(entry)" /></div></template><template #empty><div class="file-empty"><AppIcon name="folder" /><span>{{ t('documents.empty') }}</span></div></template></CollectionTable>
   <CollectionPagination v-model:page-size="pageSize" :page="page" :last-page="lastPage" :total="total" :disabled="busy || loading" :label="t('documents.pagination')" :previous-label="t('documents.previous')" :next-label="t('documents.next')" @page="collectionQuery.go($event)" /></div></div>
   <FormDialog v-if="folderOpen" :visible="true" icon="folder" size="compact" :title="t(editingFolder ? 'documents.editFolder' : 'documents.newFolder')" :close-label="t('documents.cancel')" :busy="busy" :error="error ? t(error) : ''" @update:visible="!$event && closeEditor()"><template #feedback><div v-if="fields.version" id="folder-version" tabindex="-1" v-bind="validation?.attrs('version')"><small id="folder-version-error" role="alert" class="field-error">{{ t(fields.version) }}</small></div><Button v-if="conflict" :aria-label="t('documents.reloadFolder')" type="default" :disabled="busy" @click="reloadFolder"><template #icon><AppIcon name="refresh" /></template><span>{{ t('actions.refresh') }}</span></Button></template>
    <form :id="`${dialogId}-folder`" class="dialog-form" @submit.prevent="saveFolder" novalidate><div class="field dialog-wide"><label for="folder-name">{{ t('documents.folderName') }}</label><InputText placeholder="" v-bind="folderValidation.attrs('name')" v-model:value="folderForm.name" maxlength="120" :disabled="busy" :input-props="{ 'id': 'folder-name', 'required': true }"></InputText><small>{{ t('documents.folderNameHelp') }}</small><small v-if="fields.name" id="folder-name-error" role="alert" class="field-error">{{ t(fields.name) }}</small></div><div class="field dialog-wide"><label for="folder-parent">{{ t('documents.parentFolder') }}</label><FolderSelect id="folder-parent" v-bind="folderValidation.attrs('parent_id')" v-model="folderForm.parentId" :exclude="editingFolder?.id" :disabled="busy" /><small v-if="fields.parent_id" id="folder-parent-error" role="alert" class="field-error">{{ t(fields.parent_id) }}</small></div></form>
    <template #footer><Button type="default" :aria-label="t('documents.cancel')" :disabled="busy" @click="closeEditor"><template #icon><AppIcon name="close" /></template><span>{{ t('actions.cancel') }}</span></Button><Button attr-type="submit" :form="`${dialogId}-folder`" :aria-label="editingFolder ? t('documents.saveFolder') : t('actions.create')" :loading="saving" :disabled="reloading || conflict" type="primary"><template #icon><AppIcon name="save" /></template><span>{{ t(editingFolder ? 'actions.save' : 'actions.create') }}</span></Button></template></FormDialog>
   <FormDialog v-if="moving" :visible="true" icon="folder" size="compact" :title="t('documents.moveFileNamed', { title: moving.title })" :close-label="t('documents.cancel')" :busy="busy" :error="error ? t(error) : ''" @update:visible="!$event && closeEditor()"><template #feedback><div v-if="fields.version" id="move-version" tabindex="-1" v-bind="validation?.attrs('version')"><small id="move-version-error" role="alert" class="field-error">{{ t(fields.version) }}</small></div><Button v-if="conflict" :aria-label="t('documents.reloadCurrent')" type="default" :disabled="busy" @click="reloadEdit"><template #icon><AppIcon name="refresh" /></template><span>{{ t('actions.refresh') }}</span></Button></template><form :id="`${dialogId}-move`" class="dialog-form" @submit.prevent="saveMove"><div class="field dialog-wide"><label for="file-destination">{{ t('documents.destination') }}</label><FolderSelect ref="moveFolderSelect" id="file-destination" v-bind="moveFolderOpen ? moveFolderValidation.attrs('parent_id') : moveValidation.attrs('folder_id')" v-model="moveDestination" :disabled="busy" /><small v-if="fields.folder_id || fields.parent_id" id="file-destination-error" role="alert" class="field-error">{{ t(fields.folder_id || fields.parent_id) }}</small></div>
    <div v-if="moveFolderOpen" class="field dialog-wide"><label for="move-folder-name">{{ t('documents.folderName') }}</label><InputText placeholder="" v-model:value="moveFolderName" v-bind="moveFolderValidation.attrs('name')" maxlength="120" :disabled="busy" @keydown.enter.prevent="createMoveFolder" :input-props="{ 'id': 'move-folder-name' }"></InputText><small>{{ t('documents.folderNameHelp') }}</small>
     <small v-if="fields.name" id="move-folder-name-error" role="alert" class="field-error">{{ t(fields.name) }}</small>
     <div class="dialog-actions"><Button attr-type="button" type="default" :disabled="busy" @click="cancelMoveFolder"><template #icon><AppIcon name="close" /></template><span>{{ t('documents.cancelFolderCreation') }}</span></Button><Button attr-type="button" :loading="saving" :disabled="reloading || conflict" @click="createMoveFolder" type="primary"><template #icon><AppIcon name="plus" /></template><span>{{ t('documents.createFolder') }}</span></Button></div></div>
    <div v-else class="dialog-wide"><Button attr-type="button" type="default" :disabled="busy || conflict || foldersLoading" @click="openMoveFolder"><template #icon><AppIcon name="folder" /></template><span>{{ t('documents.newFolder') }}</span></Button></div></form><template #footer><Button type="default" :aria-label="t('documents.cancel')" :disabled="busy" @click="closeEditor"><template #icon><AppIcon name="close" /></template><span>{{ t('actions.cancel') }}</span></Button><Button attr-type="submit" :form="`${dialogId}-move`" :loading="saving && !moveFolderOpen" :disabled="busy || conflict || moveFolderOpen" type="primary"><template #icon><AppIcon name="folder" /></template><span>{{ t('documents.move') }}</span></Button></template></FormDialog>
   <FormDialog v-if="deletingFolder" :visible="true" icon="trash" size="compact" :title="t('documents.deleteFolder')" :close-label="t('documents.cancel')" :busy="busy" :error="error ? t(error) : ''" @update:visible="!$event && closeEditor()"><p>{{ t('documents.deleteFolderHelp', { name: deletingFolder.name }) }}</p><template #footer><Button type="default" :aria-label="t('documents.cancel')" :disabled="busy" @click="closeEditor"><template #icon><AppIcon name="close" /></template><span>{{ t('actions.cancel') }}</span></Button><Button type="error" :aria-label="t('documents.deleteFolder')" :loading="saving" @click="deleteFolder"><template #icon><AppIcon name="trash" /></template><span>{{ t('actions.delete') }}</span></Button></template></FormDialog></template></Card>
 <FilePreview v-if="preview" :file="preview" @close="previewRoute.close()" @access-lost="showError" /></template>
<style scoped>
.heading, .pagination { display: flex; align-items: center; justify-content: space-between; gap: var(--space-3); flex-wrap: wrap; }
.file-explorer { display: grid; grid-template-columns: minmax(10rem, 14rem) minmax(0, 1fr); gap: var(--ui-grid-gap); }
.folder-sidebar { border-right: 1px solid var(--theme-border); padding-right: var(--space-3); min-width: 0; }
.folder-sidebar :deep(.n-tree) { padding: 0; background: transparent; font-size: .8rem; }
.folder-sidebar :deep(.n-tree-node-content__text) { display: flex; align-items: center; gap: var(--space-1-5); min-width: 0; }
.folder-sidebar :deep(.n-tree-node-content__text span) { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.folder-sidebar :deep(.n-tree-node-content) { padding: var(--space-1-5) var(--space-1); }
.folder-sidebar :deep(.n-tree-switcher) { width: 1.3rem; height: 1.3rem; flex-shrink: 0; }
.explorer-content { min-width: 0; }
.folder-breadcrumb { display: flex; align-items: center; flex-wrap: wrap; gap: var(--space-1-5); margin-bottom: var(--space-3); font-size: .8rem; min-height: 2rem; }
.folder-breadcrumb > svg { width: .8rem; color: var(--theme-muted); }
.folder-breadcrumb button { display: inline-flex; align-items: center; gap: var(--space-1-5); background: transparent; border: 0; color: var(--theme-muted); font: inherit; cursor: pointer; padding: var(--space-1); border-radius: .3rem; overflow-wrap: anywhere; }
.folder-breadcrumb button:hover, .folder-breadcrumb [aria-current] { color: var(--theme-text); background: var(--theme-subtle); }
.folder-tools { display: flex; gap: var(--space-1); margin-left: auto; }
.filters { margin: 0 0 var(--ui-content-gap); }
.folder-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(12rem, 1fr)); gap: var(--space-2); margin-bottom: var(--ui-content-gap); }
.folder-tile { display: flex; align-items: center; gap: var(--space-1); padding: var(--space-1-5) var(--space-1-5); border: 1px solid var(--theme-border); background: var(--theme-subtle); border-radius: .65rem; min-width: 0; }
.folder-link { display: flex; align-items: center; gap: var(--space-2); min-width: 0; flex: 1; background: transparent; color: var(--theme-text); border: 0; padding: var(--space-2) var(--space-1); font: inherit; font-size: .82rem; text-align: left; cursor: pointer; }
.folder-link span { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.folder-link > svg:last-child { width: .85rem; color: var(--theme-muted); }
.folder-tile:hover { border-color: var(--theme-accent); }
.file-name { display: flex; align-items: center; gap: var(--space-2); background: transparent; border: 0; color: var(--theme-text); font: inherit; text-align: left; cursor: pointer; padding: var(--space-1) 0; width: 100%; min-width: 0; max-width: 100%; }
.file-thumbnail { width: 4rem; height: 4rem; flex-shrink: 0; object-fit: cover; border-radius: .5rem; border: 1px solid var(--theme-border); background: var(--theme-subtle); }
.file-name > svg { width: 1.6rem; height: 1.6rem; flex-shrink: 0; color: var(--theme-muted); }
.file-name span { display: grid; gap: var(--space-1); min-width: 0; flex: 1; }
.file-name strong { display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 2; overflow: hidden; font-size: .83rem; font-weight: 600; overflow-wrap: anywhere; }
.file-name small { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: .72rem; color: var(--theme-muted); }
.file-name:hover strong { color: var(--theme-accent); }
.file-context { display: block; font-size: .7rem; color: var(--theme-muted); margin-top: var(--space-1); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.file-meta { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: .76rem; }
:deep(.document-list) { min-width: 0; table-layout: fixed; }
.file-actions { display: flex; justify-content: flex-end; gap: var(--space-1); }
:deep(.document-list .collection-column-size) { width: 6.5rem; }
:deep(.document-list .collection-column-date) { width: 7.5rem; }
:deep(.document-list .collection-column-actions) { width: 9rem; }
:deep(.document-list th) { font-size: .73rem; }
:deep(.document-list td) { padding: var(--space-3) var(--space-2); }
.file-empty { display: flex; align-items: center; gap: var(--space-2); padding: var(--space-5) var(--space-2); color: var(--theme-muted); font-size: .82rem; }
.pagination { margin-top: var(--ui-content-gap); font-size: .8rem; }
input[type=file] { max-width: 100%; }
button:focus-visible { outline: 2px solid var(--theme-focus); outline-offset: 2px; }
@media(max-width: 600px) { .file-thumbnail { width: 3.5rem; height: 3.5rem; }:deep(.document-list .collection-column-size), :deep(.document-list .collection-column-date) { display: none; }.file-actions { gap: var(--space-1); }.file-name strong { font-size: .8rem; } }
@media(max-width: 1100px) { .file-explorer { grid-template-columns: 1fr; }.folder-sidebar { border-right: 0; border-bottom: 1px solid var(--theme-border); padding: 0 0 var(--space-2); }.folder-sidebar :deep(.n-tree) { max-height: 13rem; overflow-y: auto; } }
</style>
