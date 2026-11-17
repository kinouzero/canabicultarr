<script setup lang="ts">
import { journalTabs as journalPaths } from '../../tab-paths';
import AppTabs from '../../components/AppTabs.vue';
import RecordQrButton from '../../components/RecordQrButton.vue';
import { useSubjectQuery, resolveEventSubject } from '../../lib/subject-query';
import { useResolvedRecordQuery, usePathChoice } from '../../lib/route-query';
import { toRef } from 'vue';
import EventTypeSelect from '../../components/EventTypeSelect.vue';
import { systemEventTypes, type EventContextKind } from '../../api/event-types';
import DisclosureSummary from '../../components/DisclosureSummary.vue';
import { NTab } from 'naive-ui';


import NotificationDeliveryHistory from '../../components/NotificationDeliveryHistory.vue';
import { useGuardedTabs } from '../../lib/guarded-tabs';
import DiscardChangesDialog from '../../components/DiscardChangesDialog.vue';
import { useUnsavedChanges } from '../../lib/unsaved-changes';
import { useFieldErrors } from '../../lib/field-errors';
import { useCollectionPageSize } from '../../lib/collection-page-size';
import CollectionPagination from '../../components/CollectionPagination.vue';
import { useRememberDisclosure } from '../../lib/disclosure-preferences';
import ResetFilters from '../../components/ResetFilters.vue';
import { useCollectionSort, sortOptions } from '../../lib/collection-sorting';
import SortSelect from '../../components/SortSelect.vue';
import PageTitle from '../../components/PageTitle.vue';
import { queryField, useCollectionQuery } from '../../lib/collection-query';
import AppIcon from '../../components/AppIcon.vue';
import { locationLabel } from '../../lib/location';
import DetailDialog from '../../components/DetailDialog.vue';
import ListActionButton from '../../components/ListActionButton.vue';
import { formatDateTime } from '../../lib/date-time';
import FormDialog from '../../components/FormDialog.vue';
import { computed, watch, useId, onMounted, onUnmounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { NButton as Button } from 'naive-ui';
import { NCard as Card } from 'naive-ui';
import { NInput as InputText } from 'naive-ui';
import { NInput as Textarea } from 'naive-ui';
import Message from '../../components/AppMessage.vue';
import SubjectSelect from '../../components/SubjectSelect.vue';
import eventEn from '../../locales/en/events.json';
import notificationEn from '../../locales/en/notifications.json';
import notificationFr from '../../locales/fr/notifications.json';
import eventFr from '../../locales/fr/events.json';
import { ApiError } from '../../api/auth';
import { eventsApi, type EventCategory, type EventSeverity, type EventEntry, type EventRevision, type EventInput, type EventSubject, type EventSnapshot } from '../../api/events';
const pageSize = useCollectionPageSize('events');
const vRememberDisclosure = useRememberDisclosure('EventJournal');
const sort = useCollectionSort('journal', sortOptions.events);

defineProps<{ embedded?: boolean }>();
const emit = defineEmits<{ close: []; accessLost: [cause: ApiError] }>();
const { t, te, locale, mergeLocaleMessage } = useI18n({ useScope: 'global' });
mergeLocaleMessage('en', { events: eventEn, notifications: notificationEn }); mergeLocaleMessage('fr', { events: eventFr, notifications: notificationFr });
const categories: EventCategory[] = ['observation', 'maintenance', 'incident', 'note'];
const severities: EventSeverity[] = ['info', 'warning', 'critical'];
const entries = ref<EventEntry[]>([]); const page = ref(1); const lastPage = ref(1); const total = ref(0);
const loading = ref(false); const saving = ref(false); const error = ref(''); const success = ref('');

const filters = reactive({ search: '', category: '', severity: '', from: '', to: '', group: 'none' });
const fromQuery = queryField.localDateTime(toRef(filters, 'from')); const toQuery = queryField.localDateTime(toRef(filters, 'to'));
const filterSubject = ref<EventSubject | null>(null);
const subjectQuery = useSubjectQuery(filterSubject, resolveEventSubject);
const formOpen = ref(false); const editing = ref<EventEntry | null>(null); const subject = ref<EventSubject | null>(null);
const form = reactive({ eventTypeId: '', title: '', body: '', category: 'observation' as EventCategory, severity: 'info' as EventSeverity, time: '', group: 'none' });
const typeContexts = ref<EventContextKind[] | null>(null);
const typeKey = computed({ get: () => form.category === 'custom' ? form.eventTypeId : form.category, set: (value: string) => { form.category = categories.includes(value as EventCategory) ? value as EventCategory : 'custom'; form.eventTypeId = form.category === 'custom' ? value : ''; } });
function typeName(entry: Pick<EventSnapshot, 'category' | 'eventType'>) { return entry.eventType?.name ?? t(`events.categories.${entry.category}`); }
function typeIcon(entry: Pick<EventSnapshot, 'category' | 'eventType'>) { return entry.eventType?.icon ?? systemEventTypes[entry.category as keyof typeof systemEventTypes] ?? 'journal'; }
const eventRoute = useResolvedRecordQuery('event', eventsApi.show, showError); const detail = eventRoute.record; const revisions = ref<EventRevision[]>([]);
const detailLoading = ref(false); const historyPage = ref(1); const historyLast = ref(1);
let listSequence = 0; let detailSequence = 0; let alive = true;
const baseline = ref(''); const reloading = ref(false); const conflict = ref(false);
const busy = computed(() => saving.value || reloading.value);
function snapshot() { return JSON.stringify([form, subject.value?.type, subject.value?.id]); }
const dirty = computed(() => busy.value || formOpen.value && snapshot() !== baseline.value);
const unsaved = useUnsavedChanges(dirty, busy, { onNavigate: () => { formOpen.value = false; } });
const activeTab = ref('events');
usePathChoice(activeTab, journalPaths);
const historyVisited = ref(activeTab.value === 'notifications');
watch(activeTab, tab => { if (tab === 'notifications') historyVisited.value = true; });
const deliveryHistory = ref<InstanceType<typeof NotificationDeliveryHistory> | null>(null);
const journalTabs = useGuardedTabs(activeTab, async () => {
 if (!await unsaved.confirmDiscard()) return false;
 formOpen.value = false; clearDetails(); return true;
});
const validation = useFieldErrors(() => Object.fromEntries([
 ['title', 'event-title', 'titleInvalid'], ['category', 'event-category', 'categoryInvalid'], ['event_type_id', 'event-category', 'categoryInvalid'], ['severity', 'event-severity', 'severityInvalid'],
 ['occurred_at', 'event-time', 'timeInvalid'], ['subject', form.group === 'none' ? 'event-context-kind' : 'events-subject', 'subjectInvalid'],
 ['body', 'event-body', 'bodyInvalid'], ['version', 'event-version', 'versionInvalid'],
].map(([field, id, message]) => [field, { id, message: `events.validation.${message}` }])),
 () => ({ ...form, event_type_id: form.eventTypeId, occurred_at: form.time, subject: JSON.stringify([form.group, subject.value?.type, subject.value?.id]), version: editing.value?.version }));
const fields = validation.fields;
async function closeForm() { if (await unsaved.confirmDiscard()) { formOpen.value = false; conflict.value = false; validation.clear(); error.value = ''; } }
async function leave() { if (await unsaved.confirmDiscard()) emit('close'); }
function key(value: string): string { return /^(auth|events)\.(errors|validation)\.[A-Za-z]+$/.test(value) && te(value) ? value : 'auth.errors.requestFailed'; }
function showError(cause: unknown, editor = true) {
 if (!alive) return;
 error.value = key(cause instanceof ApiError ? cause.code : 'auth.errors.requestFailed');
 if (!(cause instanceof ApiError)) return;
 if ([401, 403].includes(cause.status)) { entries.value = []; detail.value = null; formOpen.value = false; emit('accessLost', cause); return; }
 if (!editor || !formOpen.value) return;
 validation.capture(cause);
 if (cause.code === 'events.errors.conflict' || cause.fields.version) conflict.value = true;
 if (cause.code === 'events.validation.subjectInvalid') { validation.set('subject'); void validation.focus(); }
 if (cause.status === 422) error.value = 'auth.errors.validationFailed';
}
function localTime(iso = new Date().toISOString()): string {
  const date = new Date(iso); return new Date(date.getTime() - date.getTimezoneOffset() * 60_000).toISOString().slice(0, 19);
}
function isoTime(value: string): string {
  const date = new Date(value);
  if (!value || Number.isNaN(date.getTime())) throw new ApiError(422, 'events.validation.timeInvalid');
  return date.toISOString();
}
function date(value: string): string { return formatDateTime(value, locale.value); }
function context(value: EventSubject | null): string {
  if (!value) return t('events.noContext');
  const location = locationLabel(value.location);
  return ['site', 'room', 'zone'].includes(value.type) ? location : [value.name, value.deviceName, location].filter(Boolean).join(' · ');
}
function selectedGroup(value: EventSubject | null): string { return value ? ['site', 'room', 'zone'].includes(value.type) ? 'location' : value.type : 'none'; }
function listFilters(): Record<string, string> {
  const result: Record<string, string> = { per_page: String(pageSize.value), sort: sort.value, search: filters.search.trim(), category: filters.category && !categories.includes(filters.category as EventCategory) ? 'custom' : filters.category, severity: filters.severity };
  if (filters.category && !categories.includes(filters.category as EventCategory)) result.event_type_id = filters.category;
  if (filters.from) result.date_from = fromQuery.read();
  if (filters.to) result.date_to = toQuery.read();
  if (filterSubject.value) result[`${filterSubject.value.type}_id`] = filterSubject.value.id;
  return result;
}
async function load(target = 1) {
  const sequence = ++listSequence; loading.value = true; if (!formOpen.value) error.value = '';
  try {
   if (!await subjectQuery.resolve()) return;
   if (filterSubject.value && filters.group !== (['site', 'room', 'zone'].includes(filterSubject.value.type) ? 'location' : filterSubject.value.type)) throw new ApiError(422, 'auth.errors.validationFailed');
    const query = listFilters(); let result = await eventsApi.list(query, target);
    if (!alive || sequence !== listSequence) return;
    if (target > result.meta.last_page) result = await eventsApi.list(query, result.meta.last_page);
    if (!alive || sequence !== listSequence) return;
    entries.value = result.data; page.value = result.meta.current_page; lastPage.value = result.meta.last_page; total.value = result.meta.total;
  } catch (cause) { if (sequence === listSequence) { if (cause instanceof ApiError && [404, 422].includes(cause.status)) entries.value = []; showError(cause, false); } }
  finally { if (sequence === listSequence) loading.value = false; }
}
async function closeDetails() { if (!await unsaved.confirmDiscard()) return; formOpen.value = false; void eventRoute.close(); }
function clearDetails() { detailSequence++; detail.value = null; revisions.value = []; detailLoading.value = false; }
async function open(entry: EventEntry | null = null) { if (await unsaved.confirmDiscard()) replaceEditor(entry); }
function replaceEditor(entry: EventEntry | null = null) {
  editing.value = entry; subject.value = entry?.subject ?? null;
  typeContexts.value = null;
  Object.assign(form, { eventTypeId: entry?.eventType?.id ?? '', title: entry?.title ?? '', body: entry?.body ?? '', category: entry?.category ?? 'observation', severity: entry?.severity ?? 'info', time: localTime(entry?.occurredAt), group: selectedGroup(subject.value) });
  validation.clear(); conflict.value = false; error.value = success.value = ''; baseline.value = snapshot(); formOpen.value = true; clearDetails();
}
async function save() {
  if (busy.value || conflict.value) return;
  validation.clear(); error.value = success.value = '';
  if (!form.title.trim() || form.title.length > 160) validation.set('title');
  if (!form.body.trim() || form.body.length > 10000) validation.set('body');
  if (!categories.includes(form.category) && !(form.category === 'custom' && /^[a-f0-9-]{36}$/i.test(form.eventTypeId))) validation.set('category');
  const changedType = form.category !== editing.value?.category || form.eventTypeId !== (editing.value?.eventType?.id ?? '');
  const changedSubject = subject.value?.id !== editing.value?.subject?.id || subject.value?.type !== editing.value?.subject?.type;
  if (form.category === 'custom' && typeContexts.value && (!editing.value || changedType || changedSubject) && !typeContexts.value.includes(subject.value?.type ?? 'none')) validation.set('subject', 'eventTypes.validation.scope');
  if (!severities.includes(form.severity)) validation.set('severity');
  if (!form.time || Number.isNaN(new Date(form.time).getTime())) validation.set('occurred_at');
  if (form.group !== 'none' && !subject.value) validation.set('subject');
  if (Object.keys(fields.value).length) { error.value = 'auth.errors.validationFailed'; void validation.focus(); return; }
  try {
    const occurredAt = editing.value && localTime(editing.value.occurredAt) === form.time ? editing.value.occurredAt : isoTime(form.time);
    const input: EventInput = { title: form.title, body: form.body, category: form.category, event_type_id: form.category === 'custom' ? form.eventTypeId : null, severity: form.severity, occurred_at: occurredAt, subject: subject.value ? { type: subject.value.type, id: subject.value.id } : null };
    saving.value = true;
    if (editing.value) { input.version = editing.value.version; await eventsApi.update(editing.value.id, input); }
    else await eventsApi.create(input);
    if (!alive) return; formOpen.value = false; success.value = editing.value ? 'events.updated' : 'events.created'; await load(editing.value ? page.value : 1);
  } catch (cause) { showError(cause); } finally { saving.value = false; }
}
async function reloadEdit() {
 if (!editing.value || !await unsaved.confirmDiscard()) return;
 reloading.value = true;
 try { const current = await eventsApi.show(editing.value.id); if (alive) replaceEditor(current); }
 catch (cause) { showError(cause); } finally { reloading.value = false; }
}
async function showDetails(entry: EventEntry, target = 1) {
 if (detail.value?.id !== entry.id) { if (await unsaved.confirmDiscard()) void eventRoute.open(entry.id); return; }
 return loadRevisions(entry, target, true);
}
async function loadRevisions(entry: EventEntry, target = 1, refresh = false) {
  if (!await unsaved.confirmDiscard()) return;
  const sequence = ++detailSequence; detail.value = entry; detailLoading.value = true; formOpen.value = false; error.value = '';
  try {
    const [current, history] = await Promise.all([refresh ? eventsApi.show(entry.id) : Promise.resolve(entry), eventsApi.revisions(entry.id, target)]);
    if (!alive || sequence !== detailSequence) return;
    detail.value = current; revisions.value = history.data; historyPage.value = history.meta.current_page; historyLast.value = history.meta.last_page;
  } catch (cause) { if (sequence === detailSequence) showError(cause); } finally { if (sequence === detailSequence) detailLoading.value = false; }
}
onMounted(() => load(page.value)); onUnmounted(() => { alive = false; listSequence++; detailSequence++; });
const dialogId = useId();
watch(() => detail.value?.id, id => {
 detailSequence++; revisions.value = []; detailLoading.value = false;
 if (!id) { formOpen.value = false; }
 if (id && detail.value) { formOpen.value = false; void loadRevisions(detail.value, historyPage.value); }
});
const collectionQuery = useCollectionQuery({ paths: Object.values(journalPaths), paused: () => formOpen.value, fields: { ...subjectQuery.fields, q: queryField.text(toRef(filters, 'search'), 160), category: queryField.text(toRef(filters, 'category'), 64), severity: queryField.choice(toRef(filters, 'severity'), ['', ...severities]), from: fromQuery, to: toQuery, group: queryField.text(toRef(filters, 'group'), 32), sort: queryField.choice(sort, sortOptions.events) }, page, pageSize, load: target => load(target) });
const applyFilters = collectionQuery.apply;
const revisionQuery = useCollectionQuery({ paths: Object.values(journalPaths), prefix: 'eventHistory.', fields: {}, page: historyPage, load: target => detail.value && loadRevisions(detail.value, target) });


</script>

<template><DiscardChangesDialog v-if="unsaved.pending.value" @decision="unsaved.finish" />
  <Card class="events-card"><template #header><div class="card-heading-block"><div class="page-heading"><div class="events-heading"><PageTitle icon="journal">{{ t('events.title') }}</PageTitle><Button v-if="!embedded" :aria-label="t('events.back')" type="default" :disabled="busy" @click="leave" :title="t('events.back')" class="list-action-button"><template #icon><AppIcon name="arrowLeft" /></template></Button></div><div class="page-actions"><Button v-if="activeTab === 'events'" attr-type="button" :aria-label="t('events.add')" :disabled="busy" @click="open()" data-action-icon="plus" :title="t('events.add')" class="list-action-button" type="primary"><template #icon><AppIcon name="plus" /></template></Button><ListActionButton icon="refresh" :label="t(activeTab === 'events' ? 'collection.refresh' : 'notifications.refreshHistory')" :loading="activeTab === 'events' ? loading : deliveryHistory?.loading" :disabled="busy || (activeTab === 'notifications' && !deliveryHistory)" @click="activeTab === 'events' ? load(page) : deliveryHistory?.reload()" /></div></div><div class="card-subtitle">{{ t(activeTab === 'events' ? 'events.description' : 'notifications.historyTitle') }}</div></div></template>

    <template #default><AppTabs :value="journalTabs.value.value" @update:value="journalTabs.change" class="journal-tabs" animated><NTab name="events" :id="`${dialogId}-events-tab`"><AppIcon name="journal" />{{ t('events.entries') }}</NTab><NTab name="notifications" :id="`${dialogId}-notifications-tab`"><AppIcon name="notification" />{{ t('notifications.historyTitle') }}</NTab></AppTabs>
      <div v-if="historyVisited" v-show="activeTab === 'notifications'" :id="`${dialogId}-notifications-panel`" role="tabpanel" :aria-labelledby="`${dialogId}-notifications-tab`"><NotificationDeliveryHistory ref="deliveryHistory" @access-lost="emit('accessLost', $event)" /></div>
      <div v-show="activeTab === 'events'" :id="`${dialogId}-events-panel`" role="tabpanel" :aria-labelledby="`${dialogId}-events-tab`"><Message v-if="error && !(formOpen)" type="error" closable role="alert">{{ t(error) }}</Message>
      <Message v-if="success" type="success" closable role="status">{{ t(success) }}</Message>

   <form class="events-filters search-filters" @submit.prevent="applyFilters"><div class="filter-primary"><div class="filter-field"><label for="events-search">{{ t('events.search') }}</label><InputText placeholder="" v-model:value="filters.search" maxlength="160" :disabled="busy" :input-props="{ 'id': 'events-search' }"></InputText></div>
     <SortSelect v-model="sort" :options="sortOptions.events" :disabled="busy" /></div>
    <div class="filter-secondary"><div class="filter-field"><label for="events-category-filter">{{ t('events.category') }}</label><EventTypeSelect hide-label id="events-category-filter" v-model="filters.category" :label="t('events.category')" :empty-label="t('events.allCategories')" include-archived :disabled="busy" @access-lost="showError" /></div>
     <div class="filter-field"><label for="events-severity-filter">{{ t('events.severity') }}</label><select v-app-select="{ filter: $t('selectControl.filter'), empty: $t('selectControl.empty') }" id="events-severity-filter" v-model="filters.severity" :disabled="busy"><option value="">{{ t('events.allSeverities') }}</option><option v-for="value in severities" :key="value" :value="value">{{ t(`events.severities.${value}`) }}</option></select></div>
     <div class="filter-field"><label for="events-from">{{ t('events.dateFrom') }}</label><InputText placeholder="" v-model:value="filters.from" step="1" :disabled="busy" :input-props="{ 'id': 'events-from', type: 'datetime-local' }"></InputText></div>
     <div class="filter-field"><label for="events-to">{{ t('events.dateTo') }}</label><InputText placeholder="" v-model:value="filters.to" step="1" :disabled="busy" :status="!!fields.date_to ? 'error' : undefined" :input-props="{ 'id': 'events-to', type: 'datetime-local' }"></InputText></div>
     <div class="filter-field"><label for="events-context-filter">{{ t('events.filterContext') }}</label><select v-app-select="{ filter: $t('selectControl.filter'), empty: $t('selectControl.empty') }" id="events-context-filter" v-model="filters.group" :disabled="busy" @change="subjectQuery.clear()"><option value="none">{{ t('events.allContexts') }}</option><option value="location">{{ t('events.contextKinds.location') }}</option><option value="asset">{{ t('events.contextKinds.asset') }}</option><option value="sensor">{{ t('events.contextKinds.sensor') }}</option></select></div>
     <SubjectSelect v-if="filters.group !== 'none'" :model-value="filterSubject" @update:model-value="filterSubject = $event as EventSubject | null" id="events-subject-filter" :label="t('events.chooseFilter')" :placeholder="t('events.allContexts')" :kind="filters.group" :disabled="busy" @access-lost="showError" /></div>
    <ResetFilters :disabled="busy" @reset="Object.assign(filters, { search: '', category: '', severity: '', from: '', to: '', group: 'none' }); subjectQuery.clear(); sort = 'occurred_desc'" /></form>
      <p v-if="filterSubject" class="events-selected-context">{{ context(filterSubject) }}</p>
      <FormDialog icon="journal" v-if="formOpen" :visible="true" :title="t(editing ? 'events.editTitle' : 'events.createTitle')" :close-label="t('events.cancel')" :busy="busy" :error="error ? t(error) : ''" @update:visible="!$event && closeForm()"><template #feedback><div v-if="fields.version" id="event-version" tabindex="-1" v-bind="validation.attrs('version')"><small id="event-version-error" role="alert" class="field-error">{{ t(fields.version) }}</small></div><Button v-if="conflict && editing" :aria-label="t('events.reloadCurrent')" type="default" :loading="saving" @click="reloadEdit"><template #icon><AppIcon name="refresh" /></template><span>{{ t('actions.refresh') }}</span></Button></template>
<form :id="`${dialogId}-0`" class="dialog-form event-form" @submit.prevent="save" novalidate><p v-if="editing">{{ t('events.correctionHelp') }}</p>
        <div class="event-form-grid"><div class="field event-full"><label for="event-title">{{ t('events.entryTitle') }}</label><InputText placeholder="" v-bind="validation.attrs('title')" v-model:value="form.title" maxlength="160" :disabled="busy" :status="!!fields.title ? 'error' : undefined" :input-props="{ 'id': 'event-title' }"></InputText><small v-if="fields.title" role="alert" id="event-title-error" class="field-error">{{ t(fields.title) }}</small></div>
          <div class="field"><label for="event-category">{{ t('events.category') }}</label><EventTypeSelect hide-label id="event-category" v-model="typeKey" :label="t('events.category')" :selected="editing?.eventType" :invalid="!!fields.category || !!fields.event_type_id" :described-by="fields.category || fields.event_type_id ? 'event-category-error' : undefined" :disabled="busy" @metadata="typeContexts = $event?.contexts ?? null" @access-lost="showError" /><small v-if="fields.category || fields.event_type_id" role="alert" id="event-category-error" class="field-error">{{ t(fields.category || fields.event_type_id!) }}</small></div>
          <div class="field"><label for="event-severity">{{ t('events.severity') }}</label><select v-app-select="{ filter: $t('selectControl.filter'), empty: $t('selectControl.empty') }" id="event-severity" v-bind="validation.attrs('severity')" v-model="form.severity" :disabled="busy"><option v-for="value in severities" :key="value" :value="value">{{ t(`events.severities.${value}`) }}</option></select><small v-if="fields.severity" role="alert" id="event-severity-error" class="field-error">{{ t(fields.severity) }}</small></div>
          <div class="field"><label for="event-time">{{ t('events.occurredAt') }}</label><InputText placeholder="" v-bind="validation.attrs('occurred_at')" v-model:value="form.time" step="1" :disabled="busy" :status="!!fields.occurred_at ? 'error' : undefined" :input-props="{ 'id': 'event-time', type: 'datetime-local', 'aria-describedby': fields.occurred_at ? 'event-time-help event-time-error' : 'event-time-help' }"></InputText><small id="event-time-help">{{ t('events.timeHelp') }}</small><small v-if="fields.occurred_at" role="alert" id="event-time-error" class="field-error">{{ t(fields.occurred_at) }}</small></div>
          <div class="field"><label for="event-context-kind">{{ t('events.context') }}</label><select v-app-select="{ filter: $t('selectControl.filter'), empty: $t('selectControl.empty') }" id="event-context-kind" v-bind="validation.attrs('subject')" v-model="form.group" :disabled="busy" @change="subject = null"><option value="none">{{ t('events.noContext') }}</option><option value="location">{{ t('events.contextKinds.location') }}</option><option value="asset">{{ t('events.contextKinds.asset') }}</option><option value="sensor">{{ t('events.contextKinds.sensor') }}</option></select></div></div>
        <p v-if="form.category === 'custom' && typeContexts" class="record-note">{{ t('eventTypes.allowed', { contexts: typeContexts.map(value => t(`eventTypes.contextNames.${value}`)).join(' · ') }) }}</p>
        <div v-if="form.group !== 'none'" class="event-context"><SubjectSelect :model-value="subject" @update:model-value="subject = $event as EventSubject | null" id="events-subject" :described-by="fields.subject ? 'events-subject-error' : undefined" :label="t('events.chooseContext')" :placeholder="t('events.noSubjectSelected')" :kind="form.group" :disabled="busy" :invalid="!!fields.subject" @access-lost="showError" /></div><small v-if="fields.subject" :id="form.group === 'none' ? 'event-context-kind-error' : 'events-subject-error'" role="alert" class="field-error">{{ t(fields.subject) }}</small>
        <div class="field"><label for="event-body">{{ t('events.body') }}</label><Textarea placeholder="" v-bind="validation.attrs('body')" v-model:value="form.body" rows="6" maxlength="10000" :disabled="busy" :status="!!fields.body ? 'error' : undefined" type="textarea" :input-props="{ 'id': 'event-body' }"></Textarea><small v-if="fields.body" role="alert" id="event-body-error" class="field-error">{{ t(fields.body) }}</small></div></form>
<template #footer><div class="dialog-actions"><Button :aria-label="t('events.cancel')" type="default" :disabled="busy" @click="closeForm"><template #icon><AppIcon name="close" /></template><span>{{ t('actions.cancel') }}</span></Button><Button :form="`${dialogId}-0`" attr-type="submit" :aria-label="editing ? t('events.save') : t('actions.create')" :loading="saving" :disabled="reloading || conflict" type="primary"><template #icon><AppIcon name="save" /></template><span>{{ t(editing ? 'actions.save' : 'actions.create') }}</span></Button></div></template></FormDialog>
      <DetailDialog v-if="detail" module :title="detail.title" :close-label="t('events.closeDetails')" :busy="busy" :error="error ? t(error) : ''" icon="journal" @close="closeDetails"><section class="modal-panel"><p v-if="detailLoading" role="status">{{ t('events.loading') }}</p>
        <template v-else><nav class="record-actions" :aria-label="t('recordDetails.actions')"><div class="record-action-group record-management-actions"><RecordQrButton :record="{ type: 'event', id: detail.id, name: detail.title }" :disabled="busy" /><Button v-if="detail.canEdit" type="default" :disabled="busy" data-action-icon="edit" @click="open(detail)"><template #icon><AppIcon name="edit" /></template><span>{{ t('events.correct') }}</span></Button></div></nav>
          <p class="event-meta"><time :datetime="detail.occurredAt">{{ date(detail.occurredAt) }}</time> · {{ detail.author.name ?? t('events.authorUnavailable') }}</p>
          <p>{{ context(detail.subject) }}</p><p class="event-body">{{ detail.body }}</p>

          <h4>{{ t('events.history') }}</h4>
          <ol class="event-revisions"><li v-for="revision in revisions" :key="revision.id"><details v-remember-disclosure="`${detail.id}:${revision.id}`"><DisclosureSummary :level="3" icon="journal">{{ t('events.version', { count: revision.version }) }} · {{ date(revision.recordedAt) }} · {{ revision.editor.name ?? t('events.authorUnavailable') }}</DisclosureSummary><h4>{{ revision.snapshot.title }}</h4><p><AppIcon :name="typeIcon(revision.snapshot)" />{{ typeName(revision.snapshot) }} · {{ t(`events.severities.${revision.snapshot.severity}`) }}</p><p><time :datetime="revision.snapshot.occurredAt">{{ date(revision.snapshot.occurredAt) }}</time></p><p>{{ context(revision.snapshot.subject) }}</p><p class="event-body">{{ revision.snapshot.body }}</p></details></li></ol>
          <nav v-if="historyLast > 1" class="events-pagination" :aria-label="t('events.historyPagination')"><Button :disabled="detailLoading || historyPage <= 1" @click="revisionQuery.go(historyPage - 1)" type="primary"><template #icon><AppIcon name="arrowLeft" /></template><span>{{ t('events.previous') }}</span></Button><span>{{ t('events.page', { current: historyPage, total: historyLast }) }}</span><Button :disabled="detailLoading || historyPage >= historyLast" @click="revisionQuery.go(historyPage + 1)" type="primary"><template #icon><AppIcon name="arrow" /></template><span>{{ t('events.next') }}</span></Button></nav></template></section></DetailDialog>

      <p v-if="loading" role="status">{{ t('events.loading') }}</p><p v-else-if="!entries.length">{{ t('events.empty') }}</p>
      <ul v-else class="event-list" :aria-busy="loading"><li v-for="entry in entries" :key="entry.id" class="event-node"><div class="event-labels"><span><AppIcon :name="typeIcon(entry)" />{{ typeName(entry) }}</span><span class="event-severity" :data-severity="entry.severity">{{ t(`events.severities.${entry.severity}`) }}</span></div><h3><button class="event-title" type="button" :aria-label="t('events.viewNamed', { title: entry.title })" :disabled="busy" @click="showDetails(entry)">{{ entry.title }}</button></h3>
        <p class="event-meta"><time :datetime="entry.occurredAt">{{ date(entry.occurredAt) }}</time> · {{ entry.author.name ?? t('events.authorUnavailable') }}</p>
        <p class="event-context-label">{{ context(entry.subject) }}</p><p class="event-body event-preview">{{ entry.body }}</p>
        <div class="event-actions card-management-actions"><ListActionButton icon="edit" v-if="entry.canEdit" :label="t('events.correctNamed', { title: entry.title })"  severity="secondary" :disabled="busy" @click="open(entry)" /></div></li></ul>
      <CollectionPagination v-model:page-size="pageSize" :page="page" :last-page="lastPage" :total="total" :disabled="busy || loading" :label="t('events.pagination')" :previous-label="t('events.previous')" :next-label="t('events.next')" @page="collectionQuery.go($event)" /></div></template></Card></template>
<style scoped>
.event-labels > span { display: inline-flex; align-items: center; gap: var(--ui-icon-gap); }
.event-title { padding: 0; border: 0; background: transparent; color: inherit; font: inherit; text-align: start; cursor: pointer; }
.event-title:hover { text-decoration: underline; text-underline-offset: .2em; }
.event-title:focus-visible { outline: 2px solid var(--theme-focus); outline-offset: 3px; }
.journal-tabs { margin-bottom: var(--ui-content-gap); }
.events-card { padding: var(--space-3); }
.events-heading, .events-pagination { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: var(--ui-grid-gap); }
h2 { margin: 0; font-size: 1.6rem; letter-spacing: -0.035em; }
.events-filters { display: flex; align-items: flex-end; flex-wrap: wrap; gap: var(--space-3); margin: var(--ui-content-gap) 0; }
.events-filters > div { display: grid; gap: var(--space-2); min-width: 0; }
.events-filters label { font-size: 0.875rem; font-weight: 600; }
.events-filters input { min-width: 0; width: 100%; }
select { font: inherit; padding: var(--space-2); border: 1px solid var(--theme-border, var(--theme-border, #c1d3c8)); border-radius: 0.4rem; background: var(--theme-surface, var(--theme-surface, white)); color: var(--theme-text, inherit); min-width: 0; }
.event-form, .event-details { border: 1px solid var(--theme-border, var(--theme-border, #dbe7df)); padding: var(--space-5); border-radius: 0.75rem; margin: var(--ui-content-gap) 0; }
.event-form h3 { margin-top: 0; }
.event-form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0 var(--ui-grid-gap); }
.event-full { grid-column: 1 / -1; }
.event-actions { display: flex; gap: var(--space-2); flex-wrap: wrap; }
.event-context p, .events-selected-context { overflow-wrap: anywhere; }
.event-context small { display: block; margin-top: var(--space-2); }
.event-list { padding: 0; list-style: none; display: grid; gap: var(--ui-grid-gap); }
.event-node { min-width: 0; border: 1px solid var(--theme-border, var(--theme-border, #dbe7df)); border-radius: 0.75rem; padding: var(--space-5); display: grid; gap: var(--space-3); }
.event-node h3, .event-node p { margin: 0; overflow-wrap: anywhere; }
.event-meta { font-size: 0.85rem; opacity: 0.75; }
.event-context-label { font-size: 0.875rem; }
.event-body { white-space: pre-wrap; line-height: 1.65; overflow-wrap: anywhere; }
.event-preview { display: -webkit-box; -webkit-line-clamp: 4; -webkit-box-orient: vertical; overflow: hidden; }
.event-labels { display: flex; justify-content: space-between; align-items: center; gap: var(--space-3); font-size: 0.8rem; }
.event-severity { padding: var(--space-1) var(--space-2); background: var(--theme-subtle, #e3f2e9); color: var(--theme-success, #1d5b3a); border-radius: 1rem; }
.event-severity[data-severity="warning"] { background: var(--theme-warning-soft, #fff3d0); color: var(--theme-warning, #705408); }
.event-severity[data-severity="critical"] { background: var(--theme-danger-soft, #fee6e3); color: var(--theme-danger, #a32519); }
.event-revisions { padding-left: var(--space-5); }
.event-revisions li { padding: var(--space-2) 0; overflow-wrap: anywhere; }
.events-pagination { margin-top: var(--ui-content-gap); font-size: 0.875rem; }
@media (max-width: 600px) { .event-form-grid { grid-template-columns: 1fr; } .events-filters > div { flex: 1 1 100%; } .event-form, .event-details { padding: var(--space-3); } .events-card { padding: 0; } }
</style>
