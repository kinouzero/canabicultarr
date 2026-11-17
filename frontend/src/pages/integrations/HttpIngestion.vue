<script setup lang="ts">
import RecordBatchAction from '../../components/RecordBatchAction.vue';
import CollectionTable from '../../components/CollectionTable.vue';
import CollectionViewToggle from '../../components/CollectionViewToggle.vue';
import { queryField, useCollectionQuery } from '../../lib/collection-query';
import IntegrationChannelFilters from '../../components/IntegrationChannelFilters.vue';
import DisclosureSummary from '../../components/DisclosureSummary.vue';
import DiscardChangesDialog from '../../components/DiscardChangesDialog.vue';
import { useUnsavedChanges } from '../../lib/unsaved-changes';
import { useFieldErrors } from '../../lib/field-errors';
import { useCollectionPageSize } from '../../lib/collection-page-size';
import CollectionPagination from '../../components/CollectionPagination.vue';
import { useRememberDisclosure } from '../../lib/disclosure-preferences';
import PageTitle from '../../components/PageTitle.vue';
import IntegrationStatus from '../../components/IntegrationStatus.vue';
import AppIcon from '../../components/AppIcon.vue';
import ResourceCard from '../../components/ResourceCard.vue';
import { NTag as Tag } from 'naive-ui';
import ListActionButton from '../../components/ListActionButton.vue';
import { formatDateTime } from '../../lib/date-time';
import FormDialog from '../../components/FormDialog.vue';
import { computed, useId, onMounted, onUnmounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { NButton as Button } from 'naive-ui';
import { NCard as Card } from 'naive-ui';
import { NInput as InputText } from 'naive-ui';
import Message from '../../components/AppMessage.vue';
import { ApiError } from '../../api/auth';
import { httpIngestionApi, type HttpSource, type HttpSourceResult } from '../../api/httpIngestion';
import { sensorsApi, type Sensor } from '../../api/sensors';
import en from '../../locales/en/http-ingestion.json';
import fr from '../../locales/fr/http-ingestion.json';
import ReferenceSelect from '../../components/ReferenceSelect.vue';
const vRememberDisclosure = useRememberDisclosure('HttpIngestion');
const pageSize = useCollectionPageSize('http');
defineProps<{ embedded?: boolean }>();
const emit = defineEmits<{ close: []; accessLost: [cause: ApiError] }>();
const { t, te, locale, mergeLocaleMessage } = useI18n({ useScope: 'global' });
mergeLocaleMessage('en', { httpIngestion: en }); mergeLocaleMessage('fr', { httpIngestion: fr });
const search = ref(''); const state = ref('');
const sources = ref<HttpSource[]>([]); const selected = ref<Sensor | null>(null); const options = new Map<string, Sensor>();
const formOpen = ref(false); const days = ref(90); const page = ref(1); const lastPage = ref(1); const total = ref(0);
const loading = ref(false); const busy = ref(false); const error = ref(''); const notice = ref('');
const secret = ref(''); const secretSource = ref(''); let active = true; let readSequence = 0;
const rotating = ref<HttpSource | null>(null); const stale = ref(new Set<string>()); const baseline = ref('');
const snapshot = () => JSON.stringify([selected.value?.id ?? null, days.value]);
const dirty = computed(() => busy.value || formOpen.value && snapshot() !== baseline.value);
const unsaved = useUnsavedChanges(dirty, busy, { onNavigate: () => { formOpen.value = false; } });
const validation = useFieldErrors(() => ({
 sensor_id: { id: 'httpIngestion-sensor', message: 'httpIngestion.validation.sensorInvalid' },
 expires_days: { id: 'http-expiry-days', message: 'httpIngestion.validation.expiryInvalid' },
}), () => ({ sensor_id: selected.value?.id, expires_days: days.value }));
const fields = validation.fields;
function clearSecret() { secret.value = secretSource.value = ''; }
function resetForm(source: HttpSource | null = null) { rotating.value = source; selected.value = null; days.value = 90; baseline.value = snapshot(); validation.clear(); error.value = ''; }
async function open(source: HttpSource | null = null) {
 if (loading.value || !await unsaved.confirmDiscard()) return;
 clearSecret(); notice.value = ''; resetForm(source); formOpen.value = true;
}
async function closeForm() { if (!await unsaved.confirmDiscard()) return false; formOpen.value = false; resetForm(); return true; }
async function leave() { if (await closeForm()) emit('close'); }
function date(value: string | null) { return value ? formatDateTime(value, locale.value) : t('httpIngestion.never'); }
function key(value: string) { return /^(auth|httpIngestion)\.(errors|validation)\.[A-Za-z]+$/.test(value) && te(value) ? value : 'auth.errors.requestFailed'; }
function failure(cause: unknown, source?: HttpSource) {
 error.value = key(cause instanceof ApiError ? cause.code : 'auth.errors.requestFailed');
 if (cause instanceof ApiError && source && (cause.status === 409 || cause.fields.version)) { stale.value.add(source.id); error.value = 'httpIngestion.errors.conflict'; }
 if (cause instanceof ApiError && formOpen.value) {
  validation.capture(cause);
  if (cause.status === 422) error.value = cause.fields.version ? 'httpIngestion.errors.conflict' : 'auth.errors.validationFailed';
  if (!rotating.value && ['httpIngestion.errors.alreadyLinked', 'httpIngestion.errors.sensorUnavailable'].includes(cause.code)) validation.set('sensor_id', cause.code);
  void validation.focus();
 }
 if (cause instanceof ApiError && [401, 403].includes(cause.status)) { sources.value = []; formOpen.value = false; clearSecret(); emit('accessLost', cause); }
}
async function read(target: number) {
 const current = ++readSequence;
 const result = await httpIngestionApi.list(target, pageSize.value, search.value.trim(), state.value);
 if (!active || current !== readSequence) return;
 sources.value = result.data; page.value = result.meta.current_page; lastPage.value = result.meta.last_page; total.value = result.meta.total;
 for (const row of result.data) stale.value.delete(row.id);
 return result;
}
async function load(target = 1) {
 if (busy.value || formOpen.value) return; error.value = ''; loading.value = true;
 try { await read(target); } catch (cause) { if (active) failure(cause); } finally { if (active) loading.value = false; }
}
async function reloadForm() {
 if (!rotating.value || !await unsaved.confirmDiscard()) return;
 const id = rotating.value.id; busy.value = true;
 try {
  const result = await read(page.value); if (!active) return;
  const fresh = result?.data.find(row => row.id === id);
  if (fresh) resetForm(fresh); else { stale.value.add(id); error.value = 'httpIngestion.errors.sensorUnavailable'; }
 } catch (cause) { if (active) failure(cause, rotating.value); }
 finally { if (active) busy.value = false; }
}
async function sensorPage(search: string, target: number) {
 const result = await sensorsApi.list(search, '', target);
 return { ...result, data: result.data.map(sensor => { options.set(sensor.id, sensor); return { id: sensor.id, name: sensor.name, detail: [sensor.device.name, sensor.measurementType.unit].filter(Boolean).join(' · '), disabled: !sensor.isEnabled || sensor.device.status === 'retired' }; }) };
}
function choose(id: string) { selected.value = options.get(id) ?? null; }
async function change(action: 'create' | 'toggle' | 'rotate' | 'revoke', source?: HttpSource) {
 if (busy.value || loading.value || source && stale.value.has(source.id)) return;
 error.value = notice.value = ''; validation.clear();
 if (action === 'create' && (!selected.value || !selected.value.isEnabled || selected.value.device.status === 'retired')) validation.set('sensor_id');
 if (['create', 'rotate'].includes(action) && (!Number.isInteger(days.value) || days.value < 1 || days.value > 365)) validation.set('expires_days');
 if (Object.keys(fields.value).length) { error.value = 'auth.errors.validationFailed'; void validation.focus(); return; }
 busy.value = true; clearSecret(); let committed = false;
 try {
  let result: HttpSourceResult;
  if (action === 'create') result = await httpIngestionApi.create(selected.value!.id, days.value);
  else if (action === 'rotate') result = await httpIngestionApi.rotate(source!, days.value);
  else if (action === 'revoke') result = await httpIngestionApi.revoke(source!);
  else result = await httpIngestionApi.toggle(source!);
  if (!active) return;
  committed = true;
  if (action === 'create') total.value++;
  // Keep the successful result and one-time token before any fallible list refresh.
  sources.value = [result.source, ...sources.value.filter(row => row.id !== result.source.id)];
  if (result.token) { secret.value = result.token; secretSource.value = result.source.id; }
  formOpen.value = false; resetForm();
  notice.value = `httpIngestion.${action === 'create' ? 'created' : action === 'rotate' ? 'rotated' : action === 'revoke' ? 'revoked' : 'updated'}`;
  await read(page.value);
 } catch (cause) { if (active) { failure(cause, committed ? undefined : source); if (committed && !(cause instanceof ApiError && [401, 403].includes(cause.status))) error.value = 'httpIngestion.errors.refreshFailed'; } }
 finally { if (active) busy.value = false; }
}
function endpoint(id: string) { return `${window.location.origin}/api/v1/ingestion/http/${id}`; }
onMounted(() => load(page.value)); onUnmounted(() => { active = false; readSequence++; clearSecret(); });
const dialogId = useId();

const collectionQuery = useCollectionQuery({ paths: ['/integrations/http'], fields: { q: queryField.text(search, 160), state: queryField.choice(state, ['enabled', 'disabled']) }, page, pageSize, paused: () => busy.value || formOpen.value, load: target => load(target) });
const selectionHeader = ref<HTMLElement | null>(null);
</script>
<template><Card class="operational-page integration-page http-card"><template #header><div class="card-heading-block"><div class="page-heading"><div class="heading"><PageTitle icon="plug">{{ t('httpIngestion.title') }}</PageTitle><Button v-if="!embedded" :aria-label="t('httpIngestion.back')" type="default" :disabled="busy" @click="leave" :title="t('httpIngestion.back')" class="list-action-button"><template #icon><AppIcon name="arrowLeft" /></template></Button></div><div ref="selectionHeader" class="page-actions"><CollectionViewToggle :preference-key="'http-sources'" :disabled="busy"  /><ListActionButton icon="plus" :label="t('httpIngestion.add')" :disabled="busy || loading" @click="open()" /><ListActionButton icon="refresh" :label="t('httpIngestion.reload')" :disabled="busy || loading" @click="load(page)" /></div></div><div class="card-subtitle">{{ t('httpIngestion.description') }}</div></div></template>

  <template #default><slot name="navigation" />
   <DiscardChangesDialog v-if="unsaved.pending.value" @decision="unsaved.finish" />
   <Message v-if="error && !formOpen" closable type="error" role="alert">{{ t(error) }}</Message><Message v-if="notice" closable type="success" role="status">{{ t(notice) }}</Message>
   <IntegrationStatus icon="plug" reception :status="loading ? 'loading' : error ? 'unknown' : 'ready'" />
   <section v-if="secret" class="operational-panel" :aria-label="t('httpIngestion.secretTitle')"><h3>{{ t('httpIngestion.secretTitle') }}</h3><p>{{ t('httpIngestion.secretHelp') }}</p><div class="field"><label for="http-one-time-token">{{ t('httpIngestion.secret') }}</label><InputText placeholder="" :value="secret" readonly spellcheck="false" :input-props="{ 'id': 'http-one-time-token', 'autocomplete': 'off' }"></InputText></div>
    <p>{{ t('httpIngestion.endpoint') }}</p><code>{{ endpoint(secretSource) }}</code><Button type="default" @click="clearSecret"><template #icon><AppIcon name="close" /></template><span>{{ t('httpIngestion.hide') }}</span></Button></section>
   <details v-remember-disclosure="'help'" class="operational-help"><DisclosureSummary icon="help">{{ t('httpIngestion.helpTitle') }}</DisclosureSummary><p>{{ t('httpIngestion.payload') }}</p><p>{{ t('httpIngestion.rotateHelp') }}</p></details>
   <FormDialog icon="plug" v-if="formOpen" :visible="true" :title="rotating ? t('httpIngestion.rotate', { name: rotating.sensor.name }) : t('httpIngestion.add')" :close-label="t('httpIngestion.cancel')" :busy="busy" :error="error ? t(error) : ''" @update:visible="!$event && closeForm()"><template #feedback><Button v-if="rotating && stale.has(rotating.id)" :aria-label="t('httpIngestion.reload')" type="default" :disabled="busy" @click="reloadForm"><template #icon><AppIcon name="refresh" /></template><span>{{ t('actions.refresh') }}</span></Button></template>
    <form :id="`${dialogId}-0`" class="dialog-form" @submit.prevent="change(rotating ? 'rotate' : 'create', rotating ?? undefined)" novalidate><p class="dialog-wide">{{ t(rotating ? 'httpIngestion.rotateHelp' : 'httpIngestion.sourceHelp') }}</p>
     <ReferenceSelect v-if="!rotating" :invalid="!!fields.sensor_id" :described-by="fields.sensor_id ? 'httpIngestion-sensor-error' : undefined" id="httpIngestion-sensor" :model-value="selected?.id ?? ''" :selected="selected ? { id: selected.id, name: selected.name, detail: selected.device.name } : null" :label="t('httpIngestion.chooseSensor')" :fetch-page="sensorPage" :disabled="busy || loading" @select="choose" @access-lost="failure" />
     <small v-if="fields.sensor_id" id="httpIngestion-sensor-error" class="field-error dialog-wide" role="alert">{{ t(fields.sensor_id) }}</small>
     <div class="field"><label for="http-expiry-days">{{ t('httpIngestion.expiryDays') }}</label><input id="http-expiry-days" :aria-invalid="!!fields.expires_days" v-model.number="days" type="number" min="1" max="365" step="1" :disabled="busy" :aria-describedby="fields.expires_days ? 'http-expiry-help http-expiry-days-error' : 'http-expiry-help'" /><small id="http-expiry-help">{{ t('httpIngestion.expiryHelp') }}</small><small v-if="fields.expires_days" id="http-expiry-days-error" class="field-error" role="alert">{{ t(fields.expires_days) }}</small></div></form>
    <template #footer><div class="dialog-actions"><Button attr-type="button" :aria-label="t('httpIngestion.cancel')" type="default" :disabled="busy" @click="closeForm"><template #icon><AppIcon name="close" /></template><span>{{ t('actions.cancel') }}</span></Button><Button :form="`${dialogId}-0`" attr-type="submit" :aria-label="t(rotating ? 'actions.renew' : 'actions.create')" :disabled="busy || loading || !!rotating && stale.has(rotating.id)" :loading="busy" type="primary"><template #icon><AppIcon :name="rotating ? 'refresh' : 'plus'" /></template><span>{{ t(rotating ? 'actions.renew' : 'actions.create') }}</span></Button></div></template></FormDialog>
   <IntegrationChannelFilters v-model:search="search" v-model:state="state" :disabled="busy || formOpen" />
   <p v-if="loading" class="operational-empty" role="status">{{ t('httpIngestion.loading') }}</p><div v-else-if="!sources.length" class="resource-empty"><AppIcon name="plug" /><p>{{ t(search.trim() || state ? 'integrationPages.noMatchingChannels' : 'httpIngestion.empty') }}</p></div>
   <CollectionTable :selection-target="selectionHeader" static-grid :preference-key="'http-sources'" grid-class="source-list operational-list" :rows="sources" :columns="[{ key: 'name', label: t('collection.name') }, { key: 'endpoint', label: t('httpIngestion.endpoint') }, { key: 'date', label: t('httpIngestion.lastAccepted') }, { key: 'actions', label: t('collection.actions') }]" :can-select="() => true" :row-name="row => row.sensor.name" :remove="httpIngestionApi.remove" :loading="loading || busy" @removed="load(1)" @access-lost="failure">
<template #cards="{ selectionFor }"><ResourceCard :selection="selectionFor(source)" v-for="source in sources" :key="source.id" icon="plug" :eyebrow="source.sensor.device.name" :tone="source.credentialCurrent ? 'success' : 'warning'"><template #title>{{ source.sensor.name }}</template><template #badge><Tag :type="source.isEnabled ? 'success' : 'default'">{{ t(source.isEnabled ? 'httpIngestion.active' : 'httpIngestion.paused') }}</Tag></template>
    <dl class="resource-facts"><div><dt>{{ t('httpIngestion.expiresAt') }}</dt><dd>{{ date(source.expiresAt) }}</dd></div><div><dt>{{ t('httpIngestion.lastAccepted') }}</dt><dd>{{ date(source.lastReceivedAt) }}</dd></div><div class="resource-wide"><dt>{{ t('httpIngestion.endpoint') }}</dt><dd><code>{{ endpoint(source.id) }}</code></dd></div></dl>
    <p :class="{ 'operational-warning': !source.credentialCurrent }">{{ t(source.credentialCurrent ? 'httpIngestion.credentialCurrent' : 'httpIngestion.credentialInvalid') }}</p>
    <template #actions><ListActionButton icon="binary" :label="t(source.isEnabled ? 'httpIngestion.pause' : 'httpIngestion.resume', { name: source.sensor.name })" :disabled="busy || loading || stale.has(source.id) || !source.isEnabled && !source.credentialCurrent" @click="change('toggle', source)" /></template><template #management><ListActionButton icon="refresh" :label="t('httpIngestion.rotate', { name: source.sensor.name })" :disabled="busy || loading || stale.has(source.id)" @click="open(source)" /><ListActionButton icon="unlink" :label="t('httpIngestion.revoke', { name: source.sensor.name })" severity="danger" :disabled="busy || loading || stale.has(source.id)" @click="change('revoke', source)" /></template></ResourceCard>
</template>
 <template #batch-actions="{ rows: selectedSources, clear }">
  <RecordBatchAction :rows="selectedSources.filter(row => row.credentialCurrent || row.isEnabled)" :action="httpIngestionApi.revoke" :name="row => row.sensor.name" :label="t('collection.batch.revoke')" icon="unlink" :confirmation="t('collection.batch.revokeWarning')" @applied="clear($event); load(page)" @access-lost="failure" />
 </template>
 <template #name="{ row }"><strong>{{ row.sensor.name }}</strong> · {{ row.sensor.device.name }} <Tag :type="row.isEnabled ? 'success' : 'default'">{{ t(row.isEnabled ? 'httpIngestion.active' : 'httpIngestion.paused') }}</Tag></template>
 <template #endpoint="{ row }"><code>{{ endpoint(row.id) }}</code></template>
 <template #date="{ row }">{{ date(row.lastReceivedAt) }}</template>
 <template #actions="{ row }"><div class="collection-table-actions"><ListActionButton icon="binary" :label="t(row.isEnabled ? 'httpIngestion.pause' : 'httpIngestion.resume', { name: row.sensor.name })" :disabled="busy || loading || stale.has(row.id) || !row.isEnabled && !row.credentialCurrent" @click="change('toggle', row)" /><ListActionButton icon="refresh" :label="t('httpIngestion.rotate', { name: row.sensor.name })" :disabled="busy || loading || stale.has(row.id)" @click="open(row)" /><ListActionButton icon="unlink" :label="t('httpIngestion.revoke', { name: row.sensor.name })" severity="danger" :disabled="busy || loading || stale.has(row.id)" @click="change('revoke', row)" /></div></template></CollectionTable>
   <CollectionPagination v-model:page-size="pageSize" :page="page" :last-page="lastPage" :total="total" :disabled="busy || loading" :label="t('httpIngestion.pagination')" :previous-label="t('httpIngestion.previous')" :next-label="t('httpIngestion.next')" @page="collectionQuery.go($event)" /></template></Card></template>
