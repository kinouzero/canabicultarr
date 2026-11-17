<script setup lang="ts">
import DisclosureSummary from './DisclosureSummary.vue';
import { useRememberDisclosure } from '../lib/disclosure-preferences';
import RichNotes from './RichNotes.vue';
import { computed, onUnmounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { NButton as Button } from 'naive-ui';
import AppIcon from './AppIcon.vue';
import StrainGeneticsTag from './StrainGeneticsTag.vue';
import { strainsApi, type Strain } from '../api/strains';
import { ApiError } from '../api/auth';
const vRememberDisclosure = useRememberDisclosure('PlantStrainSummary');
const props = defineProps<{ strain: Pick<Strain, 'id' | 'name' | 'classification' | 'genetics'> }>();
const emit = defineEmits<{ accessLost: [cause: ApiError] }>();
const { t } = useI18n();
const data = ref<Strain | null>(null); const loading = ref(false); const unavailable = ref(false); let sequence = 0;
const facts = computed(() => (['lineage', 'thc', 'cbd', 'aroma', 'reportedEffects'] as const).filter(key => data.value?.[key]?.trim()));
async function load() {
 const current = ++sequence; data.value = null; loading.value = true; unavailable.value = false;
 try {
  const result = await strainsApi.get(props.strain.id);
  if (current !== sequence) return;
  if (result?.id !== props.strain.id) throw new Error('Strain unavailable');
  data.value = result;
 } catch (cause) {
  if (current !== sequence) return;
  unavailable.value = true;
  if (cause instanceof ApiError && [401, 403].includes(cause.status)) emit('accessLost', cause);
 } finally { if (current === sequence) loading.value = false; }
}
watch(() => props.strain.id, () => { void load(); }, { immediate: true });
onUnmounted(() => { sequence++; });
</script>
<template><section class="record-section strain-summary" :aria-label="t('plants.strainSummary.title')" :aria-busy="loading"><div class="record-section-heading"><h4><AppIcon name="strain" />{{ t('plants.strainSummary.title') }}</h4><div class="record-badges"><StrainGeneticsTag :classification="data ? data.classification : strain.classification" /><a v-if="data?.sourceUrl" class="record-web-link" :href="data.sourceUrl" :aria-label="t('catalog.sourceUrl')" :title="t('catalog.sourceUrl')" target="_blank" rel="noopener noreferrer"><AppIcon name="web" /></a></div></div>
  <strong class="strain-name">{{ data?.name ?? strain.name }}</strong>
  <p v-if="loading" class="record-empty" role="status">{{ t('plants.strainSummary.loading') }}</p>
  <div v-else-if="unavailable" class="strain-unavailable"><p class="record-empty" role="status">{{ t('plants.strainSummary.unavailable') }}</p><Button :aria-label="t('plants.strainSummary.retry')" type="default" @click="load"><template #icon><AppIcon name="refresh" /></template><span>{{ t('actions.retry') }}</span></Button></div>
  <template v-else-if="data"><dl v-if="facts.length" class="record-facts"><div v-for="field in facts" :key="field"><dt>{{ t(`catalog.${field}`) }}</dt><dd>{{ data[field] }}</dd></div></dl>
   <p v-else class="record-empty">{{ t('plants.strainSummary.empty') }}</p>
   <details v-remember-disclosure="`${strain.id}:notes`" v-if="data.notes"><DisclosureSummary :level="3">{{ t('catalog.notes') }}</DisclosureSummary><RichNotes class="strain-notes" :value="data.notes" /></details></template></section></template>
<style scoped>
.strain-name { font-size: .88rem; color: var(--theme-muted, #40563a); }.strain-unavailable { display: flex; gap: var(--space-3); align-items: center; flex-wrap: wrap; }.strain-notes { white-space: pre-wrap; overflow-wrap: anywhere; }
</style>
