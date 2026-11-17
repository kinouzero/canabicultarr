<script setup lang="ts">
import { useFeedback } from '../lib/feedback';
import PlantInventoryFields from './PlantInventoryFields.vue';
import PlantActionTabs from './PlantActionTabs.vue';
import { emptyInventoryDraft, stockCommentContext, substratePayload, containerPayload } from '../lib/plant-inventory';
import AppIcon from './AppIcon.vue';
import DiscardChangesDialog from './DiscardChangesDialog.vue';
import { useUnsavedChanges } from '../lib/unsaved-changes';
import { hasCareDraft, plantActionTab, usePlantCareValidation } from '../lib/plant-care-form';
import { phaseDefinition, actionDefinition, actionLabel } from '../lib/lifecycle';
import { plantActionIcon } from '../lib/plant-action-icon';
import { useId, computed, onMounted, onUnmounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { NButton as Button } from 'naive-ui';
import PlantActionFields from './PlantActionFields.vue';
import CommentField from './CommentField.vue';
import FormDialog from './FormDialog.vue';
import PlantActionAttachments from './PlantActionAttachments.vue';
import { ApiError } from '../api/auth';
import { plantsApi, type Plant, type PlantAttachment, type PlantAction } from '../api/plants';
const props = defineProps<{ plantId: string; name: string; action: PlantAction }>();
const emit = defineEmits<{ close: []; saved: []; accessLost: [cause: ApiError] }>();
const { t, te } = useI18n();
const feedback = useFeedback();
const plant = ref<Plant | null>(null); const loading = ref(false); const saving = ref(false);
const attachmentBusy = ref(false); const attachmentPending = ref(false); const attachments = ref<PlantAttachment[]>([]);
const busy = computed(() => saving.value || attachmentBusy.value);
const error = ref(''); const form = reactive({ comment: '', occurredAt: '', harvestWeight: '' as string | number });
const available = computed(() => plant.value?.canManage && phaseDefinition(plant.value.phase)?.actions.some(action => action.id === props.action));
const completed = ref(false); const inventoryChanged = ref(false);
const inventoryDraft = ref(emptyInventoryDraft());
const inventoryFields = ref<InstanceType<typeof PlantInventoryFields> | null>(null);
const dirty = computed(() => !completed.value && (hasCareDraft(form, attachments.value) || inventoryChanged.value || busy.value || attachmentPending.value));
const unsaved = useUnsavedChanges(dirty, busy);
const actionTab = ref('action');
const validation = usePlantCareValidation(() => ({ ...form, documentIds: attachments.value.map(row => row.id) }), () => ({ occurred_at: 'plant-action-date', harvest_weight_grams: 'plant-harvest-weight', comment: 'plant-action-comment', document_ids: 'plant-care-attachments' }), () => !!actionDefinition(props.action)?.requires_harvest_weight, field => { actionTab.value = plantActionTab(field); });
async function close() { if (await unsaved.confirmDiscard()) emit('close'); }
let active = true; const requestId = crypto.randomUUID();
function failure(cause: unknown) {
 validation.capture(cause);
 const code = cause instanceof ApiError ? Object.values(cause.fields).flat()[0] ?? cause.code : '';
 error.value = /^(plants|documents|auth|inventory)\.(errors|validation|stock|containers)\.[A-Za-z]+$/.test(code) && te(code) ? code : 'auth.errors.requestFailed';
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
 if (!plant.value || !available.value || busy.value || loading.value) return;
 if (inventoryFields.value && !inventoryFields.value.validate()) { actionTab.value = 'inventory'; return; }
 if (!validation.validate()) { error.value = 'plants.validation.invalid'; return; }
 saving.value = true; error.value = '';
 try {
  const occurredAt = form.occurredAt ? new Date(form.occurredAt).toISOString() : null;
  await plantsApi.action(plant.value, props.action, requestId, form.comment || null, occurredAt, attachments.value.map(row => row.id), actionDefinition(props.action)?.requires_harvest_weight ? form.harvestWeight : undefined, substratePayload(inventoryDraft.value), containerPayload(inventoryDraft.value));
  if (active) { completed.value = true; saving.value = false; feedback.success(t('plants.actionSaved')); emit('saved'); }
 } catch (cause) { if (active) failure(cause); }
 finally { if (active) saving.value = false; }
}
onMounted(read); onUnmounted(() => { active = false; });
const dialogId = useId();
</script>
<template><DiscardChangesDialog v-if="unsaved.pending.value" @decision="unsaved.finish" />
 <FormDialog size="compact" :icon="plantActionIcon(action)" :visible="true" :title="t('plants.actionTitle', { action: actionLabel(action, t) })" :close-label="t('plants.cancel')" :busy="busy" :error="error ? t(error) : ''" @update:visible="!$event && close()"><p v-if="loading" role="status">{{ t('plants.loading') }}</p>
  <p v-else-if="plant && !available">{{ t('plants.errors.actionUnavailable') }}</p>
  <Button v-if="error === 'plants.errors.conflict'" :aria-label="t('plants.reload')" :disabled="busy || loading" @click="read" type="primary"><template #icon><AppIcon name="refresh" /></template><span>{{ t('actions.refresh') }}</span></Button>
<form :id="`${dialogId}-0`" v-if="plant && available" data-testid="lifecycle-form" class="dialog-form plant-panel" @submit.prevent="save" novalidate><p>{{ plant.name }}</p>
    <PlantActionTabs v-model="actionTab">
     <template #action>
      <PlantActionFields v-model:date="form.occurredAt" v-model:weight="form.harvestWeight"
       :requires-weight="actionDefinition(action)?.requires_harvest_weight" :disabled="busy"
       :date-error="validation.fields.value.occurred_at" :weight-error="validation.fields.value.harvest_weight_grams" />
     </template>
     <template v-if="action.startsWith('repot') || actionDefinition(action)?.substrates?.length || actionDefinition(action)?.container_inventory_item_id" #inventory>
        <PlantInventoryFields ref="inventoryFields" @dirty="inventoryChanged = $event" v-model="inventoryDraft" :plant="plant" :action="action" :enabled="true" :disabled="busy" @failure="failure" />
     </template>
     <template #comment>
      <div class="field"><label for="plant-action-comment">{{ t('plants.comment') }}</label><CommentField :validation-error="validation.fields.value.comment" id="plant-action-comment" v-model="form.comment" :plant="plant" :action="{ ...form, id: action, inventory: stockCommentContext(inventoryDraft, false) }" :attachments="attachments" :disabled="busy" @failure="failure" /></div>
     </template>
     <template #attachments>
      <PlantActionAttachments :validation-error="validation.fields.value.document_ids" v-model="attachments" :key="plant.id" :location="plant.location" :name="plant.name" :disabled="busy" @busy="attachmentBusy = $event" @pending="attachmentPending = $event" @failure="failure" />
     </template>
    </PlantActionTabs>
   </form>
 <template #footer><div class="dialog-actions"><Button :aria-label="t('actions.cancel')" type="default" :disabled="busy" @click="close"><template #icon><AppIcon name="close" /></template><span>{{ t('actions.cancel') }}</span></Button><Button :form="`${dialogId}-0`" attr-type="submit" :aria-label="t('plants.confirmAction')" :loading="busy" :disabled="busy || loading || attachmentPending" type="primary"><template #icon><AppIcon name="check" /></template><span>{{ t('actions.record') }}</span></Button></div></template></FormDialog></template>
<style scoped>
.field { display: grid; gap: var(--space-1-5); margin: var(--ui-content-gap) 0; }.field input { width: 100%; padding: var(--space-3); font: inherit; border: 1px solid var(--theme-border, #aebfb5); border-radius: .5rem; }
.plant-heading { display: flex; flex-wrap: wrap; align-items: center; gap: var(--ui-grid-gap); }small { color: var(--theme-muted, #63745e); }
</style>
