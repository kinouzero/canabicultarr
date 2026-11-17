<script lang="ts">
import { cloneVNode, defineComponent, Fragment, h, isVNode, useId, type VNode } from 'vue';
import { NTabs, NTab, NTabPane } from 'naive-ui';

// Keep tab semantics and keyboard navigation consistent across application workspaces.
export default defineComponent({
 inheritAttrs: false,
 props: { value: [String, Number] },
 emits: ['update:value'],
 setup(props, { attrs, slots, emit }) {
  const prefix = useId();
  function tabNodes(nodes: VNode[]): VNode[] {
   return nodes.map(node => {
    if (!isVNode(node)) return node;
    if (node.type === Fragment && Array.isArray(node.children)) return cloneVNode({ ...node, children: tabNodes(node.children as VNode[]) });
    if (node.type !== NTab && node.type !== NTabPane) return node;
    const original = node.props ?? {}; const name = original.name;
    const tabId = original.id ?? `${prefix}-tab-${name}`; const panelId = `${prefix}-panel-${name}`;
    const tab = { ...original.tabProps, id: tabId, role: 'tab', 'aria-selected': String(props.value === name), 'aria-disabled': String(!!original.disabled), tabindex: props.value === name ? 0 : -1,
     ...(original['data-testid'] ? { 'data-testid': original['data-testid'] } : {}), ...(original['aria-label'] ? { 'aria-label': original['aria-label'] } : {}), ...(node.type === NTabPane ? { 'aria-controls': panelId } : {}) };
    return cloneVNode(node, node.type === NTabPane ? { id: panelId, role: 'tabpanel', 'aria-labelledby': tabId, 'data-testid': undefined, 'aria-label': undefined, tabProps: tab } : tab);
   });
  }
  function keydown(event: KeyboardEvent) {
   if (!['ArrowLeft', 'ArrowRight', 'Home', 'End', 'Enter', ' '].includes(event.key) || !(event.target instanceof HTMLElement)) return;
   const current = event.target.closest<HTMLElement>('[role="tab"]'); const root = event.currentTarget as HTMLElement;
   if (!current || current.closest('.app-tabs') !== root) return;
   const tabs = Array.from(root.querySelectorAll<HTMLElement>('[role="tab"]')).filter(tab => tab.closest('.app-tabs') === root && tab.getAttribute('aria-disabled') !== 'true');
   const index = tabs.indexOf(current); if (index < 0) return;
   event.preventDefault();
   const next = event.key === 'Home' ? tabs[0] : event.key === 'End' ? tabs.at(-1) : event.key === 'ArrowRight' ? tabs[(index + 1) % tabs.length] : event.key === 'ArrowLeft' ? tabs[(index - 1 + tabs.length) % tabs.length] : current;
   next?.focus(); next?.click();
  }
  function semantics(instance: unknown) {
   const element = (instance as { $el?: HTMLElement } | null)?.$el;
   const nav = element?.querySelector<HTMLElement>('[role="tablist"]') ?? element?.querySelector<HTMLElement>('.n-tabs-nav');
   if (nav) { nav.setAttribute('role', 'tablist'); if (typeof attrs['aria-label'] === 'string') nav.setAttribute('aria-label', attrs['aria-label']); }
  }
  return () => h(NTabs, { ...attrs, ref: semantics, value: props.value, class: ['app-tabs', attrs.class], onKeydown: keydown, 'onUpdate:value': (value: string | number) => emit('update:value', value) }, { ...slots, default: () => tabNodes(slots.default?.() ?? []) });
 },
});
</script>
