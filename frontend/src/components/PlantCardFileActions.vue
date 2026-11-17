<script setup lang="ts">
import { useFeedback } from '../lib/feedback';
import { onUnmounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Message from './AppMessage.vue';
import RecordPhotoActions from './RecordPhotoActions.vue';
import ListActionButton from './ListActionButton.vue';
import ConfirmRecordDeletion from './ConfirmRecordDeletion.vue';
import { plantsApi, type Plant } from '../api/plants';
import { photosApi } from '../api/photos';
import { ApiError } from '../api/auth';
const props = withDefaults(defineProps<{ plantId: string; name: string; canManage: boolean; canDelete: boolean; disabled?: boolean; showPhotos?: boolean }>(), { showPhotos: true });
const emit = defineEmits<{ changed: []; accessLost: [cause: ApiError] }>();
const { t, te } = useI18n();
const feedback = useFeedback();
const busy = ref(false); const error = ref(''); const deleting = ref<Plant | null>(null);
let alive = true;
onUnmounted(() => { alive = false; });
function failure(cause: unknown) {
 const code = cause instanceof ApiError ? cause.code : '';
 error.value = /^(plants|documents|auth)\.(errors|validation)\.[A-Za-z]+$/.test(code) && te(code) ? code : 'auth.errors.requestFailed';
 if (cause instanceof ApiError && [401, 403].includes(cause.status)) emit('accessLost', cause);
}
async function photo(id: string | null, file?: File, disk?: string) {
 if (busy.value || props.disabled) return false;
 busy.value = true; error.value = '';
 try {
  const plant = await plantsApi.get(props.plantId); if (!alive) return false;
  if (!plant.canManage) throw new Error('Plant cannot be edited');
  if (file) { const document = await photosApi.upload(file, plant.name, plant.location, disk); if (!alive) return false; id = document.id; }
  await plantsApi.photo(plant, id); if (alive) { feedback.success(t('plants.photoSaved')); emit('changed'); } return alive;
 } catch (cause) { if (alive) failure(cause); return false; }
 finally { busy.value = false; }
}
function upload(file: File, disk?: string): Promise<boolean> { return photo(null, file, disk); }

async function confirmDeletion() {
 if (busy.value || props.disabled) return;
 busy.value = true; error.value = '';
 try { const plant = await plantsApi.get(props.plantId); if (alive && plant.canDelete) deleting.value = plant; }
 catch (cause) { if (alive) failure(cause); }
 finally { busy.value = false; }
}
async function remove() {
 if (!deleting.value || busy.value) return;
 busy.value = true; error.value = '';
 try { await plantsApi.remove(deleting.value); if (alive) { deleting.value = null; feedback.success(t('plants.deleted')); emit('changed'); } }
 catch (cause) { if (alive) failure(cause); }
 finally { busy.value = false; }
}
</script>
<template><RecordPhotoActions v-if="canManage && showPhotos !== false" :has-photo="false" :disabled="disabled || busy" :fetch-photos="photosApi.library" @select="photo" :upload-file="upload" :upload-error="error ? t(error) : ''" @access-lost="failure" />
 <slot />
 <ListActionButton v-if="canDelete" icon="trash" :label="t('plants.delete', { name })" :disabled="disabled || busy" @click.stop="confirmDeletion" />
 <ConfirmRecordDeletion v-if="deleting" :name="name" :message="t('plants.deleteHelp')" :busy="busy" :error="error ? t(error) : ''" @cancel="deleting = null; error = ''" @confirm="remove" />
 <Message v-if="error && !deleting" type="error" closable role="alert">{{ t(error) }}</Message></template>
