<script setup lang="ts">
import { inject } from 'vue';
import { filePreviewOpener } from '../lib/route-query';
import { maintenanceLabel } from '../lib/plant-maintenance';
import { phaseLabel, actionLabel } from '../lib/lifecycle';
import { locationLabel } from '../lib/location';
import PlantPhaseIcon from './PlantPhaseIcon.vue';
import { plantActionIcon } from '../lib/plant-action-icon';
import { formatDateTime } from '../lib/date-time';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppIcon from './AppIcon.vue';
import { NTimeline, NTimelineItem } from 'naive-ui';
import RichNotes from './RichNotes.vue';
import FilePreview from './FilePreview.vue';
import type { ApiError } from '../api/auth';
import type { PlantAttachment, PlantEvent } from '../api/plants';
const props = defineProps<{ events: PlantEvent[]; busy: boolean }>();
const emit = defineEmits<{ download: [document: PlantAttachment]; accessLost: [cause: ApiError] }>();
const { t, locale } = useI18n();
const preview = ref<PlantAttachment | null>(null);
const openPreview = inject(filePreviewOpener, null);
function showPreview(file: PlantAttachment) { if (openPreview) openPreview(file); else preview.value = file; }
const groups = computed(() => {
 const ordered = [...props.events].sort((a, b) => Date.parse(b.occurredAt) - Date.parse(a.occurredAt) || Date.parse(b.recordedAt) - Date.parse(a.recordedAt));
 const result: { date: string; events: PlantEvent[] }[] = [];
 for (const event of ordered) {
  const date = formatDateTime(event.occurredAt, locale.value, 'date');
  const previous = result.at(-1); if (previous?.date === date) previous.events.push(event); else result.push({ date, events: [event] });
 }
 return result;
});
function eventLabel(event: PlantEvent) { if (event.snapshot.strain_change) return t('plants.strainChanged'); if (event.snapshot.lifecycle_action) return actionLabel(event.kind, t, event.snapshot.lifecycle_action); return t(['imported', 'trello_comment', 'created', 'updated', 'matured', 'watered', 'maintained'].includes(event.kind) ? `plants.events.${event.kind}` : `plants.actions.${event.kind}`); }
function icon(event: PlantEvent) { if (event.snapshot.lifecycle_action) return event.snapshot.lifecycle_action.icon; return event.kind === 'watered' ? 'water' : event.kind === 'maintained' ? 'scissors' : event.kind === 'trello_comment' ? 'message' : event.kind === 'updated' ? 'edit' : plantActionIcon(event.kind); }
function image(document: PlantAttachment) { return ['image/jpeg', 'image/png', 'image/webp'].includes(document.mediaType); }
function url(document: PlantAttachment) { return `/api/v1/documents/${encodeURIComponent(document.id)}/preview`; }
</script>
<template>
 <div class="plant-timeline">
  <p v-if="!events.length" class="timeline-empty">{{ t('plants.noHistory') }}</p>
  <section v-for="group in groups" :key="group.date" class="timeline-day" :aria-label="group.date">
   <h5>{{ group.date }}</h5>
   <NTimeline class="plant-history" :theme-overrides="{ iconSizeMedium: '2rem' }"><NTimelineItem v-for="event in group.events" :key="event.id">
    <template #icon><span class="timeline-marker" :data-kind="event.kind"><AppIcon :name="icon(event)" /></span></template>
    <template #default>
     <article class="timeline-event" :data-kind="event.kind" :data-event-id="event.id">
      <header class="timeline-heading"><strong>{{ eventLabel(event) }}</strong><time :datetime="event.occurredAt" :title="formatDateTime(event.occurredAt, locale)"><AppIcon name="clock" />{{ formatDateTime(event.occurredAt, locale, 'time') }}</time></header>
      <div class="timeline-context">
       <span v-if="event.kind !== 'trello_comment'" class="timeline-stage"><PlantPhaseIcon :phase="event.toPhase" :icon="event.snapshot.lifecycle_stage?.icon" />{{ phaseLabel(event.toPhase, t, event.snapshot.lifecycle_stage) }}</span>
       <span v-if="event.snapshot.location" class="timeline-location"><AppIcon name="location" />{{ locationLabel(event.snapshot.location) }}</span>
      </div>
      <div v-if="event.snapshot.automatic_relocation || event.maintenance || event.watering || (event.harvestWeightGrams && (event.snapshot.lifecycle_action?.requires_harvest_weight || event.kind === 'start_maturation'))" class="timeline-tags">
       <span v-if="event.snapshot.automatic_relocation" :title="locationLabel(event.snapshot.previous_location)"><AppIcon name="location" />{{ t('plants.automaticRelocation') }}</span>
       <span v-if="event.maintenance"><AppIcon name="scissors" />{{ maintenanceLabel(event.maintenance.type, t) }}</span>
       <span v-if="event.watering"><AppIcon name="water" />{{ t(event.watering.withProducts ? 'plants.watering.with' : 'plants.watering.without') }}</span>
       <span v-if="event.watering?.pump"><AppIcon name="pump" />{{ event.watering.pump.name }}</span>
       <span v-if="event.watering?.duringFlush">{{ t('plants.watering.duringFlush') }}</span>
       <span v-if="event.harvestWeightGrams && (event.snapshot.lifecycle_action?.requires_harvest_weight || event.kind === 'start_maturation')">{{ t('plants.harvestWeightValue', { weight: new Intl.NumberFormat(locale).format(Number(event.harvestWeightGrams)) }) }}</span>
      </div>
      <div v-if="event.watering?.waterLiters || event.watering?.fertilizers?.length" class="timeline-tags"><span v-if="event.watering.waterLiters"><AppIcon name="water" />{{ event.watering.waterLiters }} L</span><span v-for="product in event.watering.fertilizers" :key="product.inventoryItemId"><AppIcon name="leaf" />{{ product.name }} · {{ product.quantity }} {{ product.unit }} ({{ product.dosePerLiter }} {{ product.unit }}/L)</span></div>
      <div v-if="event.substrates?.length" class="timeline-tags"><span v-for="product in event.substrates" :key="product.inventoryItemId"><AppIcon name="pot" />{{ product.name }} · {{ product.quantity }} {{ product.unit }}</span></div>
      <div v-if="event.container" class="timeline-tags"><span><AppIcon name="pot" />{{ event.container.current.name }} · {{ Number(event.container.current.capacityLiters) }} L</span><span v-if="event.container.previous">{{ t(event.container.previousDisposition === 'recover' ? 'plants.containers.recover' : 'plants.containers.discard') }} · {{ event.container.previous.name }}</span></div>
      <div v-if="event.snapshot.strain_change" class="timeline-comment"><strong>{{ event.snapshot.strain_change.previous.name }} → {{ event.snapshot.strain_change.current.name }}</strong><p>{{ event.snapshot.strain_change.reason }}</p></div>
      <RichNotes v-if="event.comment" class="timeline-comment" :value="event.comment" />
      <ul v-if="event.attachments?.length" class="timeline-files">
       <li v-for="document in event.attachments" :key="document.id" :class="{ 'timeline-image-file': image(document) }">
        <button class="timeline-file-preview" :aria-label="t(image(document) ? 'recordDetails.enlargePhoto' : 'filePreview.openNamed', { name: document.originalName })" :title="document.originalName" @click="showPreview(document)">
         <img v-if="image(document)" :src="url(document)" :alt="document.originalName" loading="lazy" />
         <span v-else class="timeline-file-icon"><AppIcon name="file" /></span>
         <span class="timeline-file-name">{{ document.originalName }}</span>
        </button>
        <button class="timeline-file-download" :aria-label="t('plants.attachments.download', { name: document.originalName })" :title="t('plants.attachments.download', { name: document.originalName })" :disabled="busy" @click="emit('download', document)"><AppIcon name="download" /></button>
       </li>
      </ul>
     </article>
    </template>
   </NTimelineItem></NTimeline>
  </section>
  <FilePreview v-if="preview" :file="preview" @close="preview = null" @access-lost="emit('accessLost', $event)" />
 </div>
</template>
<style scoped>
.plant-timeline { min-width: 0; }
.timeline-day + .timeline-day { margin-top: var(--ui-content-gap); }
.timeline-day h5 { display: flex; align-items: center; gap: var(--space-3); margin: 0 0 var(--space-3); color: var(--theme-muted); font-size: .72rem; font-weight: 600; }
.timeline-day h5::after { content: ''; height: 1px; flex: 1; background: var(--theme-border); }
.plant-history { margin: 0; padding: 0; }
.plant-history :deep(.n-timeline-item) { min-height: 0; }
.plant-history :deep(.n-timeline-item-content) { min-width: 0; padding: 0 0 var(--ui-content-gap); }
.plant-history :deep(.n-timeline-item:last-child .n-timeline-item-content) { padding-bottom: 0; }
.plant-history :deep(.n-timeline-item-timeline) { top: 0; }
.plant-history :deep(.n-timeline-item-timeline__line) { background: var(--theme-border); width: 1px; left: calc(var(--n-icon-size) / 2 - .5px); }
.timeline-marker { display: grid; place-items: center; flex-shrink: 0; width: 2rem; height: 2rem; border: 1px solid var(--theme-border); border-radius: 50%; color: var(--theme-accent-fill); background: var(--theme-subtle); }
.timeline-marker svg { width: .95rem; height: .95rem; }
.timeline-marker[data-kind="watered"] { background: var(--theme-info-soft); }
.timeline-marker[data-kind="maintained"] { background: var(--theme-warning-soft); }
.timeline-event { min-width: 0; }
.timeline-heading { min-height: 2rem; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: var(--space-1-5) var(--space-3); }
.timeline-heading strong { font-size: .8rem; font-weight: 650; color: var(--theme-text); overflow-wrap: anywhere; }
.timeline-heading time { display: inline-flex; align-items: center; gap: var(--space-1); flex-shrink: 0; font-size: .68rem; color: var(--theme-muted); font-variant-numeric: tabular-nums; }
.timeline-heading time svg { width: .75rem; height: .75rem; }
.timeline-context { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-1-5) var(--space-3); margin-top: var(--space-1-5); font-size: .68rem; color: var(--theme-muted); }
.timeline-context > span { display: inline-flex; align-items: center; gap: var(--space-1); min-width: 0; overflow-wrap: anywhere; }
.timeline-context svg, .timeline-tags svg { width: .8rem; height: .8rem; flex-shrink: 0; }
.timeline-stage { padding: var(--space-1) var(--space-2); border-radius: 2rem; background: var(--theme-subtle); }
.timeline-location { flex: 1 1 10rem; }
.timeline-comment { margin-top: var(--space-2); font-size: .8rem; line-height: 1.6; color: var(--theme-text); }
.timeline-comment :deep(> :first-child) { margin-top: 0; }
.timeline-comment :deep(> :last-child) { margin-bottom: 0; }
.timeline-tags { display: flex; flex-wrap: wrap; gap: var(--space-1-5); margin-top: var(--space-2); }
.timeline-tags > span { display: inline-flex; align-items: center; gap: var(--space-1); border-radius: .4rem; padding: var(--space-1) var(--space-2); background: var(--theme-subtle); color: var(--theme-muted); font-size: .68rem; }
.timeline-files { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 13rem), 1fr)); gap: var(--space-2); padding: 0; list-style: none; margin: var(--space-3) 0 0; }
.timeline-files li { display: flex; align-items: center; min-width: 0; border: 1px solid var(--theme-border); border-radius: .55rem; overflow: hidden; background: var(--theme-subtle); }
.timeline-files button { display: inline-flex; align-items: center; gap: var(--space-2); border: 0; background: transparent; color: var(--theme-text); font: inherit; cursor: pointer; }
.timeline-file-preview { flex: 1; min-width: 0; padding: var(--space-1-5); text-align: start; }
.timeline-file-preview img, .timeline-file-icon { width: 2.8rem; height: 2.5rem; border-radius: .35rem; flex-shrink: 0; object-fit: cover; }
.timeline-file-icon { display: grid; place-items: center; background: var(--theme-surface); color: var(--theme-muted); }
.timeline-file-icon svg { width: 1.1rem; height: 1.1rem; }
.timeline-file-name { font-size: .7rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.timeline-file-download { padding: var(--space-3); flex-shrink: 0; }
.timeline-file-download svg { width: .9rem; height: .9rem; }
.timeline-files button:enabled:hover { background: var(--theme-border); }
.timeline-files button:disabled { opacity: .5; cursor: default; }
.timeline-files button:focus-visible { outline: 2px solid var(--theme-focus); outline-offset: -2px; }
.timeline-empty { margin: 0; padding: var(--space-4); border: 1px dashed var(--theme-border); border-radius: .8rem; background: var(--theme-subtle); font-size: .8rem; color: var(--theme-muted); }
@media (max-width: 600px) {.timeline-heading strong { flex: 1 1 8rem; }.timeline-files { grid-template-columns: minmax(0, 1fr); } }
</style>
