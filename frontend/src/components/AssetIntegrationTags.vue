<script setup lang="ts">
import { NTag } from 'naive-ui';
import type { AssetIntegration } from '../api/assets';
import AppIcon from './AppIcon.vue';

defineProps<{ integrations?: AssetIntegration[] }>();

const sources: Record<AssetIntegration, { label: string; icon: string }> = {
 home_assistant: { label: 'Home Assistant', icon: 'homeassistant' },
 homebridge: { label: 'Homebridge', icon: 'homebridge' },
 bluetooth: { label: 'Bluetooth', icon: 'bluetooth' },
 mqtt: { label: 'MQTT', icon: 'plug' },
 http: { label: 'HTTP', icon: 'webhook' },
};
</script>

<template>
 <NTag v-for="source in integrations" :key="source" size="small" round>
  <template #icon><AppIcon :name="sources[source].icon" /></template>
  {{ sources[source].label }}
 </NTag>
</template>
