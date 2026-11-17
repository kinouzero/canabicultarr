<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import type { Plant } from '../api/plants';
import { assetCategoryIcon } from '../lib/asset-category';
import AppIcon from './AppIcon.vue';

defineProps<{ device: Plant['devices'][number] }>();
const { t } = useI18n();
</script>

<template>
 <div class="plant-hardware-heading">
  <span class="plant-hardware-icon"><AppIcon :name="assetCategoryIcon(device.category)" /></span>
  <div class="plant-hardware-identity">
   <strong :title="device.name">{{ device.name }}</strong>
   <div class="plant-hardware-meta">
    <span>{{ t(`assets.categories.${device.category || 'other'}`) }}</span>
    <span class="plant-hardware-status" :data-status="device.status">
     <span aria-hidden="true"></span>{{ t(`assets.statuses.${device.status}`) }}
    </span>
   </div>
  </div>
 </div>
</template>
