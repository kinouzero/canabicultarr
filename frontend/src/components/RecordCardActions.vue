<script setup lang="ts">
import '../styles/record-cards.css';
</script>
<template><footer class="record-card-actions"><slot /><div v-if="$slots.end" class="card-management-actions"><slot name="end" /></div></footer></template>
