<script setup lang="ts">
import { useI18n } from 'vue-i18n';
const { t, locale } = useI18n();
function change(event: Event) {
 locale.value = (event.target as HTMLSelectElement).value;
 localStorage.setItem('locale', locale.value);
}
</script>
<template>
 <div class="language-preference"><label for="language">{{ t('language') }}</label><select v-app-select="{ filter: $t('selectControl.filter'), empty: $t('selectControl.empty') }" id="language" :value="locale" @change="change"><option value="en">{{ t('english') }}</option><option value="fr">{{ t('french') }}</option></select></div>
</template>
<style scoped>
.language-preference { max-width: 26rem; margin: 0 0 var(--ui-content-gap); }label { display: block; margin-bottom: var(--space-2); color: var(--theme-muted, #506548); font-size: .8rem; }select { width: 100%; padding: var(--space-3); border: 1px solid var(--theme-border, #cad7bf); border-radius: .6rem; background: var(--theme-surface, white); color: var(--theme-text, #344c35); font: inherit; font-size: .85rem; }select:focus-visible { outline: 2px solid var(--theme-focus, #648752); outline-offset: 2px; }
</style>
