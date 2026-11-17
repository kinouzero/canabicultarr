<script setup lang="ts">
import RecordQrButton from './RecordQrButton.vue';
import { toRef } from 'vue';
import { queryField, useCollectionQuery } from '../lib/collection-query';
import MeasurementQuality from './MeasurementQuality.vue';
import MeasurementOrigin from './MeasurementOrigin.vue';
import provenanceEn from '../locales/en/measurement-provenance.json';
import provenanceFr from '../locales/fr/measurement-provenance.json';
import DeviceAssignment from './DeviceAssignment.vue';
import { useCollectionPageSize } from '../lib/collection-page-size';
import CollectionPagination from './CollectionPagination.vue';
import ResetFilters from './ResetFilters.vue';
import { measurementNatureIcon } from '../lib/measurement-nature-icon';
import { computed, onUnmounted, ref, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { NInput as InputText } from 'naive-ui';
import Message from './AppMessage.vue';
import ReferenceSelect from './ReferenceSelect.vue';
import InsightChart from './InsightChart.vue';
import InsightOptions from './InsightOptions.vue';
import AppIcon from './AppIcon.vue';
import ReportExportsDialog from './ReportExportsDialog.vue';
import ListActionButton from './ListActionButton.vue';
import { ApiError } from '../api/auth';
import { sensorsApi, type Sensor, type ReferenceOption } from '../api/sensors';
import { reportsApi, type SensorReport } from '../api/reports';
import { measurementsApi, type MeasurementPage } from '../api/measurements';
import type { Page } from '../api/types';
import { formatDateTime } from '../lib/date-time';
import { localDateInput, measurementWindow } from '../lib/measurement-window';
import { useInsightPreferences } from '../lib/insight-preferences';
import en from '../locales/en/reports.json';
import fr from '../locales/fr/reports.json';
const pageSize = useCollectionPageSize('sensor-history', 50);
const props = defineProps<{ sensorId: string }>();
const emit = defineEmits<{ accessLost: [cause: ApiError]; loaded: [sensor: Sensor] }>();
const { t, te, locale, mergeLocaleMessage } = useI18n({ useScope: 'global' });
mergeLocaleMessage('en', { measurementProvenance: provenanceEn }); mergeLocaleMessage('fr', { measurementProvenance: provenanceFr });
mergeLocaleMessage('en', { reports: en }); mergeLocaleMessage('fr', { reports: fr });
const id = useId();
const prefs = useInsightPreferences('analysis', { period: '7d', fromDate: '', toDate: '', chart: 'line', group: 'hour', sections: ['summary', 'trend', 'reliability', 'readings'] });
if (!['24h', '7d', '30d', 'custom'].includes(prefs.period)) prefs.period = '7d';
if (!['line', 'bar'].includes(prefs.chart)) prefs.chart = 'line';
if (!['hour', 'day'].includes(prefs.group)) prefs.group = 'hour';
const selectedId = computed(() => props.sensorId);
const compareId = ref(''); const from = ref(prefs.fromDate || localDateInput(new Date(Date.now() - 7 * 86400000))); const to = ref(prefs.toDate || localDateInput(new Date()));
const fromQuery = queryField.localDateTime(from); const toQuery = queryField.localDateTime(to);
const sensors = ref<Sensor[]>([]); const sensor = ref<Sensor | null>(null);
const report = ref<SensorReport | null>(null); const comparison = ref<SensorReport | null>(null); const readings = ref<MeasurementPage | null>(null);
const exportReport = ref<SensorReport | null>(null);
const loading = ref(false); const tableLoading = ref(false); const error = ref('');
let sequence = 0; let tableSequence = 0;
const historyPage = ref(1); let loadedCriteria = '';
const criteria = () => JSON.stringify([selectedId.value, prefs.period, prefs.group, fromQuery.read(), toQuery.read(), compareId.value]);
const sectionOptions = computed(() => ['summary', 'trend', 'reliability', 'readings'].map(id => ({ id, label: t(`insights.${id}`) })));
const shown = (section: string) => prefs.sections.includes(section);
function resetFilters() { prefs.period = '7d'; prefs.group = 'hour'; compareId.value = ''; from.value = localDateInput(new Date(Date.now() - 7 * 86400000)); to.value = localDateInput(new Date()); }
function date(value: string | null) { return value ? formatDateTime(value, locale.value) : '—'; }
function value(input: string | number | null | undefined) { return input == null ? '—' : new Intl.NumberFormat(locale.value, { maximumFractionDigits: sensor.value?.measurementType.decimalPlaces ?? 2 }).format(Number(input)); }
function fail(cause: unknown) {
 const key = cause instanceof ApiError ? Object.values(cause.fields).flat()[0] ?? cause.code : '';
 error.value = /^(auth|reports|measurements)\.(errors|validation)\.[A-Za-z]+$/.test(key) && te(key) ? key : 'auth.errors.requestFailed';
 if (cause instanceof ApiError && [401, 403].includes(cause.status)) { sensor.value = null; report.value = comparison.value = null; readings.value = null; emit('accessLost', cause); }
}
async function sensorPage(search: string, page: number): Promise<Page<ReferenceOption>> {
 const result = await sensorsApi.list(search, '', page);
 sensors.value = [...new Map([...sensors.value, ...result.data].map(row => [row.id, row])).values()];
 return { ...result, data: result.data.map(row => ({ id: row.id, name: row.name, detail: [row.device.name, row.measurementType.unit].filter(Boolean).join(' · ') })) };
}
function compatible(first: Sensor, second: Sensor) {
 const a = first.measurementType; const b = second.measurementType;
 return !!a.nature && a.nature === b.nature && a.unit === b.unit && (a.nature !== 'other' || a.id === b.id);
}
async function comparisonPage(search: string, page: number): Promise<Page<ReferenceOption>> {
 const result = await sensorPage(search, page);
 return { ...result, data: result.data.filter(row => { const candidate = sensors.value.find(s => s.id === row.id); return !!candidate && !!sensor.value && candidate.id !== selectedId.value && compatible(candidate, sensor.value); }) };
}

async function load() {
 const requestedCriteria = criteria();
 const current = ++sequence; tableSequence++; loading.value = false; tableLoading.value = false;
 report.value = comparison.value = null; readings.value = null; error.value = '';
 if (!selectedId.value) { sensor.value = null; return; }
 const query = measurementWindow(prefs.period, fromQuery.read(), toQuery.read()); if (!query) { error.value = 'insights.rangeInvalid'; return; }
 query.group_by = prefs.group;
 loading.value = true;
 try {
  const [result, samples] = await Promise.all([reportsApi.show(selectedId.value, query), measurementsApi.readings(selectedId.value, { per_page: String(pageSize.value), date_from: query.date_from!, date_to: query.date_to! }, historyPage.value)]);
  if (current !== sequence) return;
  sensor.value = result.sensor; emit('loaded', result.sensor); report.value = result; readings.value = samples; loadedCriteria = requestedCriteria;
  if (compareId.value) {
   const other = await reportsApi.show(compareId.value, { date_from: result.window.from, date_to: result.window.to, as_of: result.window.asOf, group_by: result.window.groupBy });
   if (current !== sequence) return;
   if (compatible(other.sensor, result.sensor)) comparison.value = other;
  }
 } catch (cause) { if (current === sequence) fail(cause); }
 finally { if (current === sequence) loading.value = false; }
}
async function page(number: number) {
 if (!report.value) return;
 const current = ++tableSequence; const parent = sequence; tableLoading.value = true;
 try {
  const result = await measurementsApi.readings(selectedId.value, { per_page: String(pageSize.value), date_from: report.value.window.from, date_to: report.value.window.to }, number);
  if (current === tableSequence && parent === sequence) readings.value = result;
 } catch (cause) { if (current === tableSequence && parent === sequence) fail(cause); }
 finally { if (current === tableSequence && parent === sequence) tableLoading.value = false; }
}
function download() { if (report.value && !loading.value) exportReport.value = report.value; }

const chartLabels = computed(() => report.value?.buckets.map(bucket => date(bucket.startAt)) ?? []);
const series = computed(() => {
 const result = report.value ? [{ label: report.value.sensor.name, values: report.value.buckets.map(bucket => bucket.mean === null ? null : Number(bucket.mean)) }] : [];
 if (comparison.value) result.push({ label: comparison.value.sensor.name, values: comparison.value.buckets.map(bucket => bucket.mean === null ? null : Number(bucket.mean)) });
 return result;
});
const change = computed(() => report.value?.statistics.first && report.value.statistics.last ? Number(report.value.statistics.last.value) - Number(report.value.statistics.first.value) : null);
const populated = computed(() => !report.value?.buckets.length ? 0 : Math.round(report.value.buckets.filter(row => row.count > 0).length / report.value.buckets.length * 100));
watch([from, to], () => { prefs.fromDate = from.value; prefs.toDate = to.value; });
const historyQuery = useCollectionQuery({ prefix: 'history.', presentationKeys: ['chart'], fields: { period: queryField.choice(toRef(prefs, 'period'), ['24h', '7d', '30d', 'custom']), group: queryField.choice(toRef(prefs, 'group'), ['hour', 'day']), chart: queryField.choice(toRef(prefs, 'chart'), ['line', 'bar']), from: fromQuery, to: toQuery, compare: queryField.identifier(compareId) }, page: historyPage, pageSize, load: target => { if (!report.value || loadedCriteria !== criteria()) return load(); if (readings.value?.meta.current_page !== target || readings.value.meta.per_page !== pageSize.value) return page(target); } });
watch(selectedId, () => { sensor.value = sensors.value.find(row => row.id === selectedId.value) ?? null; void load(); }, { immediate: true });
onUnmounted(() => { sequence++; tableSequence++; });


</script>
<template><section class="insight-workspace" data-testid="sensor-analysis" :aria-busy="loading"><div class="insight-section-heading"><p v-if="sensor" class="insight-help">{{ sensor.device.name }} · {{ sensor.measurementType.name }}<DeviceAssignment :device="sensor.device" /></p><div v-else />
   <div class="insight-actions sensor-history-actions"><RecordQrButton v-if="sensor && sensor.id === selectedId" :record="{ type: 'sensor', id: sensor.id, name: sensor.name }" :disabled="loading" />
    <InsightOptions v-model="prefs.sections" :options="sectionOptions" />
    <ListActionButton icon="download" :label="t('reports.download')" :disabled="!report || loading" @click="download" />
    <ListActionButton icon="refresh" :label="t('insights.refresh')" :loading="loading" :disabled="!selectedId" @click="load" /></div></div>
  <Message v-if="error" type="error" closable role="alert">{{ t(error) }}</Message>
  <div class="search-filters sensor-analysis-filters"><div class="sensor-filter-row"><div class="filter-field"><label :for="`${id}-period`">{{ t('insights.period') }}</label><select :id="`${id}-period`" v-model="prefs.period" v-app-select="{ filter: t('selectControl.filter'), empty: t('selectControl.empty') }"><option v-for="period in ['24h','7d','30d','custom']" :key="period" :value="period">{{ t(`insights.${period}`) }}</option></select></div>
   <div class="filter-field"><label :for="`${id}-group`">{{ t('reports.group') }}</label><select :id="`${id}-group`" v-model="prefs.group" v-app-select="{ filter: t('selectControl.filter'), empty: t('selectControl.empty') }"><option value="hour">{{ t('reports.hour') }}</option><option value="day">{{ t('reports.day') }}</option></select></div></div>
   <ReferenceSelect v-if="sensor" :key="`${sensor.id}:${sensor.measurementType.unit}`" v-model="compareId" :label="t('insights.compare')" :placeholder="t('insights.noComparison')" :fetch-page="comparisonPage" @access-lost="emit('accessLost', $event)" />
   <div v-if="prefs.period === 'custom'" class="sensor-filter-row sensor-date-row"><div class="filter-field"><label :for="`${id}-from`">{{ t('reports.from') }}</label><InputText placeholder="" v-model:value="from" :input-props="{ 'id': `${id}-from`, type: 'datetime-local' }"></InputText></div>
   <div class="filter-field"><label :for="`${id}-to`">{{ t('reports.to') }}</label><InputText placeholder="" v-model:value="to" :input-props="{ 'id': `${id}-to`, type: 'datetime-local' }"></InputText></div></div>
  <ResetFilters @reset="resetFilters" /></div>

  <p v-if="sensor" class="insight-help">{{ t('insights.comparisonHelp') }}</p><p v-if="loading" role="status" class="insight-help">{{ t('insights.loading') }}</p><p v-if="!selectedId" class="insight-empty">{{ t('insights.chooseChannel') }}</p>
  <template v-if="report"><p class="insight-stamp">{{ date(report.window.from) }} → {{ date(report.window.to) }} · {{ t('reports.asOf') }} {{ date(report.window.asOf) }}</p>
   <dl v-if="shown('summary')" class="insight-metrics"><div class="insight-metric"><dt><AppIcon :name="measurementNatureIcon(sensor?.measurementType.nature)" />{{ t('insights.latest') }}</dt><dd>{{ value(readings?.summary.latest?.value) }} <small v-if="readings?.summary.latest">{{ sensor?.measurementType.unit }}</small></dd><small>{{ date(readings?.summary.latest?.observedAt ?? null) }}</small><MeasurementQuality v-if="readings?.summary.latest" :quality="readings.summary.latest.quality" /><span v-if="readings" class="insight-status" :class="readings.summary.status">{{ t(`insights.${readings.summary.status}`) }}</span></div>
    <div v-for="metric in ['mean','minimum','maximum'] as const" :key="metric" class="insight-metric"><dt>{{ t(`reports.${metric}`) }}</dt><dd :data-testid="`report-${metric}`">{{ value(report.statistics[metric]) }} <small v-if="report.statistics.count">{{ sensor?.measurementType.unit }}</small></dd></div>
    <div class="insight-metric"><dt>{{ t('reports.count') }}</dt><dd data-testid="report-count">{{ report.statistics.count.toLocaleString(locale) }}</dd></div><div class="insight-metric"><dt>{{ t('insights.change') }}</dt><dd>{{ change !== null && change > 0 ? '+' : '' }}{{ value(change) }} <small v-if="change !== null">{{ sensor?.measurementType.unit }}</small></dd></div></dl>
   <div class="insight-grid"><section v-if="shown('trend')" class="insight-panel insight-wide"><div class="insight-section-heading"><h3>{{ t('insights.trend') }}</h3><select v-model="prefs.chart" v-app-select="{ filter: t('selectControl.filter'), empty: t('selectControl.empty') }" :aria-label="t('insights.chartType')" class="chart-kind"><option value="line">{{ t('insights.line') }}</option><option value="bar">{{ t('insights.bar') }}</option></select></div><InsightChart v-if="report.statistics.count || comparison?.statistics.count" :label="t('insights.trendHelp')" :labels="chartLabels" :series="series" :type="prefs.chart === 'bar' ? 'bar' : 'line'" :unit="sensor?.measurementType.unit" /><p v-else class="insight-empty">{{ t('insights.emptyTrend') }}</p></section>
    <section v-if="shown('reliability')" class="insight-panel" :aria-label="t('insights.reliability')"><h3>{{ t('insights.reliability') }}</h3><dl class="insight-metrics"><div class="insight-metric"><dt>{{ t('insights.populated') }}</dt><dd>{{ populated }}%</dd></div><div class="insight-metric"><dt>{{ t('reports.largestGap') }}</dt><dd>{{ report.statistics.largestGapSeconds === null ? '—' : t('reports.seconds', { count: report.statistics.largestGapSeconds }) }}</dd></div></dl><p class="insight-help">{{ t('insights.intervalHelp') }}</p><p v-if="report.statistics.gapCount !== null" class="insight-help">{{ t('reports.gapCount', { count: report.statistics.gapCount, seconds: report.statistics.gapThresholdSeconds }) }}</p><p v-else class="insight-help">{{ t('reports.noInterval') }}</p><p class="insight-help">{{ t('insights.gapHelp') }}</p><dl class="insight-metrics"><div v-for="metric in ['overlapping','opened','resolved','activeAtEnd'] as const" :key="metric" class="insight-metric"><dt>{{ t(`reports.alertMetrics.${metric}`) }}</dt><dd>{{ report.alerts[metric] }}</dd></div></dl></section>
    <section v-if="shown('readings')" class="insight-panel" :class="{ 'insight-wide': !shown('reliability') }"><h3>{{ t('insights.readings') }}</h3><div v-if="readings?.data.length" class="insight-table-scroll"><table class="insight-table"><thead><tr><th>{{ t('insights.observed') }}</th><th>{{ sensor?.measurementType.unit ?? t('insights.latest') }}</th><th>{{ t('measurementProvenance.title') }}</th></tr></thead><tbody><tr v-for="reading in readings.data" :key="reading.id"><td>{{ date(reading.observedAt) }}</td><td>{{ value(reading.value) }} {{ reading.unit }}</td><td><MeasurementOrigin :reading="reading" /></td></tr></tbody></table></div><p v-else class="insight-empty">{{ t('insights.emptyTrend') }}</p><CollectionPagination v-model:page-size="pageSize" v-if="readings" :page="readings.meta.current_page" :last-page="readings.meta.last_page" :total="readings.meta.total" :disabled="tableLoading" :label="t('reports.pagination')" :previous-label="t('reports.previous')" :next-label="t('reports.next')" @page="historyQuery.go($event)" /></section></div></template>
  <ReportExportsDialog v-if="exportReport" :report="exportReport" @close="exportReport = null" @access-lost="fail" /></section></template>
<style scoped>.chart-kind { width: 9rem; } h3 { font-size: .95rem; margin: 0; }</style>

<style scoped>
.sensor-analysis-filters > :is(.sensor-filter-row, .reference-select) { grid-column: 1 / -1; }
.sensor-filter-row { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); align-items: end; gap: var(--space-3); min-width: 0; }
.sensor-date-row { grid-template-columns: repeat(auto-fit, minmax(min(100%, 14rem), 1fr)); }
.sensor-history-actions { flex-wrap: nowrap; flex-shrink: 0; margin-inline-start: auto; }
</style>
