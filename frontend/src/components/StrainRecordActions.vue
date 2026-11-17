<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import type { ApiError } from '../api/auth';
import type { Strain } from '../api/strains';
import { photosApi } from '../api/photos';
import RecordPhotoActions from './RecordPhotoActions.vue';
import ListActionButton from './ListActionButton.vue';
withDefaults(defineProps<{ strain: Strain; disabled?: boolean; error?: string; showPhotos?: boolean; uploadFile: (file: File, disk?: string) => Promise<boolean> }>(), { showPhotos: true });
const emit = defineEmits<{ photo: [id: string | null]; edit: []; remove: []; failure: [cause: ApiError] }>();
const { t } = useI18n();
</script>
<template>
 <template v-if="strain.canManage">
  <RecordPhotoActions v-if="showPhotos !== false" :disabled="disabled" :has-photo="false" :fetch-photos="photosApi.library" :upload-file="uploadFile" :upload-error="error" @select="emit('photo', $event)" @access-lost="emit('failure', $event)" />
  <ListActionButton icon="edit" :label="t('catalog.edit', { name: strain.name })" :disabled="disabled" @click.stop="emit('edit')" />
  <ListActionButton icon="trash" :label="t('catalog.delete', { name: strain.name })" :disabled="disabled" @click.stop="emit('remove')" />
 </template>
</template>
