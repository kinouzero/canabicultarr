<script setup lang="ts">
import AppSwitch from './AppSwitch.vue';
import CommentVariableHelp from './CommentVariableHelp.vue';
import { commentExample } from '../lib/commentTemplateReference';
import DiscardChangesDialog from './DiscardChangesDialog.vue';
import { useUnsavedChanges } from '../lib/unsaved-changes';
import { useFieldErrors } from '../lib/field-errors';
import { useCollectionPageSize } from '../lib/collection-page-size';
import CollectionPagination from './CollectionPagination.vue';
import ResetFilters from './ResetFilters.vue';
import { useCollectionSort, sortOptions } from '../lib/collection-sorting';
import SortSelect from './SortSelect.vue';
import PageTitle from './PageTitle.vue';
import { queryField, useCollectionQuery } from '../lib/collection-query';
import AppIcon from './AppIcon.vue';
import ListActionButton from './ListActionButton.vue';
import { useId, computed, onMounted, onUnmounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { NButton as Button } from 'naive-ui';
import Message from './AppMessage.vue';
import FormDialog from './FormDialog.vue';
import { ApiError } from '../api/auth';
import { commentTemplatesApi, type CommentTemplate } from '../api/commentTemplates';
import { commentVariables, expandCommentTemplate } from '../lib/commentTemplateVariables';
import en from '../locales/en/comment-templates.json';
import fr from '../locales/fr/comment-templates.json';
const pageSize = useCollectionPageSize('templates');
const sort = useCollectionSort('comment-templates', sortOptions.templates);
defineProps<{ hideActions?: boolean }>();
const emit = defineEmits<{ accessLost: [cause: ApiError] }>();
const { t, te, locale, mergeLocaleMessage } = useI18n({ useScope: 'global' });
mergeLocaleMessage('en', { commentTemplates: en }); mergeLocaleMessage('fr', { commentTemplates: fr });
const rows = ref<CommentTemplate[]>([]); const canManage = ref(false); const loading = ref(false); const busy = ref(false);
const open = ref(false); const editing = ref<CommentTemplate | null>(null); const search = ref(''); const status = ref('active');
const page = ref(1); const lastPage = ref(1); const total = ref(0); const error = ref(''); const notice = ref('');
const form = reactive({ name: '', body: '', archived: false }); let sequence = 0; let alive = true;
const dialogId = useId(); const baseline = ref(''); const conflict = ref(false);
const snapshot = () => JSON.stringify(form);
const blocked = computed(() => busy.value || open.value && loading.value);
const dirty = computed(() => blocked.value || open.value && snapshot() !== baseline.value);
const unsaved = useUnsavedChanges(dirty, blocked, { onNavigate: () => { open.value = false; } });
const validation = useFieldErrors(() => ({
 name: { id: 'comment-template-name', message: 'commentTemplates.validation.name' },
 body: { id: 'comment-template-body', message: 'commentTemplates.validation.body' },
 is_archived: { id: 'comment-template-archived', message: 'commentTemplates.validation.archived' },
}), () => ({ name: form.name, body: form.body, is_archived: form.archived }));
const fields = validation.fields;
async function confirmLeave() {
 if (!await unsaved.confirmDiscard()) return false;
 open.value = false; error.value = ''; validation.clear(); return true;
}
async function reload() {
 if (blocked.value || !await unsaved.confirmDiscard()) return;
 if (await load(page.value)) { open.value = false; conflict.value = false; validation.clear(); }
}

const variables = computed(() => { const example = commentExample(t); return commentVariables(example.plant, locale.value, new Date(), example.action); });
function insertVariable(token: string) { if (blocked.value) return; if (form.body.length + token.length > 2000) { error.value = 'commentTemplates.tooLong'; return; } form.body += token; }
const expanded = computed(() => expandCommentTemplate(form.body, variables.value));
function failure(cause: unknown) {
 if (!alive) return;
 const code = cause instanceof ApiError ? cause.code : '';
 error.value = cause instanceof ApiError && cause.status === 409 ? 'commentTemplates.errors.conflict' : /^(commentTemplates|auth)\.errors\.[A-Za-z]+$/.test(code) && te(code) ? code : 'auth.errors.requestFailed';
 if (cause instanceof ApiError && cause.status === 409) conflict.value = true;
 if (cause instanceof ApiError && cause.status === 422 && open.value) {
  error.value = 'auth.errors.validationFailed'; validation.capture(cause);
  if (cause.fields.name?.includes('commentTemplates.errors.nameTaken')) { validation.set('name', 'commentTemplates.errors.nameTaken'); void validation.focus(); }
 }
 if (cause instanceof ApiError && [401, 403].includes(cause.status)) { rows.value = []; open.value = false; canManage.value = false; emit('accessLost', cause); }
}
async function load(target = 1) {
 const current = ++sequence; loading.value = true; error.value = '';
 try { const result = await commentTemplatesApi.list(search.value, target, status.value, sort.value, pageSize.value); if (current !== sequence) return; rows.value = result.data; canManage.value = result.canManage; page.value = result.meta.current_page; lastPage.value = result.meta.last_page; total.value = result.meta.total; return true; }
 catch (cause) { if (current === sequence) failure(cause); }
 finally { if (current === sequence) loading.value = false; }
}
async function edit(row: CommentTemplate | null = null) {
 if (loading.value || !canManage.value || !await unsaved.confirmDiscard()) return;
 editing.value = row; Object.assign(form, { name: row?.name ?? '', body: row?.body ?? '', archived: row?.isArchived ?? false });
 error.value = notice.value = ''; conflict.value = false; validation.clear(); baseline.value = snapshot(); open.value = true;
}
async function save() {
 if (blocked.value || conflict.value || !open.value || !canManage.value) return;
 validation.clear(); error.value = '';
 if (!form.name.trim() || form.name.length > 120) validation.set('name');
 if (!form.body.trim() || form.body.length > 2000) validation.set('body');
 if (Object.keys(fields.value).length) { error.value = 'auth.errors.validationFailed'; void validation.focus(); return; }
 busy.value = true;
 try {
  const input = { name: form.name, body: form.body, is_archived: form.archived, ...(editing.value ? { version: editing.value.version } : {}) };
  if (editing.value) await commentTemplatesApi.update(editing.value.id, input); else await commentTemplatesApi.create(input);
  if (!alive) return;
  open.value = false; notice.value = 'commentTemplates.saved'; await load(page.value);
 } catch (cause) { failure(cause); } finally { if (alive) busy.value = false; }
}
function errorText() { return te(error.value) ? t(error.value) : error.value; }
onMounted(() => load(page.value)); onUnmounted(() => { alive = false; sequence++; });
defineExpose({ reload, create: () => edit(), loading, saving: blocked, canManage, confirmLeave });
const collectionQuery = useCollectionQuery({ paths: ['/settings/catalogs'], prefix: 'templates.', fields: { q: queryField.text(search), status: queryField.choice(status, ['active', 'archived', 'all']), sort: queryField.choice(sort, sortOptions.templates) }, page, pageSize, load: target => load(target) });
const applyFilters = collectionQuery.apply;


</script>
<template><section class="comment-templates"><DiscardChangesDialog v-if="unsaved.pending.value" @decision="unsaved.finish" />
  <header v-if="!hideActions" class="page-heading"><PageTitle icon="message">{{ t('commentTemplates.title') }}</PageTitle><div v-if="!hideActions" class="page-actions"><Button v-if="canManage" attr-type="button" :aria-label="t('commentTemplates.add')" :disabled="blocked" @click="edit()" data-action-icon="plus" :title="t('commentTemplates.add')" class="list-action-button" type="primary"><template #icon><AppIcon name="plus" /></template></Button></div></header><p>{{ t('commentTemplates.description') }}</p>
  <Message v-if="error && !open" type="error" closable role="alert">{{ errorText() }}</Message><Message v-if="notice" type="success" closable role="status">{{ t(notice) }}</Message>

   <form class="template-toolbar search-filters" @submit.prevent="applyFilters"><div class="filter-primary"><label class="filter-field">{{ t('commentTemplates.search') }}<input v-model="search" maxlength="120" :disabled="blocked" /></label>
     <SortSelect v-model="sort" :options="sortOptions.templates" /></div>
    <div class="filter-secondary"><label class="filter-field">{{ t('commentTemplates.status') }}<select v-app-select="{ filter: $t('selectControl.filter'), empty: $t('selectControl.empty') }" v-model="status" :aria-label="t('commentTemplates.status')" :disabled="blocked"><option v-for="value in ['active', 'archived', 'all']" :key="value" :value="value">{{ t(`commentTemplates.${value}`) }}</option></select></label></div>
    <ResetFilters @reset="search = ''; status = 'active'; sort = 'name_asc'" /></form>
  <FormDialog icon="message" v-if="open" :visible="true" :title="t(editing ? 'commentTemplates.edit' : 'commentTemplates.create')" :close-label="t('commentTemplates.cancel')" :busy="blocked" :error="error ? errorText() : ''" @update:visible="!$event && confirmLeave()"><form :id="`${dialogId}-0`" class="dialog-form" novalidate @submit.prevent="save"><label>{{ t('commentTemplates.name') }}<input id="comment-template-name" v-bind="validation.attrs('name')" v-model="form.name" maxlength="120" required :disabled="blocked" /></label><small v-if="fields.name" id="comment-template-name-error" class="field-error" role="alert">{{ t(fields.name) }}</small><label>{{ t('commentTemplates.body') }}<textarea id="comment-template-body" v-bind="validation.attrs('body')" v-model="form.body" rows="6" maxlength="2000" required :disabled="blocked" /></label><small v-if="fields.body" id="comment-template-body-error" class="field-error" role="alert">{{ t(fields.body) }}</small><AppSwitch id="comment-template-archived" v-bind="validation.attrs('is_archived')" v-model="form.archived" :disabled="blocked">{{ t('commentTemplates.archive') }}</AppSwitch><small v-if="fields.is_archived" id="comment-template-archived-error" class="field-error" role="alert">{{ t(fields.is_archived) }}</small>
    <CommentVariableHelp :disabled="blocked" @insert="insertVariable" />
    <h4>{{ t('commentTemplates.preview') }}</h4><pre>{{ expanded.text }}</pre><p v-if="expanded.missing.length">{{ t('commentTemplates.missing', { names: expanded.missing.join(', ') }) }}</p></form>
  <template #footer><div class="dialog-actions"><Button v-if="conflict" attr-type="button" type="default" :aria-label="t('commentTemplates.reload')" :disabled="blocked" @click="reload"><template #icon><AppIcon name="refresh" /></template><span>{{ t('actions.refresh') }}</span></Button><Button attr-type="button" :aria-label="t('commentTemplates.cancel')" type="default" :disabled="blocked" @click="confirmLeave"><template #icon><AppIcon name="close" /></template><span>{{ t('actions.cancel') }}</span></Button><Button :form="`${dialogId}-0`" attr-type="submit" :aria-label="editing ? t('commentTemplates.save') : t('actions.create')" :loading="busy" :disabled="blocked || conflict" type="primary"><template #icon><AppIcon name="save" /></template><span>{{ t(editing ? 'actions.save' : 'actions.create') }}</span></Button></div></template></FormDialog>
  <p v-if="loading" role="status">{{ t('commentTemplates.loading') }}</p><p v-else-if="!rows.length">{{ t('commentTemplates.empty') }}</p>
  <ul class="template-list"><li v-for="row in rows" :key="row.id"><h3>{{ row.name }}</h3><pre>{{ row.body }}</pre><div class="template-actions"><ListActionButton icon="edit" v-if="canManage" :label="t('commentTemplates.editNamed', { name: row.name })" severity="secondary" @click="edit(row)" /></div></li></ul>
  <CollectionPagination v-model:page-size="pageSize" :page="page" :last-page="lastPage" :total="total" :disabled="loading" :previous-label="t('commentTemplates.previous')" :next-label="t('commentTemplates.next')" @page="collectionQuery.go($event)" /></section></template>
<style scoped>
.template-toolbar, .template-actions { display: flex; flex-wrap: wrap; gap: var(--space-3); align-items: center; margin: var(--ui-content-gap) 0; }label { display: block; margin: var(--space-3) 0; }input:not([type=checkbox]), textarea { display: block; width: 100%; min-width: 0; padding: var(--space-3); font: inherit; }select { padding: var(--space-3); font: inherit; }pre, code { white-space: pre-wrap; overflow-wrap: anywhere; font: inherit; font-size: .85rem; }ul { list-style: none; padding: 0; }li { border: 1px solid var(--theme-border, #dce5da); padding: var(--space-4); margin: var(--space-3) 0; border-radius: .7rem; min-width: 0; }small { display: block; overflow-wrap: anywhere; color: var(--theme-muted, #63745e); }h2, h3 { margin-top: 0; }
</style>
