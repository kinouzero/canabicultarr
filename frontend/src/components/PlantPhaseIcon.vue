<script setup lang="ts">
import AppIcon from './AppIcon.vue';
import { plantPhaseIcon } from '../lib/plant-phase-icon';
defineProps<{ phase: string | null | undefined; icon?: string }>();
</script>
<template><AppIcon class="phase-icon" :name="icon ?? plantPhaseIcon(phase)" /></template>
<style scoped>
.phase-icon { display: inline-block; width: 1em; height: 1em; min-width: .85rem; min-height: .85rem; flex-shrink: 0; vertical-align: -.15em; }
</style>
