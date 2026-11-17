<script setup lang="ts" generic="T extends { id: string }">
import { computed, onUnmounted, ref, shallowRef } from 'vue';
import { NButton } from 'naive-ui';
import { useI18n } from 'vue-i18n';
import { ApiError } from '../api/auth';
import { useFeedback } from '../lib/feedback';
import AppIcon from './AppIcon.vue';
import FormDialog from './FormDialog.vue';

const props = defineProps<{
  rows: T[];
  action?: (row: T) => Promise<unknown>;
  groupAction?: (rows: T[]) => Promise<unknown>;
  label: string;
  triggerLabel?: string;
  icon: string;
  name: (row: T) => string;
  destructive?: boolean;
  confirmation?: string;
  disabled?: boolean;
  incomplete?: boolean;
}>();
const emit = defineEmits<{ applied: [ids: string[]]; accessLost: [cause: ApiError] }>();
const { t, te } = useI18n();
const feedback = useFeedback();
const pending = shallowRef<T[] | null>(null);
const busy = ref(false);
const failures = ref<{ id: string; name: string; message: string }[]>([]);
const title = computed(() => t('collection.batch.title', { action: props.label, count: pending.value?.length ?? 0 }));
let active = true;
onUnmounted(() => { active = false; });

function open() {
  failures.value = [];
  pending.value = [...props.rows];
}

async function apply() {
  if (!pending.value || busy.value || props.incomplete) return;
  busy.value = true;
  failures.value = [];
  const completed: string[] = [];
  try {
    if (props.groupAction) {
      try {
        await props.groupAction(pending.value);
        completed.push(...pending.value.map(row => row.id));
      } catch (cause) {
        const code = cause instanceof ApiError ? cause.code : '';
        failures.value.push({ id: '', name: props.label, message: code && te(code) ? t(code) : t('auth.errors.requestFailed') });
        if (cause instanceof ApiError && [401, 403].includes(cause.status)) emit('accessLost', cause);
      }
    } else for (const row of pending.value) {
      if (!active) break;
      try {
        await props.action?.(row);
        completed.push(row.id);
      } catch (cause) {
        const code = cause instanceof ApiError ? cause.code : '';
        failures.value.push({
          id: row.id,
          name: props.name(row),
          message: code && te(code) ? t(code) : t('auth.errors.requestFailed'),
        });
        if (cause instanceof ApiError && [401, 403].includes(cause.status)) emit('accessLost', cause);
        if (cause instanceof ApiError && [401, 403, 429].includes(cause.status)) break;
      }
    }
    if (!active) return;
    if (completed.length) {
      feedback.success(t('feedback.batch', { count: completed.length }));
      emit('applied', completed);
    }
    if (!failures.value.length) pending.value = null;
  } finally {
    busy.value = false;
  }
}
</script>

<template>
  <NButton :type="destructive ? 'error' : 'default'" :aria-label="label" :title="label" :disabled="disabled || !rows.length" @click="open">
    <template #icon><AppIcon :name="icon" /></template>{{ triggerLabel ?? label }}
  </NButton>
  <FormDialog v-if="pending" :visible="true" :title="title" :close-label="t('actions.cancel')" :icon="icon" size="compact" :busy="busy" @update:visible="!$event && (pending = null)">
    <p v-if="confirmation || destructive">{{ confirmation || t('collection.batch.deleteWarning') }}</p>
    <slot v-if="!failures.length" :busy="busy" />
    <div v-else role="alert">
      <p>{{ t('collection.batch.failed') }}</p>
      <ul><li v-for="failure in failures" :key="failure.id">{{ failure.name }} · {{ failure.message }}</li></ul>
    </div>
    <template #footer>
      <div class="dialog-actions">
        <NButton :disabled="busy" @click="pending = null">{{ t(failures.length ? 'actions.close' : 'actions.cancel') }}</NButton>
        <NButton v-if="!failures.length" :type="destructive ? 'error' : 'primary'" :loading="busy" :disabled="incomplete || busy" @click="apply">
          <template #icon><AppIcon :name="icon" /></template>{{ label }}
        </NButton>
      </div>
    </template>
  </FormDialog>
</template>
