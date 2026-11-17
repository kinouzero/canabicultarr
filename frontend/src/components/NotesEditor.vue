<script setup lang="ts">
import DisclosureSummary from './DisclosureSummary.vue';
import AppIcon from './AppIcon.vue';
import { useRememberDisclosure } from '../lib/disclosure-preferences';
import { computed, nextTick, onMounted, onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Quill from 'quill';
import 'quill/dist/quill.snow.css';
import RichNotes from './RichNotes.vue';
import { notesMarkdown, renderNotes, supportsVisualNotes } from '../lib/notes';
import '../styles/notes.css';
const vRememberDisclosure = useRememberDisclosure('NotesEditor');

defineOptions({ inheritAttrs: false });
const props = withDefaults(defineProps<{ modelValue: string; id: string; label: string; disabled?: boolean; invalid?: boolean; describedBy?: string; maxlength?: number }>(), { maxlength: 2000 });
const emit = defineEmits<{ 'update:modelValue': [value: string] }>();
const { t } = useI18n({ useScope: 'global' });
const mode = ref<'visual' | 'markdown'>('visual');
const warning = ref(false);
const visualAllowed = computed(() => supportsVisualNotes(props.modelValue));
const html = computed(() => renderNotes(props.modelValue));
watch(visualAllowed, allowed => { if (!allowed) mode.value = 'markdown'; }, { immediate: true });
let instance: Quill | null = null;
let restoring = false;
function accessibility() {
 if (!instance) return;
 instance.root.id = props.id;
 instance.root.setAttribute('role', 'textbox');
 instance.root.setAttribute('aria-label', props.label);
 instance.root.setAttribute('aria-multiline', 'true');
 instance.root.setAttribute('aria-disabled', String(!!props.disabled));
 instance.root.setAttribute('aria-invalid', String(!!props.invalid));
 instance.root.setAttribute('aria-describedby', [props.describedBy, `${props.id}-help`, warning.value ? `${props.id}-warning` : ''].filter(Boolean).join(' '));
}
watch(() => [props.label, props.disabled, props.invalid, props.describedBy, warning.value], accessibility);
const editorRoot = ref<HTMLElement | null>(null); const toolbarRoot = ref<HTMLElement | null>(null);
function releaseEditor() { instance?.off('text-change'); instance = null; }
async function mountEditor() {
 await nextTick();
 if (mode.value !== 'visual' || !editorRoot.value || !toolbarRoot.value || instance) return;
 const editor = new Quill(editorRoot.value, { theme: 'snow', readOnly: !!props.disabled, formats, modules: { toolbar: toolbarRoot.value } });
 instance = editor;
 editor.setContents(editor.clipboard.convert({ html: html.value }), 'silent'); accessibility();
 editor.on('text-change', (_delta, _old, source) => { if (source === 'user') changed(); });
}
onMounted(mountEditor); onBeforeUnmount(releaseEditor);
watch(mode, mountEditor, { flush: 'post' });
watch(() => props.disabled, value => { instance?.enable(!value); });
watch(() => props.modelValue, () => { if (instance && notesMarkdown(instance.root.innerHTML) !== props.modelValue) instance.setContents(instance.clipboard.convert({ html: html.value }), 'silent'); });
function changed() {
 if (props.disabled || restoring || !instance) return;
 const value = notesMarkdown(instance.root.innerHTML);
 if (value.length > props.maxlength) {
  warning.value = true;
  // A replacement may emit a transient empty change before its final value.
  emit('update:modelValue', props.modelValue);
  const editor = instance;
  const position = editor.getSelection()?.index ?? 0;
  restoring = true;
  editor.setContents(editor.clipboard.convert({ html: html.value }), 'silent');
  editor.setSelection(Math.min(position, editor.getLength() - 1), 0, 'silent');
  requestAnimationFrame(() => { restoring = false; });
  return;
 }
 warning.value = false; emit('update:modelValue', value);
}
function source(event: Event) {
 const value = (event.target as HTMLTextAreaElement).value;
 if (value.length <= props.maxlength) { warning.value = false; emit('update:modelValue', value); }
}
async function changeMode(value: 'visual' | 'markdown') {
 if (props.disabled || value === 'visual' && !visualAllowed.value) return;
 releaseEditor(); mode.value = value; warning.value = false;
 await nextTick();
 if (value === 'markdown') document.getElementById(props.id)?.focus();
}
const formats = ['bold', 'italic', 'header', 'blockquote', 'list', 'code', 'code-block', 'link'];
</script>
<template>
 <div class="notes-editor" :data-notes-id="id" :class="{ 'is-disabled': disabled, 'is-invalid': invalid }">
  <div class="notes-editor-heading">
   <div class="notes-mode" role="group" :aria-label="t('notesEditor.mode')">
    <button type="button" class="notes-mode-visual" :aria-pressed="mode === 'visual'" :disabled="disabled || !visualAllowed" @click="changeMode('visual')"><AppIcon name="edit" class="button-icon" />{{ t('notesEditor.visual') }}</button>
    <button type="button" class="notes-mode-markdown" :aria-pressed="mode === 'markdown'" :disabled="disabled" @click="changeMode('markdown')"><AppIcon name="file" class="button-icon" />{{ t('notesEditor.markdown') }}</button>
   </div>
   <small class="notes-count">{{ modelValue.length }} / {{ maxlength }}</small>
  </div>
  <div v-if="mode === 'visual'" class="notes-visual" :class="{ 'is-invalid': invalid }"><div ref="toolbarRoot" class="notes-toolbar">

    <span class="ql-formats">
     <button type="button" class="ql-bold" :aria-label="t('notesEditor.bold')" :title="t('notesEditor.bold')" :disabled="disabled" />
     <button type="button" class="ql-italic" :aria-label="t('notesEditor.italic')" :title="t('notesEditor.italic')" :disabled="disabled" />
     <button type="button" class="ql-header" value="2" :aria-label="t('notesEditor.heading')" :title="t('notesEditor.heading')" :disabled="disabled" />
     <button type="button" class="ql-list" value="bullet" :aria-label="t('notesEditor.bullets')" :title="t('notesEditor.bullets')" :disabled="disabled" />
     <button type="button" class="ql-list" value="ordered" :aria-label="t('notesEditor.numbered')" :title="t('notesEditor.numbered')" :disabled="disabled" />
     <button type="button" class="ql-blockquote" :aria-label="t('notesEditor.quote')" :title="t('notesEditor.quote')" :disabled="disabled" />
     <button type="button" class="ql-code-block" :aria-label="t('notesEditor.code')" :title="t('notesEditor.code')" :disabled="disabled" />
     <button type="button" class="ql-clean" :aria-label="t('notesEditor.clear')" :title="t('notesEditor.clear')" :disabled="disabled" />
    </span>
  </div><div ref="editorRoot" class="notes-visual-input" /></div>
  <template v-else>
   <textarea :id="id" class="notes-source" :value="modelValue" :aria-label="label" :aria-invalid="!!invalid" :aria-describedby="[describedBy, `${id}-help`].filter(Boolean).join(' ')" :maxlength="maxlength" :disabled="disabled" rows="9" @input="source" />
   <details v-remember-disclosure="id.replace(/^v-\d+-/, '')" v-if="modelValue" class="notes-preview"><DisclosureSummary :level="3" icon="eye">{{ t('notesEditor.preview') }}</DisclosureSummary><RichNotes :value="modelValue" /></details>
  </template>
  <small :id="`${id}-help`" class="notes-help">{{ t(visualAllowed ? 'notesEditor.help' : 'notesEditor.sourceRequired') }}</small>
  <small v-if="warning" :id="`${id}-warning`" class="notes-warning" role="alert">{{ t('notesEditor.tooLong', { count: maxlength }) }}</small>
 </div>
</template>
