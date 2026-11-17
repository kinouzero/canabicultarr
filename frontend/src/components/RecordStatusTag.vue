<script setup lang="ts">
import { computed } from 'vue';
import { NPopselect, NTag } from 'naive-ui';
import AppIcon from './AppIcon.vue';
defineOptions({ inheritAttrs: false });
type StatusOption = { value: string; label: string; type?: 'success' | 'warning' | 'default' };
const props = defineProps<{ value: string; options: StatusOption[]; label: string; interactive?: boolean; busy?: boolean; plain?: boolean; icon?: string }>();
const emit = defineEmits<{ 'update:value': [value: string] }>();
const current = computed(() => props.options.find(option => option.value === props.value));
function select(value: string) {
 if (props.interactive && !props.busy && value !== props.value) emit('update:value', value);
}
function click() {
 if (props.options.length === 2) {
  const next = props.options.find(option => option.value !== props.value);
  if (next) select(next.value);
 }
}
</script>
<template>
 <NPopselect :value="value" :options="options" trigger="click" :disabled="!interactive || busy || options.length < 3" @update:value="select">
  <component v-bind="$attrs" :is="interactive ? 'button' : 'span'" :type="interactive ? 'button' : undefined" class="record-status-control" :class="{ interactive }" :disabled="interactive && busy || undefined" :aria-label="interactive ? label : undefined" :aria-busy="busy || undefined" :title="interactive ? label : undefined" @click.stop="click" @keydown.stop>
   <template v-if="plain"><AppIcon v-if="icon" :name="icon" /><span>{{ current?.label ?? value }}</span></template>
   <NTag v-else :type="current?.type ?? 'default'"><AppIcon v-if="icon" :name="icon" />{{ current?.label ?? value }}</NTag>
  </component>
 </NPopselect>
</template>
<style scoped>
.record-status-control { display: inline-flex; align-items: center; gap: var(--space-1-5); min-width: 0; max-width: 100%; padding: 0; border: 0; border-radius: 2rem; background: transparent; color: inherit; font-family: inherit; vertical-align: middle; }
.record-status-control.interactive { cursor: pointer; }
.record-status-control.interactive:hover { filter: brightness(.94); }
.record-status-control.interactive:focus-visible { outline: 2px solid var(--theme-focus); outline-offset: 2px; }
.record-status-control:disabled { cursor: wait; opacity: .65; }
.record-status-control svg { width: .8rem; height: .8rem; flex-shrink: 0; }
</style>
