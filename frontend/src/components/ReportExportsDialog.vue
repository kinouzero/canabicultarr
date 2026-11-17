<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import { NButton as Button } from 'naive-ui';
import Message from './AppMessage.vue';
import { NProgress as ProgressBar } from 'naive-ui';
import { NTag as Tag } from 'naive-ui';
import AppIcon from './AppIcon.vue';
import ListActionButton from './ListActionButton.vue';
import FormDialog from './FormDialog.vue';
import CollectionTable from './CollectionTable.vue';
import { ApiError } from '../api/auth';
import { reportsApi, type SensorReport } from '../api/reports';
import { reportExportsApi, type MeasurementExport } from '../api/report-exports';
import { formatDateTime } from '../lib/date-time';
import en from '../locales/en/report-exports.json';
import fr from '../locales/fr/report-exports.json';
const props = defineProps<{ report?: SensorReport; culture?: { siteId: string; days: number; sections: string[]; siteName: string } }>();
const emit = defineEmits<{ close: []; accessLost: [cause: ApiError] }>();
const { t, te, locale, mergeLocaleMessage } = useI18n({ useScope: 'global' });
mergeLocaleMessage('en', { reportExports: en }); mergeLocaleMessage('fr', { reportExports: fr });
const id = useId();
const format = ref<'csv' | 'pdf'>(props.culture ? 'pdf' : 'csv'); const rows = ref<MeasurementExport[]>([]); const busy = ref(false); const loading = ref(false); const error = ref(''); const notice = ref('');
let sequence = 0; let controller: AbortController | undefined;
let alive = true; let timer: ReturnType<typeof setTimeout> | undefined; let requestKey: string | null = null;
const pending = computed(() => rows.value.some(row => ['queued', 'processing'].includes(row.status)));
const columns = computed(() => ['format', 'status', 'expires', 'actions'].map(key => ({ key, label: t(`reportExports.columns.${key}`) })));
const filters: Record<string, string> = props.report ? { date_from: props.report.window.from, date_to: props.report.window.to, as_of: props.report.window.asOf, group_by: props.report.window.groupBy } : {};
function date(value: string) { return formatDateTime(value, locale.value); }
function fail(cause: unknown) {
 error.value = cause instanceof ApiError && te(cause.code) ? cause.code : 'reportExports.errors.failed';
 if (cause instanceof ApiError && [401, 403].includes(cause.status)) emit('accessLost', cause);
}
async function reload() {
 clearTimeout(timer); if (loading.value || busy.value) return; loading.value = true;
 const current = ++sequence; const request = new AbortController(); controller = request;
 try { const result = await (props.culture ? reportExportsApi.cultureList(request.signal) : reportExportsApi.list(props.report!.sensor.id, request.signal)); if (alive && current === sequence) rows.value = result; }
 catch (cause) { if (alive && current === sequence) fail(cause); }
 finally { if (alive && current === sequence) { loading.value = false; if (pending.value) timer = setTimeout(() => { void reload(); }, 2000); } }
}
function beginAction() {
 if (busy.value) return false;
 sequence++; controller?.abort(); clearTimeout(timer); loading.value = false; busy.value = true; error.value = '';
 return true;
}
watch(format, () => { requestKey = null; error.value = ''; notice.value = ''; });
async function generate() {
 if (!beginAction()) return; notice.value = '';
 try {
  let direct = !props.culture && format.value === 'csv' && props.report!.statistics.count <= 1000;
  if (direct) {
   try { await reportsApi.export(props.report!.sensor.id, filters); }
   catch (cause) { if (cause instanceof ApiError && cause.code === 'reports.errors.exportTooLarge') direct = false; else throw cause; }
  }
  if (direct) { if (alive) notice.value = 'reportExports.downloaded'; }
  else {
   requestKey ??= crypto.randomUUID();
   const options = { format: format.value, locale: locale.value.startsWith('fr') ? 'fr' : 'en', timezone: Intl.DateTimeFormat().resolvedOptions().timeZone, request_key: requestKey };
   const result = await (props.culture
    ? reportExportsApi.createCulture({ ...options, site_id: props.culture.siteId || null, days: props.culture.days, sections: props.culture.sections })
    : reportExportsApi.create(props.report!.sensor.id, { ...filters, ...options }));
   requestKey = null;
   if (alive) { rows.value = [result, ...rows.value.filter(row => row.id !== result.id)].slice(0, 10); notice.value = 'reportExports.queued'; }
  }
 } catch (cause) { if (cause instanceof ApiError) requestKey = null; if (alive) fail(cause); }
 finally { if (alive) { busy.value = false; await reload(); } }
}
async function download(row: MeasurementExport) {
 if (!beginAction()) return;
 try { await reportExportsApi.download(row); }
 catch (cause) { if (alive) fail(cause); }
 finally { if (alive) { busy.value = false; await reload(); } }
}
async function cancel(row: MeasurementExport) {
 if (!beginAction()) return;
 try { await reportExportsApi.cancel(row.id); }
 catch (cause) { if (alive) fail(cause); }
 finally { if (alive) { busy.value = false; await reload(); } }
}
onMounted(reload); onUnmounted(() => { alive = false; sequence++; controller?.abort(); clearTimeout(timer); });
const selectionHeader = ref<HTMLElement | null>(null);
</script>
<template><FormDialog :visible="true" :title="t(culture ? 'insights.exportTitle' : 'reportExports.title')" :close-label="t('actions.close')" :busy="busy" icon="download" size="wide" @update:visible="!$event && emit('close')" :error="error ? t(error) : ''"><div class="export-content"><p v-if="report" class="export-subject"><strong>{{ report.sensor.name }}</strong> · {{ report.sensor.measurementType.name }} <span v-if="report.sensor.measurementType.unit">({{ report.sensor.measurementType.unit }})</span></p>
   <p v-if="report" class="export-period"><AppIcon name="clock" />{{ date(report.window.from) }} → {{ date(report.window.to) }}</p>
   <p v-if="culture" class="export-subject"><strong>{{ culture.siteName }}</strong> · {{ t(culture.days === 30 ? 'insights.days30' : 'insights.days7') }}</p>
   <Message v-if="notice" type="success" closable role="status">{{ t(notice) }}</Message>
   <div class="export-controls"><div class="field"><label :for="`${id}-format`">{{ t('reportExports.format') }}</label><select :id="`${id}-format`" v-model="format" v-app-select="{ filter: t('selectControl.filter'), empty: t('selectControl.empty'), searchable: false }" :disabled="busy"><option value="csv">CSV</option><option value="pdf">PDF</option></select></div><Button :loading="busy" :disabled="busy || pending" @click="generate" type="primary"><template #icon><AppIcon name="download" /></template><span>{{ t('reportExports.generate') }}</span></Button></div>
   <p class="export-help">{{ t(culture ? 'insights.exportHelp' : 'reportExports.help') }}</p>
   <div v-if="rows.length" class="page-heading"><h3><AppIcon name="download" />{{ t('reportExports.history') }}</h3><div ref="selectionHeader" class="page-actions"></div></div><p v-else-if="!loading" class="export-empty"><AppIcon name="archive" />{{ t('reportExports.empty') }}</p>
   <CollectionTable v-if="rows.length" :selection-target="selectionHeader" :rows="rows" :can-select="row => row.status === 'ready'" :row-name="row => row.format.toUpperCase()" :remove="row => reportExportsApi.cancel(row.id)" :locked="busy" @removed="reload" @access-lost="fail" :columns="columns"><template #format="{ row }"><Tag type="default"><template #icon><AppIcon :name="row.format === 'pdf' ? 'pdf' : 'file'" /></template>{{ row.format.toUpperCase() }}</Tag></template>
    <template #status="{ row }"><Tag :type="row.status === 'ready' ? 'success' : row.status === 'failed' ? 'error' : 'default'">{{ t(`reportExports.statuses.${row.status}`) }}</Tag><ProgressBar v-if="['queued', 'processing'].includes(row.status)" :percentage="row.progress" :aria-label="t('reportExports.progress')"></ProgressBar><small v-if="row.totalRows !== null">{{ t('reportExports.rows', { done: row.processedRows, total: row.totalRows }) }}</small><small v-if="row.status === 'ready' && !row.canDownload">{{ t('reportExports.errors.accessLost') }}</small><small v-if="row.errorCode">{{ t(te(row.errorCode) ? row.errorCode : 'reportExports.errors.failed') }}</small></template>
    <template #expires="{ row }">{{ date(row.expiresAt) }}</template>
    <template #actions="{ row }"><div class="export-actions"><ListActionButton icon="download" v-if="row.status === 'ready' && row.canDownload" :disabled="busy" :label="t('reportExports.download', { format: row.format.toUpperCase() })" :title="t('reportExports.download', { format: row.format.toUpperCase() })" @click="download(row)" /><Button v-if="['queued', 'processing', 'ready'].includes(row.status)" type="error" ghost :disabled="busy" :aria-label="t(row.status === 'ready' ? 'reportExports.remove' : 'reportExports.cancel')" :title="t(row.status === 'ready' ? 'reportExports.remove' : 'reportExports.cancel')" @click="cancel(row)"><template #icon><AppIcon :name="row.status === 'ready' ? 'trash' : 'close'" /></template></Button></div></template></CollectionTable></div>
  <template #footer><Button type="default" :disabled="busy" @click="emit('close')"><AppIcon name="close" /><span>{{ t('actions.close') }}</span></Button></template></FormDialog></template>
<style scoped>
.export-content { display: grid; gap: var(--ui-content-gap); }.export-content p,.export-content h3 { margin: 0; }.export-help,.export-content small { color: var(--theme-muted); font-size: .8rem; }.export-controls { display: flex; align-items: end; justify-content: flex-end; flex-wrap: wrap; gap: var(--ui-grid-gap); }.export-controls .field { display: grid; gap: var(--space-2); flex: 1; min-width: min(12rem, 100%); }.export-controls label { font-size: .8rem; color: var(--theme-muted); }.export-actions { display: flex; align-items: center; gap: var(--space-2); }.export-content small { display: block; }.export-content :is(h3,.export-period,.export-empty) { display: flex; align-items: center; gap: var(--ui-icon-gap); flex-wrap: wrap; }.export-content :is(h3,.export-period,.export-empty) svg { width: 1rem; height: 1rem; flex-shrink: 0; }.export-period { font-size: .78rem; color: var(--theme-muted); font-variant-numeric: tabular-nums; }.export-empty { padding-block: var(--space-4); color: var(--theme-muted); font-size: .8rem; }.export-content h3 { font-size: .95rem; }.n-progress { min-width: 8rem; margin-block: var(--space-2); height: .9rem; }
</style>
