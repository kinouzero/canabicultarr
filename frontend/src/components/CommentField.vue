<script setup lang="ts">
import CommentVariableHelp from './CommentVariableHelp.vue';
import { phaseDefinition, actionLabel } from '../lib/lifecycle';
import { photosApi, type RecordPhoto } from '../api/photos';
import AppIcon from './AppIcon.vue';
import { computed, onUnmounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { NButton as Button } from 'naive-ui';
import Message from './AppMessage.vue';
import ReferenceSelect from './ReferenceSelect.vue';
import { strainsApi } from '../api/strains';
import { commentTemplatesApi, type CommentTemplate } from '../api/commentTemplates';
import { commentVariables, expandCommentTemplate } from '../lib/commentTemplateVariables';
import { plantsApi, type PlantEvent, type PlantAttachment, type Plant } from '../api/plants';
import en from '../locales/en/comment-templates.json';
import fr from '../locales/fr/comment-templates.json';
const props = defineProps<{ modelValue: string; id: string; plant: Plant; disabled?: boolean; validationError?: string; action?: { inventory?: unknown; id?: string; kind?: string; choice?: string; type?: string; occurredAt?: string; harvestWeight?: string | number }; attachments?: PlantAttachment[] }>();
const emit = defineEmits<{ 'update:modelValue': [value: string]; failure: [cause: unknown] }>();
const { t, locale, mergeLocaleMessage } = useI18n({ useScope: 'global' });
mergeLocaleMessage('en', { commentTemplates: en }); mergeLocaleMessage('fr', { commentTemplates: fr });
const templateId = ref(''); const templates = new Map<string, CommentTemplate>();
const error = ref(''); const strain = ref<unknown>(null); const loadingContext = ref(false); let sequence = 0;
const contextFailed = ref(false);
const events = ref<PlantEvent[] | null>(null); const photos = ref<RecordPhoto[] | null>(null);
const context = computed(() => ({ ...props.plant, strain: strain.value ?? props.plant.strain, ...(events.value ? { events: events.value } : {}), ...(photos.value ? { photos: photos.value } : {}) }));
const currentAction = computed(() => {
 const draft = props.action ?? {}; const id = draft.id ?? draft.kind ?? '';
 const definition = phaseDefinition(props.plant.phase)?.actions.find(action => action.id === id);
 const date = draft.occurredAt ? new Date(draft.occurredAt) : new Date();
 return { ...draft, id, kind: draft.kind ?? id, name: id === 'watered' ? t('plants.watering.title') : id === 'maintained' ? t('plants.maintenance.record') : actionLabel(id, t, definition),
  comment: props.modelValue, occurredAt: Number.isNaN(date.getTime()) ? '' : date.toISOString(),
  fromPhase: props.plant.phase, definition: definition ?? null, toPhase: definition?.target ?? props.plant.phase,
  withProducts: draft.choice ? draft.choice === 'with' : null, maintenance: { type: draft.type ?? null }, harvestWeightGrams: draft.harvestWeight === '' || draft.harvestWeight === undefined ? null : draft.harvestWeight,
  attachments: props.attachments ?? [],
 };
});
const values = computed(() => commentVariables(context.value, locale.value, new Date(), currentAction.value));
const template = computed(() => templates.get(templateId.value));
const expanded = computed(() => expandCommentTemplate(template.value?.body ?? '', values.value));
async function fetchTemplates(search: string, page: number) {
 const result = await commentTemplatesApi.list(search, page, 'active');
 for (const row of result.data) templates.set(row.id, row);
 return { ...result, data: result.data.filter(row => !row.isArchived).map(row => ({ id: row.id, name: row.name, detail: '' })) };
}
async function loadContext() {
 const current = ++sequence; error.value = ''; contextFailed.value = false; loadingContext.value = true;
 const body = template.value?.body ?? '';
 const loads: Promise<void>[] = [];
 if (!strain.value) loads.push(strainsApi.get(props.plant.strain.id).then(result => { if (current === sequence) strain.value = result; }));
 if (/plant\.events(?:[.\s}])/.test(body) && events.value === null) loads.push((async () => {
  const rows: PlantEvent[] = []; let page = 1; let last = 1;
  do { const result = await plantsApi.events(props.plant.id, page); if (current !== sequence) return; rows.push(...result.data); last = result.meta.last_page; page++; } while (page <= last);
  if (current === sequence) events.value = rows;
 })());
 if (/plant\.photos(?:[.\s}])/.test(body) && photos.value === null) loads.push((async () => {
  const rows: RecordPhoto[] = []; let page = 1; let last = 1;
  do { const result = await photosApi.list('plant', props.plant.id, page); if (current !== sequence) return; rows.push(...result.data); last = result.meta.last_page; page++; } while (page <= last);
  if (current === sequence) photos.value = rows;
 })());
 const results = await Promise.allSettled(loads);
 if (current !== sequence) return;
 contextFailed.value = results.some(result => result.status === 'rejected');
 if (contextFailed.value) error.value = t('commentTemplates.contextFailed');
 loadingContext.value = false;
}
function insertVariable(token: string) {
 if (props.disabled || loadingContext.value) return;
 const result = expandCommentTemplate(token, values.value);
 if (result.missing.length) { error.value = t('commentTemplates.missing', { names: result.missing.join(', ') }); return; }
 const value = props.modelValue + result.text;
 if (value.length > 2000) { error.value = t('commentTemplates.tooLong'); return; }
 emit('update:modelValue', value); error.value = '';
}
function insert() {
 if (!template.value || loadingContext.value || props.disabled) return;
 if (expanded.value.missing.length) { error.value = t('commentTemplates.missing', { names: expanded.value.missing.join(', ') }); return; }
 const value = props.modelValue ? props.modelValue + '\n' + expanded.value.text : expanded.value.text;
 if (value.length > 2000) { error.value = t('commentTemplates.tooLong'); return; }
 emit('update:modelValue', value); templateId.value = ''; error.value = '';
}
watch(templateId, id => { error.value = ''; contextFailed.value = false; if (id) void loadContext(); else { sequence++; loadingContext.value = false; } });
watch(() => [props.plant.id, props.plant.version, props.plant.strain.id], () => { sequence++; contextFailed.value = false; strain.value = null; events.value = photos.value = null; loadingContext.value = false; templateId.value = ''; });
onUnmounted(() => { sequence++; });
</script>
<template><div class="comment-field"><textarea :id="id" :value="modelValue" rows="3" maxlength="2000" :disabled="disabled" :aria-invalid="!!validationError" :aria-describedby="validationError ? `${id}-error` : undefined" @input="emit('update:modelValue', ($event.target as HTMLTextAreaElement).value)" /><small v-if="validationError" :id="`${id}-error`" class="field-error" role="alert">{{ t(validationError) }}</small>
  <ReferenceSelect :id="`${id}-template`" v-model="templateId" :label="t('commentTemplates.select')" :fetch-page="fetchTemplates" hide-when-empty :disabled="disabled" @access-lost="emit('failure', $event)" />
  <div v-if="template" class="template-preview"><strong>{{ t('commentTemplates.preview') }}</strong><pre>{{ expanded.text }}</pre><p>{{ t('commentTemplates.append') }}</p><Button v-if="contextFailed" attr-type="button" type="default" :disabled="disabled || loadingContext" @click="loadContext"><template #icon><AppIcon name="refresh" /></template><span>{{ t('commentTemplates.retryContext') }}</span></Button><Button attr-type="button" type="default" :disabled="disabled || loadingContext" :loading="loadingContext" @click="insert"><template #icon><AppIcon name="message" /></template><span>{{ t('commentTemplates.choose') }}</span></Button></div>
  <CommentVariableHelp v-if="template" :values="values" :disabled="disabled || loadingContext" @insert="insertVariable" />
  <Message v-if="error" type="error" closable role="alert">{{ error }}</Message></div></template>
<style scoped>
textarea { display: block; width: 100%; min-width: 0; padding: var(--space-3); font: inherit; border: 1px solid var(--theme-border, #aebfb5); border-radius: .5rem; margin-bottom: var(--space-2); }
.template-preview { padding: var(--space-3); border: 1px solid var(--theme-border); border-radius: .6rem; background: var(--theme-subtle); font-size: .8rem; }.template-preview pre { white-space: pre-wrap; overflow-wrap: anywhere; font: inherit; }.template-preview p { color: var(--theme-muted); }
</style>
