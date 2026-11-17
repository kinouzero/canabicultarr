<script setup lang="ts">
import { onMounted, onUnmounted, ref, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import { documentsApi } from '../api/documents';
const model = defineModel<string>({ default: '' });
const props = defineProps<{ disabled?: boolean; id?: string; invalid?: boolean; describedBy?: string }>();
const { t, mergeLocaleMessage } = useI18n({ useScope: 'global' });
mergeLocaleMessage('en', { documentStorage: { label: 'Storage', default: 'Default storage', documents: 'Local storage', documents_s3: 'RustFS / S3' } });
mergeLocaleMessage('fr', { documentStorage: { label: 'Stockage', default: 'Stockage par défaut', documents: 'Stockage local', documents_s3: 'RustFS / S3' } });
const generatedId = useId(); let alive = true;
onUnmounted(() => { alive = false; });
const disks = ref(['documents']);
onMounted(async () => {
 try { const result = await documentsApi.storageOptions(); if (alive) disks.value = result.disks; }
 catch { /* The server still validates the default storage when options are unavailable. */ }
});
</script>
<template>
 <div class="field"><label :for="props.id || generatedId">{{ t('documentStorage.label') }}</label><select :id="props.id || generatedId" :aria-invalid="!!invalid" :aria-describedby="describedBy" v-model="model" v-app-select="{ filter: t('selectControl.filter'), empty: t('selectControl.empty') }" :disabled="disabled"><option value="">{{ t('documentStorage.default') }}</option><option v-for="disk in disks" :key="disk" :value="disk">{{ t(`documentStorage.${disk}`) }}</option></select></div>
</template>
