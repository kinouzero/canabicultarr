<script setup lang="ts">
import ChannelSwitchTag from '../../components/ChannelSwitchTag.vue';
import type { PlantMeasurement } from '../../api/plants';
import MeasurementQuality from '../../components/MeasurementQuality.vue';
import { useSensorHistoryRoute } from '../../lib/sensor-history-route';
import { useCollectionPageSize } from '../../lib/collection-page-size';
import CollectionPagination from '../../components/CollectionPagination.vue';
import ResetFilters from '../../components/ResetFilters.vue';
import { useCollectionSort, sortOptions } from '../../lib/collection-sorting';
import SortSelect from '../../components/SortSelect.vue';
import { measurementNatureIcon } from '../../lib/measurement-nature-icon';
import { computed, defineAsyncComponent, onMounted, onUnmounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { NButton as Button } from 'naive-ui';
import { NCard as Card } from 'naive-ui';
import { NInput as InputText } from 'naive-ui';
import Message from '../../components/AppMessage.vue';
import PageTitle from '../../components/PageTitle.vue';
import DeviceAssignment from '../../components/DeviceAssignment.vue';
import AppIcon from '../../components/AppIcon.vue';
import ListActionButton from '../../components/ListActionButton.vue';
import ReferenceSelect from '../../components/ReferenceSelect.vue';
import InsightChart from '../../components/InsightChart.vue';
import InsightOptions from '../../components/InsightOptions.vue';
import { ApiError } from '../../api/auth';
import { insightsApi, type MeasurementOverview, type ReadingStatus } from '../../api/insights';
import { locationsApi } from '../../api/locations';
import { measurementTypesApi } from '../../api/sensors';
import { formatDateTime } from '../../lib/date-time';
import { queryField, useCollectionQuery } from '../../lib/collection-query';
import { toRef } from 'vue';
import { useInsightPreferences } from '../../lib/insight-preferences';
const pageSize = useCollectionPageSize('measurements');
const sort = useCollectionSort('measurements', sortOptions.measurements);
const SensorHistory = defineAsyncComponent(() => import('../../components/SensorHistory.vue'));
defineProps<{ embedded?: boolean }>();
const emit = defineEmits<{ close: []; accessLost: [cause: ApiError] }>();
const { t, locale } = useI18n();
const prefs = useInsightPreferences('measurements', { site: '', type: '', status: '', pinnedOnly: false, pins: [] as string[], sections: ['summary', 'health', 'channels'] });
const statuses: ReadingStatus[] = ['fresh', 'stale', 'missing', 'unknown', 'disabled'];
if (prefs.status && !statuses.includes(prefs.status as ReadingStatus)) prefs.status = '';
const search = ref(''); const data = ref<MeasurementOverview | null>(null); const loading = ref(false); const error = ref('');
const { sensor: history, open: openHistory, close: closeHistory } = useSensorHistoryRoute(fail); let sequence = 0;
const sitePage = async (search: string, page: number) => { const result = await locationsApi.list('sites', search, page); return { ...result, data: result.data.map(row => ({ id: row.id, name: row.name, detail: '' })) }; };
const typePage = async (search: string, page: number) => { const result = await measurementTypesApi.list(search, page); return { ...result, data: result.data.map(row => ({ id: row.id, name: row.name, detail: row.unit ?? '' })) }; };
const options = computed(() => [{ id: 'summary', label: t('insights.summary') }, { id: 'health', label: t('insights.health') }, { id: 'channels', label: t('insights.measurements') }]);
function fail(cause: unknown) { error.value = 'auth.errors.requestFailed'; if (cause instanceof ApiError && [401, 403].includes(cause.status)) { data.value = null; history.value = null; emit('accessLost', cause); } }
async function load(page = 1) {
 const current = ++sequence; loading.value = true; error.value = ''; data.value = null;
 try {
  const filters: Record<string, string> = { per_page: String(pageSize.value), sort: sort.value, search: search.value, page: String(page), ...(prefs.site ? { site_id: prefs.site } : {}), ...(prefs.type ? { measurement_type_id: prefs.type } : {}), ...(prefs.status ? { status: prefs.status } : {}), pinned_only: prefs.pinnedOnly ? '1' : '0' };
  const result = await insightsApi.measurements(filters, prefs.pins);
  if (current !== sequence) return;
  if (page > result.meta.last_page) { void load(result.meta.last_page); return; }
  data.value = result;
 } catch (cause) { if (current === sequence) fail(cause); }
 finally { if (current === sequence) loading.value = false; }
}
function pin(id: string) { prefs.pins = prefs.pins.includes(id) ? prefs.pins.filter(value => value !== id) : [...prefs.pins, id].slice(-100); }
function switchReading(row: MeasurementOverview['data'][number]): PlantMeasurement {
 return { sensorId: row.sensor.id, sensorName: row.sensor.name, device: row.sensor.device, measurementType: row.sensor.measurementType,
  switchControl: row.sensor.switchControl, value: row.latest?.value ?? null, unit: row.latest?.unit ?? null,
  observedAt: row.latest?.observedAt ?? null, isEnabled: row.sensor.isEnabled, isStale: row.status === 'stale' };
}
function value(row: MeasurementOverview['data'][number]) { return row.latest ? new Intl.NumberFormat(locale.value, { maximumFractionDigits: row.sensor.measurementType.decimalPlaces }).format(Number(row.latest.value)) : '—'; }
const healthSeries = computed(() => [{ label: t('insights.total'), values: statuses.map(status => data.value?.summary[status] ?? 0) }]);
const collectionQuery = useCollectionQuery({ paths: ['/measurements'], fields: { q: queryField.text(search), site: queryField.identifier(toRef(prefs, 'site')), type: queryField.identifier(toRef(prefs, 'type')), status: queryField.choice(toRef(prefs, 'status'), ['', ...statuses]), favorites: queryField.boolean(toRef(prefs, 'pinnedOnly')), sort: queryField.choice(sort, sortOptions.measurements) }, pageSize, load: target => load(target) });
watch(() => prefs.pins, () => { if (prefs.pinnedOnly) collectionQuery.apply(); });
onMounted(() => { void load(collectionQuery.page.value); }); onUnmounted(() => { sequence++; });


</script>
<template><Card class="insight-workspace" data-testid="measurement-workspace"><template #header><div class="card-heading-block"><div class="insight-heading"><PageTitle icon="chart">{{ t('insights.measurements') }}</PageTitle><div class="insight-actions"><InsightOptions v-model="prefs.sections" :options="options" /><ListActionButton icon="refresh" :label="t('insights.refresh')" :loading="loading" @click="load()"  /><Button v-if="!embedded" text :aria-label="t('insights.close')" @click="emit('close')" data-action-icon="close" :title="t('insights.close')" class="list-action-button" type="primary"><template #icon><AppIcon name="close" /></template></Button></div></div><div class="card-subtitle">{{ t('insights.measurementHelp') }}</div></div></template><template #default><Message v-if="error" type="error" closable role="alert">{{ t(error) }}</Message>
  <div v-if="data && (prefs.sections.includes('summary') || prefs.sections.includes('health'))" class="measurement-summary" :class="{ 'measurement-summary-single': !prefs.sections.includes('summary') || !prefs.sections.includes('health') }"><div v-if="prefs.sections.includes('summary')" class="insight-metrics"><button v-for="status in ['total', ...statuses]" :key="status" type="button" class="insight-metric" :aria-pressed="prefs.status === (status === 'total' ? '' : status)" @click="prefs.status = status === 'total' ? '' : status"><span><AppIcon :name="status === 'fresh' ? 'check' : status === 'stale' ? 'clock' : 'sensor'" />{{ t(`insights.${status}`) }}</span><strong>{{ data.summary[status as keyof typeof data.summary] }}</strong></button></div>
   <section v-if="prefs.sections.includes('health')" class="insight-panel"><h3>{{ t('insights.health') }}</h3><InsightChart v-if="data.summary.total" type="doughnut" :label="t('insights.health')" :labels="statuses.map(status => t(`insights.${status}`))" :series="healthSeries" /><p v-else class="insight-empty">{{ t('insights.empty') }}</p><p class="insight-help">{{ t('insights.healthHelp') }}</p><p class="insight-help">{{ t('insights.outdatedHelp') }}</p></section></div>
  <form class="search-filters" @submit.prevent="load()"><div class="filter-primary"><div class="filter-field"><label for="measurement-search">{{ t('insights.search') }}</label><InputText placeholder="" v-model:value="search" :input-props="{ 'id': 'measurement-search', type: 'search' }"></InputText></div>
     <SortSelect v-model="sort" :options="sortOptions.measurements" /></div>
    <div class="filter-secondary"><ReferenceSelect v-model="prefs.site" :label="t('insights.site')" :placeholder="t('insights.allSites')" :fetch-page="sitePage" @access-lost="emit('accessLost', $event)" />
     <ReferenceSelect v-model="prefs.type" :label="t('insights.type')" :placeholder="t('insights.allTypes')" :fetch-page="typePage" @access-lost="emit('accessLost', $event)" />
     <div class="filter-field"><label for="measurement-status">{{ t('insights.status') }}</label><select id="measurement-status" v-model="prefs.status" v-app-select="{ filter: t('selectControl.filter'), empty: t('selectControl.empty') }"><option value="">{{ t('insights.allStatuses') }}</option><option v-for="status in statuses" :key="status" :value="status">{{ t(`insights.${status}`) }}</option></select></div>
     <div class="filter-field"><label for="measurement-favorites">{{ t('insights.view') }}</label><select id="measurement-favorites" v-model="prefs.pinnedOnly" v-app-select="{ filter: t('selectControl.filter'), empty: t('selectControl.empty') }"><option :value="false">{{ t('insights.allChannels') }}</option><option :value="true">{{ t('insights.pinned') }}</option></select></div></div>
    <ResetFilters @reset="Object.assign(prefs, { site: '', type: '', status: '', pinnedOnly: false }); search = ''; sort = 'name_asc'" /></form>
  <p v-if="loading" role="status" class="insight-help">{{ t('insights.loading') }}</p>
  <div v-if="data" class="insight-grid"><section v-if="prefs.sections.includes('channels')" class="insight-panel insight-wide"><div class="insight-section-heading"><h3>{{ t('insights.latest') }}</h3></div><div v-if="data.data.length" class="reading-list"><article v-for="row in data.data" :key="row.sensor.id" class="channel-row"><component :is="row.sensor.measurementType.nature === 'binary' ? 'div' : 'button'" :type="row.sensor.measurementType.nature === 'binary' ? undefined : 'button'" class="channel-open" :aria-label="row.sensor.measurementType.nature === 'binary' ? row.sensor.name : t('insights.historyFor', { name: row.sensor.name })" @click="row.sensor.measurementType.nature !== 'binary' && openHistory(row.sensor)"><span class="channel-name"><strong><AppIcon :name="measurementNatureIcon(row.sensor.measurementType.nature)" />{{ row.sensor.name }}</strong><small>{{ row.sensor.device.name }} · {{ row.sensor.measurementType.name }}</small><DeviceAssignment :device="row.sensor.device" /></span><span class="channel-value"><strong><ChannelSwitchTag v-if="row.sensor.measurementType.nature === 'binary'" :reading="switchReading(row)" @access-lost="fail" /><template v-else>{{ value(row) }} <small v-if="row.latest">{{ row.latest.unit }}</small></template></strong><span v-if="row.sensor.measurementType.nature !== 'binary'" class="insight-status" :class="row.status">{{ t(`insights.${row.status}`) }}</span><MeasurementQuality v-if="row.latest && row.sensor.measurementType.nature !== 'binary'" :quality="row.latest.quality" /><small v-if="row.latest">{{ formatDateTime(row.latest.observedAt, locale) }}</small></span></component><ListActionButton :icon="prefs.pins.includes(row.sensor.id) ? 'starFilled' : 'star'" :label="t(prefs.pins.includes(row.sensor.id) ? 'insights.unpin' : 'insights.pin')" :aria-pressed="prefs.pins.includes(row.sensor.id)" @click="pin(row.sensor.id)" /></article></div><p v-else class="insight-empty">{{ t('insights.empty') }}</p><CollectionPagination v-model:page-size="pageSize" :page="data.meta.current_page" :last-page="data.meta.last_page" :total="data.meta.total" :disabled="loading" @page="collectionQuery.go($event)" /></section></div><p v-if="data" class="insight-stamp">{{ formatDateTime(data.generatedAt, locale) }}</p>
  <SensorHistory v-if="history" :sensor-id="history.id" :name="history.name" @close="closeHistory" @access-lost="fail" /></template></Card></template>
<style scoped>
.measurement-summary { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--space-3); margin: var(--space-3) 0; align-items: stretch; }
.measurement-summary-single { grid-template-columns: minmax(0, 1fr); }
.measurement-summary .insight-metrics { grid-template-columns: repeat(2, minmax(0, 1fr)); margin: 0; }
@media (max-width: 720px) { .measurement-summary { grid-template-columns: minmax(0, 1fr); } }

h3 { margin: 0; font-size: .95rem; }.reading-list { margin-top: var(--space-2); }.channel-row { display: flex; align-items: center; gap: var(--space-1); border-bottom: 1px solid var(--theme-border); }.channel-open { display: flex; align-items: center; justify-content: space-between; gap: var(--space-2); min-width: 0; width: 100%; border: 0; border-radius: .5rem; background: transparent; padding: var(--space-3) var(--space-1); font: inherit; color: inherit; text-align: left; cursor: pointer; }.channel-open:hover { background: var(--theme-subtle); }.channel-name, .channel-value { display: flex; flex-direction: column; gap: var(--space-1); min-width: 0; }.channel-name strong { font-size: .82rem; overflow-wrap: anywhere; }.channel-name strong { display: flex; align-items: center; gap: var(--space-1-5); }.channel-name strong svg { width: 1rem; height: 1rem; flex-shrink: 0; }.channel-name small, .channel-value > small { color: var(--theme-muted); font-size: .66rem; }.channel-value { text-align: right; align-items: flex-end; flex-shrink: 0; }.channel-value strong { font-size: 1rem; }.channel-value strong small { font-weight: 400; font-size: .7rem; }
@media(max-width: 420px) { .channel-open { align-items: flex-start; flex-direction: column; }.channel-value { align-items: flex-start; text-align: left; } }
.channel-open:not(button) { cursor: default; }
</style>
