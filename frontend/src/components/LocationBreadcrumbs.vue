<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { NBreadcrumb, NBreadcrumbItem } from 'naive-ui';
import { locationsApi, type LocationNode } from '../api/locations';
import type { ApiError } from '../api/auth';
import type { BreadcrumbPageLoader } from '../lib/breadcrumb-options';
import { locationIcons } from '../lib/location';
import AppIcon from './AppIcon.vue';
import BreadcrumbMenu from './BreadcrumbMenu.vue';
const props = defineProps<{ site: LocationNode | null; room: LocationNode | null; disabled?: boolean }>();
const emit = defineEmits<{ select: [key: string]; accessLost: [cause: ApiError] }>();
const { t } = useI18n();
function loader(path: string, kind: LocationNode['kind']): BreadcrumbPageLoader {
 return async (search, page) => {
  const result = await locationsApi.list(path, search, page, 'name_asc', 20);
  return { ...result, data: result.data.map(row => ({ id: `${kind}:${row.id}`, name: row.name, detail: row.code ?? '', icon: locationIcons[kind], disabled: kind === 'zone' && row.canSelect === false })) };
 };
}
const sites = loader('sites', 'site');
const rooms = computed(() => loader(`sites/${encodeURIComponent(props.site?.id ?? '')}/rooms`, 'room'));
const zones = computed(() => loader(`sites/${encodeURIComponent(props.site?.id ?? '')}/rooms/${encodeURIComponent(props.room?.id ?? '')}/zones`, 'zone'));
</script>
<template><NBreadcrumb class="app-breadcrumb location-breadcrumbs" :aria-label="t('locations.breadcrumbs')">
 <NBreadcrumbItem><span class="breadcrumb-segment"><button type="button" class="breadcrumb-link" :class="{ 'is-current': !site }" :disabled="!site || disabled" :aria-current="!site ? 'page' : undefined" @click="emit('select', 'sites')"><AppIcon name="building" /><span>{{ t('locations.sites') }}</span></button><BreadcrumbMenu :label="t('locations.chooseSite')" :fetch-page="sites" :value="site ? `site:${site.id}` : undefined" :disabled="disabled" @select="emit('select', $event)" @access-lost="emit('accessLost', $event)" /></span></NBreadcrumbItem>
 <NBreadcrumbItem v-if="site"><span class="breadcrumb-segment"><button type="button" class="breadcrumb-link" :class="{ 'is-current': !room }" :disabled="!room || disabled" :aria-current="!room ? 'page' : undefined" @click="emit('select', `site:${site!.id}`)"><AppIcon name="building" /><span>{{ site.name }}</span></button><BreadcrumbMenu :key="site.id" :label="t('locations.chooseRoom')" :fetch-page="rooms" :value="room ? `room:${room.id}` : undefined" :disabled="disabled" @select="emit('select', $event)" @access-lost="emit('accessLost', $event)" /></span></NBreadcrumbItem>
 <NBreadcrumbItem v-if="site && room"><span class="breadcrumb-segment"><span class="breadcrumb-link is-current" aria-current="page"><AppIcon name="home" /><span>{{ room.name }}</span></span><BreadcrumbMenu :key="room.id" :label="t('locations.chooseZone')" :fetch-page="zones" :disabled="disabled" @select="emit('select', $event)" @access-lost="emit('accessLost', $event)" /></span></NBreadcrumbItem>
</NBreadcrumb></template>
