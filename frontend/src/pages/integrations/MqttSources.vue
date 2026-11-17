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
import ListActionButton from '../../components/ListActionButton.vue';
import ResourceCard from '../../components/ResourceCard.vue';
import { NTag as Tag } from 'naive-ui';
import { formatDateTime } from '../../lib/date-time';
import FormDialog from '../../components/FormDialog.vue';
import { computed, useId, onMounted, onUnmounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { NButton as Button } from 'naive-ui';
import { NCard as Card } from 'naive-ui';
import { NInput as InputText } from 'naive-ui';
import Message from '../../components/AppMessage.vue';
import { ApiError } from '../../api/auth';
import { mqttApi, type MqttSource } from '../../api/mqtt';
import { sensorsApi, type Sensor } from '../../api/sensors';
import en from '../../locales/en/mqtt.json';
import fr from '../../locales/fr/mqtt.json';
import ReferenceSelect from '../../components/ReferenceSelect.vue';
const vRememberDisclosure = useRememberDisclosure('MqttSources');
const pageSize = useCollectionPageSize('mqtt');
defineProps<{ embedded?: boolean }>();
const emit = defineEmits<{ close: []; accessLost: [cause: ApiError] }>();
const { t, te, locale, mergeLocaleMessage } = useI18n({ useScope: 'global' });
mergeLocaleMessage('en', { mqtt: en }); mergeLocaleMessage('fr', { mqtt: fr });
const search = ref(''); const state = ref('');
const sources = ref<MqttSource[]>([]); const selected = ref<Sensor | null>(null); const options = new Map<string, Sensor>();
const formOpen = ref(false); const topic = ref(''); const configured = ref(false); const page = ref(1); const lastPage = ref(1); const total = ref(0);
const loading = ref(false); const busy = ref(false); const error = ref(''); const notice = ref(''); let active = true; let sequence = 0;
const baseline = ref(''); const stale = ref(new Set<string>());
const snapshot = () => JSON.stringify([selected.value?.id ?? null, topic.value]);
const dirty = computed(() => busy.value || formOpen.value && snapshot() !== baseline.value);
const unsaved = useUnsavedChanges(dirty, busy, { onNavigate: () => { formOpen.value = false; } });
const validation = useFieldErrors(() => ({
 sensor_id: { id: 'mqtt-sensor', message: 'mqtt.validation.sensorInvalid' },
 topic: { id: 'mqtt-topic', message: 'mqtt.validation.topicInvalid' },
}), () => ({ sensor_id: selected.value?.id, topic: topic.value }));
const fields = validation.fields;
async function open() {
 if (loading.value || !configured.value || !await unsaved.confirmDiscard()) return;
 selected.value = null; topic.value = ''; baseline.value = snapshot(); validation.clear(); error.value = notice.value = ''; formOpen.value = true;
}
async function closeForm() { if (!await unsaved.confirmDiscard()) return false; formOpen.value = false; validation.clear(); error.value = ''; return true; }
async function leave() { if (await closeForm()) emit('close'); }
function date(value: string | null) { return value ? formatDateTime(value, locale.value) : t('mqtt.never'); }
function failure(cause: unknown, source?: MqttSource) {
 const code = cause instanceof ApiError ? cause.code : '';
 error.value = /^mqtt\.errors\.[A-Za-z]+$/.test(code) && te(code) ? code : 'auth.errors.requestFailed';
 if (cause instanceof ApiError && source && (cause.status === 409 || cause.fields.version)) { stale.value.add(source.id); error.value = 'mqtt.errors.conflict'; }
 if (cause instanceof ApiError && formOpen.value) {
  validation.capture(cause);
  if (cause.status === 422 && Object.keys(cause.fields).length) error.value = 'auth.errors.validationFailed';
  if (code === 'mqtt.errors.sensorUnavailable') validation.set('sensor_id', code);
  if (code === 'mqtt.errors.alreadyLinked') { validation.set('sensor_id', code); validation.set('topic', code); }
  void validation.focus();
 }
 if (cause instanceof ApiError && [401, 403].includes(cause.status)) { sources.value = []; formOpen.value = false; emit('accessLost', cause); }
}
async function read(target: number) {
 const current = ++sequence;
 const [status, result] = await Promise.all([mqttApi.status(), mqttApi.list(target, pageSize.value, search.value.trim(), state.value)]);
 if (!active || current !== sequence) return;
 configured.value = status.configured; sources.value = result.data; page.value = result.meta.current_page; lastPage.value = result.meta.last_page; total.value = result.meta.total;
 for (const row of result.data) stale.value.delete(row.id);
}
async function load(target = 1) {
 if (busy.value || formOpen.value) return;
 loading.value = true; error.value = '';
 try { await read(target); }
 catch (cause) { if (active) failure(cause); }
 finally { if (active) loading.value = false; }
}
async function sensorPage(search: string, target: number) {
 const result = await sensorsApi.list(search, '', target);
 return { ...result, data: result.data.map(sensor => { options.set(sensor.id, sensor); return { id: sensor.id, name: sensor.name, detail: [sensor.device.name, sensor.measurementType.unit].filter(Boolean).join(' · '), disabled: !sensor.isEnabled || sensor.device.status === 'retired' }; }) };
}
function choose(id: string) { selected.value = options.get(id) ?? null; }
async function change(source?: MqttSource) {
 if (busy.value || loading.value || !configured.value || source && stale.value.has(source.id)) return;
 notice.value = error.value = ''; validation.clear();
 if (!source) {
  if (!selected.value || !selected.value.isEnabled || selected.value.device.status === 'retired') validation.set('sensor_id');
  if (!topic.value.trim() || topic.value.trim().length > 200 || /[\u0000-\u0020\u007f#+$]/u.test(topic.value.trim())) validation.set('topic');
  if (Object.keys(fields.value).length) { error.value = 'auth.errors.validationFailed'; void validation.focus(); return; }
 }
 busy.value = true; let committed = false;
 try {
  const result = source ? await mqttApi.toggle(source) : await mqttApi.create(selected.value!.id, topic.value.trim());
  if (!active) return;
  committed = true; if (!source) total.value++;
  sources.value = [result, ...sources.value.filter(row => row.id !== result.id)];
  formOpen.value = false; selected.value = null; topic.value = ''; notice.value = 'mqtt.saved';
  await read(page.value);
 } catch (cause) { if (active) { failure(cause, committed ? undefined : source); if (committed && !(cause instanceof ApiError && [401, 403].includes(cause.status))) error.value = 'mqtt.errors.refreshFailed'; } }
 finally { if (active) busy.value = false; }
}
onMounted(() => load(page.value)); onUnmounted(() => { active = false; sequence++; });
const dialogId = useId();

const collectionQuery = useCollectionQuery({ paths: ['/integrations/mqtt'], fields: { q: queryField.text(search, 160), state: queryField.choice(state, ['enabled', 'disabled']) }, page, pageSize, paused: () => busy.value || formOpen.value, load: target => load(target) });
const selectionHeader = ref<HTMLElement | null>(null);
</script>
<template><Card class="operational-page integration-page mqtt-card"><template #header><div class="card-heading-block"><div class="page-heading"><div class="heading"><PageTitle icon="plug">{{ t('mqtt.title') }}</PageTitle><Button v-if="!embedded" :aria-label="t('mqtt.back')" type="default" :disabled="busy" @click="leave" :title="t('mqtt.back')" class="list-action-button"><template #icon><AppIcon name="arrowLeft" /></template></Button></div><div ref="selectionHeader" class="page-actions"><CollectionViewToggle :preference-key="'mqtt-sources'" :disabled="busy"  /><ListActionButton icon="plus" :label="t('mqtt.add')" :disabled="busy || loading || !configured" @click="open" /><ListActionButton icon="refresh" :label="t('mqtt.reload')" :disabled="busy || loading" @click="load(page)" /></div></div><div class="card-subtitle">{{ t('mqtt.description') }}</div></div></template>

  <template #default><slot name="navigation" />
   <DiscardChangesDialog v-if="unsaved.pending.value" @decision="unsaved.finish" />
   <Message v-if="error && !formOpen" closable type="error" role="alert">{{ t(error) }}</Message><Message v-if="notice" closable type="success" role="status">{{ t(notice) }}</Message>
   <IntegrationStatus icon="plug" reception :status="loading ? 'loading' : error ? 'unknown' : configured ? 'configured' : 'missing'"><p>{{ t(configured ? 'mqtt.sourceHelp' : 'mqtt.notConfigured') }}</p></IntegrationStatus>
   <details v-remember-disclosure="'help'" class="operational-help"><DisclosureSummary icon="help">{{ t('mqtt.helpTitle') }}</DisclosureSummary><p>{{ t('mqtt.payload') }}</p><code>{"value":"12.345678901","unit":"test","observed_at":"2026-11-07T12:00:00Z"}</code></details>
   <FormDialog icon="plug" v-if="formOpen" :visible="true" :title="t('mqtt.add')" :close-label="t('mqtt.cancel')" :busy="busy" :error="error ? t(error) : ''" @update:visible="!$event && closeForm()"><form :id="`${dialogId}-0`" class="dialog-form" @submit.prevent="change()" novalidate><p class="dialog-wide">{{ t('mqtt.sourceHelp') }}</p>
     <ReferenceSelect :invalid="!!fields.sensor_id" :described-by="fields.sensor_id ? 'mqtt-sensor-error' : undefined" id="mqtt-sensor" :model-value="selected?.id ?? ''" :selected="selected ? { id: selected.id, name: selected.name, detail: selected.device.name } : null" :label="t('mqtt.chooseSensor')" :fetch-page="sensorPage" :disabled="busy || loading || !configured" @select="choose" @access-lost="failure" />
     <small v-if="fields.sensor_id" id="mqtt-sensor-error" class="field-error dialog-wide" role="alert">{{ t(fields.sensor_id) }}</small>
     <div class="field"><label for="mqtt-topic">{{ t('mqtt.topic') }}</label><InputText placeholder="" v-bind="validation.attrs('topic')" v-model:value="topic" maxlength="200" :disabled="busy || !configured" spellcheck="false" :input-props="{ 'id': 'mqtt-topic', ...validation.attrs('topic'), 'required': true, 'autocomplete': 'off' }"></InputText><small v-if="fields.topic" id="mqtt-topic-error" class="field-error" role="alert">{{ t(fields.topic) }}</small></div></form>
    <template #footer><div class="dialog-actions"><Button attr-type="button" :aria-label="t('mqtt.cancel')" type="default" :disabled="busy" @click="closeForm"><template #icon><AppIcon name="close" /></template><span>{{ t('actions.cancel') }}</span></Button><Button :form="`${dialogId}-0`" attr-type="submit" :aria-label="t('actions.create')" :disabled="busy || loading || !configured" :loading="busy" type="primary"><template #icon><AppIcon name="plus" /></template><span>{{ t('actions.create') }}</span></Button></div></template></FormDialog>
   <IntegrationChannelFilters v-model:search="search" v-model:state="state" :disabled="busy || formOpen" />
   <p v-if="loading" class="operational-empty" role="status">{{ t('mqtt.loading') }}</p><div v-else-if="!sources.length" class="resource-empty"><AppIcon name="plug" /><p>{{ t(search.trim() || state ? 'integrationPages.noMatchingChannels' : 'mqtt.empty') }}</p></div>
   <CollectionTable :selection-target="selectionHeader" static-grid :preference-key="'mqtt-sources'" grid-class="source-list operational-list" :rows="sources" :columns="[{ key: 'name', label: t('collection.name') }, { key: 'topic', label: t('mqtt.topic') }, { key: 'date', label: t('mqtt.lastAccepted') }, { key: 'actions', label: t('collection.actions') }]" :can-select="() => true" :row-name="row => row.sensor.name" :remove="mqttApi.remove" :loading="loading || busy" @removed="load(1)" @access-lost="failure">
<template #cards="{ selectionFor }"><ResourceCard :selection="selectionFor(source)" v-for="source in sources" :key="source.id" icon="plug" :eyebrow="source.sensor.device.name" :tone="source.isEnabled && source.instanceCurrent ? 'success' : 'muted'"><template #title>{{ source.sensor.name }}</template><template #badge><Tag :type="source.isEnabled ? 'success' : 'default'">{{ t(source.isEnabled ? 'mqtt.active' : 'mqtt.paused') }}</Tag></template>
    <dl class="resource-facts"><div class="resource-wide"><dt>{{ t('mqtt.topic') }}</dt><dd><code>{{ source.topic }}</code></dd></div><div class="resource-wide"><dt>{{ t('mqtt.lastAccepted') }}</dt><dd>{{ date(source.lastReceivedAt) }}</dd></div></dl>
    <p v-if="!source.instanceCurrent" class="operational-warning">{{ t('mqtt.errors.instanceChanged') }}</p><p v-if="source.lastError" class="operational-warning">{{ te(source.lastError) ? t(source.lastError) : t('mqtt.errors.failed') }}</p>
    <template #actions><ListActionButton icon="binary" :label="t(source.isEnabled ? 'mqtt.pause' : 'mqtt.resume', { name: source.sensor.name })" :disabled="busy || loading || stale.has(source.id) || !configured || !source.isEnabled && !source.instanceCurrent" @click="change(source)" /></template></ResourceCard>
</template>
 <template #batch-actions="{ rows: selectedSources, clear }">
  <RecordBatchAction :rows="selectedSources.filter(row => configured && row.isEnabled)" :action="mqttApi.toggle" :name="row => row.sensor.name" :label="t('collection.batch.pause')" icon="unlink" @applied="clear($event); load(page)" @access-lost="failure" />
 </template>
 <template #name="{ row }"><strong>{{ row.sensor.name }}</strong> · {{ row.sensor.device.name }} <Tag :type="row.isEnabled ? 'success' : 'default'">{{ t(row.isEnabled ? 'mqtt.active' : 'mqtt.paused') }}</Tag></template>
 <template #topic="{ row }"><code>{{ row.topic }}</code></template>
 <template #date="{ row }">{{ date(row.lastReceivedAt) }}</template>
 <template #actions="{ row }"><div class="collection-table-actions"><ListActionButton icon="binary" :label="t(row.isEnabled ? 'mqtt.pause' : 'mqtt.resume', { name: row.sensor.name })" :disabled="busy || loading || stale.has(row.id) || !configured || !row.isEnabled && !row.instanceCurrent" @click="change(row)" /></div></template></CollectionTable>
   <CollectionPagination v-model:page-size="pageSize" :page="page" :last-page="lastPage" :total="total" :disabled="busy || loading" :label="t('mqtt.pagination')" :previous-label="t('mqtt.previous')" :next-label="t('mqtt.next')" @page="collectionQuery.go($event)" /></template></Card></template>
