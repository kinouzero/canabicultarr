<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { NButton as Button } from 'naive-ui';
import { NInputNumber as InputNumber } from 'naive-ui';
import Message from './AppMessage.vue';
import { NTag as Tag } from 'naive-ui';
import AppIcon from './AppIcon.vue';
import FormDialog from './FormDialog.vue';
import CollectionTable from './CollectionTable.vue';
import { ApiError } from '../api/auth';
import { measurementRetentionApi, type RetentionState, type RetentionPreview, type PurgeRun } from '../api/measurement-retention';
import { formatDateTime } from '../lib/date-time';
import en from '../locales/en/retention.json';
import fr from '../locales/fr/retention.json';
const { t, locale, mergeLocaleMessage } = useI18n({ useScope: 'global' });
mergeLocaleMessage('en', { retention: en }); mergeLocaleMessage('fr', { retention: fr });
const state = ref<RetentionState | null>(null); const busy = ref(false); const error = ref(''); const dialogError = ref('');
const open = ref(false); const days = ref<number | null>(30); const mode = ref<'manual' | 'policy'>('manual'); const preview = ref<RetentionPreview | null>(null);
let alive = true; let timer: ReturnType<typeof setTimeout> | undefined;
const active = computed(() => state.value?.runs.some(run => ['queued', 'running'].includes(run.status)) ?? false);
const columns = computed(() => ['createdAt', 'mode', 'cutoffAt', 'deletedCount', 'status', 'actions'].map(key => ({ key, label: t(`retention.columns.${key}`) })));
const validDays = computed(() => days.value !== null && Number.isInteger(days.value) && days.value >= 1 && days.value <= 36500);
function date(value: string) { return formatDateTime(value, locale.value); }
function message(cause: unknown) { return cause instanceof ApiError && cause.code.startsWith('retention.') ? cause.code : 'retention.errors.failed'; }
async function reload() {
 clearTimeout(timer); if (busy.value) return; busy.value = true; error.value = '';
 try { const result = await measurementRetentionApi.get(); if (alive) state.value = result; }
 catch (cause) { if (alive) error.value = message(cause); }
 finally { if (alive) { busy.value = false; if (active.value) timer = setTimeout(() => { void reload(); }, 5000); } }
}
function show(next: 'manual' | 'policy') { mode.value = next; days.value = state.value?.policy.days ?? 30; preview.value = null; dialogError.value = ''; open.value = true; }
watch(days, () => { preview.value = null; dialogError.value = ''; });
async function recover(cause: unknown) {
 dialogError.value = message(cause);
 if (cause instanceof ApiError && ['retention.errors.previewExpired', 'retention.errors.conflict'].includes(cause.code)) preview.value = null;
 if (cause instanceof ApiError && cause.code === 'retention.errors.conflict') {
  try { const result = await measurementRetentionApi.get(); if (alive) state.value = result; } catch { /* The original conflict remains actionable. */ }
 }
}
async function inspect() {
 if (!state.value || !validDays.value || busy.value) return; busy.value = true; dialogError.value = '';
 try { const result = await measurementRetentionApi.preview(days.value!, mode.value, state.value.policy.version); if (alive) preview.value = result; }
 catch (cause) { if (alive) await recover(cause); }
 finally { if (alive) busy.value = false; }
}
async function confirm() {
 if (!preview.value || busy.value) return; busy.value = true; dialogError.value = '';
 try { await measurementRetentionApi.confirm(preview.value.token); if (alive) { open.value = false; preview.value = null; } }
 catch (cause) { if (alive) await recover(cause); }
 finally { if (alive) { busy.value = false; if (!open.value) await reload(); } }
}
async function disable() {
 if (!state.value || busy.value) return; busy.value = true; error.value = '';
 try { const result = await measurementRetentionApi.disable(state.value.policy.version); if (alive) state.value = result; }
 catch (cause) { if (alive) error.value = message(cause); }
 finally { if (alive) { busy.value = false; await reload(); } }
}
async function cancel(run: PurgeRun) {
 if (busy.value) return; busy.value = true; error.value = '';
 try { const result = await measurementRetentionApi.cancel(run.id); if (alive) state.value = result; }
 catch (cause) { if (alive) error.value = message(cause); }
 finally { if (alive) { busy.value = false; await reload(); } }
}
onMounted(reload); onUnmounted(() => { alive = false; clearTimeout(timer); });
defineExpose({ reload, busy });
</script>
<template><section class="retention-settings" :aria-label="t('retention.title')"><Message v-if="error" type="error" closable role="alert">{{ t(error) }}</Message>
  <template v-if="state">
   <div class="retention-heading"><h2><AppIcon name="database" />{{ t('retention.title') }}</h2></div>
   <p>{{ t('retention.help') }}</p>
   <div class="retention-options">
    <section class="retention-option" :aria-label="t('retention.configure')">
     <div class="retention-heading"><h3><AppIcon name="clock" />{{ t('retention.configure') }}</h3><Tag :type="state.policy.days === null ? 'default' : 'success'">{{ state.policy.days === null ? t('retention.unlimited') : t('retention.policyDays', { count: state.policy.days }) }}</Tag></div>
     <p>{{ t('retention.policyHelp') }}</p>
     <div class="actions"><Button type="primary" :disabled="busy || active" @click="show('policy')"><template #icon><AppIcon name="settings" /></template>{{ t(state.policy.days === null ? 'retention.enable' : 'retention.edit') }}</Button>
      <Button v-if="state.policy.days !== null" type="default" :disabled="busy" @click="disable"><template #icon><AppIcon name="close" /></template>{{ t('retention.disable') }}</Button></div>
    </section>
    <section class="retention-option" :aria-label="t('retention.modes.manual')">
     <div class="retention-heading"><h3><AppIcon name="trash" />{{ t('retention.modes.manual') }}</h3></div>
     <p>{{ t('retention.manualHelp') }}</p>
     <div class="actions"><Button type="error" ghost :disabled="busy || active" @click="show('manual')"><template #icon><AppIcon name="trash" /></template>{{ t('retention.purge') }}</Button></div>
    </section>
   </div>
   <Message v-if="active" type="info" closable>{{ t('retention.active') }}</Message>
   <h3 v-if="state.runs.length">{{ t('retention.history') }}</h3>
   <CollectionTable v-if="state.runs.length" :rows="state.runs" :columns="columns"><template #createdAt="{ row }">{{ date(row.createdAt) }}</template><template #mode="{ row }">{{ t(`retention.modes.${row.mode}`) }}</template><template #cutoffAt="{ row }">{{ date(row.cutoffAt) }}</template><template #deletedCount="{ row }">{{ row.deletedCount.toLocaleString(locale) }}</template>
    <template #status="{ row }"><span>{{ t(`retention.statuses.${row.status}`) }}</span><small v-if="row.errorCode">{{ t(row.errorCode) }}</small></template>
    <template #actions="{ row }"><Button v-if="['queued', 'running'].includes(row.status)" type="default" :disabled="busy" :aria-label="t('retention.cancel')" :title="t('retention.cancel')" @click="cancel(row)"><template #icon><AppIcon name="close" /></template></Button></template></CollectionTable></template>
  <FormDialog v-if="open" :visible="true" :title="t(mode === 'manual' ? 'retention.purge' : 'retention.configure')" :close-label="t('actions.cancel')" :busy="busy" :error="dialogError ? t(dialogError) : ''" :icon="mode === 'manual' ? 'trash' : 'clock'" size="compact" @update:visible="open = $event"><div class="dialog-form"><p class="retention-dialog-help">{{ t(mode === 'manual' ? 'retention.manualHelp' : 'retention.policyHelp') }}</p><div class="field"><label for="retention-days">{{ t('retention.days') }}</label><InputNumber :input-props="{ id: 'retention-days' }" v-model:value="days" :min="1" :max="36500" :disabled="busy"></InputNumber><small>{{ t('retention.receiptGuard') }}</small></div>
    <template v-if="preview"><dl class="retention-preview"><div><dt>{{ t('retention.cutoff') }}</dt><dd>{{ date(preview.cutoffAt) }}</dd></div><div><dt>{{ t('retention.affected') }}</dt><dd>{{ preview.countIsExact ? preview.count.toLocaleString(locale) : t('retention.atLeast', { count: preview.count.toLocaleString(locale) }) }}</dd></div></dl>
     <Message type="warning" closable>{{ t(mode === 'manual' ? 'retention.confirmManual' : 'retention.confirmPolicy') }}</Message><p>{{ t('retention.preserved') }}</p></template></div>
   <template #footer><Button type="default" :disabled="busy" @click="open = false"><AppIcon name="close" /><span>{{ t('actions.cancel') }}</span></Button>
    <Button v-if="!preview" :disabled="busy || !validDays" :loading="busy" @click="inspect" type="primary"><AppIcon name="eye" /><span>{{ t('retention.preview') }}</span></Button>
    <Button v-else type="error" :loading="busy" :disabled="busy || (mode === 'manual' && preview.count === 0)" @click="confirm"><AppIcon :name="mode === 'manual' ? 'trash' : 'clock'" /><span>{{ t(mode === 'manual' ? 'retention.confirmPurge' : 'retention.confirmEnable') }}</span></Button></template></FormDialog></section></template>
<style scoped>
.retention-options { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 22rem), 1fr)); gap: var(--ui-content-gap); }
.retention-option { display: flex; flex-direction: column; align-items: flex-start; gap: var(--ui-content-gap); padding: var(--ui-card-inset); border: 1px solid var(--theme-border); border-radius: .6rem; min-width: 0; }
.retention-option .actions { margin-top: auto; }
.retention-option h3 { display: flex; align-items: center; gap: var(--ui-icon-gap); }
.retention-option h3 svg { width: 1.1rem; height: 1.1rem; }
.retention-dialog-help { margin: 0; color: var(--theme-muted); }
.retention-preview { display: grid; gap: var(--space-3); margin: 0; }.retention-preview > div { display: flex; align-items: baseline; justify-content: space-between; flex-wrap: wrap; gap: var(--ui-icon-gap); }.retention-preview dt { color: var(--theme-muted); font-size: .82rem; }.retention-preview dd { margin: 0; font-weight: 600; font-size: .85rem; }
.retention-settings { display: grid; gap: var(--ui-content-gap); }.retention-heading,.actions { display: flex; align-items: center; flex-wrap: wrap; gap: var(--space-3); }.retention-heading h2 { display: flex; align-items: center; gap: var(--ui-icon-gap); margin: 0; font-size: 1rem; }.retention-heading h2 svg { width: 1.1rem; height: 1.1rem; }.retention-settings p { margin: 0; color: var(--theme-muted); }.retention-settings h3 { margin: 0; font-size: .9rem; }.retention-settings small { display: block; color: var(--theme-muted); }
</style>
