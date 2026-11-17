<script setup lang="ts">
import { queryString, useRecordQuery } from '../../lib/route-query';
import { registerModuleMessages } from '../../lib/locale-messages';
import ResetFilters from '../../components/ResetFilters.vue';
import { computed, defineAsyncComponent, onMounted, onUnmounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { NCard as Card } from 'naive-ui';
import { NButton as Button } from 'naive-ui';
import Message from '../../components/AppMessage.vue';
import PageTitle from '../../components/PageTitle.vue';
import AppIcon from '../../components/AppIcon.vue';
import ListActionButton from '../../components/ListActionButton.vue';
import InsightChart from '../../components/InsightChart.vue';
import InsightOptions from '../../components/InsightOptions.vue';
import ReferenceSelect from '../../components/ReferenceSelect.vue';
import { ApiError } from '../../api/auth';
import { insightsApi, type CultureOverview } from '../../api/insights';
import type { DashboardModule } from '../../api/dashboard';
import { locationsApi } from '../../api/locations';
import { useInsightPreferences } from '../../lib/insight-preferences';
import { queryField, useCollectionQuery } from '../../lib/collection-query';
import { toRef } from 'vue';
import { formatDateTime } from '../../lib/date-time';
import { locationLabel } from '../../lib/location';
import { phaseLabel, phaseDefinition } from '../../lib/lifecycle';
import en from '../../locales/en/plants.json';
import fr from '../../locales/fr/plants.json';
const ReportExportsDialog = defineAsyncComponent(() => import('../../components/ReportExportsDialog.vue'));
const exportOpen = ref(false);
const siteName = ref('');
const PlantRegistry = defineAsyncComponent(() => import('./PlantRegistry.vue'));
defineProps<{ embedded?: boolean; canManageIntegrations?: boolean; canManage?: boolean }>();
const emit = defineEmits<{ close: []; open: [module: DashboardModule]; accessLost: [cause: ApiError] }>();
const { t, locale, mergeLocaleMessage } = useI18n({ useScope: 'global' }); registerModuleMessages(mergeLocaleMessage, { en: { plants: en }, fr: { plants: fr } });
const prefs = useInsightPreferences('culture', { site: '', days: 7, sections: ['summary', 'stages', 'strains', 'locations', 'activity', 'upcoming'] });
if (![7, 30].includes(prefs.days)) prefs.days = 7;
const data = ref<CultureOverview | null>(null); const loading = ref(false); const error = ref(''); const plantRoute = useRecordQuery('plant');
const plantId = computed<string | null>({ get: () => queryString(plantRoute.route.query.plant) || null, set: value => { if (value) void plantRoute.open(value); else void plantRoute.close(); } }); let sequence = 0;
const locationPage = ref(1); let loadedScope = '';
const currentScope = () => JSON.stringify([prefs.site, prefs.days]);
const sitePage = async (search: string, page: number) => { const result = await locationsApi.list('sites', search, page); return { ...result, data: result.data.map(row => ({ id: row.id, name: row.name, detail: '' })) }; };
const options = computed(() => ['summary', 'stages', 'strains', 'locations', 'activity', 'upcoming'].map(id => ({ id, label: t(`insights.${id}`) })));
const phases = computed(() => Object.entries(data.value?.plants.byPhase ?? {}).filter(([phase, count]) => !['planned', 'archived'].includes(phase) && count > 0));
const strains = computed(() => data.value?.strains.filter(row => row.active > 0).slice(0, 10) ?? []);
const locationLast = computed(() => Math.max(1, Math.ceil((data.value?.locations.length ?? 0) / 10)));
const locations = computed(() => data.value?.locations.slice((locationPage.value - 1) * 10, locationPage.value * 10) ?? []);
const activitySeries = computed(() => (['waterings', 'maintenance', 'transitions'] as const).map(field => ({ label: t(`insights.${field}`), values: data.value?.activity.map(row => row[field]) ?? [] })));
function fail(cause: unknown) { error.value = 'auth.errors.requestFailed'; if (cause instanceof ApiError && [401,403].includes(cause.status)) { data.value = null; plantId.value = null; emit('accessLost', cause); } }
async function load() { const requestedScope = currentScope(); const current = ++sequence; loading.value = true; error.value = ''; data.value = null; try { const result = await insightsApi.culture(prefs.site, prefs.days); if (current === sequence) { data.value = result; loadedScope = requestedScope; locationPage.value = Math.min(locationPage.value, locationLast.value); } } catch (cause) { if (current === sequence) fail(cause); } finally { if (current === sequence) loading.value = false; } }
const shown = (section: string) => prefs.sections.includes(section);
const collectionQuery = useCollectionQuery({ paths: ['/culture'], page: locationPage, fields: { site: queryField.identifier(toRef(prefs, 'site')), days: queryField.integer(toRef(prefs, 'days'), [7, 30]) }, load: target => { if (data.value && loadedScope === currentScope()) { locationPage.value = target; return; } return load(); } });
onMounted(() => { void load(); }); onUnmounted(() => { sequence++; });
</script>
<template><Card class="insight-workspace" data-testid="culture-overview"><template #header><div class="card-heading-block"><div class="insight-heading"><PageTitle icon="chart">{{ t('insights.overview') }}</PageTitle><div class="insight-actions"><ListActionButton icon="download" :label="t('insights.export')" :disabled="loading || !data || !prefs.sections.length" @click="exportOpen = true" /><InsightOptions v-model="prefs.sections" :options="options" /><ListActionButton icon="refresh" :label="t('insights.refresh')" :loading="loading" @click="load"  /><Button v-if="!embedded" text :aria-label="t('insights.close')" @click="emit('close')" data-action-icon="close" :title="t('insights.close')" class="list-action-button" type="primary"><template #icon><AppIcon name="close" /></template></Button></div></div><div class="card-subtitle">{{ t('insights.overviewHelp') }}</div></div></template><template #default><Message v-if="error" type="error" closable role="alert">{{ t(error) }}</Message>
  <div class="search-filters"><ReferenceSelect v-model="prefs.site" :label="t('insights.site')" :placeholder="t('insights.allSites')" :fetch-page="sitePage" @option="siteName = $event?.name ?? ''" @access-lost="emit('accessLost', $event)" /><div class="filter-field"><label for="culture-activity-period">{{ t('insights.activity') }}</label><select id="culture-activity-period" v-model="prefs.days" v-app-select="{ filter: t('selectControl.filter'), empty: t('selectControl.empty') }"><option :value="7">{{ t('insights.days7') }}</option><option :value="30">{{ t('insights.days30') }}</option></select></div><ResetFilters @reset="prefs.site = ''; prefs.days = 7" /></div>
  <p v-if="loading" role="status" class="insight-help">{{ t('insights.loading') }}</p>
  <template v-if="data"><div v-if="shown('summary')" class="insight-metrics"><button type="button" class="insight-metric" @click="emit('open', 'plants')"><span><AppIcon name="leaf" />{{ t('insights.active') }}</span><strong data-testid="culture-active">{{ data.plants.active }}</strong><small>{{ t('insights.planned') }} · {{ data.plants.planned }}</small></button><div class="insight-metric"><span><AppIcon name="clock" />{{ t('insights.due') }}</span><strong data-testid="culture-due">{{ data.plants.due }}</strong><small>{{ t('insights.wateringDue') }} · {{ data.plants.wateringDue }}</small><small>{{ t('insights.lifecycleDue') }} · {{ data.plants.lifecycleDue }}</small></div><div class="insight-metric"><span><AppIcon name="harvest" />{{ t('insights.harvest') }}</span><strong>{{ Number(data.plants.harvestWeightGrams).toLocaleString(locale) }} <small>g</small></strong><small>{{ t('insights.harvestedPlants', { count: data.plants.harvested }) }}</small></div><div class="insight-metric"><span><AppIcon name="strain" />{{ t('insights.strains') }}</span><strong>{{ data.strains.filter(row => row.active > 0).length }}</strong></div></div>
   <div class="insight-grid"><section v-if="shown('stages')" class="insight-panel"><h3>{{ t('insights.stages') }}</h3><InsightChart v-if="phases.length" type="doughnut" :label="t('insights.stages')" :labels="phases.map(([phase]) => phaseLabel(phase, t))" :series="[{ label: t('insights.active'), values: phases.map(([, count]) => count) }]" /><p v-else class="insight-empty">{{ t('insights.empty') }}</p></section>
    <section v-if="shown('strains')" class="insight-panel"><h3>{{ t('insights.strains') }}</h3><InsightChart v-if="strains.length" type="bar" horizontal :label="t('insights.moreStrains')" :labels="strains.map(row => row.name)" :series="[{ label: t('insights.active'), values: strains.map(row => row.active) }]" /><p v-else class="insight-empty">{{ t('insights.empty') }}</p></section>
    <section v-if="shown('activity')" class="insight-panel insight-wide"><h3>{{ t('insights.activity') }}</h3><InsightChart type="bar" :label="t('insights.activityHelp')" :labels="data.activity.map(row => new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'short', timeZone: 'UTC' }).format(new Date(`${row.day}T00:00:00Z`)))" :series="activitySeries" /></section>
    <section v-if="shown('locations')" class="insight-panel"><h3>{{ t('insights.locations') }}</h3><div v-if="locations.length" class="insight-table-scroll"><table class="insight-table"><thead><tr><th>{{ t('insights.location') }}</th><th>{{ t('insights.active') }}</th><th>{{ t('insights.planned') }}</th><th>{{ t('insights.due') }}</th></tr></thead><tbody><tr v-for="row in locations" :key="row.key"><td>{{ row.location ? locationLabel(row.location) : t('insights.unassigned') }}</td><td>{{ row.active }}</td><td>{{ row.planned }}</td><td>{{ row.due }}</td></tr></tbody></table></div><p v-else class="insight-empty">{{ t('insights.empty') }}</p><nav v-if="locationLast > 1" class="insight-pagination"><Button text :aria-label="t('collection.previous')" :disabled="locationPage <= 1" @click="collectionQuery.go(locationPage - 1)" type="primary"><template #icon><AppIcon name="arrowLeft" /></template></Button><span>{{ locationPage }} / {{ locationLast }}</span><Button text :aria-label="t('collection.next')" :disabled="locationPage >= locationLast" @click="collectionQuery.go(locationPage + 1)" type="primary"><template #icon><AppIcon name="arrow" /></template></Button></nav><p class="insight-help">{{ t('insights.attentionHelp') }}</p></section>
    <section v-if="shown('upcoming')" class="insight-panel"><h3>{{ t('insights.upcoming') }}</h3><ul v-if="data.upcoming.length" class="upcoming-list"><li v-for="plant in data.upcoming" :key="plant.id"><AppIcon :name="phaseDefinition(plant.phase)?.icon ?? 'plant'" /><div><button type="button" class="insight-link" @click="plantId = plant.id"><AppIcon name="eye" class="button-icon" />{{ plant.name }}</button><small>{{ phaseLabel(plant.phase, t) }}<span v-if="plant.location"> · {{ locationLabel(plant.location) }}</span></small></div><time :datetime="plant.dueAt">{{ formatDateTime(plant.dueAt, locale) }}</time></li></ul><p v-else class="insight-empty">{{ t('insights.noUpcoming') }}</p></section></div><p class="insight-help">{{ t('insights.harvestHelp') }}</p><p class="insight-stamp">{{ formatDateTime(data.generatedAt, locale) }}</p></template>
  <ReportExportsDialog v-if="exportOpen && data" :culture="{ siteId: prefs.site, days: prefs.days, sections: [...prefs.sections], siteName: (prefs.site ? siteName || t('insights.site') : t('insights.allSites')) }" @close="exportOpen = false" @access-lost="fail" />
  <PlantRegistry v-if="plantId" detail-only :can-manage="!!canManage" :initial-plant-id="plantId" @close="plantId = null; load()" @access-lost="fail" /></template></Card></template>
<style scoped>
h3 { margin: 0; font-size: .95rem; }.upcoming-list { padding: 0; margin: var(--space-2) 0 0; list-style: none; }.upcoming-list li { display: flex; align-items: center; gap: var(--space-2); padding: var(--space-3) 0; border-bottom: 1px solid var(--theme-border); }.upcoming-list li > svg { width: 1.1rem; height: 1.1rem; flex-shrink: 0; color: var(--theme-muted); }.upcoming-list li > div { min-width: 0; flex: 1; font-size: .82rem; }.upcoming-list small { display: block; font-size: .67rem; color: var(--theme-muted); margin-top: var(--space-1); }.upcoming-list time { font-size: .7rem; color: var(--theme-muted); text-align: right; max-width: 8rem; }
</style>
