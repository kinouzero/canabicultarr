<script setup lang="ts">
import { seedStockEmpty } from '../lib/strain-stock';
import { photoThumbnailUrl } from '../lib/photo-urls';
import RecordStatusTag from './RecordStatusTag.vue';
import RecordCardActions from './RecordCardActions.vue';
import RecordCardMedia from './RecordCardMedia.vue';
import RecordCardSelection, { type CardSelection } from './RecordCardSelection.vue';
import { onMounted, onUnmounted, ref, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { ApiError } from '../api/auth';
import { photosApi } from '../api/photos';
import type { Strain } from '../api/strains';
import AppIcon from './AppIcon.vue';
import StrainRecordActions from './StrainRecordActions.vue';
import ListActionButton from './ListActionButton.vue';
import PlantPhaseIcon from './PlantPhaseIcon.vue';
import StrainGeneticsTag from './StrainGeneticsTag.vue';
import '../styles/photo-cards.css';
const props = defineProps<{ strain: Strain; floweringDays: number; loading?: boolean; canCreatePlant?: boolean; selection?: CardSelection; error?: string; uploadFile: (file: File, disk?: string) => Promise<boolean> }>();
const emit = defineEmits<{ open: []; createPlant: []; edit: []; status: []; remove: []; photo: [id: string | null]; failure: [cause: unknown] }>();
const { t, n, locale } = useI18n();
const lineageId = useId();
const card = ref<HTMLElement | null>(null); const photoUrl = ref<string | null>(null);
let alive = true; let observer: IntersectionObserver | undefined;
async function loadPhoto() {
 try { const result = await photosApi.list('strain', props.strain.id); if (alive) photoUrl.value = result.data[0]?.url ?? null; }
 catch (cause) { if (alive && cause instanceof ApiError && [401, 403].includes(cause.status)) emit('failure', cause); }
}
onMounted(() => {
 if (!card.value || typeof IntersectionObserver === 'undefined') { void loadPhoto(); return; }
 observer = new IntersectionObserver(entries => { if (entries.some(entry => entry.isIntersecting)) { observer?.disconnect(); void loadPhoto(); } }, { rootMargin: '150px' });
 observer.observe(card.value);
});
watch(() => props.strain.version, () => { void loadPhoto(); });
onUnmounted(() => { alive = false; observer?.disconnect(); });
const quantity = (value: string) => new Intl.NumberFormat(locale.value, { maximumFractionDigits: 3 }).format(Number(value));
</script>
<template>
 <article ref="card" class="photo-plant record-card strain-photo-card" :data-selected="selection?.checked">
  <RecordCardSelection v-if="selection" class="strain-card-selection" :selection="selection" />
  <div class="strain-card-opening"><button type="button" class="open-plant" :aria-label="t('catalog.open', { name: strain.name })" :aria-describedby="strain.lineage ? lineageId : undefined" @click="emit('open')"></button>
   <RecordCardMedia :key="strain.version" class="plant-image" :src="photoUrl ? photoThumbnailUrl(photoUrl) : null" :empty-label="t('recordDetails.noPhotos')" :error-label="t('recordDetails.photoUnavailable')">
    <span class="photo-top-tags"><RecordStatusTag class="photo-phase" plain :value="strain.isArchived ? 'archived' : 'active'" :icon="strain.isArchived ? 'archive' : 'strain'" :options="[{ value: 'active', label: t('catalog.activeTag'), type: 'success' }, { value: 'archived', label: t('catalog.archivedTag'), type: 'default' }]" :interactive="strain.canManage" :busy="loading" :label="t('collection.changeStatus', { name: strain.name })" @update:value="emit('status')" /><span class="photo-age"><PlantPhaseIcon phase="flowering" /><span>{{ t('catalog.weekCount', { count: n(floweringDays / 7, { maximumFractionDigits: 2 }) }) }}</span></span></span>
    <StrainGeneticsTag class="photo-genetics" :classification="strain.classification" /><span class="photo-reference card-reference" :title="strain.id">#{{ strain.id.slice(0, 8) }}</span>
   </RecordCardMedia>
   <span class="plant-card-body">
    <span class="plant-card-top"><span class="plant-name record-card-title">{{ strain.name }}</span><AppIcon name="arrow" /></span>
    <span v-if="strain.lineage" :id="lineageId" class="plant-strain strain-lineage" :title="strain.lineage">{{ strain.lineage }}</span>
    <span v-if="strain.thc || strain.cbd" class="strain-card-facts"><span v-if="strain.thc">{{ t('catalog.thc') }} · {{ strain.thc }}</span><span v-if="strain.cbd">{{ t('catalog.cbd') }} · {{ strain.cbd }}</span></span>
    <span v-if="strain.theoreticalYield != null" class="card-watering"><strong><AppIcon name="scissors" /><span>{{ t('catalog.theoreticalYield') }}</span></strong><span>{{ t('catalog.yieldValue', { count: n(Number(strain.theoreticalYield), { maximumFractionDigits: 2 }) }) }}</span></span>
    <span class="card-watering seed-stock" :data-testid="`strain-seed-stock-${strain.id}`"><strong><AppIcon name="seed" /><span>{{ t('catalog.seedStock') }}</span></strong><span>{{ strain.seedStock ? t('catalog.seedQuantity', { count: quantity(strain.seedStock.quantity) }) : t('catalog.noSeedStock') }}<small v-if="strain.seedStock?.scoped"> · {{ t('catalog.scopedStock') }}</small></span></span>
   </span>
  </div>
  <RecordCardActions v-if="strain.canManage || canCreatePlant && !strain.isArchived" class="card-actions"><ListActionButton v-if="canCreatePlant && !strain.isArchived" icon="leaf" :label="t('plants.add')" :disabled="loading || seedStockEmpty(strain)" @click.stop="emit('createPlant')" /><template v-if="strain.canManage" #end><StrainRecordActions :show-photos="false" :strain="strain" :disabled="loading" :error="error" :upload-file="uploadFile" @photo="emit('photo', $event)" @edit="emit('edit')" @remove="emit('remove')" @failure="emit('failure', $event)" /></template></RecordCardActions>
 </article>
</template>
<style scoped>
.strain-card-opening { position: relative; }
.strain-card-opening > .open-plant { position: absolute; inset: 0; z-index: 1; }
.photo-top-tags { z-index: 2; pointer-events: none; }
.strain-photo-card { position: relative; }
.strain-card-selection { position: absolute; inset-block-start: .75rem; inset-inline-start: .75rem; z-index: 3; }
.strain-photo-card:has(.strain-card-selection) .photo-top-tags { inset-inline-start: 2.7rem; }
.photo-top-tags :deep(.record-status-control) { pointer-events: auto; }
.strain-photo-card > .card-actions { padding-top: var(--record-card-gap); }
.strain-lineage { display: block; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.strain-card-facts { display: flex; flex-wrap: wrap; gap: var(--space-1-5) var(--space-3); font-size: .7rem; color: var(--theme-muted, #63745e); margin-top: var(--space-3); }
</style>
