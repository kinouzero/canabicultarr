<script setup lang="ts">
import AppTabs from '../../components/AppTabs.vue';
import CollectionViewToggle from '../../components/CollectionViewToggle.vue';
import '../../styles/settings.css';
import ListActionButton from '../../components/ListActionButton.vue';
import ConnectionSettings from '../../components/ConnectionSettings.vue';
import DiscardChangesDialog from '../../components/DiscardChangesDialog.vue';
import { useAutoSave } from '../../lib/auto-save';
import { useUnsavedChanges } from '../../lib/unsaved-changes';
import { useRoute, useRouter } from 'vue-router';
import { isSettingsTabChange, settingsTab, type SettingsTab } from '../../lib/settings-navigation';
import { connectionTabs, isTab, settingsTabs, tabAtPath } from '../../tab-paths';
import connectionEn from '../../locales/en/connectionSettings.json';
import connectionFr from '../../locales/fr/connectionSettings.json';
import type { ConnectionGroup } from '../../api/connectionSettings';
import { NTabPane } from 'naive-ui';




import ThemeEditor from '../../components/ThemeEditor.vue';
import ColorField from '../../components/ColorField.vue';
import AppIcon from '../../components/AppIcon.vue';
import OidcProviderIcon from '../../components/OidcProviderIcon.vue';
import selfhstIcons from '../../lib/selfhst-icons.json';
import { presetTheme, readTheme, type ThemeSettings } from '../../lib/theme';
import PageTitle from '../../components/PageTitle.vue';
import { siteBranding } from '../../lib/branding';
import StrainGeneticsTag from '../../components/StrainGeneticsTag.vue';
import { defaultGeneticsColors, geneticsKinds, readGeneticsColors, type GeneticsColors } from '../../lib/genetics-colors';
import { computed, defineAsyncComponent, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { NButton as Button } from 'naive-ui';
import Message from '../../components/AppMessage.vue';
import { ApiError } from '../../api/auth';
import { instanceSettingsApi } from '../../api/instanceSettings';
import { applyDisplaySettings, dateFormatLabels, dateFormats, formatDateTime, type DateTimeStyle } from '../../lib/date-time';
const LifecycleSettings = defineAsyncComponent(() => import('../../components/LifecycleSettings.vue'));
const CatalogSettings = defineAsyncComponent(() => import('../../components/CatalogSettings.vue'));
const AboutSettings = defineAsyncComponent(() => import('../../components/AboutSettings.vue'));
const aboutSettings = ref<InstanceType<typeof AboutSettings> | null>(null);
const MeasurementRetention = defineAsyncComponent(() => import('../../components/MeasurementRetention.vue'));
const retentionSettings = ref<InstanceType<typeof MeasurementRetention> | null>(null);
const NotificationCenter = defineAsyncComponent(() => import('../../components/NotificationCenter.vue'));
const props = defineProps<{ canAdminister?: boolean }>();
const emit = defineEmits<{ accessLost: [cause: ApiError] }>();
const { t, locale, mergeLocaleMessage } = useI18n();
mergeLocaleMessage('en', { connectionSettings: connectionEn }); mergeLocaleMessage('fr', { connectionSettings: connectionFr });
const route = useRoute();
const router = useRouter();
const version = ref(0);
const canManage = ref(false);
function tabAllowed(tab: SettingsTab) {
 if (tab === 'connections' || tab === 'data') return canManage.value;
 if (tab === 'catalogs' || tab === 'cycle') return !!props.canAdminister;
 return true;
}
const activeTab = computed(() => {
 const tab = settingsTab(route) ?? 'general';
 return tabAllowed(tab) ? tab : 'general';
});
const visited = reactive(new Set<SettingsTab>());
watch(activeTab, tab => visited.add(tab), { immediate: true });
const lifecycleSettings = ref<InstanceType<typeof LifecycleSettings> | null>(null);
const catalogSettings = ref<InstanceType<typeof CatalogSettings> | null>(null);

// Infrastructure, home automation, then notification delivery.
const connectionGroups: ConnectionGroup[] = ['authentication', 'storage', 'oidc', 'home_assistant', 'homebridge', 'mqtt', 'mail', 'webhook', 'push'];
const connectionGroup = ref<ConnectionGroup>('storage');
function groupAtPath(path: string): ConnectionGroup {
 return tabAtPath(connectionTabs, path) ?? 'storage';
}
watch(() => route.path, path => {
 if (settingsTab(route) === 'connections') connectionGroup.value = groupAtPath(path);
}, { immediate: true });

function selectSettingsTab(value: string | number) {
 if (!isTab(settingsTabs, value) || !tabAllowed(value) || value === activeTab.value) return;
 const path = value === 'connections' ? connectionTabs[connectionGroup.value] : settingsTabs[value];
 void router.push({ path, query: route.query });
}

function selectConnectionGroup(value: string | number) {
 if (!isTab(connectionTabs, value) || value === connectionGroup.value || settingsBusy.value) return;
 void router.push({ path: connectionTabs[value], query: route.query });
}
const connectionSettings = ref<InstanceType<typeof ConnectionSettings> | null>(null);
function setConnectionSettings(value: unknown) { connectionSettings.value = value as InstanceType<typeof ConnectionSettings> | null; }
const notificationCenter = ref<{ reload: () => Promise<void>; flush: () => Promise<void>; busy: boolean } | null>(null);
const reloading = computed(() => loading.value || (activeTab.value === 'about' && aboutSettings.value?.busy) || (activeTab.value === 'data' && retentionSettings.value?.busy) || (activeTab.value === 'cycle' ? lifecycleSettings.value?.loading : activeTab.value === 'catalogs' ? catalogSettings.value?.loading : false) || (activeTab.value === 'connections' ? connectionSettings.value?.loading : activeTab.value === 'notifications' ? notificationCenter.value?.busy : false));
async function reloadActive() {
 if (settingsBusy.value) return;
 if (activeTab.value === 'connections') {
  if (!await unsaved.confirmDiscard()) return;
  if (connectionGroup.value === 'oidc') await load(true);
  await connectionSettings.value?.reload();
 } else if (activeTab.value === 'cycle') await lifecycleSettings.value?.reload();
 else if (activeTab.value === 'catalogs') await catalogSettings.value?.reload();
 else if (activeTab.value === 'notifications') await notificationCenter.value?.reload();
 else if (activeTab.value === 'data') await retentionSettings.value?.reload();
 else if (activeTab.value === 'about') await aboutSettings.value?.reload();
 else await load();
}

const connectionIcons: Record<ConnectionGroup, string> = {
 authentication: 'user', storage: 'folder', oidc: 'shield', home_assistant: 'homeassistant', homebridge: 'homebridge',
 mqtt: 'sensor', mail: 'mail', webhook: 'webhook', push: 'bell',
};
const form = reactive<DateTimeStyle & { theme: ThemeSettings; geneticsColors: GeneticsColors; oidcProviderName: string; oidcProviderNameLocked: boolean; oidcProviderIcon: string | null; oidcProviderIconLocked: boolean; siteTitle: string; siteSubtitle: string | null }>({ oidcProviderIcon: siteBranding.value.oidcProviderIcon ?? null, oidcProviderIconLocked: false, theme: presetTheme(), oidcProviderNameLocked: false, oidcProviderName: siteBranding.value.oidcProviderName, siteTitle: siteBranding.value.siteTitle, siteSubtitle: siteBranding.value.siteSubtitle, dateFormat: 'd/m/Y', timeFormat: '24', geneticsColors: { ...defaultGeneticsColors } });
const loading = ref(true); const saving = ref(false); const error = ref(''); const saved = ref(false); let alive = true;
const baseline = ref('');
function formSnapshot() { return JSON.stringify({
 oidcProviderIcon: form.oidcProviderIcon, oidcProviderIconLocked: form.oidcProviderIconLocked,
 oidcProviderName: form.oidcProviderName, oidcProviderNameLocked: form.oidcProviderNameLocked,
 siteTitle: form.siteTitle, siteSubtitle: form.siteSubtitle || null, dateFormat: form.dateFormat,
 timeFormat: form.timeFormat, geneticsColors: form.geneticsColors, theme: form.theme,
}); }
const connectionStates = reactive<Record<string, { dirty: boolean; busy: boolean }>>({});
const generalDirty = computed(() => canManage.value && version.value > 0 && baseline.value !== formSnapshot());
watch(generalDirty, changed => { if (changed) saved.value = false; });
const settingsDirty = computed(() => generalDirty.value || Object.values(connectionStates).some(state => state.dirty));
const settingsBusy = computed(() => saving.value || aboutSettings.value?.busy || retentionSettings.value?.busy || lifecycleSettings.value?.saving || catalogSettings.value?.saving || Object.values(connectionStates).some(state => state.busy));
const unsaved = useUnsavedChanges(settingsDirty, settingsBusy, {
 flush: flushSettings,
 preserveDraft: (to, from) => isSettingsTabChange(to, from) && (settingsTab(to) !== 'connections' || groupAtPath(to.path) === connectionGroup.value),
});
async function flushSettings() {
 await autoSave.flush();
 await connectionSettings.value?.flush();
 await notificationCenter.value?.flush();
}
const sample = new Date(2026, 10, 8, 23, 5);
const preview = computed(() => formatDateTime(sample, locale.value, 'datetime', form));
function failure(cause: unknown) {
 error.value = cause instanceof ApiError && cause.status === 409 ? 'instanceSettings.conflict' : cause instanceof ApiError && cause.status === 422 ? 'instanceSettings.invalid' : 'auth.errors.requestFailed';
 if (cause instanceof ApiError && [401, 403].includes(cause.status)) { canManage.value = false; emit('accessLost', cause); }
}
async function load(discardConfirmed = false) {
 if (!discardConfirmed) {
  await autoSave.flush();
  if (!await unsaved.confirmDiscard(generalDirty.value)) return;
 }
 loading.value = true; error.value = ''; saved.value = false;
 try { const result = await instanceSettingsApi.get(); if (!alive) return; Object.assign(form, { oidcProviderIcon: result.oidcProviderIcon ?? null, oidcProviderIconLocked: result.oidcProviderIconLocked === true, oidcProviderNameLocked: result.oidcProviderNameLocked === true, oidcProviderName: result.oidcProviderName ?? siteBranding.value.oidcProviderName, siteTitle: result.siteTitle ?? siteBranding.value.siteTitle, siteSubtitle: result.siteSubtitle ?? null, dateFormat: result.dateFormat, timeFormat: result.timeFormat, geneticsColors: readGeneticsColors(result.geneticsColors), theme: readTheme(result.theme) }); version.value = result.version; baseline.value = formSnapshot(); canManage.value = result.canManage; applyDisplaySettings(result); }
 catch (cause) { if (alive) failure(cause); }
 finally { if (alive) loading.value = false; }
}
async function save() {
 if (loading.value || saving.value || !canManage.value || !generalDirty.value) return false;
 if (!form.siteTitle.trim() || (!form.oidcProviderNameLocked && !form.oidcProviderName.trim())) {
  error.value = 'instanceSettings.invalid';
  return false;
 }
 const submitted = JSON.parse(formSnapshot()) as typeof form;
 saving.value = true; error.value = ''; saved.value = false;
 try {
  const result = await instanceSettingsApi.save(submitted, version.value);
  if (!alive) return false;
  const persisted = {
   oidcProviderIcon: result.oidcProviderIcon ?? null,
   oidcProviderIconLocked: result.oidcProviderIconLocked === true,
   oidcProviderName: result.oidcProviderName ?? submitted.oidcProviderName,
   oidcProviderNameLocked: result.oidcProviderNameLocked === true,
   siteTitle: result.siteTitle ?? submitted.siteTitle,
   siteSubtitle: result.siteSubtitle ?? null,
   dateFormat: result.dateFormat,
   timeFormat: result.timeFormat,
   geneticsColors: readGeneticsColors(result.geneticsColors),
   theme: readTheme(result.theme),
  };
  // Keep text typed while a previous version is being written.
  const title = form.siteTitle === submitted.siteTitle ? persisted.siteTitle : form.siteTitle;
  const subtitle = (form.siteSubtitle || null) === submitted.siteSubtitle ? persisted.siteSubtitle : form.siteSubtitle;
  const provider = persisted.oidcProviderNameLocked || form.oidcProviderName === submitted.oidcProviderName ? persisted.oidcProviderName : form.oidcProviderName;
  baseline.value = JSON.stringify(persisted);
  Object.assign(form, persisted, { siteTitle: title, siteSubtitle: subtitle, oidcProviderName: provider });
  version.value = result.version;
  applyDisplaySettings(result);
  saved.value = !generalDirty.value;
  return true;
 } catch (cause) { if (alive) failure(cause); return false; }
 finally { if (alive) saving.value = false; }
}
const hasConflict = computed(() => error.value === 'instanceSettings.conflict');
const autoSave = useAutoSave(formSnapshot, computed(() => generalDirty.value && !hasConflict.value && !saving.value && !loading.value && !unsaved.pending.value), save);
onMounted(load); onUnmounted(() => { alive = false; });
const selectionHeader = ref<HTMLElement | null>(null);
</script>
<template><section class="instance-settings" data-testid="instance-settings"><header class="page-heading settings-heading"><PageTitle icon="settings">{{ t('instanceSettings.title') }}</PageTitle><div ref="selectionHeader" class="page-actions">
   <ListActionButton icon="refresh" :label="t(activeTab === 'cycle' && lifecycleSettings ? 'catalog.reloadSettings' : activeTab === 'catalogs' ? 'collection.refresh' : activeTab === 'notifications' ? 'notifications.reload' : 'instanceSettings.reload')" :loading="reloading" :disabled="reloading || settingsBusy" @click="reloadActive" />
   <CollectionViewToggle v-if="activeTab === 'catalogs' && catalogSettings?.viewPreference" :preference-key="catalogSettings.viewPreference" :disabled="reloading || settingsBusy" />
   <ListActionButton v-if="activeTab === 'cycle' && lifecycleSettings?.canManage" icon="plus" :label="t('catalog.workflow.addStage')" :disabled="!lifecycleSettings.canCreate" @click="lifecycleSettings.create()" />
   <ListActionButton v-if="activeTab === 'catalogs' && catalogSettings?.canCreate" icon="plus" :label="catalogSettings.creationLabel" :disabled="catalogSettings.loading || catalogSettings.saving" @click="catalogSettings.create()" /></div></header>
  <p class="settings-intro">{{ t('instanceSettings.description') }}</p>
  <Message v-if="error" type="error" closable>{{ t(error) }} <Button v-if="autoSave.failed.value && error === 'auth.errors.requestFailed'" text @click="autoSave.flush(true)">{{ t('actions.retry') }}</Button></Message>
  <p v-if="generalDirty && !hasConflict && !autoSave.failed.value" role="status">{{ t('actions.saving') }}</p><Message v-if="saved" type="success" closable>{{ t('instanceSettings.saved') }}</Message>
  <p v-if="loading" role="status">{{ t('instanceSettings.loading') }}</p>
  <AppTabs class="settings-tabs" v-if="version" :value="activeTab" @update:value="selectSettingsTab" animated><NTabPane name="general" display-directive="show:lazy"><template #tab><AppIcon name="settings" />{{ t('instanceSettings.tabs.general') }}</template><form class="general-settings-form" @submit.prevent="autoSave.flush()"><div class="settings-grid"><fieldset class="branding-settings" :disabled="!canManage || loading"><legend><AppIcon name="settings" />{{ t('instanceSettings.branding') }}</legend>
    <div class="branding-fields"><div><label for="instance-site-title">{{ t('instanceSettings.siteTitle') }}</label><input id="instance-site-title" v-model="form.siteTitle" type="text" required maxlength="80" /></div>
    <div><label for="instance-site-subtitle">{{ t('instanceSettings.siteSubtitle') }}</label><input id="instance-site-subtitle" v-model="form.siteSubtitle" type="text" maxlength="160" :placeholder="t('instanceSettings.optionalSubtitle')" /></div></div>

    <p>{{ t('instanceSettings.brandingHelp') }}</p><div class="branding-preview"><strong>{{ form.siteTitle || t('appTitle') }}</strong><span v-if="form.siteSubtitle">{{ form.siteSubtitle }}</span></div></fieldset>
   <fieldset class="display-settings" :disabled="saving || !canManage"><legend><AppIcon name="clock" />{{ t('instanceSettings.displaySection') }}</legend><div class="date-settings-fields"><div><label for="instance-date-format">{{ t('instanceSettings.dateFormat') }}</label><select v-app-select="{ filter: $t('selectControl.filter'), empty: $t('selectControl.empty') }" id="instance-date-format" v-model="form.dateFormat" :disabled="saving || !canManage"><option v-for="format in dateFormats" :key="format" :value="format">{{ t(`instanceSettings.formats.${dateFormatLabels[format]}`) }}</option></select></div>
   <div><label for="instance-time-format">{{ t('instanceSettings.timeFormat') }}</label><select v-app-select="{ filter: $t('selectControl.filter'), empty: $t('selectControl.empty') }" id="instance-time-format" v-model="form.timeFormat" :disabled="saving || !canManage"><option value="24">{{ t('instanceSettings.hour24') }}</option><option value="12">{{ t('instanceSettings.hour12') }}</option></select></div></div>
   <div class="date-preview"><span>{{ t('instanceSettings.preview') }}</span><output>{{ preview }}</output></div></fieldset></div></form></NTabPane><NTabPane v-if="canManage" name="data" display-directive="show:lazy"><template #tab><AppIcon name="database" />{{ t('instanceSettings.tabs.data') }}</template><MeasurementRetention v-if="visited.has('data')" ref="retentionSettings" /></NTabPane><NTabPane name="themes" display-directive="show:lazy"><template #tab><AppIcon name="palette" />{{ t('instanceSettings.tabs.themes') }}</template><form class="theme-settings-form" @submit.prevent="autoSave.flush()"><ThemeEditor v-model="form.theme" :disabled="saving || !canManage" />
   <fieldset class="genetics-settings" :disabled="saving || !canManage"><legend>{{ t('instanceSettings.geneticsColors') }}</legend><p>{{ t('instanceSettings.geneticsColorsHelp') }}</p>
    <div class="genetics-colors"><ColorField v-for="kind in geneticsKinds" :key="kind" :id="`genetics-color-${kind}`" v-model="form.geneticsColors[kind]" :label="t(`strainGenetics.${kind}`)" :disabled="saving || !canManage"><StrainGeneticsTag :classification="kind" :colors="form.geneticsColors" /></ColorField></div>
    <Button v-if="canManage" attr-type="button" type="default" :disabled="saving" :aria-label="t('instanceSettings.resetGeneticsColors')" @click="form.geneticsColors = { ...defaultGeneticsColors }"><template #icon><AppIcon name="refresh" /></template><span>{{ t('actions.reset') }}</span></Button></fieldset></form></NTabPane><NTabPane v-if="canAdminister" name="cycle" display-directive="show:lazy"><template #tab><AppIcon name="refresh" />{{ t('workspaces.tabs.cycle') }}</template><LifecycleSettings v-if="visited.has('cycle')" ref="lifecycleSettings" hide-heading @access-lost="emit('accessLost', $event)" /></NTabPane><NTabPane v-if="canAdminister" name="catalogs" display-directive="show:lazy"><template #tab><AppIcon name="library" />{{ t('workspaces.tabs.catalogs') }}</template><CatalogSettings v-if="visited.has('catalogs')" :selection-target="activeTab === 'catalogs' ? selectionHeader : null" ref="catalogSettings" hide-heading @access-lost="emit('accessLost', $event)" /></NTabPane><NTabPane name="connections" v-if="canManage" display-directive="show:lazy"><template #tab><AppIcon name="plug" />{{ t('connectionSettings.title') }}</template><AppTabs type="bar" class="button-tabs connection-tabs" :value="connectionGroup" @update:value="selectConnectionGroup" animated><NTabPane v-for="group in connectionGroups" :key="group" :name="group" :disabled="settingsBusy || unsaved.pending.value" display-directive="show:lazy"><template #tab><AppIcon :name="connectionIcons[group]" />{{ t(`connectionSettings.groups.${group}`) }}</template><template v-if="group === connectionGroup"><form v-if="connectionGroup === 'oidc'" class="oidc-provider-settings" @submit.prevent="autoSave.flush()"><div class="branding-fields"><div><label for="instance-oidc-provider-name">{{ t('instanceSettings.oidcProviderName') }} <span v-if="form.oidcProviderNameLocked" class="provider-environment"><AppIcon name="shield" />{{ t('connectionSettings.environment') }}</span></label><input id="instance-oidc-provider-name" v-model="form.oidcProviderName" type="text" required maxlength="80" :disabled="loading || !canManage || form.oidcProviderNameLocked" aria-describedby="oidc-provider-name-help" /><small id="oidc-provider-name-help">{{ t('instanceSettings.oidcProviderNameHelp') }}</small></div></div>
       <div class="oidc-logo-field"><label for="instance-oidc-provider-icon">{{ t('instanceSettings.oidcProviderIcon') }} <span v-if="form.oidcProviderIconLocked" class="provider-environment"><AppIcon name="shield" />{{ t('connectionSettings.environment') }}</span></label><div class="oidc-logo-control"><OidcProviderIcon :name="form.oidcProviderIcon" /><select id="instance-oidc-provider-icon" v-model="form.oidcProviderIcon" v-app-select="{ filter: $t('selectControl.filter'), empty: $t('selectControl.empty') }" :disabled="saving || !canManage || form.oidcProviderIconLocked" aria-describedby="oidc-provider-icon-help"><option :value="null">{{ t('instanceSettings.defaultIcon') }}</option><option v-if="form.oidcProviderIcon && !selfhstIcons.some(([slug]) => slug === form.oidcProviderIcon)" :value="form.oidcProviderIcon">{{ form.oidcProviderIcon }}</option><option v-for="[slug, name] in selfhstIcons" :key="slug" :value="slug">{{ name }}</option></select></div><small id="oidc-provider-icon-help">{{ t('instanceSettings.oidcProviderIconHelp') }} <a href="https://selfh.st/icons/" target="_blank" rel="noopener noreferrer">selfh.st</a></small></div>
      </form>
      <ConnectionSettings :ref="setConnectionSettings" :key="connectionGroup" :group="connectionGroup" :can-manage="canManage" @state-change="connectionStates.connections = $event" @access-lost="emit('accessLost', $event)" /></template></NTabPane></AppTabs></NTabPane><NTabPane name="notifications" display-directive="show:lazy"><template #tab><AppIcon name="notification" />{{ t('workspaces.tabs.notifications') }}</template><NotificationCenter ref="notificationCenter" v-if="visited.has('notifications')" embedded nested hide-heading @state-change="connectionStates.notifications = $event" @access-lost="emit('accessLost', $event)" /></NTabPane><NTabPane name="about" display-directive="show:lazy"><template #tab><AppIcon name="info" />{{ t('instanceSettings.tabs.about') }}</template><AboutSettings v-if="visited.has('about')" ref="aboutSettings" @access-lost="emit('accessLost', $event)" /></NTabPane></AppTabs>
  <DiscardChangesDialog v-if="unsaved.pending.value" @decision="unsaved.finish" /></section></template>

<style scoped>
.oidc-logo-field { display: grid; gap: var(--space-1-5); }
.oidc-logo-control { display: flex; align-items: center; gap: var(--space-3); }
.oidc-logo-control > :last-child { flex: 1; min-width: 0; }
</style>
