<script setup lang="ts">
import AppIcon from './AppIcon.vue';
import DisclosureChevron from './DisclosureChevron.vue';
withDefaults(defineProps<{ icon?: string; level?: 1 | 2 | 3 }>(), { level: 2 });
</script>
<template><summary class="disclosure-summary" :data-level="level"><AppIcon v-if="icon" :name="icon" /><slot /><DisclosureChevron /></summary></template>
<style scoped>
.disclosure-summary { display: flex; align-items: center; justify-content: flex-start; gap: var(--space-2); width: 100%; min-width: 0; min-height: var(--disclosure-height, 2.6rem); box-sizing: border-box; margin: 0; padding: var(--disclosure-padding, var(--space-2) var(--space-3)); border: 0; border-radius: .6rem; background: var(--theme-subtle); color: var(--theme-text); font-size: var(--disclosure-font-size, .8rem); font-weight: var(--disclosure-weight, 600); line-height: 1.45; list-style: none; cursor: pointer; overflow-wrap: anywhere; transition: background .15s; }
.disclosure-summary[data-level="1"] { --disclosure-height: 3.35rem; --disclosure-padding: .65rem .85rem; --disclosure-font-size: 1rem; --disclosure-weight: 650; --disclosure-toggle-size: 1.75rem; --disclosure-icon-size: 1.1rem; background: var(--theme-surface); border: 1px solid var(--theme-border); border-radius: .8rem; }
.disclosure-summary[data-level="1"] > :deep(svg) { padding: var(--space-1-5); border-radius: .55rem; background: var(--theme-subtle); box-sizing: content-box; }
.disclosure-summary[data-level="2"] { --disclosure-height: 2.6rem; --disclosure-padding: .6rem .75rem; --disclosure-font-size: .8rem; --disclosure-weight: 600; --disclosure-toggle-size: 1.5rem; --disclosure-icon-size: 1rem; }
.disclosure-summary[data-level="3"] { --disclosure-height: 2.25rem; --disclosure-padding: .45rem .6rem; --disclosure-font-size: .74rem; --disclosure-weight: 550; --disclosure-toggle-size: 1.25rem; --disclosure-icon-size: .85rem; }
.disclosure-summary::-webkit-details-marker { display: none; }
.disclosure-summary::marker { content: ''; }
.disclosure-summary:hover { background: color-mix(in srgb, var(--theme-focus) 12%, var(--theme-surface)); }
details[open] > .disclosure-summary { color: var(--theme-accent); }
.disclosure-summary:focus-visible { outline: 2px solid var(--theme-focus); outline-offset: 2px; }
.disclosure-summary > :deep(svg), .disclosure-summary > :deep(span > svg) { display: block; width: var(--disclosure-icon-size, 1rem); height: var(--disclosure-icon-size, 1rem); flex-shrink: 0; }
.disclosure-summary > :deep(span:not(.disclosure-chevron)) { display: inline-flex; align-items: center; gap: var(--space-2); min-width: 0; }
.disclosure-summary > :deep(h3), .disclosure-summary > :deep(h4) { margin: 0; font: inherit; letter-spacing: normal; text-transform: none; color: inherit; }
:global(#workspace-content.workspace-pages) .disclosure-summary > :deep(h3), :global(#workspace-content.workspace-pages) .disclosure-summary > :deep(h4) { margin: 0; font: inherit; letter-spacing: normal; text-transform: none; color: inherit; }
.disclosure-summary > :deep(small) { margin-inline-start: auto; color: var(--theme-muted); font-size: .65rem; font-weight: 400; }
@media (prefers-reduced-motion: reduce) { .disclosure-summary { transition: none; } }
</style>
