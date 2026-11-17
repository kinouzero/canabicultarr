<script setup lang="ts">
import type { Plant } from '../api/plants';
import { usePlantAge } from '../lib/plant-age';
import AppIcon from './AppIcon.vue';
const props = defineProps<{ plant: Pick<Plant, 'phase' | 'day' | 'week'> }>();
const { label, compact } = usePlantAge(() => props.plant);
</script>
<template><span v-if="label" class="collection-record-tag" :title="label"><AppIcon name="clock" />{{ compact }}</span></template>
