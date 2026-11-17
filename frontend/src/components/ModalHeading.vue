<script setup lang="ts">
import AppIcon from './AppIcon.vue';
defineProps<{ title: string; icon: string }>();
</script>
<template><div class="dialog-heading"><span class="dialog-heading-symbol"><AppIcon :name="icon" /></span><h3>{{ title }}</h3></div></template>
