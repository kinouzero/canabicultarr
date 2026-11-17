<script setup lang="ts">
import { computed } from 'vue';
import { RouterLink } from 'vue-router';
import AppIcon from './AppIcon.vue';
import { locationIcons } from '../lib/location';
import type { LocationContext } from '../api/types';
import { panelPaths } from '../router';
const props = defineProps<{ location: LocationContext | null | undefined }>();
const nodes = computed(() => (['site', 'room', 'zone'] as const).flatMap(kind => {
 const node = props.location?.[kind];
 return node ? [{ ...node, kind }] : [];
}));
</script>
<template><span v-if="nodes.length" class="location-trail"><template v-for="(node, index) in nodes" :key="`${node.kind}:${node.id}`"><AppIcon v-if="index" class="location-trail-separator" name="chevron" /><RouterLink class="location-trail-link" :to="{ path: panelPaths.locations, query: { location: `${node.kind}:${node.id}` } }" @click.stop><AppIcon :name="locationIcons[node.kind]" /><span>{{ node.name }}</span></RouterLink></template></span></template>
<style scoped>
.location-trail { display: inline-flex; align-items: center; flex-wrap: wrap; gap: var(--space-1); min-width: 0; max-width: 100%; line-height: 1.5; color: inherit; }
.location-trail .location-trail-link { display: inline-flex; align-items: center; gap: var(--space-1); min-width: 0; max-width: 100%; color: inherit; text-decoration: none; }
.location-trail-link > span { min-width: 0; overflow-wrap: anywhere; }
.location-trail-link:hover { color: var(--theme-accent); text-decoration: underline; text-underline-offset: .2em; }
.location-trail-link:focus-visible { outline: 2px solid var(--theme-focus); outline-offset: 2px; border-radius: .2rem; }
.location-trail .location-trail-link > svg { width: .9rem; height: .9rem; flex: 0 0 auto; margin: 0; }
.location-trail .location-trail-separator { width: .65rem; height: .65rem; flex: 0 0 auto; transform: rotate(-90deg); margin: 0; color: var(--theme-muted); }
</style>
