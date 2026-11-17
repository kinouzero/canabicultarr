<script setup lang="ts">
import { defineAsyncComponent, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { registerModuleMessages } from '../lib/locale-messages';
import en from '../locales/en/qr-labels.json';
import fr from '../locales/fr/qr-labels.json';
import ListActionButton from './ListActionButton.vue';
import type { QrRecord } from '../lib/record-link';
defineProps<{ record: QrRecord; disabled?: boolean }>();
const { t, mergeLocaleMessage } = useI18n();
registerModuleMessages(mergeLocaleMessage, { en: { qr: en }, fr: { qr: fr } }); const opened = ref(false);
const RecordQrDialog = defineAsyncComponent(() => import('./RecordQrDialog.vue'));
</script>
<template>
 <ListActionButton icon="qrcode" :label="t('qr.open')" :disabled="disabled" @click.stop="opened = true" />
 <RecordQrDialog v-if="opened" :record="record" @close="opened = false" />
</template>
