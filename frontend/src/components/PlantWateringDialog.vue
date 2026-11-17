<script setup lang="ts">
import { useFeedback } from '../lib/feedback';
import PlantActionTabs from './PlantActionTabs.vue';
import PlantInventoryFields from './PlantInventoryFields.vue';
import { emptyInventoryDraft, stockCommentContext, fertilizerPayload } from '../lib/plant-inventory';
import AppIcon from './AppIcon.vue';
import DiscardChangesDialog from './DiscardChangesDialog.vue';
import { useUnsavedChanges } from '../lib/unsaved-changes';
import { hasCareDraft, plantActionTab, usePlantCareValidation } from '../lib/plant-care-form';
import { useId, computed, onMounted, onUnmounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { NButton as Button } from 'naive-ui';
import Message from './AppMessage.vue';
import CommentField from './CommentField.vue';
import FormDialog from './FormDialog.vue';
import PlantActionAttachments from './PlantActionAttachments.vue';
import { ApiError } from '../api/auth';
import { plantsApi, type Plant, type PlantAttachment } from '../api/plants';
const props = defineProps<{ plantId: string; name: string; pump?: { id: string; name: string; occurredAt: string | null } }>();
const emit = defineEmits<{ close: []; saved: []; accessLost: [cause: ApiError] }>();
const { t, te } = useI18n();
const feedback = useFeedback();
const plant = ref<Plant | null>(null); const loading = ref(false); const saving = ref(false);
const attachmentBusy = ref(false); const attachmentPending = ref(false); const attachments = ref<PlantAttachment[]>([]);
const busy = computed(() => saving.value || attachmentBusy.value);
const error = ref(''); const form = reactive({ choice: '', comment: '', occurredAt: '' });
const completed = ref(false);
const fertilizerDraft = ref(emptyInventoryDraft());
const fertilizerFields = ref<InstanceType<typeof PlantInventoryFields> | null>(null);
const dirty = computed(() => !completed.value && (hasCareDraft(form, attachments.value) || busy.value || attachmentPending.value));
const unsaved = useUnsavedChanges(dirty, busy);
const actionTab = ref('action');
const validation = usePlantCareValidation(() => ({ ...form, documentIds: attachments.value.map(row => row.id) }), () => ({ occurred_at: 'plant-watering-date', with_products: 'plant-watering-choice', comment: 'plant-watering-comment', document_ids: 'plant-care-attachments' }), undefined, field => { actionTab.value = plantActionTab(field); });
async function close() { if (await unsaved.confirmDiscard()) emit('close'); }
let active = true; const requestId = props.pump?.id ?? crypto.randomUUID();
function failure(cause: unknown) {
 validation.capture(cause);
 const code = cause instanceof ApiError ? Object.values(cause.fields).flat()[0] ?? cause.code : '';
 error.value = /^(plants|documents|inventory|auth)\.(errors|validation|stock|containers)\.[A-Za-z]+$/.test(code) && te(code) ? code : 'auth.errors.requestFailed';
 if (cause instanceof ApiError && (cause.status === 401 || cause.status === 403)) { completed.value = true; emit('accessLost', cause); }
}
async function read() {
 loading.value = true; error.value = ''; validation.clear();
 try { const result = await plantsApi.get(props.plantId); if (active) plant.value = result; }
 catch (cause) { if (active) failure(cause); }
 finally { if (active) loading.value = false; }
}
async function save() {
 if (attachmentPending.value) return;
 if (!plant.value?.canManage || plant.value.watering.status === 'unavailable' || busy.value || loading.value) return;
 if (!fertilizerFields.value?.validate()) { actionTab.value = 'action'; return; }
 if (!validation.validate()) { error.value = 'plants.validation.invalid'; return; }
 saving.value = true; error.value = '';
 try {
  const occurredAt = form.occurredAt ? new Date(form.occurredAt).toISOString() : props.pump?.occurredAt ?? null;
  await plantsApi.water(plant.value, form.choice === 'with', requestId, form.comment || null, occurredAt, attachments.value.map(row => row.id), fertilizerPayload(fertilizerDraft.value, form.choice === 'with'));
  if (active) { completed.value = true; saving.value = false; feedback.success(t('plants.watering.saved')); emit('saved'); }
 } catch (cause) { if (active) failure(cause); }
 finally { if (active) saving.value = false; }
}
onMounted(read); onUnmounted(() => { active = false; });
const dialogId = useId();
</script>
<template><DiscardChangesDialog v-if="unsaved.pending.value" @decision="unsaved.finish" />
 <FormDialog size="compact" icon="water" :visible="true" :title="t('plants.watering.forPlant', { name })" :close-label="t('plants.cancel')" :busy="busy" :error="error ? t(error) : ''" @update:visible="!$event && close()"><Message v-if="pump" type="success" closable>{{ t('assets.pump.started', { name: pump.name }) }}</Message><slot name="pump-control" />
  <p v-if="loading" role="status">{{ t('plants.loading') }}</p>
  <p v-else-if="plant && (!plant.canManage || plant.watering.status === 'unavailable')">{{ t('plants.watering.unavailable') }}</p>
  <Button v-if="error === 'plants.errors.conflict'" :aria-label="t('plants.reload')" :disabled="busy || loading" @click="read" type="primary"><template #icon><AppIcon name="refresh" /></template><span>{{ t('actions.refresh') }}</span></Button>
<form :id="`${dialogId}-0`" v-if="plant && plant.canManage && plant.watering.status !== 'unavailable'" data-testid="watering-form" class="dialog-form plant-panel" @submit.prevent="save" novalidate>
    <PlantActionTabs v-model="actionTab">
     <template #action>
      <div class="dialog-form">
      <Message v-if="plant.watering.status === 'flush'" type="warning" closable>{{ t('plants.watering.flushHelp') }}</Message>
    <div class="dialog-form-grid"><div class="field"><label for="plant-watering-choice">{{ t('plants.watering.choice') }}</label><select id="plant-watering-choice" v-bind="validation.attrs('with_products')" v-model="form.choice" v-app-select="{ filter: t('selectControl.filter'), empty: t('selectControl.empty') }" :disabled="busy" required><option value="" disabled>{{ t('plants.watering.choose') }}</option><option value="with">{{ t('plants.watering.with') }}</option><option value="without">{{ t('plants.watering.without') }}</option></select><small v-if="validation.fields.value.with_products" id="plant-watering-choice-error" class="field-error" role="alert">{{ t(validation.fields.value.with_products) }}</small></div>
<div class="field"><label for="plant-watering-date">{{ t('plants.watering.occurredAt') }}</label><input id="plant-watering-date" v-bind="validation.attrs('occurred_at')" v-model="form.occurredAt" type="datetime-local" :disabled="busy" /><small>{{ t('plants.nowHelp') }}</small><small v-if="validation.fields.value.occurred_at" id="plant-watering-date-error" class="field-error" role="alert">{{ t(validation.fields.value.occurred_at) }}</small></div></div>
<PlantInventoryFields ref="fertilizerFields" v-model="fertilizerDraft" :plant="plant" :at="form.occurredAt || pump?.occurredAt" :enabled="!!form.choice" :with-fertilizer="form.choice === 'with'" :disabled="busy" @failure="failure" />
      </div>
     </template>
     <template #comment>
      <div class="field"><label for="plant-watering-comment">{{ t('plants.comment') }}</label><CommentField :validation-error="validation.fields.value.comment" id="plant-watering-comment" v-model="form.comment" :plant="plant" :action="{ ...form, kind: 'watered', inventory: stockCommentContext(fertilizerDraft, true, form.choice === 'with') }" :attachments="attachments" :disabled="busy" @failure="failure" /></div>
     </template>
     <template #attachments>
      <PlantActionAttachments :validation-error="validation.fields.value.document_ids" v-model="attachments" :key="plant.id" :location="plant.location" :name="plant.name" :disabled="busy" @busy="attachmentBusy = $event" @pending="attachmentPending = $event" @failure="failure" />
     </template>
    </PlantActionTabs>
   </form>
 <template #footer><div class="dialog-actions"><Button :aria-label="t('plants.watering.cancel')" type="default" :disabled="busy" @click="close"><template #icon><AppIcon name="close" /></template><span>{{ t('actions.cancel') }}</span></Button><Button :form="`${dialogId}-0`" attr-type="submit" :aria-label="t('plants.watering.confirm')" :loading="busy" :disabled="!form.choice || busy || loading || attachmentPending" type="primary"><template #icon><AppIcon name="water" /></template><span>{{ t('actions.record') }}</span></Button></div></template></FormDialog></template>
<style scoped>
.field { display: grid; gap: var(--space-1-5); min-width: 0; }.field input, textarea { width: 100%; padding: var(--space-3); font: inherit; border: 1px solid var(--theme-border, #aebfb5); border-radius: .5rem; }
fieldset { margin: var(--ui-content-gap) 0; padding: var(--space-4); border: 1px solid var(--theme-border, #c1d3c8); border-radius: .5rem; }.plant-heading { display: flex; flex-wrap: wrap; align-items: center; gap: var(--ui-grid-gap); }small { color: var(--theme-muted, #63745e); }
</style>
