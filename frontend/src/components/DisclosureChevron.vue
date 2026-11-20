<script setup lang="ts">
import AppIcon from './AppIcon.vue';
</script>
<template><span class="disclosure-chevron" aria-hidden="true"><AppIcon name="chevron" class="app-disclosure-icon" /></span></template>
<style scoped>
.disclosure-chevron { display: inline-flex; align-items: center; justify-content: center; align-self: center; flex: 0 0 var(--disclosure-toggle-size, 1.5rem); width: var(--disclosure-toggle-size, 1.5rem); height: var(--disclosure-toggle-size, 1.5rem); margin-inline-start: auto; border-radius: 50%; background: var(--theme-surface); color: var(--theme-text); line-height: 0; }
.disclosure-chevron :deep(svg) { display: block; width: var(--disclosure-icon-size, 1rem); height: var(--disclosure-icon-size, 1rem); flex: 0 0 auto; }
</style>
