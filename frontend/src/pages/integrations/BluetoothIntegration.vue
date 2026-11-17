<script setup lang="ts">
import { useCollectionView } from '../../lib/collection-view';
import IntegrationChannelFilters from '../../components/IntegrationChannelFilters.vue';
import { queryField, useCollectionQuery } from '../../lib/collection-query';
import CollectionTable from '../../components/CollectionTable.vue';
import CollectionViewToggle from '../../components/CollectionViewToggle.vue';
import { onMounted, onUnmounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { NButton, NSwitch } from 'naive-ui';
import PageTitle from '../../components/PageTitle.vue';
import AppIcon from '../../components/AppIcon.vue';
import ListActionButton from '../../components/ListActionButton.vue';
import CollectionPagination from '../../components/CollectionPagination.vue';
import Message from '../../components/AppMessage.vue';
import { bluetoothApi, type BluetoothRecord, type BluetoothCandidate, type BluetoothOperation, type BluetoothChannel } from '../../api/bluetooth';
import { ApiError } from '../../api/auth';
import { formatDateTime } from '../../lib/date-time';
import en from '../../locales/en/bluetooth.json';
import fr from '../../locales/fr/bluetooth.json';
const view = useCollectionView('bluetooth-devices');
const props = defineProps<{ canDiscover: boolean }>();
const emit = defineEmits<{ accessLost: [cause: ApiError] }>();
const { t, te, locale, mergeLocaleMessage } = useI18n({ useScope: 'global' });
mergeLocaleMessage('en', { bluetooth: en }); mergeLocaleMessage('fr', { bluetooth: fr });
const search = ref(''); const state = ref('');
const devices = ref<BluetoothRecord[]>([]); const candidates = ref<BluetoothCandidate[]>([]); const discoveryId = ref('');
const page = ref(1); const lastPage = ref(1); const total = ref(0);
const transport = ref('bluez'); const enabled = ref(false); const adapter = ref(''); const busy = ref(false); const error = ref(''); const notice = ref('');
const pending = ref<BluetoothOperation | null>(null);
let alive = true; let sequence = 0; let timer: ReturnType<typeof setTimeout> | undefined; let resume: (() => void) | undefined;
function failure(cause: unknown) {
 if (!alive) return;
 if (cause instanceof ApiError && cause.status === 404) { pending.value = null; error.value = 'bluetooth.errors.expired'; return; }
 error.value = cause instanceof ApiError && te(cause.code) ? cause.code : 'bluetooth.errors.failed';
 if (cause instanceof ApiError && [401, 403].includes(cause.status)) emit('accessLost', cause);
}
async function load(target = page.value) {
 const current = ++sequence;
 const result = await bluetoothApi.list(target, search.value.trim(), state.value);
 if (!alive || current !== sequence) return;
 devices.value = result.data; page.value = result.meta.current_page; lastPage.value = result.meta.last_page; total.value = result.meta.total;
}
async function run(work: () => Promise<void>) {
 if (busy.value) return;
 busy.value = true; error.value = ''; notice.value = '';
 try { await work(); } catch (cause) { failure(cause); } finally { if (alive) busy.value = false; }
}
async function follow(operation: BluetoothOperation) {
 pending.value = operation;
 const deadline = Date.now() + 125000;
 while (alive && operation.status === 'pending') {
  await new Promise<void>(resolve => { resume = resolve; timer = setTimeout(resolve, 1000); });
  resume = undefined;
  if (!alive) return;
  if (Date.now() > deadline) throw new ApiError(503, 'bluetooth.errors.timeout');
  operation = await bluetoothApi.operation(operation.id);
  if (!alive) return;
  pending.value = operation;
 }
 if (!alive) return;
 if (operation.status === 'failed') { pending.value = null; throw new ApiError(503, operation.error && te(operation.error) ? operation.error : 'bluetooth.errors.failed'); }
 pending.value = null;
 if (operation.kind === 'discover') { candidates.value = operation.data as BluetoothCandidate[]; discoveryId.value = operation.id; }
 else { notice.value = operation.kind === 'pair' ? 'bluetooth.paired' : 'bluetooth.saved'; await load(1); }
}
function discover() { void run(async () => { candidates.value = []; await follow(await bluetoothApi.discover(crypto.randomUUID())); }); }
function pair(candidate: BluetoothCandidate) { void run(async () => { await follow(await bluetoothApi.pair(discoveryId.value, candidate.id, crypto.randomUUID())); candidates.value = candidates.value.filter(item => item.id !== candidate.id); }); }
function read(device: BluetoothRecord) { void run(async () => { await follow(await bluetoothApi.read(device, crypto.randomUUID())); }); }
function retry() { if (pending.value) void run(async () => { await follow(await bluetoothApi.operation(pending.value!.id)); }); }
function toggle(channel: BluetoothChannel) { void run(async () => { await bluetoothApi.toggle(channel); await load(); }); }
function remove(device: BluetoothRecord) { void run(async () => { await bluetoothApi.remove(device); await load(1); }); }
async function refresh() {
 const status = await bluetoothApi.status(); if (!alive) return;
 transport.value = status.transport ?? 'bluez'; enabled.value = status.enabled; adapter.value = status.adapter; if (!status.operationId) pending.value = null; await load();
 if (status.operationId && alive) { try { await follow(await bluetoothApi.operation(status.operationId)); } catch (cause) { if (cause instanceof ApiError && cause.status === 404) pending.value = null; else throw cause; } }
}
onMounted(() => { if (props.canDiscover) void run(refresh); });
onUnmounted(() => { alive = false; clearTimeout(timer); resume?.(); });
const collectionQuery = useCollectionQuery({ paths: ['/integrations/bluetooth'], enabled: () => props.canDiscover, fields: { q: queryField.text(search), state: queryField.choice(state, ['enabled', 'disabled']) }, page, paused: () => busy.value, load: target => load(target).catch(failure) });
const selectionHeader = ref<HTMLElement | null>(null);
</script>
<template>
 <section class="bluetooth-integration">
  <div class="page-heading"><PageTitle icon="bluetooth">{{ t('bluetooth.title') }}</PageTitle><div ref="selectionHeader" class="page-actions"><CollectionViewToggle :preference-key="'bluetooth-devices'" :disabled="busy" v-if="canDiscover" /><ListActionButton icon="refresh" :label="t('bluetooth.refresh')" :disabled="busy || !canDiscover" @click="run(refresh)" /><ListActionButton icon="search" :label="t('bluetooth.discover')" :disabled="busy || !enabled || !canDiscover || !!pending" @click="discover" /></div></div>
  <p class="card-subtitle">{{ t('bluetooth.description') }}</p>
  <Message v-if="error" severity="error">{{ t(error) }}</Message><Message v-if="notice" severity="success">{{ t(notice) }}</Message>
  <p class="muted">{{ t('bluetooth.scope') }}</p><p v-if="canDiscover && enabled" class="muted">{{ transport === 'relay' ? t('bluetooth.relay') : t('bluetooth.adapter', { name: adapter }) }}</p>
  <p v-if="!canDiscover">{{ t('bluetooth.globalOnly') }}</p><Message v-else-if="!enabled && !busy" severity="info">{{ t('bluetooth.setup') }}</Message>
  <div v-if="pending" role="status" class="bluetooth-operation"><span>{{ t('bluetooth.pending') }}</span><NButton v-if="!busy" @click="retry"><template #icon><AppIcon name="refresh" /></template>{{ t('bluetooth.retry') }}</NButton></div>
  <section v-if="discoveryId" :aria-label="t('bluetooth.discovered')" class="bluetooth-candidates">
   <h2>{{ t('bluetooth.discovered') }}</h2><p v-if="!candidates.length">{{ t('bluetooth.noCandidates') }}</p>
   <div v-for="candidate in candidates" :key="candidate.id" class="bluetooth-channel"><strong>{{ candidate.name }}</strong><small v-if="candidate.rssi !== null">{{ candidate.rssi }} dBm</small><NButton :disabled="busy || !!pending" @click="pair(candidate)"><template #icon><AppIcon name="plus" /></template>{{ t('bluetooth.add') }}</NButton></div>
  </section>
  <IntegrationChannelFilters v-if="canDiscover" v-model:search="search" v-model:state="state" :disabled="busy" :max-length="120" />
  <template v-if="view === 'cards'"><article v-for="device in devices" :key="device.id" class="bluetooth-device">
   <header><h2>{{ device.name }}</h2><div class="bluetooth-actions"><ListActionButton icon="download" :label="t('bluetooth.readNamed', { name: device.name })" :disabled="busy || !!pending || !enabled || !device.channels.some(channel => channel.isEnabled)" @click="read(device)" /><RouterLink :to="{ path: '/hardware', query: { q: device.name } }">{{ t('bluetooth.hardware') }}</RouterLink><ListActionButton icon="unlink" :label="t('bluetooth.unpair')" :disabled="busy || !!pending" @click="remove(device)" /></div></header>
   <div v-for="channel in device.channels" :key="channel.id" class="bluetooth-channel"><RouterLink :to="{ path: '/measurements', query: { sensor: channel.sensorId } }">{{ channel.name }}</RouterLink><time v-if="channel.lastSeenAt" :datetime="channel.lastSeenAt">{{ formatDateTime(channel.lastSeenAt, locale) }}</time><NSwitch :value="channel.isEnabled" :disabled="busy || !!pending" :aria-label="t('bluetooth.channelEnabled', { name: channel.name })" @update:value="toggle(channel)" /></div>
  </article></template>
<CollectionTable :selection-target="selectionHeader" v-else :rows="devices" :columns="[{ key: 'name', label: t('collection.name') }, { key: 'channels', label: t('assets.channels') }, { key: 'actions', label: t('collection.actions') }]" :remove="bluetoothApi.remove" :can-select="() => canDiscover" :locked="!!pending" :row-name="row => row.name" :loading="busy" @removed="run(() => load(1))" @access-lost="failure">
 <template #name="{ row }"><RouterLink :to="{ path: '/hardware', query: { q: row.name } }">{{ row.name }}</RouterLink></template>
 <template #channels="{ row }"><div v-for="channel in row.channels" :key="channel.id" class="bluetooth-channel"><RouterLink :to="{ path: '/measurements', query: { sensor: channel.sensorId } }">{{ channel.name }}</RouterLink><NSwitch :value="channel.isEnabled" :aria-label="channel.name" :disabled="busy || !!pending" @update:value="toggle(channel)" /></div></template>
 <template #actions="{ row }"><ListActionButton icon="download" :label="t('bluetooth.readNamed', { name: row.name })" :disabled="busy || !!pending || !enabled || !row.channels.some(channel => channel.isEnabled)" @click="read(row)" /></template></CollectionTable>
  <p v-if="canDiscover && !devices.length && !busy">{{ t(search.trim() || state ? 'integrationPages.noMatchingChannels' : 'bluetooth.empty') }}</p>
  <CollectionPagination v-if="canDiscover" :page="page" :last-page="lastPage" :total="total" :disabled="busy" @page="collectionQuery.go($event)" />
 </section>
</template>
<style scoped>
.bluetooth-integration { display:grid; gap:var(--ui-content-gap); }.bluetooth-device,.bluetooth-candidates { padding:var(--ui-content-gap); border:1px solid var(--theme-border); border-radius:.75rem; min-width:0; }.bluetooth-device header,.bluetooth-channel,.bluetooth-actions,.bluetooth-operation { display:flex; align-items:center; gap:var(--ui-content-gap); flex-wrap:wrap; }.bluetooth-device h2,.bluetooth-candidates h2 { margin:0 auto 0 0; font-size:1rem; }.bluetooth-channel { padding-block:var(--space-3); }.bluetooth-channel>a,.bluetooth-channel>strong { margin-right:auto; }.bluetooth-channel time,.muted { color:var(--theme-muted); font-size:.875rem; }
</style>
