<script setup lang="ts">
import { inject } from 'vue';
import { useI18n } from 'vue-i18n';
import { strainRecordOpener } from '../lib/strain-record';
import { registerModuleMessages } from '../lib/locale-messages';
import en from '../locales/en/catalog.json';
import fr from '../locales/fr/catalog.json';
import AppIcon from './AppIcon.vue';
defineProps<{ strain: { id: string; name: string } }>();
const open = inject(strainRecordOpener);
const { t, mergeLocaleMessage } = useI18n();
registerModuleMessages(mergeLocaleMessage, { en: { catalog: en }, fr: { catalog: fr } });
</script>
<template><button type="button" class="strain-reference-tag" :title="strain.name" :aria-label="t('catalog.open', { name: strain.name })" @click.stop="open?.(strain.id, $event.currentTarget as HTMLButtonElement)"><AppIcon name="strain" /><span>{{ strain.name }}</span></button></template>
<style scoped>
.strain-reference-tag { font-family: inherit; cursor: pointer; text-decoration: none; }
.strain-reference-tag > span { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.strain-reference-tag svg { flex-shrink: 0; }
.strain-reference-tag:hover { filter: brightness(.94); text-decoration: none; }
.strain-reference-tag:focus-visible { outline: 2px solid var(--theme-focus); outline-offset: 2px; }
</style>
