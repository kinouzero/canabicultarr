<script setup lang="ts">
import { ref, onUnmounted } from 'vue';
import { NButton as Button } from 'naive-ui';
import FormDialog from './FormDialog.vue';
import CollectionPagination from './CollectionPagination.vue';
import { assetsApi, type LinkedPlant } from '../api/assets';
import { useI18n } from 'vue-i18n';
import AppIcon from './AppIcon.vue';
import LocationTrail from './LocationTrail.vue';
import { RouterLink } from 'vue-router';
import { panelPaths } from '../router';
import type { DeviceAssignment } from '../api/assets';
const props = defineProps<{ device: DeviceAssignment }>();
const { t } = useI18n();
const open = ref(false); const loading = ref(false); const error = ref(false);
const rows = ref<LinkedPlant[]>([]); const page = ref(1); const lastPage = ref(1); const total = ref(0);
let controller: AbortController | null = null;
function close() { controller?.abort(); controller = null; open.value = false; rows.value = []; }
async function load(target = 1) {
 if (!props.device.id) return;
 controller?.abort(); const current = new AbortController(); controller = current;
 open.value = true; loading.value = true; error.value = false;
 try {
  const result = await assetsApi.plants(props.device.id, target, current.signal);
  if (controller !== current) return;
  rows.value = result.data; page.value = result.meta.current_page; lastPage.value = result.meta.last_page; total.value = result.meta.total;
 } catch (cause) { if (controller === current && !(cause instanceof DOMException && cause.name === 'AbortError')) { rows.value = []; error.value = true; } }
 finally { if (controller === current) loading.value = false; }
}
onUnmounted(close);
</script>
<template><span class="device-assignment"><span v-for="location in device.locations" :key="`${location.type}:${location.id}`" class="assignment-row"><LocationTrail :location="location" /></span>
  <span v-for="plant in device.plants" :key="plant.id" class="assignment-row"><AppIcon name="leaf" /><span><RouterLink class="assignment-plant-link" :to="{ path: panelPaths.plants, query: { plant: plant.id } }" @click.stop>{{ t('assets.linkedPlant', { name: plant.name }) }}</RouterLink><small v-if="plant.location" class="assignment-location"><LocationTrail :location="plant.location" /></small></span></span>
  <span v-if="!device.locations?.length && !device.plants?.length" class="assignment-row"><AppIcon name="location" /><span>{{ t('assets.unassigned') }}</span></span>
  <Button v-if="device.id && (device.plantsCount ?? 0) > device.plants.length" size="small" variant="text" @click.stop="load()" type="primary"><template #icon><AppIcon name="leaf" /></template><span>{{ t('assets.allLinkedPlants', { count: device.plantsCount }) }}</span></Button>
  <FormDialog v-if="open" :visible="true" icon="leaf" size="compact" :title="t('assets.allLinkedPlants', { count: device.plantsCount })" :close-label="t('modalActions.closeForm')" :error="error ? t('auth.errors.requestFailed') : ''" @update:visible="!$event && close()"><div @click.stop><p v-if="loading" role="status">{{ t('assets.loading') }}</p>
    <Button v-if="error" @click="load(page)" type="primary"><template #icon><AppIcon name="refresh" /></template><span>{{ t('actions.refresh') }}</span></Button>
    <ul v-else class="linked-plant-list"><li v-for="plant in rows" :key="plant.id"><AppIcon name="leaf" /><span><RouterLink class="assignment-plant-link" :to="{ path: panelPaths.plants, query: { plant: plant.id } }" @click.stop>{{ plant.name }}</RouterLink><small v-if="plant.location" class="assignment-location"><LocationTrail :location="plant.location" /></small></span></li></ul>
    <CollectionPagination :page="page" :last-page="lastPage" :total="total" :disabled="loading" @page="load" /></div></FormDialog></span></template>
<style scoped>
.linked-plant-list { list-style: none; padding: 0; margin: 0; display: grid; gap: var(--space-3); }.linked-plant-list li { display: flex; gap: var(--space-2); align-items: start; padding-block: var(--space-2); border-bottom: 1px solid var(--theme-border); }.linked-plant-list li > span { min-width: 0; overflow-wrap: anywhere; }
.device-assignment { display: grid; gap: var(--space-2); min-width: 0; font-size: .75rem; color: var(--theme-muted); }
.assignment-row { display: flex; align-items: flex-start; gap: var(--space-1-5); min-width: 0; line-height: 1.5; }
.assignment-row > svg { width: 1rem; height: 1rem; flex-shrink: 0; margin-top: .05rem; }
.assignment-row > span { min-width: 0; overflow-wrap: anywhere; }
.assignment-row small { font-size: .68rem; }
.assignment-location { display: flex; align-items: flex-start; gap: var(--space-1); }
.assignment-location > svg { width: .85rem; height: .85rem; flex: 0 0 auto; margin-top: .08rem; }
.assignment-location > span { min-width: 0; overflow-wrap: anywhere; }
.device-assignment.resource-path { display: grid; }
.assignment-plant-link { color: inherit; text-decoration: none; }
.assignment-plant-link:hover { color: var(--theme-accent); text-decoration: underline; text-underline-offset: .2em; }
.assignment-plant-link:focus-visible { outline: 2px solid var(--theme-focus); outline-offset: 2px; border-radius: .2rem; }
</style>
