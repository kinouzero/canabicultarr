<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import AppIcon from './AppIcon.vue';
const props = defineProps<{ name?: string | null }>();
const failed = ref(false);
const source = computed(() => props.name && /^[a-z0-9][a-z0-9-]{0,99}$/.test(props.name)
 ? `https://cdn.jsdelivr.net/gh/selfhst/icons@main/webp/${props.name}.webp` : null);
watch(source, () => { failed.value = false; });
</script>
<template>
 <img v-if="source && !failed" class="oidc-provider-icon" :src="source" alt="" aria-hidden="true" referrerpolicy="no-referrer" @error="failed = true" />
 <AppIcon v-else name="key" class="oidc-provider-icon" />
</template>
<style scoped>
.oidc-provider-icon { width: 1.2rem; height: 1.2rem; flex: 0 0 1.2rem; object-fit: contain; vertical-align: middle; }
</style>
