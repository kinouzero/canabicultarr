<script setup lang="ts">
import PhotoPlaceholderMark from './PhotoPlaceholderMark.vue';
import { computed, ref, watch } from 'vue';
import { NButton as Button } from 'naive-ui';
import AppIcon from './AppIcon.vue';
import { photoPreviewUrl } from '../lib/photo-urls';
import { useI18n } from 'vue-i18n';
import DetailDialog from './DetailDialog.vue';
const props = defineProps<{ url: string; name: string }>();
const broken = ref(false);
const original = ref(false);
const displayUrl = computed(() => original.value ? props.url : photoPreviewUrl(props.url));
watch(() => props.url, () => { broken.value = false; original.value = false; });
const emit = defineEmits<{ close: [] }>();
const { t } = useI18n();
</script>
<template><DetailDialog icon="expand" :title="name" :close-label="t('recordDetails.closePhoto')" @close="emit('close')"><img v-if="!broken" class="preview-photo" :src="displayUrl" :alt="name" @error="broken = true" /><div v-else class="preview-empty"><PhotoPlaceholderMark /><p>{{ t('recordDetails.photoUnavailable') }}</p></div>
  <Button v-if="!original && photoPreviewUrl(url) !== url" type="default" class="original-photo" @click="original = true; broken = false"><AppIcon name="expand" /><span>{{ t('recordDetails.originalPhoto') }}</span></Button></DetailDialog></template>
<style scoped>
.preview-empty { padding: 3rem; text-align: center; color: var(--theme-muted, #718363); }
.preview-photo { display: block; width: 100%; max-height: 72dvh; object-fit: contain; border-radius: .8rem; background: var(--theme-subtle, #f1f4ee); }
.original-photo { margin-top: var(--ui-content-gap); }
</style>
