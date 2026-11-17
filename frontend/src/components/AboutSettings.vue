<script setup lang="ts">
import Message from './AppMessage.vue';
import { computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { NButton, NTag } from 'naive-ui';
import AppIcon from './AppIcon.vue';
import BrandMark from './BrandMark.vue';
import { version } from '../../package.json';
import { applicationInfo } from '../lib/application-info';
import { ApiError } from '../api/auth';
import { formatDateTime } from '../lib/date-time';
import en from '../locales/en/about.json';
import fr from '../locales/fr/about.json';

const { t, locale, mergeLocaleMessage } = useI18n();
mergeLocaleMessage('en', { about: en }); mergeLocaleMessage('fr', { about: fr });
const emit = defineEmits<{ accessLost: [cause: ApiError] }>();
const { data, busy, error } = applicationInfo;
const repository = 'https://github.com/kinouzero/canabicultarr';
const status = computed(() => data.value?.update.status ?? 'unavailable');
const technologies = [
 { name: 'Vue', icon: 'layoutGrid', url: 'https://vuejs.org/', category: 'interface' },
 { name: 'TypeScript', icon: 'file', url: 'https://www.typescriptlang.org/', category: 'language' },
 { name: 'Naive UI', icon: 'palette', url: 'https://www.naiveui.com/', category: 'components' },
 { name: 'Vite', icon: 'power', url: 'https://vite.dev/', category: 'build' },
 { name: 'Chart.js', icon: 'chart', url: 'https://www.chartjs.org/', category: 'charts' },
 { name: 'Lucide', icon: 'leaf', url: 'https://lucide.dev/', category: 'icons' },
 { name: 'Laravel', icon: 'equipment', url: 'https://laravel.com/', category: 'backend' },
 { name: 'PHP', icon: 'file', url: 'https://www.php.net/', category: 'language' },
 { name: 'PostgreSQL · TimescaleDB', icon: 'database', url: 'https://www.timescale.com/', category: 'database' },
 { name: 'Redis', icon: 'power', url: 'https://redis.io/', category: 'cache' },
 { name: 'S3 · RustFS', icon: 'folder', url: 'https://rustfs.com/', category: 'storage' },
 { name: 'Docker Compose', icon: 'box', url: 'https://docs.docker.com/compose/', category: 'deployment' },
];
async function reload() {
 await applicationInfo.load(true);
 const cause = error.value;
 if (cause instanceof ApiError && [401, 403].includes(cause.status)) emit('accessLost', cause);
}
onMounted(() => applicationInfo.load());
defineExpose({ reload, busy });
</script>

<template>
 <section class="about-settings" :aria-label="t('about.title')" :aria-busy="busy">
  <header class="about-heading"><div class="about-product"><BrandMark /><div><h2>Canabicultarr</h2><p>{{ t('about.description') }}</p></div></div>
   <NButton tag="a" :href="repository" target="_blank" rel="noopener noreferrer"><template #icon><AppIcon name="github" /></template>GitHub</NButton>
  </header>
  <div class="about-versions"><div><span>{{ t('about.installed') }}</span><strong>{{ data?.version ?? version }}</strong></div><div><span>{{ t('about.latest') }}</span><strong>{{ data?.update.latestVersion ?? '—' }}</strong></div></div>
  <Message v-if="error" type="error" closable role="alert">{{ t('about.failed') }}</Message>
  <section class="about-update" :aria-label="t('about.updates')">
   <div class="about-update-title"><h3><AppIcon name="download" />{{ t('about.updates') }}</h3><NTag v-if="data && !error" :type="status === 'available' ? 'warning' : status === 'current' ? 'success' : 'default'">{{ t(`about.status.${status}`) }}</NTag></div>
   <p v-if="busy" role="status">{{ t('about.checking') }}</p>
   <template v-else-if="data && !error"><p>{{ t(`about.details.${status}`) }}</p><small>{{ t('about.checked', { date: formatDateTime(data.update.checkedAt, locale) }) }}</small>
    <NButton v-if="data.update.releaseUrl" tag="a" :href="data.update.releaseUrl" target="_blank" rel="noopener noreferrer"><template #icon><AppIcon name="externalLink" /></template>{{ t('about.release') }}</NButton>
    <NTag v-if="data.update.prerelease" type="warning">{{ t('about.prerelease') }}</NTag>
   </template><small>{{ t('about.cacheHelp') }}</small>
  </section>
  <section class="about-stack"><h3><AppIcon name="equipment" />{{ t('about.technologies') }}</h3><ul><li v-for="technology in technologies" :key="technology.name"><AppIcon :name="technology.icon" /><div><a :href="technology.url" target="_blank" rel="noopener noreferrer">{{ technology.name }}<AppIcon name="externalLink" /></a><small>{{ t(`about.categories.${technology.category}`) }}<template v-if="technology.name === 'PHP' && data"> · {{ data.runtime.php }}</template><template v-if="technology.name === 'Laravel' && data"> · {{ data.runtime.laravel }}</template></small></div></li></ul></section>
 </section>
</template>

<style scoped>
.about-settings { display: grid; gap: var(--ui-section-gap); }
.about-heading, .about-product, .about-update-title { display: flex; align-items: center; gap: var(--ui-content-gap); min-width: 0; }
.about-heading { justify-content: space-between; flex-wrap: wrap; }
.about-product { flex: 1; }
.about-settings h2, .about-settings h3, .about-settings p { margin: 0; }
.about-settings h2 { font-size: 1.2rem; }
.about-settings h3 { display: flex; align-items: center; gap: var(--ui-icon-gap); font-size: .95rem; }
.about-settings h3 svg { width: 1.1rem; height: 1.1rem; }
.about-settings p, .about-settings small { color: var(--theme-muted); }
.about-settings p { font-size: .82rem; }
.about-settings small { display: block; font-size: .74rem; }
.about-versions { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--ui-grid-gap); }
.about-versions > div { display: grid; gap: var(--ui-field-gap); padding: var(--ui-card-inset); border-radius: .65rem; background: var(--theme-subtle); }
.about-versions span { font-size: .76rem; color: var(--theme-muted); }
.about-versions strong { overflow-wrap: anywhere; }
.about-update, .about-stack { display: grid; gap: var(--ui-content-gap); }
.about-update-title { flex-wrap: wrap; }
.about-update > .n-button, .about-update > .n-tag { justify-self: start; }
.about-stack ul { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 16rem), 1fr)); gap: var(--ui-grid-gap); padding: 0; margin: 0; list-style: none; }
.about-stack li { display: flex; align-items: center; gap: var(--ui-control-gap); min-width: 0; padding-block: var(--space-2); }
.about-stack li > svg { width: 1.25rem; height: 1.25rem; flex-shrink: 0; color: var(--theme-muted); }
.about-stack a { display: inline-flex; align-items: center; gap: var(--ui-icon-gap); color: var(--theme-text); text-decoration: none; font-weight: 600; font-size: .84rem; }
.about-stack a:hover { text-decoration: underline; }
.about-stack a svg { width: .8rem; height: .8rem; flex-shrink: 0; }
</style>
