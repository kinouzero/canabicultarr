<script setup lang="ts">
import AppIcon from './AppIcon.vue';
defineProps<{ icon: string }>();
</script>
<template><h2 class="page-title"><AppIcon :name="icon" /><span><slot /></span></h2></template>
<style scoped>
.page-title { display: flex; align-items: center; gap: var(--space-2); min-width: 0; }
.page-title > svg { flex: 0 0 auto; width: 1.2em; height: 1.2em; color: var(--theme-muted, #53684e); }
.page-title > span { min-width: 0; overflow-wrap: anywhere; }
</style>
