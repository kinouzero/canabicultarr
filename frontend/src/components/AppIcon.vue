<script setup lang="ts">
import { Icon } from '@iconify/vue/offline';
import { appIcons, appIconNames } from '../lib/app-icons';
defineProps<{ name: string }>();
</script>
<template><Icon :icon="appIcons[name] ?? appIcons.folder!" :data-icon="appIconNames[name] ?? appIconNames.folder" aria-hidden="true" focusable="false" /></template>
