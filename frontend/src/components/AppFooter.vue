<script setup lang="ts">
import { computed, onMounted, onUnmounted, watch } from 'vue';
import { NButton } from 'naive-ui';
import AppIcon from './AppIcon.vue';
import { applicationInfo, applicationVersion as version } from '../lib/application-info';
import { useI18n } from 'vue-i18n';
import BrandMark from './BrandMark.vue';

const props = defineProps<{ name: string; checkUpdates?: boolean }>();
const emit = defineEmits<{ openAbout: [] }>();
const update = computed(() => props.checkUpdates && applicationInfo.data.value?.update.status === 'available' ? applicationInfo.data.value.update : null);
let timer: ReturnType<typeof setInterval> | undefined;
function check() { if (props.checkUpdates && !document.hidden) void applicationInfo.load(); }
function schedule() { clearInterval(timer); timer = setInterval(check, applicationInfo.refreshInterval); }
watch(applicationInfo.data, schedule);
watch(() => props.checkUpdates, enabled => { if (enabled) check(); else applicationInfo.reset(); }, { immediate: true });
onMounted(() => { schedule(); document.addEventListener('visibilitychange', check); });
onUnmounted(() => { clearInterval(timer); document.removeEventListener('visibilitychange', check); applicationInfo.reset(); });
const { t } = useI18n();
</script>

<template>
 <footer class="app-footer"><div class="footer-content">
  <span class="footer-brand"><BrandMark /><span>{{ name }}</span></span>
  <span class="footer-version" aria-live="polite"><span>{{ t('footer.version', { version }) }}</span><NButton v-if="update" class="footer-update" text type="warning" :title="t('footer.updateHint', { version: update.latestVersion })" :aria-label="t('footer.updateHint', { version: update.latestVersion })" @click="emit('openAbout')"><template #icon><AppIcon name="download" /></template>{{ t('footer.updateAvailable') }}</NButton></span>
 </div></footer>
</template>

<style scoped>
.app-footer { width: 100%; color: var(--theme-muted); font-size: .72rem; }
.footer-content { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: var(--ui-content-gap); padding-block: var(--space-3); border-top: 1px solid var(--theme-border); }
.footer-brand { display: inline-flex; align-items: center; gap: var(--ui-icon-gap); min-width: 0; max-width: 100%; }
.footer-brand .brand-mark { width: 1.5rem; height: 1.9rem; padding: 0; border-radius: 0; background: transparent; }
.footer-brand span { overflow-wrap: anywhere; }
.footer-version { display: inline-flex; align-items: center; flex-wrap: wrap; gap: var(--ui-control-gap); }
.footer-update { font-size: inherit; }
</style>
