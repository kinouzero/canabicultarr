<script setup lang="ts">
import DisclosureSummary from './DisclosureSummary.vue';
import { computed, ref, watch, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import AppIcon from './AppIcon.vue';
import { commentVariables } from '../lib/commentTemplateVariables';
import { commentExample, commentVariableGroup } from '../lib/commentTemplateReference';
const props = defineProps<{ values?: Record<string, string>; disabled?: boolean }>();
const emit = defineEmits<{ insert: [token: string] }>();
const { t, te, locale } = useI18n();
const id = useId();
const search = ref(''); const group = ref(''); const limit = ref(50);
watch([search, group], () => { limit.value = 50; });
const groups = ['plant', 'strain', 'location', 'cycle', 'watering', 'measurements', 'devices', 'action', 'events', 'photos', 'date'];
const examples = computed(() => { const example = commentExample(t); return commentVariables(example.plant, locale.value, new Date(), example.action); });
const rows = computed(() => Object.entries({ ...examples.value, ...props.values }).map(([key, value]) => ({ key, value, group: commentVariableGroup(key), actual: !!props.values && Object.hasOwn(props.values, key) })).filter(row => (!group.value || row.group === group.value) && `${row.key} ${row.value} ${label(row.key)} ${t(`commentTemplates.groups.${row.group}`)}`.toLocaleLowerCase(locale.value).includes(search.value.trim().toLocaleLowerCase(locale.value))));
function label(key: string) { const field = key.split('.').at(-1); const path = `commentTemplates.fields.${field}`; return te(path) ? t(path) : field; }
function token(key: string) { return `{{ ${key} }}`; }
</script>
<template>
 <details class="variable-reference">
  <DisclosureSummary><AppIcon name="help" />{{ t('commentTemplates.variables') }}</DisclosureSummary>
  <p>{{ t('commentTemplates.referenceIntro') }}</p>
  <ul class="reference-rules"><li>{{ t('commentTemplates.referencePaths') }}</li><li>{{ t('commentTemplates.referenceHistory') }}</li><li>{{ t('commentTemplates.referenceValues') }}</li><li>{{ t('commentTemplates.referenceDates') }}</li><li>{{ t('commentTemplates.referenceSafety') }}</li></ul>
  <p><code v-pre>{{ plant.name }} · {{ action.name }} · {{ action.occurredAt }} · {{ plant.events.0.comment }}</code></p>
  <div class="reference-filters"><div><label :for="`${id}-search`">{{ t('commentTemplates.searchVariables') }}</label><input :id="`${id}-search`" v-model="search" type="search" /></div><div><label :for="`${id}-group`">{{ t('commentTemplates.variableGroup') }}</label><select :id="`${id}-group`" v-model="group" v-app-select="{ filter: t('selectControl.filter'), empty: t('selectControl.empty') }"><option value="">{{ t('commentTemplates.allGroups') }}</option><option v-for="item in groups" :key="item" :value="item">{{ t(`commentTemplates.groups.${item}`) }}</option></select></div></div>
  <p class="reference-examples">{{ t('commentTemplates.exampleNotice') }}</p>
  <ul class="reference-variables"><li v-for="row in rows.slice(0, limit)" :key="row.key"><button type="button" :disabled="disabled" :aria-label="t('commentTemplates.insertVariable', { variable: row.key })" @click="emit('insert', token(row.key))"><AppIcon name="plus" /><code>{{ token(row.key) }}</code></button><small>{{ t(`commentTemplates.groups.${row.group}`) }} · {{ label(row.key) }} · {{ t(row.actual ? 'commentTemplates.currentValue' : 'commentTemplates.exampleValue') }}: <span>{{ row.value || '—' }}</span></small></li></ul>
  <button v-if="rows.length > limit" type="button" @click="limit += 50"><AppIcon name="plus" />{{ t('commentTemplates.moreVariables', { count: rows.length - limit }) }}</button>
  <p v-if="!rows.length">{{ t('commentTemplates.noVariables') }}</p>
 </details>
</template>
<style scoped>
.variable-reference { font-size: .85rem; min-width: 0; margin: var(--space-3) 0; } p, code { overflow-wrap: anywhere; white-space: pre-wrap; } .reference-rules { padding-left: var(--space-5); color: var(--theme-muted); line-height: 1.5; } .reference-filters { display: grid; grid-template-columns: minmax(0,1fr) minmax(0,1fr); gap: var(--space-3); } .reference-filters > div { display: grid; gap: var(--space-1-5); min-width: 0; } input, select { width: 100%; min-width: 0; font: inherit; padding: var(--space-2); } .reference-examples { color: var(--theme-muted); } .reference-variables { list-style: none; padding: 0; max-height: 22rem; overflow: auto; } .reference-variables li { padding: var(--space-2) 0; border-bottom: 1px solid var(--theme-border); } button { display: inline-flex; align-items: center; gap: var(--space-1-5); max-width: 100%; text-align: left; background: var(--theme-subtle); color: inherit; border: 1px solid var(--theme-border); border-radius: .4rem; padding: var(--space-1-5) var(--space-2); cursor: pointer; font: inherit; } button:disabled { cursor: default; opacity: .6; } small { display: block; color: var(--theme-muted); overflow-wrap: anywhere; padding-top: var(--space-1); } small span { white-space: pre-wrap; } @media(max-width: 480px) { .reference-filters { grid-template-columns: minmax(0,1fr); } }
</style>
