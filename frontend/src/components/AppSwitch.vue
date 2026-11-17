<script setup lang="ts">
import { computed, useAttrs, useId } from 'vue';
import { NSwitch } from 'naive-ui';

defineOptions({ inheritAttrs: false });
const value = defineModel<boolean>({ required: true });
const props = defineProps<{ id?: string; disabled?: boolean }>();
const attrs = useAttrs();
const generatedId = useId();
const controlId = computed(() => props.id ?? generatedId);
function controlAttrs() {
 const { class: _class, style: _style, ...control } = attrs;
 return control;
}
function toggle() {
 if (props.disabled) return;
 value.value = !value.value;
 document.getElementById(controlId.value)?.focus();
}
</script>

<template>
 <div class="app-switch-field" :class="[attrs.class, { 'is-disabled': disabled }]" :style="attrs.style">
  <NSwitch v-bind="controlAttrs()" :id="controlId" v-model:value="value" size="small" :disabled="disabled" :aria-disabled="disabled || undefined" :aria-labelledby="`${controlId}-label`" />
  <label :id="`${controlId}-label`" :for="controlId" @click.prevent="toggle"><slot /></label>
 </div>
</template>

<style scoped>
.app-switch-field { display: flex; align-items: center; gap: var(--ui-control-gap); min-width: 0; min-height: var(--app-control-height); }
.app-switch-field > .n-switch { flex-shrink: 0; margin: 0; }
.app-switch-field > label { min-width: 0; margin: 0; cursor: pointer; overflow-wrap: anywhere; }
.app-switch-field > .n-switch[aria-invalid="true"] { outline: 1px solid var(--theme-danger); outline-offset: 2px; border-radius: 999px; }
.app-switch-field.is-disabled > label { cursor: default; }
</style>
