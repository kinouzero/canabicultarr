<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import AppIcon from './AppIcon.vue';
import { appearanceIsDark, setAppearance } from '../lib/appearance';
const { t } = useI18n();
</script>
<template>
 <button type="button" class="appearance-switch" role="switch" :aria-checked="appearanceIsDark" :aria-label="t('appearance.darkMode')" :title="t(appearanceIsDark ? 'appearance.useLight' : 'appearance.useDark')" @click="setAppearance(appearanceIsDark ? 'light' : 'dark')"><AppIcon :name="appearanceIsDark ? 'moon' : 'sun'" /><span>{{ t('appearance.darkMode') }}</span><span class="appearance-track" :class="{ checked: appearanceIsDark }" aria-hidden="true"><span /></span></button>
</template>
<style scoped>
.appearance-switch { display: flex; align-items: center; gap: var(--space-3); width: calc(100% - 1.8rem); margin: var(--space-1) var(--ui-content-gap); padding: var(--space-3); border: 1px solid var(--app-border); border-radius: .65rem; color: var(--app-text); background: var(--app-subtle); font: inherit; font-size: .75rem; cursor: pointer; flex-shrink: 0; }.appearance-switch svg { width: 1.1rem; height: 1.1rem; }.appearance-track { display: flex; align-items: center; width: 2rem; height: 1.15rem; padding: var(--space-0-5); margin-left: auto; border-radius: 1rem; background: var(--theme-accent-fill, #8b9784); flex-shrink: 0; box-sizing: border-box; }.appearance-track > span { width: .85rem; height: .85rem; border-radius: 50%; background: var(--theme-surface, white); transform: translateX(0); transition: transform .15s; }.appearance-track.checked { background: var(--theme-accent-fill, #648752); }.appearance-track.checked > span { transform: translateX(.85rem); }.appearance-switch:focus-visible { outline: 2px solid var(--app-accent); outline-offset: 2px; }@media(prefers-reduced-motion:reduce) { .appearance-track > span { transition: none; } }
</style>
