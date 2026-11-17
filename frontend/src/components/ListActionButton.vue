<script setup lang="ts">
import { NButton as Button } from 'naive-ui';
import AppIcon from './AppIcon.vue';
defineOptions({ inheritAttrs: false });
withDefaults(defineProps<{ label: string; icon: string; loading?: boolean; type?: 'button' | 'submit' | 'reset' }>(), { loading: false, type: 'button' });
</script>
<template><Button :type="icon === 'trash' ? 'error' : 'default'" v-bind="$attrs" :attr-type="type" class="list-action-button" :data-action-icon="icon" :aria-label="label" :title="label" :loading="loading"><template #icon><AppIcon :name="icon" /></template></Button></template>
