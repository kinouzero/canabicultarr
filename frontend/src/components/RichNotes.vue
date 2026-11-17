<script setup lang="ts">
import { computed } from 'vue';
import { renderNotes } from '../lib/notes';
import '../styles/notes.css';
const props = defineProps<{ value: string }>();
const html = computed(() => renderNotes(props.value));
</script>
<template><div class="rich-notes" v-html="html" /></template>
