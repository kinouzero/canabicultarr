<script setup lang="ts">
import { useCollectionView } from '../lib/collection-view';
import { masonry as vMasonry } from '../lib/masonry';
import '../styles/collection-layout.css';
const props = defineProps<{ preferenceKey: string; gridClass?: string; staticGrid?: boolean }>();
const mode = useCollectionView(() => props.preferenceKey);
</script>
<template>
 <section class="collection-layout">
  <ul v-if="mode === 'cards'" v-masonry="!staticGrid" :class="['collection-grid', gridClass]" data-view="cards"><slot /></ul>
  <div v-else class="collection-grid collection-table-view" data-view="rows"><slot name="table" /></div>
 </section>
</template>
