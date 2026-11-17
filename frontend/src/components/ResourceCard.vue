<script setup lang="ts">
import AppIcon from './AppIcon.vue';
import RecordCardActions from './RecordCardActions.vue';
import RecordCardSelection, { type CardSelection } from './RecordCardSelection.vue';
import '../styles/record-cards.css';

// Shared composition for operational records; domain fields and actions stay in each module.
defineProps<{ icon: string; eyebrow: string; tone?: 'success' | 'warning' | 'muted'; selection?: CardSelection }>();
</script>

<template>
 <li class="resource-card record-card" :data-tone="tone" :data-selected="selection?.checked">
  <div v-if="$slots.media" class="resource-card-media">
   <slot name="media" />
   <div class="resource-media-labels">
    <RecordCardSelection v-if="selection" class="resource-media-selection" :selection="selection" />
    <span class="record-card-badge"><AppIcon :name="icon" /><span :title="eyebrow">{{ eyebrow }}</span></span><div v-if="$slots.badge" class="resource-badge"><slot name="badge" /></div>
   </div>
  </div>
  <div class="resource-card-body">
   <header class="resource-card-heading">
    <div v-if="!$slots.media" class="resource-card-labels">
     <RecordCardSelection v-if="selection" :selection="selection" />
     <span class="resource-type record-card-badge"><AppIcon :name="icon" /><span :title="eyebrow">{{ eyebrow }}</span></span>
     <div v-if="$slots.badge" class="resource-badge"><slot name="badge" /></div>
    </div>
    <div class="resource-title"><h3 class="record-card-title"><slot name="title" /></h3></div>
   </header>
   <div v-if="$slots.default" class="resource-content"><slot /></div>
  </div>
  <RecordCardActions v-if="$slots.actions || $slots.management" class="resource-actions"><slot name="actions" /><template v-if="$slots.management" #end><slot name="management" /></template></RecordCardActions>
 </li>
</template>

<style scoped>
.resource-card { display: flex; flex-direction: column; }
.resource-card[data-tone="warning"] { border-color: color-mix(in srgb, var(--theme-warning) 45%, var(--theme-border)); }
.resource-card-body { display: grid; gap: var(--record-card-gap); min-width: 0; padding: var(--record-card-space); }
.resource-card-media { position: relative; }
.resource-media-labels { display: flex; align-items: flex-start; justify-content: space-between; gap: var(--space-1-5); position: absolute; top: .75rem; inset-inline: .75rem; pointer-events: none; }
.resource-card-heading { display: grid; gap: var(--space-2); min-width: 0; }
.resource-card-labels { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2); min-width: 0; }
.resource-card-labels .resource-badge { margin-inline-start: auto; }
.resource-media-selection { pointer-events: auto; }
.resource-media-labels > .record-card-badge { margin-inline-end: auto; }
.resource-type { width: fit-content; }
.resource-title { grid-column: 1 / -1; min-width: 0; }
.resource-badge { min-width: 0; max-width: 9rem; justify-self: end; }
.resource-title :deep(button) { display: block; width: 100%; min-width: 0; padding: 0; border: 0; background: transparent; color: inherit; font: inherit; text-align: start; white-space: normal; overflow-wrap: anywhere; cursor: pointer; }
.resource-title :deep(button:not(:disabled):hover) { color: var(--app-accent); text-decoration: underline; text-underline-offset: .2em; }
.resource-title :deep(button:focus-visible) { outline: 2px solid var(--theme-focus); outline-offset: 3px; border-radius: .2rem; }
.resource-title :deep(button:disabled) { cursor: default; opacity: .65; }
.resource-content { display: grid; gap: var(--space-3); min-width: 0; }
.resource-card :deep(.resource-facts) { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--space-3); margin: 0; }
.resource-card :deep(.resource-facts > div) { min-width: 0; }
.resource-card :deep(.resource-facts dt) { display: flex; align-items: center; gap: var(--space-1-5); margin: 0 0 var(--space-1); color: var(--app-muted); font-size: .67rem; line-height: 1.4; }
.resource-card :deep(.resource-facts dd) { margin: 0; font-size: .78rem; line-height: 1.5; overflow-wrap: anywhere; }
.resource-card :deep(.resource-facts svg) { width: .9rem; height: .9rem; flex-shrink: 0; }
.resource-card :deep(.resource-wide) { grid-column: 1 / -1; }
.resource-card :deep(.resource-note) { border-top: 1px solid var(--app-border); padding-top: var(--space-3); font-size: .76rem; line-height: 1.55; overflow-wrap: anywhere; }
.resource-card :deep(.resource-note p) { margin: 0; }
.resource-card :deep(.resource-metric) { display: flex; align-items: baseline; flex-wrap: wrap; gap: var(--ui-icon-gap); padding: var(--ui-compact-inset); background: var(--app-subtle); border-radius: .6rem; }
.resource-card :deep(.resource-metric strong) { font-size: 1.5rem; line-height: 1.2; font-variant-numeric: tabular-nums; letter-spacing: -.04em; }
.resource-card :deep(.resource-metric span) { color: var(--app-muted); font-size: .72rem; }
.resource-card :deep(.resource-path) { color: var(--app-muted); font-size: .75rem; overflow-wrap: anywhere; }
.resource-card :deep(.resource-path > span) { min-width: 0; }
.resource-card :deep(.resource-path > svg) { flex-shrink: 0; width: 1rem; height: 1rem; margin-top: var(--space-0-5); }
</style>
