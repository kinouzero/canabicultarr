<script setup lang="ts">
import IntegrationChannelFilters from '../../components/IntegrationChannelFilters.vue';
import CollectionTable from '../../components/CollectionTable.vue';
import CollectionViewToggle from '../../components/CollectionViewToggle.vue';
import DisclosureSummary from '../../components/DisclosureSummary.vue';
import { useCollectionPageSize } from '../../lib/collection-page-size';
import CollectionPagination from '../../components/CollectionPagination.vue';
import PageTitle from '../../components/PageTitle.vue';
import IntegrationStatus from '../../components/IntegrationStatus.vue';
import ResourceCard from '../../components/ResourceCard.vue';
import AppIcon from '../../components/AppIcon.vue';
import { NTag as Tag } from 'naive-ui';
import { useRememberDisclosure } from '../../lib/disclosure-preferences';
import { queryField, useCollectionQuery } from '../../lib/collection-query';
import DetailDialog from '../../components/DetailDialog.vue';
import FormDialog from '../../components/FormDialog.vue';
import { assetCategoryIcon } from '../../lib/asset-category';
import ListActionButton from '../../components/ListActionButton.vue';
import { formatDateTime } from '../../lib/date-time';
import { onMounted, onUnmounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { NButton as Button } from 'naive-ui';
import { NCard as Card } from 'naive-ui';
import { NInput as InputText } from 'naive-ui';
import Message from '../../components/AppMessage.vue';
import SensorHistory from '../../components/SensorHistory.vue';
import en from '../../locales/en/measurements.json';
import fr from '../../locales/fr/measurements.json';
import homebridgeEn from '../../locales/en/homebridge.json';
import homebridgeFr from '../../locales/fr/homebridge.json';
import { ApiError } from '../../api/auth';
import { useSensorHistoryRoute } from '../../lib/sensor-history-route';
import { measurementsApi, homebridgeApi, type EntityOption, type RemoteSensorLink, type ImportResult } from '../../api/measurements';
const props = defineProps<{ embedded?: boolean; provider?: 'homebridge'; canManage: boolean; canDiscover?: boolean }>(); const emit = defineEmits<{ close: []; readings: [sensorId: string]; accessLost: [cause: ApiError] }>();
const entityPageSize = useCollectionPageSize(`${props.provider ?? 'home-assistant'}:entities`);
const pageSize = useCollectionPageSize(`${props.provider ?? 'home-assistant'}:links`);
const { t, te, locale, mergeLocaleMessage } = useI18n({ useScope: 'global' }); mergeLocaleMessage('en', { measurements: en }); mergeLocaleMessage('fr', { measurements: fr });
mergeLocaleMessage('en', { homebridge: homebridgeEn }); mergeLocaleMessage('fr', { homebridge: homebridgeFr });
const vRememberDisclosure = useRememberDisclosure(`Integration:${props.provider ?? 'home-assistant'}`);
const integration = props.provider === 'homebridge' ? homebridgeApi : measurementsApi;
const integrationIcon = props.provider === 'homebridge' ? 'homebridge' : 'homeassistant';
function label(key: string, values: Record<string, string | number> = {}) { return t(props.provider === 'homebridge' && te(`homebridge.${key}`) ? `homebridge.${key}` : `measurements.${key}`, values); }
const removing = ref<RemoteSensorLink | null>(null);
const importing = ref(false);
const sync = ref<import('../../api/measurements').RemoteSyncStatus | null>(null);
const error = ref(''); const notice = ref(''); const loading = ref(false); const busy = ref(false); const configured = ref(false); const connected = ref(false);
const entitiesOpen = ref(false); const entities = ref<EntityOption[]>([]); const entitySearch = ref(''); const entityPage = ref(1); const entityLast = ref(1); const entityTotal = ref(0); const selected = ref<string[]>([]); const importResult = ref<ImportResult | null>(null);
const linkSearch = ref(''); const linkState = ref('');
const links = ref<RemoteSensorLink[]>([]); const linkPage = ref(1); const linkLast = ref(1); const linkTotal = ref(0);
const { sensor, open: openHistory, close: closeHistory } = useSensorHistoryRoute(showError);
let entitySequence = 0; let linkSequence = 0; let disposed = false;
function key(value: string) { if (props.provider === 'homebridge' && value.startsWith('measurements.') && te(value.replace('measurements.', 'homebridge.'))) return value.replace('measurements.', 'homebridge.'); return /^(auth|measurements)\.(errors|validation)\.[A-Za-z]+$/.test(value) && te(value) ? value : 'auth.errors.requestFailed'; }
function showError(cause: unknown) { if (disposed) return; error.value = key(cause instanceof ApiError ? Object.values(cause.fields).flat()[0] ?? cause.code : 'auth.errors.requestFailed'); if (cause instanceof ApiError && (cause.status === 401 || cause.status === 403)) { sensor.value = null; links.value = []; entities.value = []; emit('accessLost', cause); } }
function date(value: string | null) { return value ? formatDateTime(value, locale.value) : label('never'); }
async function loadLinks(target = 1) { const current = ++linkSequence; const result = await integration.links(target, pageSize.value, linkSearch.value.trim(), linkState.value); if (disposed || current !== linkSequence) return; links.value = result.data; linkPage.value = result.meta.current_page; linkLast.value = result.meta.last_page; linkTotal.value = result.meta.total; }
async function initialize() { if (disposed || !props.canManage) return; loading.value = true; error.value = notice.value = ''; connected.value = false; try { const status = await integration.status(); if (disposed) return; configured.value = status.configured; sync.value = status.sync; await loadLinks(linkPage.value); if (entitiesOpen.value && props.canDiscover !== false && configured.value) await discover(entityPage.value); } catch (cause) { showError(cause); } finally { loading.value = false; } }
async function check() { busy.value = true; error.value = ''; connected.value = false; try { connected.value = (await integration.check()).connected; } catch (cause) { showError(cause); } finally { busy.value = false; } }
async function discover(target = 1) { const current = ++entitySequence; entitiesOpen.value = true; loading.value = true; error.value = ''; try { const result = await integration.entities(entitySearch.value.trim(), target, entityPageSize.value); if (current !== entitySequence) return; entities.value = result.data; entityPage.value = result.meta.current_page; entityLast.value = result.meta.last_page; entityTotal.value = result.meta.total; } catch (cause) { if (current === entitySequence) showError(cause); } finally { if (current === entitySequence) loading.value = false; } }
async function importEntities() {
 if (busy.value || !selected.value.length) return; busy.value = true; importing.value = true; error.value = ''; importResult.value = null;
 try { importResult.value = await integration.import([...selected.value]); selected.value = []; await loadLinks(1); await discover(entityPage.value); } catch (cause) { showError(cause); } finally { busy.value = false; importing.value = false; }
}
function selectEntity(id: string) { selected.value = selected.value.includes(id) ? selected.value.filter(v => v !== id) : [...selected.value, id]; }
async function toggle(link: RemoteSensorLink) { busy.value = true; error.value = ''; try { await integration.toggle(link); await loadLinks(linkPage.value); } catch (cause) { showError(cause); if (cause instanceof ApiError && cause.status === 409) await loadLinks(linkPage.value).catch(showError); } finally { busy.value = false; } }
async function removeLink() {
 const link = removing.value; if (!link || busy.value) return;
 busy.value = true; error.value = notice.value = '';
 try {
  await integration.remove(link); removing.value = null; notice.value = 'measurements.removed';
  await loadLinks(linkPage.value);
  if (!links.value.length && linkPage.value > 1) await loadLinks(linkPage.value - 1);
  if (entitiesOpen.value) await discover(entityPage.value);
 } catch (cause) { showError(cause); if (cause instanceof ApiError && [404, 409].includes(cause.status)) { removing.value = null; await loadLinks(linkPage.value).catch(showError); } }
 finally { busy.value = false; }
}
async function collect(link: RemoteSensorLink) { busy.value = true; error.value = notice.value = ''; try { const result = await integration.collect(link.id); if (result.code) error.value = key(result.code); else notice.value = `measurements.outcomes.${result.outcome}`; await loadLinks(linkPage.value); } catch (cause) { showError(cause); } finally { busy.value = false; } }
async function paginateLinks(target: number) { loading.value = true; try { await loadLinks(target); } catch (cause) { showError(cause); } finally { loading.value = false; } }
function openSensor(link: RemoteSensorLink) { openHistory(link.sensor); }
onMounted(initialize);
onUnmounted(() => { disposed = true; entitySequence++; });
const providerPath = props.provider === 'homebridge' ? '/integrations/homebridge' : '/integrations/home-assistant';
const linkQuery = useCollectionQuery({ paths: [providerPath], enabled: () => props.canManage, prefix: 'links.', fields: { q: queryField.text(linkSearch, 160), state: queryField.choice(linkState, ['enabled', 'disabled']) }, page: linkPage, pageSize, paused: () => busy.value, load: target => paginateLinks(target) });
const entityQuery = useCollectionQuery({ paths: [providerPath], enabled: () => props.canManage && props.canDiscover !== false, prefix: 'discovery.', fields: { q: queryField.text(entitySearch), open: queryField.boolean(entitiesOpen) }, page: entityPage, pageSize: entityPageSize, load: target => { if (entitiesOpen.value && configured.value) return discover(target); entitySequence++; loading.value = false; } });
const applyFilters = entityQuery.apply;



const selectionHeader = ref<HTMLElement | null>(null);
</script>
<template><Card class="measurements-card operational-page integration-page"><template #header><div class="card-heading-block"><div class="page-heading"><div class="heading"><PageTitle :icon="integrationIcon">{{ label('integration') }}</PageTitle><Button v-if="!embedded" :aria-label="label('back')" type="default" :disabled="busy" @click="emit('close')" :title="label('back')" class="list-action-button"><template #icon><AppIcon name="arrowLeft" /></template></Button></div><div v-if="canManage" ref="selectionHeader" class="page-actions"><ListActionButton v-if="canDiscover !== false" icon="plus" :label="label('browseEntities')" :disabled="busy || loading || !configured" @click="entitiesOpen = true" /><ListActionButton icon="refresh" :label="label('refresh')" :disabled="busy || loading" @click="initialize" /><CollectionViewToggle :preference-key="'source-links:' + provider" :disabled="busy" /></div></div><div class="card-subtitle">{{ label('description') }}</div></div></template>

  <template #default><Message v-if="error" type="error" closable role="alert">{{ t(error) }}</Message><Message v-if="notice" type="success" closable role="status">{{ t(notice) }}</Message>
   <section v-if="canManage"><IntegrationStatus :icon="integrationIcon" :status="loading ? 'loading' : connected ? 'connected' : configured ? 'configured' : error ? 'unknown' : 'missing'"><p v-if="connected" role="status">{{ label('connected') }}</p><p v-else-if="!configured && !loading">{{ label('configureHelp') }}</p>
     <template #actions><Button v-if="canDiscover !== false" type="default" :disabled="busy || loading || !configured" @click="check"><AppIcon name="check" /><span>{{ label('check') }}</span></Button></template></IntegrationStatus>
    <section v-if="sync" class="provider-sync-summary" :aria-label="t('measurements.sync.title')"><div class="provider-sync-heading"><h3><AppIcon name="refresh" />{{ t('measurements.sync.title') }}</h3><Tag :type="!sync.configurationValid ? 'warning' : sync.enabled ? 'success' : 'default'">{{ t(sync.configurationValid ? sync.enabled ? 'measurements.sync.enabled' : 'measurements.sync.paused' : 'measurements.sync.invalid') }}</Tag></div>
     <dl class="provider-sync-facts"><div><dt><AppIcon name="clock" />{{ t('measurements.sync.last') }}</dt><dd>{{ date(sync.lastSuccessAt) }}</dd></div>
      <div><dt><AppIcon name="clock" />{{ t('measurements.sync.next') }}</dt><dd>{{ sync.nextEligibleAt ? date(sync.nextEligibleAt) : t('measurements.sync.notScheduled') }}</dd></div></dl>
     <div class="provider-sync-footer"><span v-if="sync.enabled && sync.intervalSeconds"><AppIcon name="refresh" />{{ t('measurements.sync.interval', { count: sync.intervalSeconds / 60 }) }}</span><span v-if="sync.eventsEnabled"><AppIcon name="check" />{{ t('measurements.sync.events') }}</span></div>
     <p class="provider-sync-help">{{ t('measurements.sync.help') }}</p></section>
    <details v-remember-disclosure="'help'" class="operational-help"><DisclosureSummary icon="help">{{ t('integrationPages.help') }}</DisclosureSummary><p>{{ label('importHelp') }}</p><p>{{ label('manualHelp') }}</p></details>
    <FormDialog v-if="entitiesOpen" :visible="true" :title="label('entitySelector')" :close-label="label('closeSelector')" :busy="busy" :error="error ? t(error) : ''" :icon="integrationIcon" @update:visible="!$event && (entitiesOpen = false)">
     <section class="entity-picker">
      <p class="entity-picker-help">{{ label('selectionHelp') }}</p>
      <form class="dialog-form" @submit.prevent="applyFilters"><div class="field"><label :for="`${integrationIcon}-entity-search`">{{ label('search') }}</label><InputText v-model:value="entitySearch" maxlength="160" :disabled="busy" :input-props="{ id: `${integrationIcon}-entity-search` }"><template #prefix><AppIcon name="search" /></template></InputText></div></form>
      <p v-if="loading" class="entity-picker-empty" role="status"><AppIcon name="refresh" />{{ label('loading') }}</p>
      <div v-else-if="error" class="entity-picker-empty"><AppIcon name="warning" /><Button :disabled="busy" @click="discover(entityPage)"><template #icon><AppIcon name="refresh" /></template>{{ t('actions.refresh') }}</Button></div>
      <p v-else-if="!entities.length" class="entity-picker-empty"><AppIcon name="search" />{{ label('noMatches') }}</p>
      <ul v-else class="entity-picker-list"><li v-for="entity in entities" :key="entity.id" :class="{ 'is-selected': selected.includes(entity.id), 'is-disabled': busy || entity.imported || selected.length >= 10 && !selected.includes(entity.id) }">
       <label class="entity-picker-choice"><input type="checkbox" :aria-label="label('selectEntity', { name: entity.name })" :checked="selected.includes(entity.id)" :disabled="busy || entity.imported || selected.length >= 10 && !selected.includes(entity.id)" @change="selectEntity(entity.id)" />
        <span class="entity-picker-icon"><AppIcon :name="assetCategoryIcon(entity.category)" /></span>
        <span class="entity-picker-info"><strong>{{ entity.name }}</strong><span v-if="entity.manufacturer || entity.model" class="entity-picker-model">{{ [entity.manufacturer, entity.model].filter(Boolean).join(' · ') }}</span>
         <span class="entity-picker-badges"><Tag type="default">{{ t(`assets.categories.${entity.category}`) }}</Tag><Tag type="default">{{ label('channelCount', { count: entity.entityCount }) }}</Tag></span>
         <span class="entity-picker-channels"><span v-for="channel in entity.entities" :key="channel.id" :title="channel.id">{{ channel.name }}<small v-if="channel.unit"> · {{ channel.unit }}</small></span><span v-if="entity.entityCount > entity.entities.length">{{ label('otherChannels', { count: entity.entityCount - entity.entities.length }) }}</span></span>
         <span class="entity-picker-badges"><Tag v-if="!entity.imported && entity.importedCount" type="info">{{ label('partiallyImported', { count: entity.importedCount, total: entity.entityCount }) }}</Tag><Tag :type="entity.imported ? 'info' : entity.available ? 'success' : 'warning'" :title="!entity.available && !entity.imported ? label('unavailable') : undefined"><template #icon><AppIcon :name="entity.available || entity.imported ? 'check' : 'warning'" /></template>{{ label(entity.imported ? 'imported' : entity.available ? 'entityAvailable' : 'entityUnavailable') }}</Tag></span>
        </span>
       </label>
      </li></ul>
      <CollectionPagination v-model:page-size="entityPageSize" :page="entityPage" :last-page="entityLast" :total="entityTotal" :disabled="busy || loading" :previous-label="label('previous')" :next-label="label('next')" @page="entityQuery.go($event)" />
     </section>
     <template #footer><div class="entity-picker-footer"><span class="entity-picker-count" role="status"><AppIcon name="check" />{{ label('selected', { count: selected.length }) }}</span><Button :aria-label="label('importSelected')" :disabled="!selected.length || busy" :loading="importing" @click="importEntities" type="primary"><template #icon><AppIcon name="download" /></template>{{ label('importShort') }}</Button></div></template>
    </FormDialog>
    <Message v-if="importResult" type="success" closable role="status">{{ label('importResult', { devices: importResult.devicesCreated, sensors: importResult.sensorsCreated, existing: importResult.alreadyImported }) }}</Message>
    <div class="section-heading"><h3>{{ label('links') }}</h3></div>
    <IntegrationChannelFilters v-model:search="linkSearch" v-model:state="linkState" :disabled="busy" />
    <p v-if="loading" class="operational-empty" role="status">{{ label('loading') }}</p>
    <div v-else-if="!links.length" class="resource-empty"><AppIcon :name="integrationIcon" /><p>{{ linkSearch.trim() || linkState ? t('integrationPages.noMatchingChannels') : label('noLinks') }}</p></div>
    <CollectionTable :selection-target="selectionHeader" static-grid :preference-key="'source-links:' + provider" grid-class="link-list operational-list" :rows="links" :columns="[{ key: 'name', label: t('collection.name') }, { key: 'entity', label: t('recordDetails.reference') }, { key: 'date', label: label('lastSuccess') }, { key: 'actions', label: t('collection.actions') }]" :remove="integration.remove" :can-select="() => canManage" :row-name="row => row.sensor.name" :loading="loading || busy" @removed="paginateLinks(1)" @access-lost="showError">
<template #cards="{ selectionFor }"><ResourceCard :selection="selectionFor(link)" v-for="link in links" :key="link.id" :icon="integrationIcon" :eyebrow="link.sensor.device.name" :tone="link.lastError ? 'warning' : link.isEnabled ? 'success' : 'muted'"><template #title><button type="button" :aria-label="label('viewNamed', { name: link.sensor.name })" :disabled="busy" @click="openSensor(link)">{{ link.sensor.name }}</button></template><template #badge><Tag :type="link.lastError ? 'warning' : link.isEnabled ? 'success' : 'default'">{{ t(link.isEnabled ? 'integrationPages.active' : 'integrationPages.paused') }}</Tag></template>
     <p class="card-reference" :title="link.entityId">{{ link.entityId }}</p>
     <dl class="resource-facts"><div class="resource-wide"><dt><AppIcon name="clock" />{{ label('lastSuccess') }}</dt><dd>{{ date(link.lastSuccessAt) }}</dd></div><div v-if="link.nextSyncAt" class="resource-wide"><dt><AppIcon name="clock" />{{ t('measurements.sync.next') }}</dt><dd>{{ date(link.nextSyncAt) }}</dd></div></dl>
     <p v-if="link.lastError" class="operational-warning">{{ t(key(link.lastError)) }}</p>
     <template #actions><ListActionButton icon="binary" :label="t(link.isEnabled ? 'measurements.pause' : 'measurements.resume')" :disabled="busy" @click="toggle(link)" /><ListActionButton icon="refresh" :label="label('collectNamed', { name: link.sensor.name })" :disabled="busy || !link.isEnabled || !configured" @click="collect(link)" /></template><template #management><ListActionButton icon="trash" :label="label('removeNamed', { name: link.sensor.name })" severity="danger" :disabled="busy" @click="error = ''; removing = link" /></template></ResourceCard>
</template>
 <template #name="{ row }"><button type="button" class="collection-table-tag" :aria-label="label('viewNamed', { name: row.sensor.name })" @click="openSensor(row)">{{ row.sensor.name }}</button> · {{ row.sensor.device.name }} <Tag :type="row.lastError ? 'warning' : row.isEnabled ? 'success' : 'default'">{{ t(row.isEnabled ? 'integrationPages.active' : 'integrationPages.paused') }}</Tag></template>
 <template #entity="{ row }"><code>{{ row.entityId }}</code></template>
 <template #date="{ row }">{{ date(row.lastSuccessAt) }}</template>
 <template #actions="{ row }"><div class="collection-table-actions"><ListActionButton icon="binary" :label="t(row.isEnabled ? 'measurements.pause' : 'measurements.resume')" :disabled="busy" @click="toggle(row)" /><ListActionButton icon="refresh" :label="label('collectNamed', { name: row.sensor.name })" :disabled="busy || !row.isEnabled || !configured" @click="collect(row)" /><ListActionButton icon="trash" :label="label('removeNamed', { name: row.sensor.name })" severity="danger" :disabled="busy" @click="error = ''; removing = row" /></div></template></CollectionTable>
    <CollectionPagination v-model:page-size="pageSize" :page="linkPage" :last-page="linkLast" :total="linkTotal" :disabled="busy || loading" :label="label('linkPagination')" :previous-label="label('previous')" :next-label="label('next')" @page="linkQuery.go($event)" /></section>
   <DetailDialog v-if="removing" :title="label('removeNamed', { name: removing.sensor.name })" :close-label="label('closeRemoval')" :busy="busy" :error="error ? t(error) : ''" icon="trash" @close="removing = null"><p>{{ label('removeHelp') }}</p>
    <div class="actions"><Button :aria-label="label('cancel')" type="default" :disabled="busy" @click="removing = null"><template #icon><AppIcon name="close" /></template><span>{{ t('actions.cancel') }}</span></Button><Button type="error" :loading="busy" :disabled="busy" @click="removeLink"><template #icon><AppIcon name="trash" /></template><span>{{ label('remove') }}</span></Button></div></DetailDialog>
   <SensorHistory v-if="sensor" :sensor-id="sensor.id" :name="sensor.name" @close="closeHistory" @access-lost="showError" /></template></Card></template>
<style scoped>
.actions { display: flex; flex-wrap: wrap; gap: var(--space-3); }
.entity-picker { display: grid; gap: var(--ui-content-gap); min-width: 0; }
.entity-picker-help { margin: 0; color: var(--theme-muted); font-size: .78rem; line-height: 1.5; }
.entity-picker-list { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 20rem), 1fr)); gap: var(--ui-grid-gap); list-style: none; padding: 0; margin: 0; min-width: 0; }
.entity-picker-list > li { min-width: 0; border: 1px solid var(--theme-border); border-radius: .7rem; background: var(--theme-surface); }
.entity-picker-list > li.is-selected { border-color: var(--theme-accent); background: var(--theme-subtle); }
.entity-picker-choice { display: flex; align-items: flex-start; gap: var(--ui-control-gap); padding: var(--ui-card-inset); min-width: 0; cursor: pointer; }
.entity-picker-choice > input { width: 1rem; height: 1rem; flex: 0 0 auto; margin: .25rem 0 0; accent-color: var(--theme-accent); }
.entity-picker-choice:has(input:focus-visible) { outline: 2px solid var(--theme-focus); outline-offset: 2px; border-radius: .7rem; }
.entity-picker-list > li.is-disabled .entity-picker-choice { cursor: default; }
.entity-picker-icon { display: grid; place-items: center; width: 1.75rem; height: 1.75rem; flex: 0 0 auto; border-radius: .5rem; color: var(--theme-muted); background: var(--theme-subtle); }
.entity-picker-icon > svg { width: 1rem; height: 1rem; }
.entity-picker-info { display: grid; gap: var(--space-1-5); min-width: 0; }
.entity-picker-info > strong { font-size: .82rem; font-weight: 600; color: var(--theme-text); line-height: 1.45; overflow-wrap: anywhere; }
.entity-picker-model, .entity-picker-channels { color: var(--theme-muted); font-size: .72rem; line-height: 1.5; overflow-wrap: anywhere; }.entity-picker-channels { display: grid; gap: var(--space-1); }.entity-picker-channels small { font-size: inherit; }
.entity-picker-badges { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-1); }
.entity-picker-empty { display: flex; align-items: center; justify-content: center; gap: var(--ui-control-gap); margin: 0; padding: var(--ui-card-inset); color: var(--theme-muted); font-size: .8rem; line-height: 1.5; }
.entity-picker-empty > svg { width: 1rem; height: 1rem; flex: 0 0 auto; }
.entity-picker-footer { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: var(--ui-control-gap); width: 100%; }
.entity-picker-count { display: inline-flex; align-items: center; gap: var(--ui-icon-gap); color: var(--theme-muted); font-size: .76rem; }
.entity-picker-count > svg { width: 1rem; height: 1rem; }
.entity-picker-footer > .n-button { margin-inline-start: auto; }
</style>
