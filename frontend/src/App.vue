<script setup lang="ts">
import { useFeedback } from './lib/feedback';
import AppFooter from './components/AppFooter.vue';
import BackToTop from './components/BackToTop.vue';
import './styles/account.css';
import emailChangeEn from './locales/en/email-change.json';
import emailChangeFr from './locales/fr/email-change.json';
import activationEn from './locales/en/account-activation.json';
import activationFr from './locales/fr/account-activation.json';
import AppIcon from './components/AppIcon.vue';
import OidcProviderIcon from './components/OidcProviderIcon.vue';
import ListActionButton from './components/ListActionButton.vue';
import { createOfflineSync, offlineSyncKey } from './lib/offline-sync';
import OfflineSyncNotice from './components/OfflineSyncNotice.vue';
import { clearOfflineSites, readOfflineState } from './lib/offline-sites.js';
import { loadUiPreferences, clearUiPreferences } from './lib/ui-preferences';
import { insightPreferenceScope } from './lib/insight-preferences';
import PageTitle from './components/PageTitle.vue';
import { useRoute, useRouter, type LocationQueryRaw } from 'vue-router';
import { panelPaths } from './router';
import { hardwareTabs, settingsTabs } from './tab-paths';
import { queryString, useResolvedRecordQuery, filePreviewOpener } from './lib/route-query';
import { documentsApi } from './api/documents';
import { strainRecordOpener } from './lib/strain-record';
import { useSensorHistoryRoute, sensorHistoryOpener } from './lib/sensor-history-route';
import AppBreadcrumb from './components/AppBreadcrumb.vue';
import BrandMark from './components/BrandMark.vue';
import { strainsApi } from './api/strains';
import { applyLifecycle, resetLifecycle } from './lib/lifecycle';
import { computed, defineAsyncComponent, provide, nextTick, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { NButton as Button } from 'naive-ui';
import { NCard as Card } from 'naive-ui';
import { NInput as InputText } from 'naive-ui';
import Message from './components/AppMessage.vue';
import { NSpin as ProgressSpinner } from 'naive-ui';
import { applyBranding, siteBranding, oidcProviderName } from './lib/branding';
import { instanceSettingsApi } from './api/instanceSettings';
import { applyDisplaySettings, resetDisplaySettings } from './lib/date-time';
import { ApiError, authApi, type AuthUser } from './api/auth';
import { passkeysApi, supportsPasskeys } from './api/passkeys';
import type { ManagedUser } from './api/users';
import type { PlantListScope } from './api/plants';
import type { DashboardModule } from './api/dashboard';
import type { SecurityResult } from './api/accountSecurity';
import AppAvailability from './components/AppAvailability.vue';
import FormDialog from './components/FormDialog.vue';
import AppNavigation from './components/AppNavigation.vue';
import LanguagePicker from './components/LanguagePicker.vue';
import WorkspaceTabs from './components/WorkspaceTabs.vue';
import WorkspaceSectionTabs from './components/WorkspaceSectionTabs.vue';
import UserManagementTabs from './components/UserManagementTabs.vue';
import type { BreadcrumbItem, AppPanel, NavigationGroup, NavigationItem, Workspace } from './navigation';
import { unsubscribeLocalBrowser } from './lib/browserNotifications';
const route = useRoute(); const router = useRouter();
const panel = ref<AppPanel>('home');
const homeSiteId = ref<string | null>(null);
const showInstallationHelp = ref(false);
function restoreInstallationHelpFocus() {
 const selector = window.matchMedia('(max-width: 1000px)').matches ? '.menu-trigger' : '#navigation-install-help';
 document.querySelector<HTMLButtonElement>(selector)?.focus();
}
const mainContent = ref<HTMLElement | null>(null);
function viewFlag(name: AppPanel) { return computed({ get: () => panel.value === name, set: (value: boolean) => { if (value) navigate(name); else if (panel.value === name) navigate('home'); } }); }
const showPlants = viewFlag('plants');
const showCatalog = viewFlag('catalog');
const showSourceDiagnostics = viewFlag('sources');
const showHttpIngestion = viewFlag('http');
const showMqtt = viewFlag('mqtt');
const showAccessProfiles = viewFlag('profiles');
const showUsers = viewFlag('users');
const showLocations = viewFlag('locations');
const showAssets = viewFlag('assets');
const showEvents = viewFlag('events');
const showDocuments = viewFlag('documents');
const showInventory = viewFlag('inventory');
const showMeasurements = viewFlag('measurements');
const showAlerts = viewFlag('alerts');
const showDashboard = viewFlag('overview');
const showNotifications = viewFlag('notifications');
const showAccountSecurity = viewFlag('security');
const InstanceSettings = defineAsyncComponent(() => import('./pages/administration/InstanceSettings.vue'));
const BluetoothIntegration = defineAsyncComponent(() => import('./pages/integrations/BluetoothIntegration.vue'));
const PlantDashboard = defineAsyncComponent(() => import('./pages/dashboard/PlantDashboard.vue'));
const PasswordRecovery = defineAsyncComponent(() => import('./pages/auth/PasswordRecovery.vue'));
const AccountActivation = defineAsyncComponent(() => import('./pages/auth/AccountActivation.vue'));
const confirmationPurpose = ref<'activation' | 'email-change'>(window.location.hash.startsWith('#confirm-email=') ? 'email-change' : 'activation');
const emailChangeSuccess = ref(false);
const activationToken = ref(window.location.hash.startsWith('#confirm-email=') ? window.location.hash.slice('#confirm-email='.length) || 'invalid' : window.location.hash.startsWith('#activate-account=') ? window.location.hash.slice('#activate-account='.length) || 'invalid' : '');
const showActivation = ref(!!activationToken.value); const activationGeneration = ref(0);
const activationEmail = ref(''); const activationCreated = ref(false); const activationSuccess = ref(false);
if (activationToken.value) void router.replace({ hash: '' });
function closeActivation() { showActivation.value = false; activationToken.value = ''; mode.value = user.value ? 'account' : 'login'; }
async function activated(email: string) {
 closeActivation(); loginForm.email = email;
 if (confirmationPurpose.value === 'email-change') {
  sessionRevoked(email); error.value = ''; fields.value = {}; securityNotice.value = ''; emailChangeSuccess.value = true;
  void unsubscribeLocalBrowser(); await load();
 } else activationSuccess.value = true;
}
const resetFragment = window.location.hash.startsWith('#reset-password=');
const recoveryToken = ref(resetFragment ? (window.location.hash.slice('#reset-password='.length) || 'invalid') : '');
if (resetFragment) void router.replace({ hash: '' });
const showRecovery = ref(resetFragment);
const recoveryGeneration = ref(0);
const recoveryNotice = ref(false);
import { NInput as Password } from 'naive-ui';
const PlantRegistry = defineAsyncComponent(() => import('./pages/culture/PlantRegistry.vue'));
const StrainCatalog = defineAsyncComponent(() => import('./pages/culture/StrainCatalog.vue'));
const SourceDiagnostics = defineAsyncComponent(() => import('./pages/integrations/SourceDiagnostics.vue'));
const HttpIngestion = defineAsyncComponent(() => import('./pages/integrations/HttpIngestion.vue'));
const MqttSources = defineAsyncComponent(() => import('./pages/integrations/MqttSources.vue'));
const AccessProfiles = defineAsyncComponent(() => import('./pages/administration/AccessProfiles.vue'));
const NotificationPreferences = defineAsyncComponent(() => import('./pages/account/NotificationPreferences.vue'));
const AccountProfile = defineAsyncComponent(() => import('./pages/account/AccountProfile.vue'));
const MobilePairingConfirmation = defineAsyncComponent(() => import('./pages/account/MobilePairingConfirmation.vue'));
const mobilePairingCode = ref('');
const AccountSecurity = defineAsyncComponent(() => import('./pages/account/AccountSecurity.vue'));
const accountProfile = ref<InstanceType<typeof AccountProfile> | null>(null);
const accountSecurity = ref<InstanceType<typeof AccountSecurity> | null>(null);
const notificationPreferences = ref<InstanceType<typeof NotificationPreferences> | null>(null);
const InstallationDashboard = defineAsyncComponent(() => import('./pages/culture/InstallationDashboard.vue'));
const MonitoringAlerts = defineAsyncComponent(() => import('./pages/monitoring/MonitoringAlerts.vue'));
const MeasurementWorkspace = defineAsyncComponent(() => import('./pages/monitoring/MeasurementWorkspace.vue'));
const MeasurementBoard = defineAsyncComponent(() => import('./pages/integrations/MeasurementBoard.vue'));
const InventoryRegistry = defineAsyncComponent(() => import('./pages/administration/InventoryRegistry.vue'));
const DocumentLibrary = defineAsyncComponent(() => import('./pages/administration/DocumentLibrary.vue'));
const EventJournal = defineAsyncComponent(() => import('./pages/monitoring/EventJournal.vue'));
const AssetRegistry = defineAsyncComponent(() => import('./pages/monitoring/AssetRegistry.vue'));
const LocationExplorer = defineAsyncComponent(() => import('./pages/culture/LocationExplorer.vue'));
const UserAdministration = defineAsyncComponent(() => import('./pages/administration/UserAdministration.vue'));
const SensorHistory = defineAsyncComponent(() => import('./components/SensorHistory.vue'));
const FilePreview = defineAsyncComponent(() => import('./components/FilePreview.vue'));

const { t, te, locale, mergeLocaleMessage } = useI18n();
mergeLocaleMessage('en', { emailChange: emailChangeEn }); mergeLocaleMessage('fr', { emailChange: emailChangeFr });
mergeLocaleMessage('en', { accountActivation: activationEn }); mergeLocaleMessage('fr', { accountActivation: activationFr });
watch(() => siteBranding.value.siteTitle, title => { document.title = title; }, { immediate: true });
onMounted(async () => { try { applyBranding(await instanceSettingsApi.branding()); } catch { /* Keep default branding if unavailable. */ } });
watch(locale, value => { document.documentElement.lang = value; }, { immediate: true });
const mode = ref<'loading' | 'setup' | 'register' | 'login' | 'account' | 'error'>('loading');
const user = ref<AuthUser | null>(null);
const offlineSync = createOfflineSync(() => mode.value === 'account' ? user.value?.id : undefined);
provide(offlineSyncKey, offlineSync);
const feedback = useFeedback();
watch(() => user.value?.id, () => feedback.clear());
provide(insightPreferenceScope, computed(() => user.value?.id ?? 'anonymous'));
let displaySettingsSequence = 0;
watch(() => user.value?.id, async id => {
 const current = ++displaySettingsSequence; resetDisplaySettings(); resetLifecycle();
 if (!id) return;
 try { await Promise.all([instanceSettingsApi.get().then(settings => { if (current === displaySettingsSequence) applyDisplaySettings(settings); }), strainsApi.settings().then(settings => { if (current === displaySettingsSequence) applyLifecycle(settings.stages, settings.icons); })]); }
 catch (cause) { if (current === displaySettingsSequence && cause instanceof ApiError && cause.status === 401) accessLost(cause); }
});
const busy = ref(false);
const securityNotice = ref<SecurityResult | ''>('');
const error = ref('');
const oidcEnabled = ref(false);
const localEnabled = ref(true);
const passkeysAvailable = ref(false);
const passkeysEnabled = computed(() => passkeysAvailable.value && supportsPasskeys());
const registrationEnabled = ref(false);
const oidcNotice = ref(false);
const fields = ref<Record<string, string>>({});
const setupForm = reactive({ token: '', name: '', email: '', password: '', password_confirmation: '' });
const loginForm = reactive({ email: '', password: '' });

function errorKey(key: string): string {
  return /^(auth\.(errors|validation)|accountActivation)\.[A-Za-z]+$/.test(key) && te(key) ? key : 'auth.errors.requestFailed';
}

function showError(cause: unknown) {
  if (cause instanceof ApiError && cause.code === 'accountActivation.required') { confirmationPurpose.value = 'activation'; activationEmail.value = loginForm.email; activationCreated.value = false; showActivation.value = true; loginForm.password = ''; return; }
  securityNotice.value = '';
  error.value = errorKey(cause instanceof ApiError ? cause.code : 'auth.errors.requestFailed');
  fields.value = {};
  if (cause instanceof ApiError) {
    for (const [field, messages] of Object.entries(cause.fields)) {
      if (typeof messages[0] === 'string') fields.value[field] = errorKey(messages[0]);
    }
    if (cause.code === 'auth.errors.setupClosed') mode.value = 'login';
    if (cause.status === 401) {
      panel.value = 'home';

      user.value = null;
      void discardOfflineDirectory();
      mode.value = 'login';
    }
  }
}

let startupController: AbortController | null = null;
const startupScreen = computed(() => !showRecovery.value && !showActivation.value && (mode.value === 'loading' || mode.value === 'error'));
async function load() {
  startupController?.abort();
  const controller = new AbortController(); startupController = controller;
  const timeout = window.setTimeout(() => controller.abort(), 15000);
  panel.value = 'home'; homeSiteId.value = null;

  securityNotice.value = '';
  mode.value = 'loading';
  error.value = '';
  try {
    const [status, oidc] = await Promise.all([authApi.status(controller.signal), authApi.oidcStatus(controller.signal)]);
    oidcEnabled.value = oidc.enabled;
    localEnabled.value = status.localEnabled !== false;
    passkeysAvailable.value = status.passkeysAvailable === true;
    registrationEnabled.value = status.registrationEnabled === true;
    oidcNotice.value = oidc.result === 'linked';
    if (oidc.result && oidc.result !== 'linked') error.value = errorKey(oidc.result);
    if (status.requiresSetup) {
      void discardOfflineDirectory();
      mode.value = 'setup';
      return;
    }
    try {
      user.value = await authApi.user(controller.signal);
      await loadUiPreferences(user.value.id);
      if (startupController !== controller) return;
      mode.value = 'account';
      void offlineSync.resume();
    } catch (cause) {
      if (cause instanceof ApiError && cause.status === 401) {
        mode.value = 'login';
        void discardOfflineDirectory();
      }
      else throw cause;
    }
  } catch (cause) {
    if (startupController !== controller) return;
    mode.value = 'error';
    showError(controller.signal.aborted ? new ApiError(0, 'auth.errors.requestTimedOut') : cause);
  } finally {
    window.clearTimeout(timeout);
    if (startupController === controller) startupController = null;
  }
}

async function submit(kind: 'setup' | 'register' | 'login' | 'passkey') {
  if (busy.value) return;
  securityNotice.value = '';
  recoveryNotice.value = false;
  busy.value = true;
  error.value = '';
  fields.value = {};
  try {
    if (kind === 'setup' || kind === 'register') {
      const input = { ...setupForm, locale: locale.value === 'fr' ? 'fr' as const : 'en' as const };
      if (kind === 'setup') await authApi.setup(input); else { const { token: _token, ...registration } = input; await authApi.register(registration); }
      confirmationPurpose.value = 'activation'; activationEmail.value = setupForm.email; activationCreated.value = true; activationToken.value = '';
      setupForm.token = setupForm.password = setupForm.password_confirmation = '';
      showActivation.value = true; mode.value = 'login'; return;
    }
    user.value = kind === 'passkey' ? await passkeysApi.authenticate() : await authApi.login(loginForm.email, loginForm.password);
    await loadUiPreferences(user.value.id);
    setupForm.token = setupForm.password = setupForm.password_confirmation = loginForm.password = '';
    panel.value = 'home';
    mode.value = 'account';
    void offlineSync.resume();
  } catch (cause) {
    showError(cause);
  } finally {
    busy.value = false;
  }
}

async function setupOidc() {
  if (busy.value) return;
  busy.value = true; error.value = ''; fields.value = {};
  try {
    const { url } = await authApi.setupOidc(setupForm.token);
    setupForm.token = setupForm.password = setupForm.password_confirmation = '';
    window.location.assign(url);
  } catch (cause) { showError(cause); }
  finally { busy.value = false; }
}

async function linkOidc() {
  busy.value = true;
  error.value = '';
  try {
    const { url } = await authApi.linkOidc();
    window.location.assign(url);
  } catch (cause) {
    showError(cause);
    busy.value = false;
  }
}

function discardOfflineDirectory() {
  offlineSync.reset();
  return window.isSecureContext && typeof indexedDB !== 'undefined' ? clearOfflineSites().catch(() => {}) : Promise.resolve();
}

const confirmOfflineLogout = ref(false);
async function requestLogout() {
  if (window.isSecureContext && typeof indexedDB !== 'undefined') {
    const local = await readOfflineState().catch(() => null);
    if (local?.outbox?.count) {
      confirmOfflineLogout.value = true;
      return;
    }
  }
  await logout();
}

async function logout() {
  confirmOfflineLogout.value = false;
  panel.value = 'home'; homeSiteId.value = null;
  busy.value = true;
  error.value = '';
  try {
    await discardOfflineDirectory();
    await authApi.logout();
    await discardOfflineDirectory();
    await router.replace('/');
    mobilePairingCode.value = '';
    clearUiPreferences();
    void unsubscribeLocalBrowser();
    user.value = null;

    mode.value = 'login';
    loginForm.password = '';
  } catch (cause) {
    showError(cause);
  } finally {
    busy.value = false;
  }
}

function sessionRevoked(email: string) {
  void discardOfflineDirectory();
  mobilePairingCode.value = '';
  panel.value = 'home'; homeSiteId.value = null;
  user.value = null;

  loginForm.email = email;
  loginForm.password = '';
  mode.value = 'login';
  error.value = 'auth.errors.sessionChanged';
}

async function userUpdated(updated: ManagedUser) {
  if (updated.id !== user.value?.id) return;
  try { user.value = await authApi.user(); }
  catch (cause) { showError(cause); }
}

async function accessLost(cause: ApiError) {
  panel.value = 'home'; homeSiteId.value = null;

  if (cause.status === 403) { await router.replace({ path: route.path }); await load(); }
  showError(cause);
}

const workspaces = computed<Workspace[]>(() => {
 const items: Workspace[] = [
  { id: 'culture', label: 'workspaces.culture', description: 'workspaces.cultureHelp', icon: 'leaf', panels: [
   { id: 'plants', label: 'workspaces.tabs.plants', accessibleLabel: 'plants.manage', icon: 'leaf' },
   { id: 'catalog', label: 'workspaces.tabs.strains', accessibleLabel: 'catalog.manage', icon: 'strain' },
   { id: 'locations', label: 'workspaces.tabs.sites', accessibleLabel: 'locations.manage', icon: 'location' },
   { id: 'overview', label: 'workspaces.tabs.overview', icon: 'chart' },
  ], sections: [
   { id: 'plants', label: 'workspaces.tabs.plants', icon: 'leaf', panels: ['plants'] },
   { id: 'catalog', label: 'workspaces.tabs.strains', icon: 'strain', panels: ['catalog'] },
   { id: 'locations', label: 'workspaces.tabs.sites', icon: 'location', panels: ['locations'] },
   { id: 'overview', label: 'workspaces.tabs.overview', icon: 'chart', panels: ['overview'] },
  ] },
  { id: 'monitoring', label: 'workspaces.monitoring', description: 'workspaces.monitoringHelp', icon: 'monitor', panels: [
   { id: 'assets', label: 'workspaces.tabs.assets', accessibleLabel: 'assets.manage', icon: 'device' },
   { id: 'measurements', label: 'workspaces.tabs.measurements', accessibleLabel: 'measurements.manage', icon: 'chart' },
   { id: 'alerts', label: 'workspaces.tabs.alerts', accessibleLabel: 'alerts.manage', icon: 'bell' },
   { id: 'events', label: 'workspaces.tabs.events', accessibleLabel: 'events.manage', icon: 'journal' },
  ], sections: [
   { id: 'assets', label: 'workspaces.tabs.assets', icon: 'device', panels: ['assets'] },
   { id: 'measurements', label: 'workspaces.tabs.measurements', icon: 'chart', panels: ['measurements'] },
   { id: 'alerts', label: 'workspaces.tabs.alerts', icon: 'bell', panels: ['alerts'] },
   { id: 'events', label: 'workspaces.tabs.events', icon: 'journal', panels: ['events'] },
  ] },
 ];
 const integration: Workspace = { id: 'integration', label: 'workspaces.integration', description: 'workspaces.integrationHelp', icon: 'plug', panels: [
  { id: 'ha', label: 'navigation.homeAssistant', icon: 'homeassistant' },
  { id: 'homebridge', label: 'workspaces.homebridge', icon: 'homebridge' },
  { id: 'mqtt', label: 'workspaces.tabs.mqtt', accessibleLabel: 'mqtt.manage', icon: 'plug' },
  { id: 'bluetooth', label: 'navigation.bluetooth', icon: 'bluetooth' },
  { id: 'http', label: 'workspaces.tabs.http', accessibleLabel: 'httpIngestion.manage', icon: 'plug' },
  { id: 'sources', label: 'workspaces.tabs.sources', accessibleLabel: 'sourceDiagnostics.manage', icon: 'diagnostics' },
 ], sections: [
  { id: 'ha', label: 'navigation.homeAssistant', icon: 'homeassistant', panels: ['ha'] },
  { id: 'homebridge', label: 'workspaces.homebridge', icon: 'homebridge', panels: ['homebridge'] },
  { id: 'mqtt', label: 'workspaces.tabs.transports', icon: 'plug', panels: ['mqtt', 'http'] },
  { id: 'bluetooth', label: 'navigation.bluetooth', icon: 'bluetooth', panels: ['bluetooth'] },
  { id: 'sources', label: 'workspaces.tabs.sources', accessibleLabel: 'sourceDiagnostics.manage', icon: 'diagnostics', panels: ['sources'] },
 ] };
 if (!canManage('integrations.manage')) { integration.panels = []; integration.sections = []; }
 if (integration.panels.length) items.push(integration);
 const administration: Workspace = { id: 'administration', label: 'workspaces.administration', description: 'workspaces.administrationHelp', icon: 'shield', panels: [
  { id: 'instanceSettings', label: 'instanceSettings.title', icon: 'settings' },
  { id: 'users', label: 'workspaces.tabs.users', accessibleLabel: 'users.manage', icon: 'users' },
  { id: 'profiles', label: 'workspaces.tabs.profiles', accessibleLabel: 'accessProfiles.manage', icon: 'shield' },
  { id: 'cycleSettings', label: 'workspaces.tabs.cycle', accessibleLabel: 'navigation.lifecycle', icon: 'refresh' },
  { id: 'inventory', label: 'workspaces.tabs.inventory', accessibleLabel: 'inventory.manage', icon: 'inventory' },
  { id: 'documents', label: 'workspaces.tabs.documents', accessibleLabel: 'documents.manage', icon: 'folder' },
  { id: 'catalogs', label: 'workspaces.tabs.catalogs', icon: 'library' },
 ], sections: [
  { id: 'instanceSettings', label: 'instanceSettings.title', icon: 'settings', panels: ['instanceSettings', 'cycleSettings', 'catalogs', 'notifications'] },
  { id: 'users', label: 'workspaces.tabs.users', icon: 'users', panels: ['users', 'profiles'] },
  { id: 'inventory', label: 'workspaces.tabs.inventory', icon: 'inventory', panels: ['inventory'] },
  { id: 'documents', label: 'workspaces.tabs.documents', icon: 'folder', panels: ['documents'] },
 ] };
 if (!user.value?.permissions.includes('administration.view')) {
  administration.panels = administration.panels.filter(item => ['instanceSettings', 'inventory', 'documents'].includes(item.id));
  administration.sections = administration.sections!.filter(item => ['instanceSettings', 'inventory', 'documents'].includes(item.id));
 }
 administration.panels.push({ id: 'notifications', label: 'workspaces.tabs.notifications', icon: 'notification' });
 items.push(administration);
 items.push({ id: 'profile', label: 'navigation.account', description: 'workspaces.accountHelp', icon: 'user', panels: [
  { id: 'account', label: 'workspaces.tabs.account', icon: 'user' },
  { id: 'accountPreferences', label: 'workspaces.tabs.accountPreferences', icon: 'palette' },
  { id: 'accountAccess', label: 'workspaces.tabs.accountAccess', icon: 'shield' },
  { id: 'security', label: 'workspaces.tabs.security', icon: 'shield' },
  { id: 'accountNotifications', label: 'workspaces.tabs.notifications', icon: 'notification' },
 ] });
 return items;
});
const activeWorkspace = computed(() => workspaces.value.find(workspace => workspace.panels.some(item => item.id === panel.value)));
const navigation = computed<NavigationGroup[]>(() => [{ label: 'workspaces.browse', items: [
 { id: 'home', label: 'dashboard.manage', icon: 'home' },
 ...workspaces.value.filter(workspace => workspace.id !== 'profile').map(workspace => ({ id: workspace.panels[0]!.id, workspace: workspace.id, label: workspace.label, icon: workspace.icon, panels: workspace.panels.map(item => item.id), children: workspace.sections })),
] }]);
const breadcrumb = computed<BreadcrumbItem[]>(() => {
 const workspace = activeWorkspace.value;
 const roots: NavigationItem[] = [{ id: 'home', label: 'dashboard.manage', icon: 'home' }, ...workspaces.value.map(item => ({ id: item.panels[0]!.id, label: item.label, icon: item.icon }))];
 if (!workspace) return [{ ...roots[0]!, alternatives: roots }];
 const path: BreadcrumbItem[] = [];
 path.push({ id: workspace.panels[0]!.id, label: workspace.label, icon: workspace.icon, alternatives: roots });
 const section = workspace.sections?.find(item => item.panels.includes(panel.value));
 const settingsSection = workspace.sections?.find(item => item.id === 'instanceSettings');
 const pages = workspace.id === 'administration' ? workspace.sections ?? [] : workspace.panels;
 if (section && section.id !== panel.value && section !== settingsSection) path.push({ ...section, alternatives: workspace.sections });
 const current = workspace.panels.find(item => item.id === (settingsSection && section === settingsSection ? settingsSection.id : panel.value));
 if (current) path.push({ ...current, alternatives: pages });
 return path;
});
watch([() => route.fullPath, mode, () => workspaces.value.map(workspace => workspace.panels.map(item => item.id).join(',')).join('|')], () => {
 if (mode.value !== 'account' || showRecovery.value) return;
 const requested = route.meta.panel as AppPanel;
 if (requested !== 'home' && !workspaces.value.some(workspace => workspace.panels.some(item => item.id === requested))) { void router.replace('/'); return; }
 panel.value = requested;
 if (requested === 'home' && queryString(route.query.site)) homeSiteId.value = queryString(route.query.site);
}, { immediate: true });

const plantHistory = useSensorHistoryRoute(cause => {
 if (cause instanceof ApiError && [401, 403].includes(cause.status)) void accessLost(cause);
 else showError(cause);
}, { enabled: () => mode.value === 'account' && !['measurements', 'ha', 'homebridge', 'sources'].includes(panel.value) });
provide(sensorHistoryOpener, plantHistory.open);
const linkedStrainId = ref('');
let linkedStrainTrigger: HTMLElement | null = null;
provide(strainRecordOpener, (id, trigger) => { linkedStrainTrigger = trigger; linkedStrainId.value = id; });
function closeLinkedStrain() {
 linkedStrainId.value = '';
 void nextTick(() => { if (linkedStrainTrigger?.isConnected) linkedStrainTrigger.focus(); });
}
watch(() => [mode.value, panel.value, user.value?.id], () => { linkedStrainId.value = ''; });
const attachmentPreview = useResolvedRecordQuery('file', documentsApi.show, cause => { if (cause instanceof ApiError && [401, 403].includes(cause.status)) void accessLost(cause); else showError(cause); }, { enabled: () => mode.value === 'account' && panel.value !== 'documents' });
provide(filePreviewOpener, file => { void attachmentPreview.open(file.id); });

function navigate(value: AppPanel, focusContent = true, query: LocationQueryRaw = {}) {
 const target = value === 'sensors' ? 'assets' : value;
 if (target !== 'home' && !workspaces.value.some(workspace => workspace.panels.some(item => item.id === target))) return;

 void router.push({ path: value === 'sensors' ? hardwareTabs.channels : panelPaths[target], query: target === 'home' && homeSiteId.value ? { site: homeSiteId.value, ...query } : query }).then(() => {
  if (focusContent) void nextTick(() => mainContent.value?.focus());
 });
}
function openAbout() {
 void router.push(settingsTabs.about).then(() => nextTick(() => mainContent.value?.focus()));
}
function homeSiteChanged(id: string | null) {
 homeSiteId.value = id;
 if (panel.value === 'home' && id && !queryString(route.query.site)) void router.replace({ path: '/', query: { ...route.query, site: id } });
}

function canManage(permission: string): boolean { return !!user.value && user.value.permissions.includes(permission); }

function browseDashboardPlants(scope: PlantListScope) { navigate('plants', true, { location: scope.location.id, locationType: scope.location.type, status: scope.status }); }

function openSourceReadings(sensorId: string) { navigate('measurements', true, { sensor: sensorId }); }

function openDashboardModule(module: DashboardModule) { navigate(module); }

function accountSecured(result: SecurityResult) {
  const email = user.value?.email ?? '';
  sessionRevoked(email);
  error.value = '';
  fields.value = {};
  setupForm.token = setupForm.password = setupForm.password_confirmation = '';
  securityNotice.value = result;
  void unsubscribeLocalBrowser();
}

function openRecovery() {
  loginForm.password = ''; error.value = ''; fields.value = {}; securityNotice.value = ''; recoveryNotice.value = false; showRecovery.value = true;
}
function closeRecovery() { recoveryToken.value = ''; showRecovery.value = false; void load(); }
function passwordRecovered(email: string) {
  recoveryToken.value = ''; showRecovery.value = false; sessionRevoked(email); error.value = ''; fields.value = {}; securityNotice.value = ''; recoveryNotice.value = true;
  setupForm.token = setupForm.password = setupForm.password_confirmation = '';
  void unsubscribeLocalBrowser();
}
function captureMobilePairingLink() {
  const hash = window.location.hash;
  if (!hash.startsWith('#mobile-pairing=')) return false;
  const code = hash.slice('#mobile-pairing='.length);
  if (window.location.protocol === 'https:' && window.isSecureContext && /^[A-Za-z0-9_-]{43}$/.test(code)) mobilePairingCode.value = code;
  void router.replace({ hash: '' });
  return true;
}
function captureRecoveryLink() {
  if (captureMobilePairingLink()) return;
  if (window.location.hash.startsWith('#confirm-email=')) {
    confirmationPurpose.value = 'email-change'; activationGeneration.value++;
    activationToken.value = window.location.hash.slice('#confirm-email='.length) || 'invalid'; activationEmail.value = '';
    activationCreated.value = false; showActivation.value = true; showRecovery.value = false;
    void router.replace({ hash: '' }); return;
  }
  if (window.location.hash.startsWith('#activate-account=')) {
    confirmationPurpose.value = 'activation';
    activationGeneration.value++;
    activationToken.value = window.location.hash.slice('#activate-account='.length) || 'invalid';
    activationCreated.value = false; showActivation.value = true; showRecovery.value = false;
    void router.replace({ hash: '' }); return;
  }
  if (!window.location.hash.startsWith('#reset-password=')) return;
  recoveryToken.value = window.location.hash.slice('#reset-password='.length) || 'invalid';
  void router.replace({ hash: '' });
  recoveryGeneration.value++; showRecovery.value = true;
  loginForm.password = setupForm.token = setupForm.password = setupForm.password_confirmation = '';
}
watch(() => mode.value, value => { if (value !== 'account') showInstallationHelp.value = false; });
onMounted(() => {
  captureMobilePairingLink();
  window.addEventListener('hashchange', captureRecoveryLink);
  if (!showRecovery.value) void load();
});
onUnmounted(() => {
  startupController?.abort();
  window.removeEventListener('hashchange', captureRecoveryLink);
});
</script>

<template><div class="app-shell" :class="{ authenticated: mode === 'account' && user && !showRecovery && !showActivation, 'startup-screen': startupScreen, 'public-screen': !startupScreen && (mode !== 'account' || showRecovery || showActivation) }"><a class="skip-link" href="#main-content">{{ t('navigation.skip') }}</a>
    <AppNavigation :user-id="user?.id ?? ''" @access-lost="accessLost" v-if="mode === 'account' && user && !showRecovery && !showActivation" :active="activeWorkspace?.id === 'profile' ? 'account' : panel" :groups="navigation" :name="user.name" :avatar-url="user.avatarUrl" :busy="busy" @select="navigate" @logout="requestLogout" @installation-help="showInstallationHelp = true" />
    <header v-if="!startupScreen" class="app-header"><div v-if="mode === 'account' && user && !showRecovery && !showActivation" id="header-navigation" class="header-menu" /><div class="header-context"><div class="header-brand"><h1><button v-if="mode === 'account' && user && !showRecovery && !showActivation" type="button" class="home-title" :aria-label="siteBranding.siteTitle" data-testid="header-home" :disabled="busy" @click="navigate('home')"><BrandMark /><span class="brand-copy"><span class="site-title">{{ siteBranding.siteTitle }}</span><small v-if="siteBranding.siteSubtitle" class="site-subtitle">{{ siteBranding.siteSubtitle }}</small></span></button><span v-else class="custom-brand"><BrandMark /><span class="site-title">{{ siteBranding.siteTitle }}</span></span></h1><small v-if="siteBranding.siteSubtitle && (mode !== 'account' || !user || showRecovery || showActivation)" class="site-subtitle">{{ siteBranding.siteSubtitle }}</small></div><AppBreadcrumb v-if="mode === 'account' && !showRecovery && !showActivation" :items="breadcrumb" @select="navigate" /></div></header>
    <main id="main-content" ref="mainContent" tabindex="-1" :class="{ 'wide-main': mode === 'account' && !showRecovery && !showActivation }"><Message v-if="emailChangeSuccess && !showActivation" closable type="success" role="status">{{ t('emailChange.success') }}</Message>
      <Message v-if="mode === 'account' && oidcNotice" closable type="success">{{ t('auth.oidcLinkSuccess', { provider: oidcProviderName }) }}</Message>
      <Message v-if="mode === 'account' && panel !== 'account' && error" closable type="error" role="alert">{{ t(error, { provider: oidcProviderName }) }}</Message>
      <OfflineSyncNotice v-if="mode === 'account' && panel !== 'security' && !showRecovery && !showActivation" />
      <div class="workspace-frame" :class="{ 'module-workspace profile-workspace': mode === 'account' && !showRecovery && !showActivation && activeWorkspace?.id === 'profile' }"><WorkspaceTabs :key="activeWorkspace?.id" v-if="mode === 'account' && user && !showRecovery && !showActivation && activeWorkspace" :workspace="activeWorkspace" :active="panel" :show-tabs="activeWorkspace.id === 'profile'" :show-subpanels="!['users', 'profiles', 'mqtt', 'http', 'sources', 'instanceSettings', 'cycleSettings', 'catalogs', 'notifications'].includes(panel)" @select="navigate($event, false)"><template v-if="activeWorkspace.id === 'profile'" #actions><template v-if="panel === 'account' && accountProfile"><Button v-if="oidcEnabled && !user.oidcLinked" type="default" :loading="busy" @click="linkOidc" :aria-label="t('auth.oidcLink', { provider: oidcProviderName })" :title="t('auth.oidcLink', { provider: oidcProviderName })" class="list-action-button"><template #icon><AppIcon name="plug" /></template></Button>
         <ListActionButton icon="edit" :label="t('accountProfile.edit')" :disabled="busy" @click="accountProfile.edit()" /></template>
        <ListActionButton v-else-if="panel === 'security'" icon="refresh" :label="t('accountSecurity.reload')" :loading="accountSecurity?.loading" :disabled="!accountSecurity || accountSecurity.busy" @click="accountSecurity?.reload()" />
        <ListActionButton v-else-if="panel === 'accountNotifications'" icon="refresh" :label="t('notifications.reload')" :disabled="!notificationPreferences || notificationPreferences.busy" @click="notificationPreferences?.reload()" /></template></WorkspaceTabs>
      <div id="workspace-content" :class="{ 'workspace-pages': mode === 'account' && !showRecovery && !showActivation }" :role="activeWorkspace && mode === 'account' && !showRecovery && !showActivation ? (activeWorkspace.id === 'profile' ? 'tabpanel' : 'region') : undefined" :aria-labelledby="activeWorkspace && mode === 'account' && !showRecovery && !showActivation ? (activeWorkspace.id === 'profile' ? `workspace-tab-${panel}` : 'workspace-title') : undefined" tabindex="-1"><AccountActivation v-if="showActivation" :key="activationGeneration" :token="activationToken" :purpose="confirmationPurpose" :email="activationEmail" :newly-created="activationCreated" @close="closeActivation" @completed="activated" />
      <PasswordRecovery v-else-if="showRecovery" :key="recoveryGeneration" :token="recoveryToken" @discard-token="recoveryToken = ''" @close="closeRecovery" @completed="passwordRecovered" />
      <section v-else-if="startupScreen" class="startup-card" :aria-busy="mode === 'loading'" data-testid="startup-screen"><BrandMark /><span class="startup-brand">{{ siteBranding.siteTitle }}</span>
       <ProgressSpinner v-if="mode === 'loading'" class="startup-spinner" aria-hidden="true" :stroke-width="3"></ProgressSpinner>
       <h2 :role="mode === 'loading' ? 'status' : undefined">{{ t(mode === 'loading' ? 'auth.loading' : 'auth.connectionTitle') }}</h2>
       <p :role="mode === 'error' ? 'alert' : undefined">{{ t(mode === 'loading' ? 'auth.loadingHelp' : error || 'auth.errors.requestFailed') }}</p>
       <Button v-if="mode === 'error'" :aria-label="t('auth.retry')" @click="load" type="primary"><template #icon><AppIcon name="refresh" /></template><span>{{ t('actions.retry') }}</span></Button></section>
      <PlantDashboard v-else-if="mode === 'account' && user && panel === 'home'" :initial-site-id="homeSiteId" :can-manage="canManage('plants.manage')" @site-changed="homeSiteChanged" @browse-plants="browseDashboardPlants" @access-lost="accessLost" />
      <PlantRegistry embedded :key="[route.query.locationType, route.query.location].join(':')" v-else-if="showPlants && mode === 'account' && user" :can-manage="canManage('plants.manage')" @close="showPlants = false" @access-lost="accessLost" />
      <InstanceSettings v-else-if="(['instanceSettings', 'cycleSettings', 'catalogs'].includes(panel) || showNotifications) && mode === 'account' && user" :can-administer="user.permissions.includes('administration.view')" @access-lost="accessLost" />
      <StrainCatalog :can-manage-strains="canManage('strains.manage')" :can-manage-plants="canManage('plants.manage')" embedded v-else-if="showCatalog && mode === 'account' && user" :key="panel" @close="navigate('home')" @access-lost="accessLost" />
      <BluetoothIntegration v-else-if="panel === 'bluetooth' && mode === 'account' && user" :can-discover="user.locationAccess == null" @access-lost="accessLost" />
      <MeasurementBoard embedded provider="homebridge" v-else-if="panel === 'homebridge' && mode === 'account' && user" :can-manage="canManage('integrations.manage')" :can-discover="user.locationAccess == null" @readings="openSourceReadings" @access-lost="accessLost" />
      <SourceDiagnostics embedded v-else-if="showSourceDiagnostics && mode === 'account' && user" @close="showSourceDiagnostics = false" @readings="openSourceReadings" @access-lost="accessLost" />
      <MqttSources embedded v-else-if="showMqtt && mode === 'account' && user" @close="showMqtt = false" @access-lost="accessLost"><template #navigation><WorkspaceSectionTabs v-if="activeWorkspace" :workspace="activeWorkspace" :active="panel" @select="navigate($event, false)" /></template></MqttSources>
      <HttpIngestion embedded v-else-if="showHttpIngestion && mode === 'account' && user" @close="showHttpIngestion = false" @access-lost="accessLost"><template #navigation><WorkspaceSectionTabs v-if="activeWorkspace" :workspace="activeWorkspace" :active="panel" @select="navigate($event, false)" /></template></HttpIngestion>
      <AccessProfiles embedded v-else-if="showAccessProfiles && mode === 'account' && user" @close="showAccessProfiles = false" @access-lost="accessLost"><template #navigation><UserManagementTabs active="profiles" @select="navigate($event, false)" /></template></AccessProfiles>
      <NotificationPreferences ref="notificationPreferences" v-else-if="panel === 'accountNotifications' && mode === 'account' && user" @access-lost="accessLost" />
      <AccountSecurity ref="accountSecurity" v-else-if="showAccountSecurity && mode === 'account' && user" :oidc-linked="user.oidcLinked" :email="user.email" :passkeys-available="passkeysAvailable" @secured="accountSecured" @access-lost="accessLost" />
      <InstallationDashboard embedded :can-manage="canManage('plants.manage')" :can-manage-integrations="canManage('integrations.manage')" v-else-if="showDashboard && mode === 'account' && user" @close="showDashboard = false" @open="openDashboardModule" @access-lost="accessLost" />
      <MonitoringAlerts embedded v-else-if="showAlerts && mode === 'account' && user" :can-manage="canManage('alerts.manage')" @close="showAlerts = false" @access-lost="accessLost" />
      <MeasurementWorkspace embedded v-else-if="showMeasurements && mode === 'account' && user" @close="showMeasurements = false" @access-lost="accessLost" />
      <MeasurementBoard embedded v-else-if="panel === 'ha' && mode === 'account' && user" :can-manage="panel === 'ha' && canManage('integrations.manage')" :can-discover="user.locationAccess == null" @readings="openSourceReadings" @close="showMeasurements = false" @access-lost="accessLost" />
      <InventoryRegistry embedded v-else-if="showInventory && mode === 'account' && user" :can-manage="canManage('inventory.manage') && user.locationAccess == null" @close="showInventory = false" @access-lost="accessLost" />
      <DocumentLibrary embedded v-else-if="showDocuments && mode === 'account' && user" @close="showDocuments = false" @access-lost="accessLost" />
      <EventJournal embedded v-else-if="showEvents && mode === 'account' && user" @close="showEvents = false" @access-lost="accessLost" />
      <AssetRegistry embedded v-else-if="showAssets && mode === 'account' && user" :can-manage="canManage('assets.manage')" :can-configure-power="canManage('integrations.manage') && !user.locationAccess" :can-manage-sensors="canManage('sensors.manage')" @close="showAssets = false" @access-lost="accessLost" />
      <LocationExplorer embedded v-else-if="showLocations && mode === 'account' && user" :can-manage="canManage('locations.manage')" :can-create-site="user.locationAccess == null" @close="showLocations = false" @access-lost="accessLost" />
      <UserAdministration embedded v-else-if="showUsers && mode === 'account' && user" :current-user="user" @close="showUsers = false" @updated="userUpdated" @session-revoked="sessionRevoked" @access-lost="accessLost"><template #navigation><UserManagementTabs active="users" @select="navigate($event, false)" /></template></UserAdministration>
      <AccountProfile :section="panel === 'accountPreferences' ? 'preferences' : panel === 'accountAccess' ? 'access' : 'profile'" ref="accountProfile" v-else-if="mode === 'account' && user" :user="user" :oidc-enabled="oidcEnabled" :busy="busy" :error="error" @updated="user = $event" @access-lost="accessLost" />
      <Card v-else class="auth-card"><template #header><div class="card-heading-block"><PageTitle :icon="mode === 'setup' ? 'settings' : mode === 'register' ? 'userPlus' : 'login'">{{ t(mode === 'setup' ? 'auth.setupTitle' : mode === 'register' ? 'auth.registerTitle' : 'auth.loginTitle') }}</PageTitle><div class="card-subtitle"><p v-if="mode === 'setup'">{{ t('auth.setupDescription') }}</p><p v-else-if="mode === 'register'">{{ t('auth.registerDescription') }}</p><p v-else-if="mode === 'login'">{{ t('auth.loginDescription') }}</p></div></div></template>

        <template #default><Message v-if="activationSuccess && mode === 'login'" closable type="success" role="status">{{ t('accountActivation.success') }}</Message>
          <Message v-if="recoveryNotice && mode === 'login'" closable type="success" role="status">{{ t('passwordRecovery.success') }}</Message>
          <Message v-if="securityNotice && mode === 'login'" closable type="success" role="status">{{ t(`accountSecurity.${securityNotice}`) }}</Message>
          <Message closable v-if="error" type="error" class="form-message" role="alert">{{ t(error, { provider: oidcProviderName }) }}</Message>
          <Button v-if="mode === 'error'" :aria-label="t('auth.retry')" @click="load" type="primary"><template #icon><AppIcon name="refresh" /></template><span>{{ t('actions.retry') }}</span></Button>
          <form v-if="mode === 'setup' || mode === 'register'" @submit.prevent="submit(mode === 'setup' ? 'setup' : 'register')" novalidate><Message v-if="mode === 'setup'" closable type="info">{{ t('auth.tokenHelp') }}</Message>
            <div v-if="mode === 'setup'" class="field"><label for="setup-token">{{ t('auth.setupToken') }}</label>
              <InputText placeholder="" v-model:value="setupForm.token" type="password" :status="!!fields.token ? 'error' : undefined" :disabled="busy" :input-props="{ 'id': 'setup-token', 'autocomplete': 'off', 'aria-describedby': 'setup-token-error' }"></InputText>
              <small v-if="fields.token" id="setup-token-error" class="field-error">{{ t(fields.token) }}</small></div>
            <template v-if="mode !== 'setup' || localEnabled"><div class="field"><label for="setup-name">{{ t('auth.name') }}</label>
              <InputText placeholder="" v-model:value="setupForm.name" maxlength="120" :status="!!fields.name ? 'error' : undefined" :disabled="busy" :input-props="{ 'id': 'setup-name', 'autocomplete': 'name', 'aria-describedby': 'setup-name-error' }"></InputText>
              <small v-if="fields.name" id="setup-name-error" class="field-error">{{ t(fields.name) }}</small></div>
            <div class="field"><label for="setup-email">{{ t('auth.email') }}</label>
              <InputText placeholder="" v-model:value="setupForm.email" :status="!!fields.email ? 'error' : undefined" :disabled="busy" :input-props="{ 'id': 'setup-email', type: 'email', 'autocomplete': 'email', 'aria-describedby': 'setup-email-error' }"></InputText>
              <small v-if="fields.email" id="setup-email-error" class="field-error">{{ t(fields.email) }}</small></div>
            <div class="field"><label for="setup-password">{{ t('auth.password') }}</label>
              <Password placeholder="" v-model:value="setupForm.password" show-password-on="click" :status="!!fields.password ? 'error' : undefined" :disabled="busy" type="password" :input-props="{ 'id': 'setup-password', 'autocomplete': 'new-password', 'aria-describedby': 'setup-password-help setup-password-error' }"></Password>
              <small id="setup-password-help">{{ t('auth.passwordHelp') }}</small>
              <small v-if="fields.password" id="setup-password-error" class="field-error">{{ t(fields.password) }}</small></div>
            <div class="field"><label for="setup-confirmation">{{ t('auth.passwordConfirmation') }}</label>
              <Password placeholder="" v-model:value="setupForm.password_confirmation" :disabled="busy" type="password" :input-props="{ 'id': 'setup-confirmation', 'autocomplete': 'new-password' }"></Password></div>
            <Button attr-type="submit" :loading="busy" class="submit-button" type="primary"><template #icon><AppIcon name="userPlus" /></template><span>{{ t(mode === 'setup' ? 'auth.createAdministrator' : 'auth.register') }}</span></Button></template></form>
          <div v-if="mode === 'setup' && oidcEnabled" class="login-options"><hr class="auth-divider" />
            <Button attr-type="button" :loading="busy" class="submit-button" @click="setupOidc" type="primary"><template #icon><OidcProviderIcon :name="siteBranding.oidcProviderIcon" /></template><span>{{ t('auth.oidcSetup', { provider: oidcProviderName }) }}</span></Button>
            <small>{{ t('auth.oidcSetupHelp') }}</small></div>
          <Message v-if="mode === 'login' && mobilePairingCode" type="info" role="status">{{ t('mobilePairing.signInHelp') }}</Message>
          <form v-if="mode === 'login' && localEnabled" @submit.prevent="submit('login')" novalidate><div class="field"><label for="login-email">{{ t('auth.email') }}</label>
              <InputText placeholder="" v-model:value="loginForm.email" :status="!!fields.email ? 'error' : undefined" :disabled="busy" :input-props="{ 'id': 'login-email', type: 'email', 'autocomplete': 'username', 'aria-describedby': 'login-email-error' }"></InputText>
              <small v-if="fields.email" id="login-email-error" class="field-error">{{ t(fields.email) }}</small></div>
            <div class="field"><label for="login-password">{{ t('auth.password') }}</label>
              <Password placeholder="" v-model:value="loginForm.password" show-password-on="click" :status="!!fields.password ? 'error' : undefined" :disabled="busy" type="password" :input-props="{ 'id': 'login-password', 'autocomplete': 'current-password', 'aria-describedby': 'login-password-error' }"></Password>
              <small v-if="fields.password" id="login-password-error" class="field-error">{{ t(fields.password) }}</small></div>
            <Button attr-type="submit" :loading="busy" class="submit-button" type="primary"><template #icon><AppIcon name="login" /></template><span>{{ t('auth.login') }}</span></Button></form>
          <div v-if="mode === 'login' && (localEnabled || oidcEnabled || passkeysEnabled)" class="login-options"><div v-if="localEnabled" class="auth-links"><Button v-if="registrationEnabled" type="default" text :disabled="busy" class="auth-option-link" @click="mode = 'register'; error = ''; fields = {}"><span>{{ t('auth.register') }}</span></Button>
              <Button type="default" text :disabled="busy" class="auth-option-link forgot-password-link" @click="openRecovery"><span>{{ t('passwordRecovery.open') }}</span></Button></div>
            <hr v-if="localEnabled && (oidcEnabled || passkeysEnabled)" class="auth-divider" />
            <Button v-if="passkeysEnabled" attr-type="button" :disabled="busy" class="submit-button" @click="submit('passkey')"><template #icon><AppIcon name="key" /></template><span>{{ t('auth.passkeyLogin') }}</span></Button>
            <a v-if="oidcEnabled" class="oidc-login" href="/api/v1/auth/oidc/redirect"><OidcProviderIcon :name="siteBranding.oidcProviderIcon" />{{ t('auth.oidcLogin', { provider: oidcProviderName }) }}</a></div>
          <Button v-if="mode === 'register'" type="default" text :disabled="busy" class="recovery-link" @click="mode = 'login'; error = ''; fields = {}; setupForm.password = setupForm.password_confirmation = ''"><span>{{ t('auth.backToLogin') }}</span></Button>
          <Message v-if="(mode === 'setup' || (mode === 'login' && !passkeysEnabled)) && !localEnabled && !oidcEnabled" type="warning" closable>{{ t('auth.noSignIn') }}</Message></template></Card></div></div></main>
    <FormDialog v-if="confirmOfflineLogout" :visible="true" icon="logout" size="compact"
      :title="t('offlineJournal.logout')" :close-label="t('actions.cancel')" @update:visible="!$event && (confirmOfflineLogout = false)">
      <p>{{ t('offlineJournal.logoutHelp') }}</p>
      <template #footer><Button type="error" @click="logout">{{ t('offlineJournal.logout') }}</Button></template>
    </FormDialog>
    <MobilePairingConfirmation v-if="mode === 'account' && user && mobilePairingCode && !showRecovery && !showActivation" :key="mobilePairingCode" :code="mobilePairingCode" :email="user.email" @close="mobilePairingCode = ''" />
    <SensorHistory v-if="mode === 'account' && plantHistory.sensor.value" :sensor-id="plantHistory.sensor.value.id" :name="plantHistory.sensor.value.name" @close="plantHistory.close" @access-lost="accessLost" />
    <StrainCatalog v-if="mode === 'account' && user && linkedStrainId" :key="linkedStrainId" detail-only :initial-strain-id="linkedStrainId" :can-manage-strains="canManage('strains.manage')" :can-manage-plants="canManage('plants.manage')" @close="closeLinkedStrain" @access-lost="linkedStrainId = ''; accessLost($event)" />
    <FilePreview v-if="mode === 'account' && attachmentPreview.record.value" :file="attachmentPreview.record.value" @close="attachmentPreview.close()" @access-lost="accessLost" />
    <LanguagePicker v-if="!startupScreen && (mode !== 'account' || showRecovery || showActivation)" class="auth-language" />
    <AppAvailability v-if="!startupScreen" :menu-help="mode === 'account' && !showRecovery && !showActivation" :help-open="showInstallationHelp" @close="showInstallationHelp = false" @closed="restoreInstallationHelpFocus" /><AppFooter v-if="!startupScreen" :name="siteBranding.siteTitle" :check-updates="mode === 'account' && !!user && !showRecovery && !showActivation" @open-about="openAbout" />
    <BackToTop v-if="mode === 'account' && user && !showRecovery && !showActivation" :focus-target="mainContent" />
</div></template>

<style>
.home-title { max-width: 100%; min-width: 0; display: inline-flex; align-items: flex-start; gap: var(--space-2); text-align: left; border: 0; padding: 0; color: inherit; background: transparent; font: inherit; letter-spacing: inherit; cursor: pointer; }.home-title .brand-mark { width: var(--app-control-height); height: var(--app-control-height); border: 1px solid var(--theme-border); border-radius: .65rem; padding: var(--space-1); }.home-title:focus-visible { outline: 2px solid var(--theme-focus, #648752); outline-offset: 4px; border-radius: .2rem; }
.header-menu { display: none; }
.brand-copy { display: flex; flex-direction: column; justify-content: center; gap: var(--space-0-5); min-width: 0; min-height: var(--app-control-height); }
.site-title { min-width: 0; overflow-wrap: anywhere; }
.custom-brand { max-width: 100%; display: inline-flex; align-items: center; gap: var(--space-3); }

* { box-sizing: border-box; }
body { margin: 0; font-family: system-ui, sans-serif; background: var(--theme-subtle, #f1f6f3); color: var(--theme-text, #17372b); }
.app-shell { min-height: 100vh; }
.app-header { max-width: 72rem; padding: var(--space-6) var(--space-8); margin: auto; display: flex; justify-content: space-between; align-items: center; gap: var(--ui-grid-gap); }
.app-header h1 { font-size: 1.25rem; letter-spacing: -0.04em; margin: 0; }
.language-picker { display: flex; align-items: center; gap: var(--space-2); font-size: 0.875rem; }
.language-picker select { padding: var(--space-2); border: 1px solid var(--theme-border, #c1d3c8); border-radius: 0.5rem; color: inherit; background: transparent; }
main { max-width: 31rem; margin: var(--ui-content-gap) auto; padding: 0 var(--space-4) 3rem; }
main.wide-main { max-width: 72rem; }
.auth-language { margin: 0 auto var(--ui-content-gap); width: min(100% - 2rem, 26rem); }
.auth-card { padding: var(--space-3); box-shadow: 0 1rem 4rem color-mix(in srgb, var(--theme-shadow) 5%, transparent); }
.auth-card h2 { margin: 0; font-size: 1.6rem; letter-spacing: -0.035em; }
.auth-card .n-card-header__extra p { margin: var(--space-2) 0 var(--space-3); line-height: 1.6; }
.field { display: flex; flex-direction: column; gap: var(--space-2); margin: var(--ui-content-gap) 0; }
.field label { font-size: 0.875rem; font-weight: 600; }
.field small { line-height: 1.5; font-size: 0.8rem; }
.field-error { color: var(--theme-danger); }
.form-message { margin-bottom: var(--ui-content-gap); }
.submit-button { width: 100%; margin-top: var(--space-2); }
.oidc-login { display: flex; align-items: center; justify-content: center; gap: var(--space-2); text-align: center; padding: var(--space-3); border-radius: 0.5rem; background: var(--theme-subtle, #e3f2e9); color: var(--theme-success, #1d5b3a); font-weight: 600; text-decoration: none; }

.oidc-login:focus-visible { outline: 2px solid currentColor; outline-offset: 3px; }

@media (max-width: 480px) { .app-header { padding: var(--space-5) var(--space-4); flex-wrap: wrap; } main { margin-top: var(--ui-content-gap); } }

</style>

<style>
.app-shell.authenticated { display: flex; flex-direction: column; padding-left: 16rem; background: var(--theme-subtle, #f6f8f3); }
.authenticated .app-header { width: 100%; margin: 0 auto; max-width: 88rem; padding: var(--space-6) var(--space-10) var(--space-4); }
.authenticated .app-header h1 { font-size: .9rem; letter-spacing: -.025em; color: var(--theme-muted, #63745f); font-weight: 550; }
.authenticated main.wide-main { flex: 1; width: 100%; max-width: 88rem; padding: 0 var(--space-10) 3rem; margin: var(--ui-content-gap) auto 0; }
.authenticated .app-footer { max-width: 88rem; margin: 0 auto; padding: 0 var(--space-10) var(--space-4); }
.authenticated .app-availability { max-width: 88rem; padding: 0 var(--space-10); }
main:focus { outline: none; }.skip-link { position: fixed; left: 1rem; top: -5rem; z-index: 100; background: var(--theme-brand, #173e32); color: var(--theme-accent-text); padding: var(--space-3) var(--space-4); border-radius: .5rem; }.skip-link:focus { top: .5rem; }
@media (max-width: 1000px) { .authenticated .app-footer { padding-inline: var(--space-4); }.app-shell.authenticated { padding-left: 0; }.authenticated .app-header { padding: var(--space-4); align-items: flex-start; }.header-menu { display: flex; flex: 0 0 var(--app-control-height); }.authenticated main.wide-main { padding: 0 var(--space-4) var(--space-8); margin-top: var(--ui-content-gap); }.authenticated .app-availability { padding: 0 var(--space-4); }.authenticated .language-picker label { position: absolute; width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%); }.authenticated .app-header { flex-wrap: nowrap; }.authenticated .app-header h1 { min-width: 0; overflow-wrap: anywhere; } }
</style>

<style>.header-brand { min-width: 0; overflow-wrap: anywhere; }.header-brand h1 { margin: 0; }.site-subtitle { display: block; color: var(--theme-muted, #63745e); font-size: .75rem; line-height: 1.45; margin-top: var(--space-1); }.header-context { min-width: 0; width: 100%; display: flex; flex-wrap: wrap; gap: var(--space-2) var(--ui-grid-gap); align-items: center; }.header-brand { flex: 0 1 auto; }.header-context > .app-breadcrumb { flex: 1 1 18rem; }
.authenticated .header-context { align-items: flex-start; flex: 1; }
.authenticated .header-brand { max-width: min(100%, 28rem); }
.authenticated .site-title { font-size: 1rem; font-weight: 650; line-height: 1.3; color: var(--theme-text); }
.authenticated .site-subtitle { margin: 0; font-size: .72rem; font-weight: 400; line-height: 1.45; letter-spacing: 0; text-wrap: pretty; }
.authenticated .header-context > .app-breadcrumb { display: flex; align-items: center; min-height: var(--app-control-height); }
@media (max-width: 1000px) { .authenticated .header-context > .app-breadcrumb { min-height: 0; } }
</style>

<style>
.app-shell.startup-screen { display: grid; place-items: center; padding: var(--space-5); min-height: 100svh; background: radial-gradient(ellipse at top, var(--theme-surface), var(--theme-subtle)); }
.startup-screen main { width: 100%; max-width: 25rem; margin: 0; padding: 0; }
.startup-card { display: flex; align-items: center; flex-direction: column; gap: var(--space-3); padding: var(--space-8) var(--space-6); border: 1px solid var(--theme-border); border-radius: 1.25rem; background: var(--theme-surface); box-shadow: 0 .75rem 3rem color-mix(in srgb, var(--theme-shadow) 3%, transparent); text-align: center; }
.startup-card .brand-mark { width: 3.5rem; height: 3.5rem; padding: var(--space-1-5); }
.startup-brand { font-size: .8rem; font-weight: 600; color: var(--theme-muted); }
.startup-card h2 { font-size: 1.05rem; letter-spacing: -.025em; margin: 0; }
.startup-card p { margin: 0; font-size: .8rem; color: var(--theme-muted); line-height: 1.6; }
.startup-card .startup-spinner { width: 1.75rem; height: 1.75rem; margin: var(--space-1-5) 0; }
.startup-card .startup-spinner circle { stroke: var(--theme-accent-fill); animation: none; }
@media (prefers-reduced-motion: reduce) { .startup-card .startup-spinner, .startup-card .startup-spinner * { animation: none; } }
</style>

<style>
.app-shell.public-screen { display: grid; grid-template-columns: minmax(0, 25rem); justify-content: center; align-content: center; gap: var(--ui-grid-gap); min-height: 100svh; padding: var(--space-8) var(--space-4); background: radial-gradient(ellipse at top, var(--theme-surface), var(--theme-subtle)); }
.public-screen .app-header { width: 100%; margin: 0 0 var(--space-1-5); padding: 0; justify-content: center; text-align: center; }
.public-screen .header-context { justify-content: center; }
.public-screen .custom-brand { gap: var(--space-3); font-size: 1.2rem; letter-spacing: -.035em; }
.public-screen .custom-brand .brand-mark { width: 3rem; height: 3rem; padding: var(--space-1-5); }
.public-screen .site-subtitle { max-width: 23rem; font-size: .72rem; }
.public-screen main { width: 100%; max-width: none; padding: 0; margin: 0; }
.public-screen .auth-card { padding: 0; border: 1px solid var(--theme-border); border-radius: 1.1rem; background: var(--theme-surface); box-shadow: 0 .75rem 3rem color-mix(in srgb, var(--theme-shadow) 3%, transparent); }
.public-screen .auth-card .n-card__content { padding: var(--space-6); gap: var(--space-3); }
.public-screen .auth-card h2 { font-size: 1.15rem; line-height: 1.4; }
.public-screen .auth-card .n-card-header__extra { font-size: .8rem; color: var(--theme-muted); }
.public-screen .auth-card .n-card-header__extra p { margin: 0; line-height: 1.6; }
.public-screen .field { gap: var(--space-1-5); margin: var(--ui-content-gap) 0; }
.public-screen .field label { font-size: .76rem; font-weight: 550; }
.public-screen .field .n-input { width: 100%; min-height: var(--app-control-height); font-size: .84rem; }
.public-screen .field .n-input__input-el { height: var(--n-height); line-height: var(--n-height) !important; }
.public-screen .field .n-input__border { border-color: color-mix(in srgb, var(--theme-muted) 75%, var(--theme-surface)); }
.public-screen .submit-button, .public-screen .oidc-login { min-height: 2.65rem; font-size: .8rem; border-radius: .6rem; }
.public-screen .oidc-login { padding: var(--space-3); margin-top: var(--space-1); border: 1px solid var(--theme-border); font-weight: 550; }
.public-screen .login-options { display: grid; gap: var(--ui-grid-gap); margin-top: var(--space-3); }
.public-screen .auth-links { display: flex; align-items: center; justify-content: space-between; gap: var(--space-3); }
.public-screen .auth-option-link { margin: 0; padding: 0; min-height: 0; font-size: .74rem; line-height: 1.4; color: var(--theme-muted); }
.public-screen .forgot-password-link { margin-left: auto; }
.public-screen .auth-divider { width: 100%; height: 0; margin: 0; border: 0; border-top: 1px solid var(--theme-border); }
.public-screen .login-options .oidc-login { margin: 0; }
.public-screen .recovery-link { display: flex; margin: var(--space-3) auto 0; padding: var(--space-1); font-size: .74rem; color: var(--theme-muted); }
.public-screen .auth-language { display: flex; align-items: center; justify-content: space-between; gap: var(--ui-grid-gap); margin: 0; padding: 0 var(--space-1-5); width: 100%; max-width: none; }
.public-screen .auth-language label { margin: 0; font-size: .72rem; color: var(--theme-muted); }
.public-screen .auth-language > :last-child { width: 10rem; max-width: 65%; }
.public-screen .app-availability { width: 100%; margin: 0; padding: 0 var(--space-1-5); color: var(--theme-muted); }
.public-screen .app-availability .installation { font-size: .72rem; }
.public-screen .app-availability details { padding: 0; }
.public-screen .app-availability details p { font-size: .74rem; }
@media (max-width: 480px) { .app-shell.public-screen { padding: var(--space-6) var(--space-4); gap: var(--ui-grid-gap); }.public-screen .auth-card .n-card__content { padding: var(--space-5); } }
</style>
