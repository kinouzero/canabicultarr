<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { appearanceChoices, appearancePreference, setAppearance, type AppearancePreference } from '../lib/appearance';
const { t } = useI18n();
function change(event: Event) { setAppearance((event.target as HTMLSelectElement).value as AppearancePreference); }
</script>
<template>
 <div class="appearance-preference"><label for="appearance-preference">{{ t('appearance.label') }}</label><select v-app-select="{ filter: $t('selectControl.filter'), empty: $t('selectControl.empty') }" id="appearance-preference" :value="appearancePreference" aria-describedby="appearance-help" @change="change"><option v-for="choice in appearanceChoices" :key="choice" :value="choice">{{ t(`appearance.${choice}`) }}</option></select><small id="appearance-help">{{ t('appearance.help') }}</small></div>
</template>
<style scoped>
.appearance-preference { max-width: 26rem; margin-bottom: var(--ui-content-gap); }label { display: block; margin-bottom: var(--space-2); font-size: .8rem; }select { width: 100%; padding: var(--space-3); border: 1px solid var(--app-border); border-radius: .6rem; background: var(--app-surface); color: var(--app-text); font: inherit; font-size: .85rem; }small { display: block; margin-top: var(--space-2); font-size: .75rem; color: var(--app-muted); }select:focus-visible { outline: 2px solid var(--app-accent); outline-offset: 2px; }
</style>
