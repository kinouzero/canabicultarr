<script setup lang="ts">
import DisclosureSummary from '../../components/DisclosureSummary.vue';
import { useSensorHistoryRoute } from '../../lib/sensor-history-route';
import { useCollectionPageSize } from '../../lib/collection-page-size';
import CollectionPagination from '../../components/CollectionPagination.vue';
import ResetFilters from '../../components/ResetFilters.vue';
import { useCollectionSort, sortOptions } from '../../lib/collection-sorting';
import SortSelect from '../../components/SortSelect.vue';
import { useRememberDisclosure } from '../../lib/disclosure-preferences';
import PageTitle from '../../components/PageTitle.vue';
import AppIcon from '../../components/AppIcon.vue';
import ResourceCard from '../../components/ResourceCard.vue';
import { NTag as Tag } from 'naive-ui';
import { queryField, useCollectionQuery } from '../../lib/collection-query';
import { toRef } from 'vue';
import SensorHistory from '../../components/SensorHistory.vue';
import ListActionButton from '../../components/ListActionButton.vue';
import { formatDateTime } from '../../lib/date-time';
import { onMounted, onUnmounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { NButton as Button } from 'naive-ui';
import { NCard as Card } from 'naive-ui';
import { NInput as InputText } from 'naive-ui';
import Message from '../../components/AppMessage.vue';
import { ApiError } from '../../api/auth';
import { sourceDiagnosticsApi, type SourceDiagnostics, type SourceFilters } from '../../api/sourceDiagnostics';
import en from '../../locales/en/source-diagnostics.json';
import fr from '../../locales/fr/source-diagnostics.json';
import mqttEn from '../../locales/en/mqtt.json';
import mqttFr from '../../locales/fr/mqtt.json';
import measurementsEn from '../../locales/en/measurements.json';
import measurementsFr from '../../locales/fr/measurements.json';
import homebridgeEn from '../../locales/en/homebridge.json';
import homebridgeFr from '../../locales/fr/homebridge.json';
const vRememberDisclosure = useRememberDisclosure('SourceDiagnostics');
const pageSize = useCollectionPageSize('sources');
defineProps<{ embedded?: boolean }>();
const emit = defineEmits<{ close: []; readings: [sensorId: string]; accessLost: [cause: ApiError] }>();
const { t, te, locale, mergeLocaleMessage } = useI18n({ useScope: 'global' });
mergeLocaleMessage('en', { sourceDiagnostics: en, measurements: measurementsEn, homebridge: homebridgeEn, mqtt: mqttEn }); mergeLocaleMessage('fr', { sourceDiagnostics: fr, measurements: measurementsFr, homebridge: homebridgeFr, mqtt: mqttFr });
const { sensor: readingSensor, open: openHistory, close: closeHistory } = useSensorHistoryRoute(cause => { error.value = 'auth.errors.requestFailed'; if (cause instanceof ApiError && [401, 403].includes(cause.status)) emit('accessLost', cause); });
const sort = useCollectionSort('sources', sortOptions.sources);
const filters = reactive({ sort, search: '', kind: '' as SourceFilters['kind'], state: '' as SourceFilters['state'], freshness: '' as SourceFilters['freshness'] });
const data = ref<SourceDiagnostics | null>(null); const error = ref(''); const loading = ref(false); let sequence = 0;
const kinds = ['home_assistant', 'homebridge', 'http', 'mqtt'] as const; const states = ['enabled', 'paused', 'sensor_disabled', 'device_retired', 'credential_invalid', 'source_unavailable'] as const; const freshnesses = ['fresh', 'stale', 'missing', 'unknown'] as const;
function date(value: string | null) { return value ? formatDateTime(value, locale.value) : t('sourceDiagnostics.never'); }
function sourceError(code: string, kind: string) { const translated = kind === 'homebridge' ? code.replace('measurements.', 'homebridge.') : code; return te(translated) ? translated : code; }
function key(value: string) { return /^(auth|sourceDiagnostics)\.(errors|validation)\.[A-Za-z]+$/.test(value) && te(value) ? value : 'auth.errors.requestFailed'; }
async function load(page = 1) {
 const current = ++sequence; loading.value = true; error.value = ''; data.value = null;
 try { const result = await sourceDiagnosticsApi.read({ per_page: String(pageSize.value), ...filters, search: filters.search.trim() }, page); if (current === sequence) data.value = result; }
 catch (cause) {
  if (current !== sequence) return;
  error.value = key(cause instanceof ApiError ? Object.values(cause.fields).flat()[0] ?? cause.code : 'auth.errors.requestFailed');
  if (cause instanceof ApiError && (cause.status === 401 || cause.status === 403)) emit('accessLost', cause);
 } finally { if (current === sequence) loading.value = false; }
}
onMounted(() => load(collectionQuery.page.value)); onUnmounted(() => { sequence++; data.value = null; });
const collectionQuery = useCollectionQuery({ paths: ['/diagnostics'], fields: { q: queryField.text(toRef(filters, 'search')), kind: queryField.choice(toRef(filters, 'kind'), ['', ...kinds]), state: queryField.choice(toRef(filters, 'state'), ['', ...states]), freshness: queryField.choice(toRef(filters, 'freshness'), ['', ...freshnesses]), sort: queryField.choice(toRef(filters, 'sort'), sortOptions.sources) }, pageSize, load: target => load(target) });
const applyFilters = collectionQuery.apply;


</script>
<template><Card class="operational-page integration-page diagnostics-card"><template #header><div class="card-heading-block"><div class="page-heading"><div class="heading"><PageTitle icon="diagnostics">{{ t('sourceDiagnostics.title') }}</PageTitle><Button v-if="!embedded" :aria-label="t('sourceDiagnostics.back')" type="default" @click="emit('close')" :title="t('sourceDiagnostics.back')" class="list-action-button"><template #icon><AppIcon name="arrowLeft" /></template></Button></div><div class="page-actions"><ListActionButton icon="refresh" :label="t('sourceDiagnostics.refresh')" severity="secondary" :disabled="loading" @click="load(data?.meta.current_page ?? 1)" /></div></div><div class="card-subtitle">{{ t('sourceDiagnostics.description') }}</div></div></template>

  <template #default><Message v-if="error" closable type="error" role="alert">{{ t(error) }}</Message>

   <template v-if="data"><section class="diagnostic-summary" :aria-label="t('sourceDiagnostics.summary')"><dl class="insight-metrics"><div class="insight-metric"><dt><AppIcon name="database" />{{ t('sourceDiagnostics.total') }}</dt><dd data-testid="source-total">{{ data.summary.total }}</dd></div><div class="insight-metric"><dt><AppIcon name="homeassistant" />{{ t('sourceDiagnostics.homeAssistant') }}</dt><dd>{{ data.summary.homeAssistant }}</dd></div><div class="insight-metric"><dt><AppIcon name="homebridge" />{{ t('sourceDiagnostics.kinds.homebridge') }}</dt><dd>{{ data.summary.homebridge }}</dd></div><div class="insight-metric"><dt><AppIcon name="plug" />{{ t('sourceDiagnostics.http') }}</dt><dd>{{ data.summary.http }}</dd></div><div class="insight-metric"><dt><AppIcon name="plug" />MQTT</dt><dd>{{ data.summary.mqtt }}</dd></div></dl></section>
    <details v-remember-disclosure="'diagnostic-summary'" class="operational-help"><DisclosureSummary icon="diagnostics">{{ t('sourceDiagnostics.configuration') }} · {{ t('sourceDiagnostics.readingSummary') }}</DisclosureSummary><section :aria-label="t('sourceDiagnostics.configuration')"><h3>{{ t('sourceDiagnostics.configuration') }}</h3><dl class="insight-metrics"><div class="insight-metric" v-for="state in states" :key="state"><dt>{{ t(`sourceDiagnostics.states.${state}`) }}</dt><dd>{{ data.summary.states[state] }}</dd></div></dl></section>
    <section :aria-label="t('sourceDiagnostics.readingSummary')"><h3>{{ t('sourceDiagnostics.readingSummary') }}</h3><dl class="insight-metrics"><div class="insight-metric" v-for="freshness in freshnesses" :key="freshness"><dt>{{ t(`sourceDiagnostics.freshnesses.${freshness}`) }}</dt><dd>{{ data.summary.readings[freshness] }}</dd></div></dl></section>
    <p class="help">{{ t('sourceDiagnostics.summaryHelp') }}</p><p class="help">{{ t('sourceDiagnostics.freshnessHelp') }}</p></details><p class="diagnostic-as-of"><AppIcon name="clock" />{{ t('sourceDiagnostics.asOf', { date: date(data.asOf) }) }}</p></template>
   <form class="filters search-filters" @submit.prevent="applyFilters"><div class="filter-primary"><div class="field filter-field"><label for="diagnostics-search">{{ t('sourceDiagnostics.search') }}</label><InputText placeholder="" v-model:value="filters.search" maxlength="120" :input-props="{ 'id': 'diagnostics-search' }"></InputText></div>
     <SortSelect v-model="filters.sort" :options="sortOptions.sources" /></div>
    <div class="filter-secondary"><div class="field filter-field"><label for="diagnostics-kind">{{ t('sourceDiagnostics.kind') }}</label><select v-app-select="{ filter: $t('selectControl.filter'), empty: $t('selectControl.empty') }" id="diagnostics-kind" v-model="filters.kind"><option value="">{{ t('sourceDiagnostics.all') }}</option><option v-for="kind in kinds" :key="kind" :value="kind">{{ t(`sourceDiagnostics.kinds.${kind}`) }}</option></select></div>
     <div class="field filter-field"><label for="diagnostics-state">{{ t('sourceDiagnostics.state') }}</label><select v-app-select="{ filter: $t('selectControl.filter'), empty: $t('selectControl.empty') }" id="diagnostics-state" v-model="filters.state"><option value="">{{ t('sourceDiagnostics.all') }}</option><option v-for="state in states" :key="state" :value="state">{{ t(`sourceDiagnostics.states.${state}`) }}</option></select></div>
     <div class="field filter-field"><label for="diagnostics-freshness">{{ t('sourceDiagnostics.freshness') }}</label><select v-app-select="{ filter: $t('selectControl.filter'), empty: $t('selectControl.empty') }" id="diagnostics-freshness" v-model="filters.freshness"><option value="">{{ t('sourceDiagnostics.all') }}</option><option v-for="freshness in freshnesses" :key="freshness" :value="freshness">{{ t(`sourceDiagnostics.freshnesses.${freshness}`) }}</option></select></div></div>
    <ResetFilters @reset="Object.assign(filters, { search: '', kind: '', state: '', freshness: '', sort: 'name_asc' })" /></form>
   <p v-if="loading" role="status">{{ t('sourceDiagnostics.loading') }}</p>
   <template v-if="data"><div v-if="!data.data.length" class="resource-empty"><AppIcon name="diagnostics" /><p>{{ t('sourceDiagnostics.empty') }}</p></div>
    <ul class="source-list operational-list"><ResourceCard v-for="source in data.data" :key="`${source.kind}:${source.id}`" :icon="source.kind === 'home_assistant' ? 'homeassistant' : source.kind === 'homebridge' ? 'homebridge' : 'plug'" :eyebrow="source.deviceName" :tone="source.freshness === 'stale' || source.credentialCurrent === false ? 'warning' : 'muted'"><template #title>{{ source.sensorName }}</template><template #badge><Tag :type="source.freshness === 'fresh' ? 'success' : source.freshness === 'stale' ? 'warning' : 'default'">{{ t(`sourceDiagnostics.freshnesses.${source.freshness}`) }}</Tag></template>
     <dl class="resource-facts"><div><dt>{{ t('sourceDiagnostics.kind') }}</dt><dd>{{ t(`sourceDiagnostics.kinds.${source.kind}`) }}</dd></div><div><dt>{{ t('sourceDiagnostics.state') }}</dt><dd>{{ t(`sourceDiagnostics.states.${source.state}`) }}</dd></div><div><dt>{{ t('sourceDiagnostics.observed') }}</dt><dd>{{ date(source.lastObservedAt) }}</dd></div><div><dt>{{ t('sourceDiagnostics.received') }}</dt><dd>{{ date(source.latestReceivedAt) }}</dd></div><template v-if="['home_assistant', 'homebridge'].includes(source.kind)"><div><dt>{{ t('sourceDiagnostics.attempt') }}</dt><dd>{{ date(source.lastAttemptAt) }}</dd></div><div><dt>{{ t('sourceDiagnostics.success') }}</dt><dd>{{ date(source.lastSuccessAt) }}</dd></div></template><div v-else class="resource-wide"><dt>{{ t('sourceDiagnostics.accepted') }}</dt><dd>{{ date(source.lastAcceptedAt) }}</dd></div></dl>
     <p v-if="source.credentialCurrent === false" class="operational-warning">{{ t('sourceDiagnostics.credentialInvalid') }}</p><p v-if="source.lastError" class="operational-warning">{{ t('sourceDiagnostics.lastError') }} : {{ te(sourceError(source.lastError, source.kind)) ? t(sourceError(source.lastError, source.kind)) : t('sourceDiagnostics.unknownError') }}</p><p v-else-if="source.hasUnknownError" class="operational-warning">{{ t('sourceDiagnostics.unknownError') }}</p>
     <template #actions><ListActionButton icon="clock" :label="t('sourceDiagnostics.history', { name: source.sensorName })" @click="openHistory({ id: source.sensorId, name: source.sensorName })" /></template></ResourceCard></ul>
    <p class="help">{{ t('sourceDiagnostics.activityHelp') }}</p><CollectionPagination v-model:page-size="pageSize" :page="data.meta.current_page" :last-page="data.meta.last_page" :total="data.meta.total" :disabled="loading" :label="t('sourceDiagnostics.pagination')" :previous-label="t('sourceDiagnostics.previous')" :next-label="t('sourceDiagnostics.next')" @page="collectionQuery.go($event)" /></template></template></Card>
 <SensorHistory v-if="readingSensor" :sensor-id="readingSensor.id" :name="readingSensor.name" @close="closeHistory" @access-lost="readingSensor = null; emit('accessLost', $event)" /></template>
<style scoped>
.diagnostic-summary { margin-block: var(--space-3); }
.diagnostic-as-of { display: flex; flex-wrap: wrap; align-items: center; gap: var(--ui-icon-gap); margin: var(--space-3) 0; color: var(--theme-muted); font-size: .75rem; }
.diagnostic-as-of svg { width: 1rem; height: 1rem; }
.operational-help section { margin-top: var(--ui-content-gap); }
.operational-help h3 { margin: 0; font-size: .85rem; }
.help { color: var(--theme-muted); font-size: .75rem; line-height: 1.6; }
</style>
