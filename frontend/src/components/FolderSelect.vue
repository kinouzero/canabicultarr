<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useFolderBrowser } from '../lib/folder-browser';
import { folderAncestors } from '../lib/document-folders';
import ListActionButton from './ListActionButton.vue';
defineOptions({ inheritAttrs: false });
const props = defineProps<{ id: string; disabled?: boolean; exclude?: string }>();
const model = defineModel<string>({ required: true });
const { t } = useI18n();
const browser = useFolderBrowser();
const loading = ref(false); const error = ref(false); let sequence = 0;
const path = computed(() => folderAncestors(model.value || null, browser.folders));
const label = (id: string) => folderAncestors(id, browser.folders).map(folder => folder.name).join(' / ');
const page = computed(() => browser.pages.get(model.value || 'root'));
const options = computed(() => [...new Map([
 ...path.value, ...(page.value?.data ?? []),
].filter(folder => folder.id !== props.exclude).map(folder => [folder.id, folder])).values()]);
async function load(nextPage = 1) {
 const current = ++sequence; loading.value = true; error.value = false;
 try {
  if (model.value) await browser.path(model.value);
  if (current !== sequence) return;
  await browser.children(model.value || null, nextPage);
 } catch (cause) { if (current === sequence && !(cause instanceof DOMException && cause.name === 'AbortError')) error.value = true; }
 finally { if (current === sequence) loading.value = false; }
}
let pendingLoad: Promise<void> | null = null;
watch(model, () => { pendingLoad = load(); }, { immediate: true });
async function focus() { await nextTick(); await pendingLoad; await nextTick(); document.getElementById(props.id)?.focus(); }
defineExpose({ focus });
</script>
<template>
 <select v-bind="$attrs" :id="id" v-model="model" v-app-select="{ filter: t('selectControl.filter'), empty: t('selectControl.empty') }" :disabled="disabled || loading">
  <option value="">{{ t('documents.root') }}</option>
  <option v-for="folder in options" :key="folder.id" :value="folder.id">{{ label(folder.id) }}</option>
 </select>
 <span v-if="loading" role="status">{{ t('documents.loadingFolders') }}</span>
 <span v-if="error" role="alert">{{ t('auth.errors.requestFailed') }} <ListActionButton icon="refresh" :label="t('actions.refresh')" @click="load()" /></span>
 <div v-if="page && page.meta.last_page > 1" class="folder-select-pages">
  <ListActionButton icon="arrowLeft" :label="t('collection.previous')" :disabled="disabled || loading || page.meta.current_page <= 1" @click="load(page.meta.current_page - 1)" />
  <span>{{ t('collection.page', { current: page.meta.current_page, total: page.meta.last_page }) }}</span>
  <ListActionButton icon="arrow" :label="t('collection.next')" :disabled="disabled || loading || page.meta.current_page >= page.meta.last_page" @click="load(page.meta.current_page + 1)" />
 </div>
</template>
<style scoped>
.folder-select-pages { display: flex; align-items: center; gap: var(--space-2); font-size: .75rem; color: var(--theme-muted); }
</style>
