<script setup lang="ts">
import { alertTabs } from '../../tab-paths';
import { useFormTabs } from '../../lib/form-tabs';
import AppSwitch from '../../components/AppSwitch.vue';
import AppTabs from '../../components/AppTabs.vue';
import { toRef } from 'vue';
import { usePathChoice } from '../../lib/route-query';
import DisclosureSummary from '../../components/DisclosureSummary.vue';
import DiscardChangesDialog from '../../components/DiscardChangesDialog.vue';
import { useUnsavedChanges } from '../../lib/unsaved-changes';
import { useFieldErrors } from '../../lib/field-errors';
import { useCollectionPageSize } from '../../lib/collection-page-size';
import CollectionPagination from '../../components/CollectionPagination.vue';
import { useRememberDisclosure } from '../../lib/disclosure-preferences';
import ResetFilters from '../../components/ResetFilters.vue';
import { useCollectionSort, sortOptions } from '../../lib/collection-sorting';
import SortSelect from '../../components/SortSelect.vue';
import { NTabPane } from 'naive-ui';




import PageTitle from '../../components/PageTitle.vue';
import ResourceCard from '../../components/ResourceCard.vue';
import { NTag as Tag } from 'naive-ui';
import { queryField, useCollectionQuery } from '../../lib/collection-query';
import AppIcon from '../../components/AppIcon.vue';
import ListActionButton from '../../components/ListActionButton.vue';
import { formatDateTime } from '../../lib/date-time';
import FormDialog from '../../components/FormDialog.vue';
import { computed, useId, onMounted, onUnmounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { NButton as Button } from 'naive-ui';
import { NCard as Card } from 'naive-ui';
import { NInput as InputText } from 'naive-ui';
import Message from '../../components/AppMessage.vue';
import ReferenceSelect from '../../components/ReferenceSelect.vue';
import en from '../../locales/en/alerts.json';
import fr from '../../locales/fr/alerts.json';
import { ApiError } from '../../api/auth';
import type { Page } from '../../api/types';
import { sensorsApi, type Sensor, type ReferenceOption } from '../../api/sensors';
import { alertsApi, type AlertRule, type AlertRuleInput, type AlertEpisode, type AlertKind, type AlertSeverity } from '../../api/alerts';
const pageSize = useCollectionPageSize('alerts');
const rulePageSize = useCollectionPageSize('alert-rules');
const vRememberDisclosure = useRememberDisclosure('MonitoringAlerts');
const sort = useCollectionSort('alert-rules', sortOptions.common);
const props = defineProps<{ embedded?: boolean; canManage: boolean }>(); const emit = defineEmits<{ close: []; accessLost: [cause: ApiError] }>();
const { t, te, locale, mergeLocaleMessage } = useI18n({ useScope: 'global' }); mergeLocaleMessage('en', { alerts: en }); mergeLocaleMessage('fr', { alerts: fr });
const view = ref<'episodes' | 'rules'>('episodes');
usePathChoice(view, alertTabs); const error = ref(''); const success = ref(''); const loading = ref(false); const busy = ref(false);
const episodes = ref<AlertEpisode[]>([]); const rules = ref<AlertRule[]>([]); const page = ref(1); const lastPage = ref(1); const total = ref(0); const rulePage = ref(1); const ruleLast = ref(1); const ruleTotal = ref(0);
const episodeSort = useCollectionSort('alerts', sortOptions.alerts);
const filters = reactive({ state: 'active', severity: '', acknowledged: '', sort: episodeSort }); const search = ref('');
const formOpen = ref(false); const editing = ref<AlertRule | null>(null); const selectedSensor = ref<Sensor | null>(null); const options = ref<Sensor[]>([]);
const form = reactive({ name: '', kind: 'threshold' as AlertKind, severity: 'warning' as AlertSeverity, minimum: '', maximum: '', maxAge: '600', delay: '300', enabled: true }); const severities: AlertSeverity[] = ['info', 'warning', 'critical'];
let episodeSequence = 0; let ruleSequence = 0; let alive = true;
const baseline = ref(''); const conflict = ref(false);
const snapshot = () => JSON.stringify([form, selectedSensor.value?.id ?? null]);
const dirty = computed(() => busy.value || formOpen.value && snapshot() !== baseline.value);
const unsaved = useUnsavedChanges(dirty, busy, { onNavigate: () => { formOpen.value = false; } });
const editorTabs = useFormTabs(formOpen, {"minimum": "conditions", "maximum": "conditions", "max_age_seconds": "conditions", "delay_seconds": "conditions"});
const editorTab = editorTabs.tab;
const validation = useFieldErrors(() => ({
 name: { id: 'alert-rule-name', message: 'alerts.validation.nameInvalid' },
 sensor_id: { id: 'alert-rule-sensor', message: 'alerts.validation.sensorInvalid' },
 kind: { id: 'alert-rule-kind', message: 'alerts.validation.kindInvalid' },
 severity: { id: 'alert-rule-severity', message: 'alerts.validation.severityInvalid' },
 minimum: { id: 'alert-rule-minimum', message: 'alerts.validation.boundInvalid' },
 maximum: { id: 'alert-rule-maximum', message: 'alerts.validation.boundInvalid' },
 max_age_seconds: { id: 'alert-rule-age', message: 'alerts.validation.intervalInvalid' },
 delay_seconds: { id: 'alert-rule-delay', message: 'alerts.validation.intervalInvalid' },
 is_enabled: { id: 'alert-rule-enabled', message: 'alerts.validation.stateInvalid' },
}), () => ({ name: form.name, sensor_id: selectedSensor.value?.id, kind: form.kind, severity: form.severity,
 minimum: [form.minimum, form.kind, form.maximum], maximum: [form.maximum, form.kind, form.minimum],
 max_age_seconds: [form.maxAge, form.kind], delay_seconds: [form.delay, form.kind], is_enabled: form.enabled }), editorTabs.reveal);
const fields = validation.fields;

async function closeForm() {
 if (!await unsaved.confirmDiscard()) return false;
 formOpen.value = false; error.value = ''; validation.clear(); return true;
}
async function leave() { if (await closeForm()) emit('close'); }
function key(value: string) { return /^(auth|alerts)\.(errors|validation)\.[A-Za-z]+$/.test(value) && te(value) ? value : 'auth.errors.requestFailed'; }
function showError(cause: unknown) {
 if (!alive) return;
 error.value = cause instanceof ApiError && cause.status === 409 ? 'alerts.errors.conflict' : key(cause instanceof ApiError ? cause.code : 'auth.errors.requestFailed');
 if (cause instanceof ApiError && cause.status === 409 && formOpen.value) conflict.value = true;
 if (cause instanceof ApiError && cause.status === 422 && formOpen.value) {
  error.value = 'auth.errors.validationFailed'; validation.capture(cause);
  for (const [field, advice] of [['minimum', 'alerts.validation.boundsRequired'], ['maximum', 'alerts.validation.boundsOrder']] as const) {
   if (cause.fields[field]?.includes(advice)) validation.set(field, advice);
  }
  if (cause.fields.version) { conflict.value = true; error.value = 'alerts.errors.conflict'; }
  void validation.focus();
 }
 if (cause instanceof ApiError && [401, 403].includes(cause.status)) {
  episodes.value = []; rules.value = []; formOpen.value = false; emit('accessLost', cause);
 }
}
function date(value: string | null) { return value ? formatDateTime(value, locale.value) : t('alerts.never'); }
function number(value: string | null) { if (value === null) return t('alerts.noBound'); const [whole, fraction] = value.split('.'); const decimal = fraction?.replace(/0+$/, ''); return whole + (decimal ? `.${decimal}` : ''); }
async function loadEpisodes(target = 1) {
 const current = ++episodeSequence; loading.value = true;
 try { const result = await alertsApi.list({ per_page: String(pageSize.value), sort: filters.sort, state: filters.state, severity: filters.severity, ...(filters.acknowledged ? { acknowledged: filters.acknowledged } : {}) }, target); if (current !== episodeSequence) return; episodes.value = result.data; page.value = result.meta.current_page; lastPage.value = result.meta.last_page; total.value = result.meta.total; }
 catch (cause) { if (current === episodeSequence) showError(cause); } finally { if (current === episodeSequence) loading.value = false; }
}
async function loadRules(target = 1) { const current = ++ruleSequence; loading.value = true; try { const result = await alertsApi.rules(search.value.trim(), target, sort.value, rulePageSize.value); if (current !== ruleSequence) return; rules.value = result.data; rulePage.value = result.meta.current_page; ruleLast.value = result.meta.last_page; ruleTotal.value = result.meta.total; } catch (cause) { if (current === ruleSequence) showError(cause); } finally { if (current === ruleSequence) loading.value = false; } }
async function changeView(next: 'episodes' | 'rules') {
 if (next === view.value || !await closeForm()) return;
 episodeSequence++; ruleSequence++; view.value = next; error.value = success.value = '';

}
function fillForm(rule: AlertRule | null) {
 editing.value = rule; selectedSensor.value = rule?.sensor ?? null;
 Object.assign(form, { name: rule?.name ?? '', kind: rule?.kind ?? 'threshold', severity: rule?.severity ?? 'warning', minimum: rule?.minimum ?? '', maximum: rule?.maximum ?? '', maxAge: String(rule?.maxAgeSeconds ?? 600), delay: String(rule?.delaySeconds ?? 300), enabled: rule?.isEnabled ?? true });
 validation.clear(); error.value = success.value = ''; conflict.value = false; baseline.value = snapshot(); formOpen.value = true;
}
async function open(rule: AlertRule | null = null) {
 if (!props.canManage || loading.value || !await unsaved.confirmDiscard()) return;
 fillForm(rule);
}
async function sensorPage(query: string, target: number): Promise<Page<ReferenceOption>> { const result = await sensorsApi.list(query, '', target); options.value = [...new Map([...options.value, ...result.data].map(row => [row.id, row])).values()]; return { ...result, data: result.data.map(sensor => ({ id: sensor.id, name: sensor.name, detail: [sensor.device.name, sensor.measurementType.unit].filter(Boolean).join(' · ') })) }; }
function chooseSensor(id: string) { selectedSensor.value = options.value.find(sensor => sensor.id === id) ?? null; }
async function save() {
 if (busy.value || conflict.value || !formOpen.value || !props.canManage) return;
 error.value = success.value = ''; validation.clear();
 if (!form.name.trim() || form.name.length > 120) validation.set('name');
 if (!selectedSensor.value) validation.set('sensor_id');
 if (!['threshold', 'missing'].includes(form.kind)) validation.set('kind');
 if (!severities.includes(form.severity)) validation.set('severity');
 const interval = form.kind === 'threshold' ? form.maxAge : form.delay;
 if (!/^\d+$/.test(interval) || Number(interval) < 120 || Number(interval) > 86400) validation.set(form.kind === 'threshold' ? 'max_age_seconds' : 'delay_seconds');
 if (form.kind === 'threshold' && !form.minimum && !form.maximum) validation.set('minimum', 'alerts.validation.boundsRequired');
 if (Object.keys(fields.value).length) { error.value = 'auth.errors.validationFailed'; void validation.focus(); return; }
 busy.value = true;
 try {
  const input: AlertRuleInput = { name: form.name, sensor_id: selectedSensor.value!.id, kind: form.kind, severity: form.severity, is_enabled: form.enabled, ...(form.kind === 'threshold' ? { minimum: form.minimum || null, maximum: form.maximum || null, max_age_seconds: Number(form.maxAge) } : { delay_seconds: Number(form.delay) }) };
  if (editing.value) { input.version = editing.value.version; await alertsApi.update(editing.value.id, input); } else await alertsApi.create(input);
  if (!alive) return;
  formOpen.value = false; conflict.value = false; success.value = 'alerts.ruleSaved'; await loadRules(editing.value ? rulePage.value : 1);
 } catch (cause) { showError(cause); } finally { if (alive) busy.value = false; }
}
async function reloadEdit() {
 if (!editing.value || !await unsaved.confirmDiscard()) return;
 const id = editing.value.id; busy.value = true;
 try { const rule = await alertsApi.show(id); if (alive) fillForm(rule); }
 catch (cause) { showError(cause); }
 finally { if (alive) busy.value = false; }
}
async function evaluate(rule: AlertRule) { busy.value = true; error.value = success.value = ''; try { await alertsApi.evaluate(rule.id); await loadRules(rulePage.value); success.value = 'alerts.evaluated'; } catch (cause) { showError(cause); } finally { busy.value = false; } }
async function acknowledge(episode: AlertEpisode) { busy.value = true; error.value = success.value = ''; try { await alertsApi.acknowledge(episode.id); await loadEpisodes(page.value); success.value = 'alerts.acknowledged'; } catch (cause) { showError(cause); } finally { busy.value = false; } }
onMounted(() => view.value === 'episodes' ? loadEpisodes(page.value) : loadRules(rulePage.value));
onUnmounted(() => { alive = false; episodeSequence++; ruleSequence++; });
const dialogId = useId();
const ruleQuery = useCollectionQuery({ paths: Object.values(alertTabs), enabled: () => view.value === 'rules', prefix: 'rules.', fields: { q: queryField.text(search), sort: queryField.choice(sort, sortOptions.common) }, page: rulePage, pageSize: rulePageSize, load: target => loadRules(target) });
const applyFilters = ruleQuery.apply;
const episodeQuery = useCollectionQuery({ paths: Object.values(alertTabs), enabled: () => view.value === 'episodes', fields: { state: queryField.choice(toRef(filters, 'state'), ['active', 'resolved', 'all']), severity: queryField.choice(toRef(filters, 'severity'), ['', ...severities]), acknowledged: queryField.choice(toRef(filters, 'acknowledged'), ['', '0', '1']), sort: queryField.choice(toRef(filters, 'sort'), sortOptions.alerts) }, page, pageSize, load: target => loadEpisodes(target) });
const applyEpisodeFilters = episodeQuery.apply;



</script>
<template><Card class="operational-page alerts-card"><template #header><div class="card-heading-block"><div class="page-heading"><div class="heading"><PageTitle icon="bell">{{ t('alerts.title') }}</PageTitle><Button v-if="!embedded" :aria-label="t('alerts.back')" type="default" :disabled="busy" @click="leave" :title="t('alerts.back')" class="list-action-button"><template #icon><AppIcon name="arrowLeft" /></template></Button></div><div class="page-actions"><ListActionButton v-if="view === 'rules' && canManage" icon="plus" :label="t('alerts.add')" :disabled="busy || loading" @click="open()" /><ListActionButton icon="refresh" :label="t('collection.refresh')" :disabled="busy || loading" @click="view === 'rules' ? loadRules(rulePage) : loadEpisodes(page)" /></div></div><div class="card-subtitle">{{ t('alerts.description') }}</div></div></template>
  <template #default><DiscardChangesDialog v-if="unsaved.pending.value" @decision="unsaved.finish" />
   <Message v-if="error && !(formOpen)" type="error" closable role="alert">{{ t(error) }}</Message><Button v-if="!(formOpen) && (error === 'alerts.errors.conflict' && editing)" :aria-label="t('alerts.reloadCurrent')" :loading="busy" @click="reloadEdit" type="primary"><template #icon><AppIcon name="refresh" /></template><span>{{ t('actions.refresh') }}</span></Button><Message v-if="success" type="success" closable role="status">{{ t(success) }}</Message>
   <AppTabs :value="view" @update:value="value => changeView(value === 'rules' ? 'rules' : 'episodes')" animated><NTabPane name="episodes" :disabled="busy" display-directive="show:lazy"><template #tab><AppIcon name="bell" />{{ t('alerts.episodes') }}</template><form class="filters search-filters" @submit.prevent="applyEpisodeFilters"><div class="filter-field"><label for="alerts-state">{{ t('alerts.state') }}</label><select v-app-select="{ filter: $t('selectControl.filter'), empty: $t('selectControl.empty') }" id="alerts-state" v-model="filters.state" :disabled="busy"><option v-for="state in ['active','resolved','all']" :key="state" :value="state">{{ t(`alerts.states.${state}`) }}</option></select></div><div class="filter-field"><label for="alerts-severity">{{ t('alerts.severity') }}</label><select v-app-select="{ filter: $t('selectControl.filter'), empty: $t('selectControl.empty') }" id="alerts-severity" v-model="filters.severity" :disabled="busy"><option value="">{{ t('alerts.allSeverities') }}</option><option v-for="severity in severities" :key="severity" :value="severity">{{ t(`alerts.severities.${severity}`) }}</option></select></div><div class="filter-field"><label for="alerts-acknowledged">{{ t('alerts.ackState') }}</label><select v-app-select="{ filter: $t('selectControl.filter'), empty: $t('selectControl.empty') }" id="alerts-acknowledged" v-model="filters.acknowledged" :disabled="busy"><option value="">{{ t('alerts.all') }}</option><option value="0">{{ t('alerts.unacknowledged') }}</option><option value="1">{{ t('alerts.acknowledgedLabel') }}</option></select></div><SortSelect v-model="filters.sort" :options="sortOptions.alerts" /><ResetFilters @reset="Object.assign(filters, { state: 'active', severity: '', acknowledged: '', sort: 'occurred_desc' })" /></form>
    <p v-if="loading" role="status">{{ t('alerts.loading') }}</p><p v-else-if="!episodes.length">{{ t('alerts.noEpisodes') }}</p>
    <ul class="episode-list operational-list"><ResourceCard v-for="episode in episodes" :key="episode.id" icon="bell" :eyebrow="episode.rule.sensor.deviceName" :tone="!episode.resolvedAt ? 'warning' : 'success'"><template #title>{{ episode.rule.name }}</template><template #badge><Tag :type="episode.severity === 'critical' ? 'error' : episode.severity === 'warning' ? 'warning' : 'info'">{{ t(`alerts.severities.${episode.severity}`) }}</Tag></template>
     <dl class="resource-facts"><div class="resource-wide"><dt>{{ t('alerts.sensor') }}</dt><dd>{{ episode.rule.sensor.name }}</dd></div><div><dt>{{ t('alerts.state') }}</dt><dd>{{ t(episode.resolvedAt ? 'alerts.resolved' : 'alerts.active') }}</dd></div><div><dt>{{ t('alerts.lastResult') }}</dt><dd>{{ t(`alerts.reasons.${episode.reason}`) }}</dd></div><div class="resource-wide"><dt>{{ t(episode.rule.kind === 'threshold' ? 'alerts.bounds' : 'alerts.delay') }}</dt><dd v-if="episode.rule.kind === 'threshold'">{{ number(episode.rule.minimum) }} / {{ number(episode.rule.maximum) }} {{ episode.rule.sensor.unit }}</dd><dd v-else>{{ t('alerts.delayValue', { seconds: episode.rule.delaySeconds }) }}</dd></div><div><dt>{{ t('alerts.opened') }}</dt><dd>{{ date(episode.openedAt) }}</dd></div><div><dt>{{ t('alerts.lastDetected') }}</dt><dd>{{ date(episode.lastDetectedAt) }}</dd></div></dl>
     <details v-remember-disclosure="episode.id" class="resource-note"><DisclosureSummary :level="3" icon="sensor">{{ t('alerts.observations') }}</DisclosureSummary><p v-if="episode.firstObservation">{{ t('alerts.firstValue') }}: {{ number(episode.firstObservation.value) }} {{ episode.firstObservation.unit }} · {{ date(episode.firstObservation.observedAt) }}</p><p v-if="episode.lastObservation">{{ t('alerts.lastValue') }}: {{ number(episode.lastObservation.value) }} {{ episode.lastObservation.unit }} · {{ date(episode.lastObservation.observedAt) }}</p><p v-if="episode.resolutionObservation">{{ t('alerts.recoveryValue') }}: {{ number(episode.resolutionObservation.value) }} {{ episode.resolutionObservation.unit }} · {{ date(episode.resolutionObservation.observedAt) }}</p><p v-if="!episode.firstObservation && !episode.lastObservation">{{ t('alerts.noObservation') }}</p></details>
     <p v-if="episode.resolvedAt">{{ t('alerts.resolved') }}: {{ date(episode.resolvedAt) }} · {{ t(`alerts.resolutions.${episode.resolution}`) }}</p><p v-if="episode.acknowledgedAt">{{ t('alerts.ackBy', { name: episode.acknowledger?.name ?? t('alerts.accountUnavailable'), date: date(episode.acknowledgedAt) }) }}</p>
     <template v-if="!episode.acknowledgedAt" #actions><Button :aria-label="t('alerts.acknowledgeNamed', { name: episode.rule.name })" :disabled="busy" @click="acknowledge(episode)" type="primary"><template #icon><AppIcon name="check" /></template><span>{{ t('alerts.acknowledge') }}</span></Button></template></ResourceCard></ul>
    <CollectionPagination v-model:page-size="pageSize" :page="page" :last-page="lastPage" :total="total" :disabled="busy || loading" :label="t('alerts.pagination')" :previous-label="t('alerts.previous')" :next-label="t('alerts.next')" @page="episodeQuery.go($event)" /></NTabPane><NTabPane name="rules" :disabled="busy" display-directive="show:lazy"><template #tab><AppIcon name="settings" />{{ t('alerts.rules') }}</template><form class="filters search-filters" @submit.prevent="applyFilters"><div class="filter-primary"><div class="filter-field"><label for="alert-rules-search">{{ t('alerts.search') }}</label><InputText placeholder="" v-model:value="search" maxlength="120" :disabled="busy" :input-props="{ 'id': 'alert-rules-search' }"></InputText></div>
     <SortSelect v-model="sort" :options="sortOptions.common" /></div>
    <ResetFilters @reset="search = ''; sort = 'name_asc'" /></form>
    <p>{{ t(canManage ? 'alerts.ruleHelp' : 'alerts.readHelp') }}</p>
    <FormDialog icon="bell" v-if="formOpen" :visible="true" :title="t(editing ? 'alerts.editTitle' : 'alerts.createTitle')" :close-label="t('alerts.cancel')" :busy="busy" :error="error ? t(error) : ''" @update:visible="!$event && closeForm()"><template #feedback><Button v-if="conflict && editing" :aria-label="t('alerts.reloadCurrent')" :loading="busy" @click="reloadEdit" type="primary"><template #icon><AppIcon name="refresh" /></template><span>{{ t('actions.refresh') }}</span></Button></template>
<form :id="`${dialogId}-0`" class="dialog-form" @submit.prevent="save" novalidate><AppTabs v-model:value="editorTab" :aria-label="t('actions.edit')"><NTabPane name="general" display-directive="show"><template #tab><AppIcon name="edit" />{{ t('editorTabs.general') }}</template><div class="dialog-form"><div class="field"><label for="alert-rule-name">{{ t('alerts.name') }}</label><InputText placeholder="" v-bind="validation.attrs('name')" v-model:value="form.name" maxlength="120" :disabled="busy" :status="!!fields.name ? 'error' : undefined" :input-props="{ ...validation.attrs('name'), 'id': 'alert-rule-name' }"></InputText><small v-if="fields.name" id="alert-rule-name-error" class="field-error" role="alert">{{ t(fields.name) }}</small></div><div><ReferenceSelect id="alert-rule-sensor" :model-value="selectedSensor?.id ?? ''" :selected="selectedSensor ? { id: selectedSensor.id, name: selectedSensor.name, detail: selectedSensor.device.name } : null" :label="t('alerts.sensor')" :fetch-page="sensorPage" :disabled="busy || !!editing" :invalid="!!fields.sensor_id" :described-by="fields.sensor_id ? 'alert-rule-sensor-error' : undefined" @select="chooseSensor" @access-lost="emit('accessLost', $event)" /><small v-if="fields.sensor_id" id="alert-rule-sensor-error" class="field-error" role="alert">{{ t(fields.sensor_id) }}</small></div>
     <div class="grid"><div class="field"><label for="alert-rule-kind">{{ t('alerts.kind') }}</label><select v-app-select="{ filter: $t('selectControl.filter'), empty: $t('selectControl.empty') }" id="alert-rule-kind" v-bind="validation.attrs('kind')" v-model="form.kind" :disabled="busy || !!editing"><option value="threshold">{{ t('alerts.kinds.threshold') }}</option><option value="missing">{{ t('alerts.kinds.missing') }}</option></select><small v-if="fields.kind" id="alert-rule-kind-error" class="field-error" role="alert">{{ t(fields.kind) }}</small></div><div class="field"><label for="alert-rule-severity">{{ t('alerts.severity') }}</label><select v-app-select="{ filter: $t('selectControl.filter'), empty: $t('selectControl.empty') }" id="alert-rule-severity" v-bind="validation.attrs('severity')" v-model="form.severity" :disabled="busy"><option v-for="severity in severities" :key="severity" :value="severity">{{ t(`alerts.severities.${severity}`) }}</option></select><small v-if="fields.severity" id="alert-rule-severity-error" class="field-error" role="alert">{{ t(fields.severity) }}</small></div></div>
     <div class="field"><AppSwitch id="alert-rule-enabled" v-bind="validation.attrs('is_enabled')" v-model="form.enabled" :disabled="busy">{{ t('alerts.enabled') }}</AppSwitch><small v-if="fields.is_enabled" id="alert-rule-enabled-error" class="field-error" role="alert">{{ t(fields.is_enabled) }}</small></div><p v-if="editing">{{ t('alerts.changeHelp') }}</p></div></NTabPane><NTabPane name="conditions" display-directive="show"><template #tab><AppIcon name="settings" />{{ t('editorTabs.conditions') }}</template><div class="dialog-form"><template v-if="form.kind === 'threshold'"><p>{{ t('alerts.thresholdHelp') }}</p><div class="grid"><div class="field"><label for="alert-rule-minimum">{{ t('alerts.minimum') }}<span v-if="selectedSensor?.measurementType.unit"> ({{ selectedSensor.measurementType.unit }})</span></label><InputText placeholder="" v-bind="validation.attrs('minimum')" v-model:value="form.minimum" maxlength="80" :disabled="busy" :status="!!fields.minimum ? 'error' : undefined" :input-props="{ ...validation.attrs('minimum'), 'id': 'alert-rule-minimum', 'inputmode': 'decimal' }"></InputText><small v-if="fields.minimum" id="alert-rule-minimum-error" class="field-error" role="alert">{{ t(fields.minimum) }}</small></div><div class="field"><label for="alert-rule-maximum">{{ t('alerts.maximum') }}<span v-if="selectedSensor?.measurementType.unit"> ({{ selectedSensor.measurementType.unit }})</span></label><InputText placeholder="" v-bind="validation.attrs('maximum')" v-model:value="form.maximum" maxlength="80" :disabled="busy" :status="!!fields.maximum ? 'error' : undefined" :input-props="{ ...validation.attrs('maximum'), 'id': 'alert-rule-maximum', 'inputmode': 'decimal' }"></InputText><small v-if="fields.maximum" id="alert-rule-maximum-error" class="field-error" role="alert">{{ t(fields.maximum) }}</small></div></div><div class="field"><label for="alert-rule-age">{{ t('alerts.maxAge') }}</label><InputText placeholder="" v-bind="validation.attrs('max_age_seconds')" v-model:value="form.maxAge" min="120" max="86400" step="1" :disabled="busy" :input-props="{ ...validation.attrs('max_age_seconds'), 'id': 'alert-rule-age', type: 'number' }"></InputText><small v-if="fields.max_age_seconds" id="alert-rule-age-error" class="field-error" role="alert">{{ t(fields.max_age_seconds) }}</small></div></template>
     <div v-else class="field"><label for="alert-rule-delay">{{ t('alerts.delay') }}</label><InputText placeholder="" v-bind="validation.attrs('delay_seconds')" v-model:value="form.delay" min="120" max="86400" step="1" :disabled="busy" :input-props="{ ...validation.attrs('delay_seconds'), 'id': 'alert-rule-delay', type: 'number' }"></InputText><small>{{ t('alerts.graceHelp') }}</small><small v-if="fields.delay_seconds" id="alert-rule-delay-error" class="field-error" role="alert">{{ t(fields.delay_seconds) }}</small></div>
     </div></NTabPane></AppTabs></form>
<template #footer><div class="dialog-actions"><Button :aria-label="t('alerts.cancel')" type="default" :disabled="busy" @click="closeForm"><template #icon><AppIcon name="close" /></template><span>{{ t('actions.cancel') }}</span></Button><Button :form="`${dialogId}-0`" attr-type="submit" :aria-label="editing ? t('alerts.save') : t('actions.create')" :loading="busy" :disabled="busy || conflict" type="primary"><template #icon><AppIcon name="save" /></template><span>{{ t(editing ? 'actions.save' : 'actions.create') }}</span></Button></div></template></FormDialog>
    <p v-if="loading" role="status">{{ t('alerts.loading') }}</p><p v-else-if="!rules.length">{{ t('alerts.noRules') }}</p><ul class="rule-list operational-list"><ResourceCard v-for="rule in rules" :key="rule.id" icon="bell" :eyebrow="rule.sensor.device.name" :tone="rule.isEnabled ? 'success' : 'muted'"><template #title>{{ rule.name }}</template><template #badge><Tag :type="rule.isEnabled ? 'success' : 'default'">{{ t(rule.isEnabled ? 'alerts.enabled' : 'alerts.disabled') }}</Tag></template>
     <dl class="resource-facts"><div class="resource-wide"><dt>{{ t('alerts.sensor') }}</dt><dd>{{ rule.sensor.name }}</dd></div><div><dt>{{ t('alerts.kind') }}</dt><dd>{{ t(`alerts.kinds.${rule.kind}`) }}</dd></div><div><dt>{{ t('alerts.severity') }}</dt><dd>{{ t(`alerts.severities.${rule.severity}`) }}</dd></div><div class="resource-wide"><dt>{{ t('alerts.lastResult') }}</dt><dd>{{ t(`alerts.results.${rule.lastResult}`) }} · {{ date(rule.lastEvaluatedAt) }}</dd></div></dl>
     <template v-if="rule.canManage" #actions><Button :aria-label="t('alerts.evaluateNamed', { name: rule.name })" type="default" :disabled="busy" @click="evaluate(rule)"><template #icon><AppIcon name="refresh" /></template><span>{{ t('alerts.evaluate') }}</span></Button><span class="card-management-actions"><ListActionButton icon="edit" :label="t('alerts.editNamed', { name: rule.name })" :disabled="busy || loading" @click="open(rule)" /></span></template></ResourceCard></ul>
    <CollectionPagination v-model:page-size="rulePageSize" :page="rulePage" :last-page="ruleLast" :total="ruleTotal" :disabled="loading || busy" :label="t('alerts.rulePagination')" :previous-label="t('alerts.previous')" :next-label="t('alerts.next')" @page="ruleQuery.go($event)" /></NTabPane></AppTabs></template></Card></template>
<style scoped>
.grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0 var(--ui-grid-gap); }
.resource-note p { margin-top: var(--space-2); }
@media (max-width: 600px) { .grid { grid-template-columns: 1fr; } }
</style>
