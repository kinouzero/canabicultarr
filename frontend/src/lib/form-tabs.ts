import { ref, watch, type Ref } from 'vue';

export function useFormTabs(open: Ref<boolean>, fieldTabs: Record<string, string>) {
 const tab = ref('general');
 watch(open, value => { if (value) tab.value = 'general'; }, { flush: 'sync' });
 return { tab, reveal: (field: string) => { tab.value = fieldTabs[field] ?? 'general'; } };
}
