<script setup lang="ts">
import EmailChangeNotice from '../../components/EmailChangeNotice.vue';
import DisclosureSummary from '../../components/DisclosureSummary.vue';
import DiscardChangesDialog from '../../components/DiscardChangesDialog.vue';
import { useUnsavedChanges } from '../../lib/unsaved-changes';
import { useFieldErrors } from '../../lib/field-errors';
import { useRememberDisclosure } from '../../lib/disclosure-preferences';
const vRememberDisclosure = useRememberDisclosure('AccountProfile');
import { computed, onUnmounted, reactive, ref, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import { NButton as Button } from 'naive-ui';
import Message from '../../components/AppMessage.vue';
import { NTag as Tag } from 'naive-ui';
import AppIcon from '../../components/AppIcon.vue';
import UserAvatar from '../../components/UserAvatar.vue';
import AppearancePicker from '../../components/AppearancePicker.vue';
import LanguagePicker from '../../components/LanguagePicker.vue';
import FormDialog from '../../components/FormDialog.vue';
import { ApiError, type AuthUser } from '../../api/auth';
import { accountProfileApi } from '../../api/accountProfile';
import { oidcProviderName } from '../../lib/branding';
import { accessProfileName } from '../../api/accessProfiles';
import { permissionLabel } from '../../api/permissions';
import en from '../../locales/en/account-profile.json';
import fr from '../../locales/fr/account-profile.json';
const props = defineProps<{ user: AuthUser; oidcEnabled: boolean; busy: boolean; error?: string; section?: 'profile' | 'preferences' | 'access' }>();
const emit = defineEmits<{ updated: [user: AuthUser]; accessLost: [cause: ApiError] }>();
const { t, te, locale, mergeLocaleMessage } = useI18n({ useScope: 'global' });
mergeLocaleMessage('en', { accountProfile: en }); mergeLocaleMessage('fr', { accountProfile: fr });
const editing = ref(false); const saving = ref(false); const saved = ref(false); const error = ref('');
const form = reactive({ name: '', email: '' });
const dialogId = useId(); let alive = true;
const baseline = ref('');
const dirty = computed(() => saving.value || editing.value && JSON.stringify(form) !== baseline.value);
const unsaved = useUnsavedChanges(dirty, saving);
const validation = useFieldErrors(() => ({ name: { id: 'profile-name', message: 'users.validation.nameInvalid' }, email: { id: 'profile-email', message: 'users.validation.emailInvalid' } }), () => ({ ...form }));
const fields = validation.fields;
async function closeForm() { if (await unsaved.confirmDiscard()) { editing.value = false; error.value = ''; validation.clear(); } }

function message(key: string) { return /^((auth|users)\.(errors|validation)|emailChange)\.[A-Za-z]+$/.test(key) && te(key) ? t(key, { provider: oidcProviderName.value }) : t('auth.errors.requestFailed'); }
async function edit() { if (!await unsaved.confirmDiscard()) return; Object.assign(form, { name: props.user.name, email: props.user.email }); error.value = ''; validation.clear(); saved.value = false; baseline.value = JSON.stringify(form); editing.value = true; }
async function save() {
 if (saving.value) return;
 error.value = ''; validation.clear();
 if (!form.name.trim() || form.name.length > 120) validation.set('name');
 if (!form.email.trim() || form.email.trim().length > 254 || !/^[^\s@]+@[^\s@]+$/.test(form.email.trim())) validation.set('email');
 if (Object.keys(fields.value).length) { error.value = message('auth.errors.validationFailed'); void validation.focus(); return; }
 saving.value = true;
 try {
  const user = await accountProfileApi.update({ name: form.name, email: form.email, locale: locale.value === 'fr' ? 'fr' : 'en' });
  if (alive) { emit('updated', user); editing.value = false; saved.value = true; }
 } catch (cause) {
  if (!alive) return;
  error.value = message(cause instanceof ApiError && cause.status === 422 ? 'auth.errors.validationFailed' : cause instanceof ApiError ? cause.code : 'auth.errors.requestFailed');
  if (cause instanceof ApiError) { validation.capture(cause); if (cause.fields.email?.includes('users.validation.emailTaken')) { validation.set('email', 'users.validation.emailTaken'); void validation.focus(); } if ([401, 403].includes(cause.status)) { editing.value = false; emit('accessLost', cause); } }
 } finally { if (alive) saving.value = false; }
}
async function manageEmail(resend: boolean) {
 if (saving.value) return;
 saving.value = true; error.value = '';
 try {
  const user = await (resend ? accountProfileApi.resendEmail(locale.value === 'fr' ? 'fr' : 'en') : accountProfileApi.cancelEmail());
  if (alive) emit('updated', user);
 } catch (cause) {
  if (alive) { error.value = message(cause instanceof ApiError ? cause.code : 'auth.errors.requestFailed'); if (cause instanceof ApiError && [401, 403].includes(cause.status)) emit('accessLost', cause); }
 } finally { if (alive) saving.value = false; }
}
defineExpose({ edit });
onUnmounted(() => { alive = false; });
</script>
<template><DiscardChangesDialog v-if="unsaved.pending.value" @decision="unsaved.finish" />
 <section class="account account-profile" data-testid="account-profile"><Message v-if="error && !editing" type="error" closable role="alert">{{ error }}</Message>
  <Message v-if="props.error" type="error" closable role="alert">{{ message(props.error) }}</Message>
  <Message v-if="saved" type="success" closable role="status">{{ t('accountProfile.saved') }}</Message>
  <header v-if="!section || section === 'profile'" class="profile-identity account-section"><UserAvatar :name="user.name" :url="user.avatarUrl" :size="64" />
   <div class="profile-name"><h3>{{ user.name }}</h3><p class="profile-email"><AppIcon name="mail" /><span>{{ user.email }}</span></p><div class="profile-tags"><Tag v-for="profile in user.permissionRoles" :key="profile.id" type="default">{{ accessProfileName(profile.name, t) }}</Tag><Tag v-if="oidcEnabled || user.oidcLinked" :type="user.oidcLinked ? 'success' : 'default'" data-testid="account-oidc-tag"><template #default><AppIcon :name="user.oidcLinked ? 'check' : 'unlink'" />{{ t(user.oidcLinked ? 'accountProfile.linked' : 'accountProfile.unlinked', { provider: oidcProviderName }) }}</template></Tag></div></div></header>
  <EmailChangeNotice v-if="user.pendingEmail" :email="user.pendingEmail" :busy="saving" @resend="manageEmail(true)" @cancel="manageEmail(false)" />
  <div class="account-tab-content"><section v-if="section === 'preferences'" class="account-section profile-preferences" data-testid="profile-preferences" :aria-label="t('accountProfile.preferences')"><h3><AppIcon name="palette" />{{ t('accountProfile.preferences') }}</h3><p class="section-help">{{ t('accountProfile.preferencesHelp') }}</p><div class="preferences-grid"><LanguagePicker /><AppearancePicker /></div><small>{{ t('auth.languageHelp') }}</small></section>
   <section v-if="section === 'access'" class="account-section profile-access" :aria-label="t('accountProfile.access')"><h3><AppIcon name="user" />{{ t('accountProfile.access') }}</h3><p class="section-help">{{ t('accountProfile.accessHelp') }}</p><dl><div><dt>{{ t('accountProfile.locations') }}</dt><dd v-if="user.locationAccess == null">{{ t('accountProfile.allLocations') }}</dd><dd v-else><span v-for="location in user.locationAccess" :key="`${location.type}:${location.id}`" class="access-chip">{{ location.name }}</span></dd></div></dl><details v-if="user.permissions.length" v-remember-disclosure="'permissions'"><DisclosureSummary icon="shield">{{ t('users.ownPermissions') }}</DisclosureSummary><ul><li v-for="permission in user.permissions" :key="permission">{{ permission === 'administration.view' ? t('auth.adminAccess') : t(permissionLabel(permission)) }}</li></ul></details></section></div>
  <FormDialog v-if="editing" :visible="true" size="compact" icon="user" :title="t('accountProfile.edit')" :close-label="t('accountProfile.cancel')" :busy="saving" :error="error" @update:visible="!$event && closeForm()"><form :id="`${dialogId}-profile`" class="dialog-form" novalidate @submit.prevent="save"><div class="field"><label for="profile-name">{{ t('accountProfile.displayName') }}</label><input id="profile-name" v-bind="validation.attrs('name')" v-model="form.name" autocomplete="nickname" maxlength="120" required :disabled="saving" :aria-invalid="!!fields.name" /><small v-if="fields.name" id="profile-name-error" role="alert" class="field-error">{{ message(fields.name) }}</small></div><div class="field"><label for="profile-email">{{ t('accountProfile.email') }}</label><input id="profile-email" v-bind="validation.attrs('email')" v-model="form.email" type="email" autocomplete="email" maxlength="254" required :disabled="saving" :aria-invalid="!!fields.email" :aria-describedby="fields.email ? 'profile-email-help profile-email-error' : 'profile-email-help'" /><small id="profile-email-help">{{ t('accountProfile.emailHelp') }}</small><small v-if="fields.email" id="profile-email-error" role="alert" class="field-error">{{ message(fields.email) }}</small></div><p v-if="user.oidcLinked" class="dialog-wide">{{ t('accountProfile.providerHelp', { provider: oidcProviderName }) }}</p></form>
   <template #footer><div class="dialog-actions"><Button attr-type="button" :aria-label="t('accountProfile.cancel')" type="default" :disabled="saving" @click="closeForm"><template #icon><AppIcon name="close" /></template><span>{{ t('actions.cancel') }}</span></Button><Button attr-type="submit" :form="`${dialogId}-profile`" :aria-label="t('accountProfile.save')" :loading="saving" type="primary"><template #icon><AppIcon name="save" /></template><span>{{ t('actions.save') }}</span></Button></div></template></FormDialog></section></template>
<style scoped>
.account-profile { display: grid; gap: var(--ui-grid-gap); min-width: 0; }.account-section { padding: 0; min-width: 0; }.profile-identity { display: flex; gap: var(--ui-grid-gap); align-items: center; padding-bottom: var(--space-5); border-bottom: 1px solid var(--theme-border); }.profile-name { flex: 1; min-width: 0; }.profile-name h3 { font-size: 1.3rem; margin: var(--space-0-5) 0 var(--space-1); letter-spacing: -.035em; }.profile-name p { font-size: .8rem; color: var(--theme-muted); margin: 0; }.profile-eyebrow { font-size: .65rem; text-transform: uppercase; letter-spacing: .1em; color: var(--theme-muted); }.profile-tags { display: flex; flex-wrap: wrap; gap: var(--space-1-5); margin-top: var(--space-3); }.profile-tags .n-tag { font-size: .68rem; gap: var(--space-1); }.profile-tags svg { width: .85rem; height: .85rem; }.account-grid { display: grid; grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr); gap: var(--ui-grid-gap); align-items: start; }.account-section h3 { display: flex; align-items: center; gap: var(--space-2); margin: 0 0 var(--space-3); font-size: .9rem; }.account-section h3 svg { width: 1.1rem; height: 1.1rem; color: var(--theme-muted); }.section-help, small { font-size: .76rem; color: var(--theme-muted); line-height: 1.6; }.section-help { margin: var(--space-1-5) 0 var(--space-3); }.preferences-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--ui-grid-gap); margin-top: var(--ui-content-gap); }.preferences-grid :deep(select) { box-sizing: border-box; min-width: 0; }.preferences-grid :deep(.appearance-preference), .preferences-grid :deep(.language-preference) { margin: 0; min-width: 0; }.profile-access dl { display: flex; flex-wrap: wrap; gap: var(--ui-grid-gap) 3rem; font-size: .78rem; margin: var(--space-3) 0; }dt { color: var(--theme-muted); margin-bottom: var(--space-1-5); }dd { margin: 0; }.access-chip { display: inline-block; border-radius: .4rem; padding: var(--space-1) var(--space-1-5); background: var(--theme-subtle); margin: var(--space-0-5); }.profile-access details { font-size: .78rem; margin-top: var(--space-3); border: 0; padding: 0; }li { line-height: 1.8; }p, h3, dd { overflow-wrap: anywhere; }
@media(max-width: 700px) { .account-grid, .preferences-grid { grid-template-columns: minmax(0, 1fr); }.profile-identity { display: grid; grid-template-columns: 4rem minmax(0, 1fr); align-items: start; } }
</style>
