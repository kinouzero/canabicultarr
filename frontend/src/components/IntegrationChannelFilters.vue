<script setup lang="ts">
import { NInput } from 'naive-ui';
import { computed, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import AppIcon from './AppIcon.vue';
import ResetFilters from './ResetFilters.vue';

defineProps<{ disabled?: boolean; maxLength?: number }>();
const search = defineModel<string>('search', { required: true });
const state = defineModel<string>('state', { required: true });
const { t } = useI18n();
const id = useId();
const filtered = computed(() => Boolean(search.value || state.value));

function reset() {
 search.value = '';
 state.value = '';
}
</script>

<template>
 <form class="filters search-filters" role="search" :aria-label="t('integrationPages.searchChannels')" @submit.prevent>
  <div class="filter-primary">
   <div class="field filter-field">
    <label :for="`${id}-search`">{{ t('integrationPages.searchChannels') }}</label>
    <NInput v-model:value="search" clearable :maxlength="maxLength ?? 160" :disabled="disabled" :input-props="{ id: `${id}-search` }">
     <template #prefix><AppIcon name="search" /></template>
    </NInput>
   </div>
   <div class="field filter-field">
    <label :for="`${id}-state`">{{ t('integrationPages.channelState') }}</label>
    <select :id="`${id}-state`" v-model="state" v-app-select="{ filter: t('selectControl.filter'), empty: t('selectControl.empty'), searchable: false }" :disabled="disabled">
     <option value="">{{ t('integrationPages.allChannels') }}</option>
     <option value="enabled">{{ t('integrationPages.enabledChannels') }}</option>
     <option value="disabled">{{ t('integrationPages.disabledChannels') }}</option>
    </select>
   </div>
  </div>
  <ResetFilters v-if="filtered" :disabled="disabled" @reset="reset" />
 </form>
</template>
