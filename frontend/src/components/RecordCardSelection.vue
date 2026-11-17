<script lang="ts">
export interface CardSelection {
 label: string;
 checked?: boolean;
 disabled?: boolean;
 toggle: (checked: boolean) => void;
}
</script>

<script setup lang="ts">
import AppIcon from './AppIcon.vue';

const props = defineProps<{ selection: CardSelection }>();
function change(event: Event) {
 props.selection.toggle((event.target as HTMLInputElement).checked);
}
</script>

<template>
 <label class="record-card-selection">
  <input type="checkbox" :checked="selection.checked" :disabled="selection.disabled" :aria-label="selection.label" @change="change" />
  <AppIcon v-if="selection.checked" name="check" />
 </label>
</template>

<style scoped>
.record-card-selection { position: relative; display: grid; place-items: center; width: 1.5rem; height: 1.5rem; flex: 0 0 1.5rem; box-sizing: border-box; border: 1px solid var(--theme-border); border-radius: 50%; background: var(--theme-surface); cursor: pointer; }
.record-card-selection:has(input:checked) { border-color: var(--app-accent); background: var(--app-accent); }
.record-card-selection:hover { border-color: var(--app-accent); }
.record-card-selection:focus-within { outline: 2px solid var(--theme-focus); outline-offset: 2px; }
.record-card-selection:has(input:disabled) { opacity: .55; }
input[type="checkbox"] { appearance: none; position: absolute; inset: 0; width: 100%; height: 100%; margin: 0; opacity: 0; cursor: inherit; }
svg { position: relative; width: .8rem; height: .8rem; color: var(--theme-accent-text, white); pointer-events: none; }
</style>
