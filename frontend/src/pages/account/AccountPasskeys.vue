<script setup lang="ts">
import { onMounted, onUnmounted, ref, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import { NButton as Button, NInput as Input } from 'naive-ui';
import { ApiError } from '../../api/auth';
import { passkeysApi, supportsPasskeys, type Passkey } from '../../api/passkeys';
import { formatDateTime } from '../../lib/date-time';
import AppIcon from '../../components/AppIcon.vue';
import FormDialog from '../../components/FormDialog.vue';
import ListActionButton from '../../components/ListActionButton.vue';
import Message from '../../components/AppMessage.vue';

const emit = defineEmits<{ accessLost: [cause: ApiError] }>();
const { t, te, locale } = useI18n();
const keys = ref<Passkey[]>([]);
const recentlyVerified = ref(false);
const loading = ref(true);
const busy = ref(false);
const error = ref('');
const notice = ref('');
const mode = ref<'add' | 'remove' | null>(null);
const selected = ref<Passkey | null>(null);
const name = ref('');
const id = useId();
const supported = supportsPasskeys();
const controller = new AbortController();

function fail(cause: unknown) {
  if (controller.signal.aborted) return;
  if (cause instanceof ApiError) {
    if (cause.status === 401) emit('accessLost', cause);
    if (cause.code === 'auth.errors.passkeyReauthenticate') recentlyVerified.value = false;
    if (/^auth\.errors\.[a-zA-Z]+$/.test(cause.code) && te(cause.code)) {
      error.value = cause.code;
      return;
    }
  }
  error.value = 'auth.errors.requestFailed';
}

async function load() {
  try {
    const result = await passkeysApi.list(controller.signal);
    keys.value = result.keys;
    recentlyVerified.value = result.recentlyVerified;
  } catch (cause) {
    fail(cause);
  } finally {
    loading.value = false;
  }
}

function open(kind: 'add' | 'remove', key?: Passkey) {
  error.value = notice.value = name.value = '';
  selected.value = key ?? null;
  mode.value = kind;
}

async function save() {
  if (busy.value || !recentlyVerified.value) return;
  busy.value = true;
  error.value = '';
  try {
    if (mode.value === 'add' && name.value.trim()) {
      keys.value.push(await passkeysApi.register(name.value.trim(), controller.signal));
      notice.value = 'accountSecurity.passkeys.added';
    } else if (mode.value === 'remove' && selected.value) {
      const key = selected.value;
      await passkeysApi.remove(key.id, controller.signal);
      keys.value = keys.value.filter(item => item.id !== key.id);
      notice.value = 'accountSecurity.passkeys.removed';
    } else return;
    mode.value = null;
  } catch (cause) {
    fail(cause);
  } finally {
    busy.value = false;
  }
}

onMounted(load);
onUnmounted(() => controller.abort());
</script>

<template>
  <section class="passkeys" :aria-labelledby="`${id}-heading`">
    <div class="passkeys-heading">
      <div>
        <h3 :id="`${id}-heading`"><AppIcon name="key" />{{ t('accountSecurity.passkeys.title') }}</h3>
        <p>{{ t('accountSecurity.passkeys.help') }}</p>
      </div>
      <Button attr-type="button" :disabled="loading || !supported || !recentlyVerified || busy" @click="open('add')">
        <template #icon><AppIcon name="plus" /></template>{{ t('accountSecurity.passkeys.add') }}
      </Button>
    </div>
    <Message v-if="notice" type="success" closable role="status">{{ t(notice) }}</Message>
    <Message v-if="error && !mode" type="error" closable role="alert">{{ t(error) }}</Message>
    <p v-if="loading" role="status">{{ t('auth.loading') }}</p>
    <template v-else>
      <p v-if="!supported">{{ t('accountSecurity.passkeys.unsupported') }}</p>
      <p v-if="!recentlyVerified && !error">{{ t('auth.errors.passkeyReauthenticate') }}</p>
      <ul v-if="keys.length" class="passkey-list">
        <li v-for="key in keys" :key="key.id">
          <span><strong>{{ key.name }}</strong><small>{{ key.lastUsedAt
            ? t('accountSecurity.passkeys.lastUsed', { date: formatDateTime(key.lastUsedAt, locale) })
            : t('accountSecurity.passkeys.created', { date: formatDateTime(key.createdAt, locale) }) }}</small></span>
          <ListActionButton icon="trash" :label="t('accountSecurity.passkeys.removeNamed', { name: key.name })"
            :disabled="!recentlyVerified || busy" @click="open('remove', key)" />
        </li>
      </ul>
    </template>

    <FormDialog v-if="mode" :visible="true" size="compact" icon="key"
      :title="t(mode === 'add' ? 'accountSecurity.passkeys.add' : 'accountSecurity.passkeys.remove')"
      :close-label="t('actions.cancel')" :busy="busy" :error="error ? t(error) : ''"
      @update:visible="!$event && !busy && (mode = null)">
      <form :id="`${id}-form`" class="dialog-form" @submit.prevent="save">
        <div v-if="mode === 'add'" class="field">
          <label :for="`${id}-name`">{{ t('accountSecurity.passkeys.name') }}</label>
          <Input v-model:value="name" :input-props="{ id: `${id}-name` }" :maxlength="80" :disabled="busy" />
        </div>
        <p v-else>{{ t('accountSecurity.passkeys.removeHelp', { name: selected?.name }) }}</p>
      </form>
      <template #footer>
        <div class="dialog-actions">
          <Button attr-type="button" :disabled="busy" @click="mode = null">
            <template #icon><AppIcon name="close" /></template>{{ t('actions.cancel') }}
          </Button>
          <Button :type="mode === 'add' ? 'primary' : 'error'" attr-type="submit" :form="`${id}-form`"
            :loading="busy" :disabled="!recentlyVerified || (mode === 'add' && !name.trim())">
            <template #icon><AppIcon :name="mode === 'add' ? 'key' : 'trash'" /></template>
            {{ t(mode === 'add' ? 'accountSecurity.passkeys.add' : 'accountSecurity.passkeys.remove') }}
          </Button>
        </div>
      </template>
    </FormDialog>
  </section>
</template>

<style scoped>
.passkeys { display: grid; gap: var(--space-3); min-width: 0; padding-block: var(--space-3); }
.passkeys-heading { display: flex; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: var(--space-3); }
.passkeys h3 { display: flex; align-items: center; gap: var(--space-2); margin: 0 0 var(--space-1); font-size: .87rem; }
.passkeys h3 svg { inline-size: 1.05rem; block-size: 1.05rem; }
.passkeys p, .passkeys small { margin: 0; color: var(--theme-muted); font-size: .76rem; line-height: 1.6; }
.passkey-list { display: grid; gap: var(--space-2); margin: 0; padding: 0; list-style: none; }
.passkey-list li { display: flex; justify-content: space-between; align-items: center; gap: var(--space-3); padding: var(--space-2) var(--space-3); border-radius: .6rem; background: var(--theme-subtle); }
.passkey-list li > span { display: grid; min-width: 0; overflow-wrap: anywhere; }
.passkey-list strong { font-size: .84rem; }
</style>
