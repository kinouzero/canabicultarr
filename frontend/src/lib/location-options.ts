import { computed, type Ref } from 'vue';
import type { TreeOption } from 'naive-ui';
export interface LocationOptionNode extends TreeOption { key: string; label: string; path: string; data: LocationContext; selectable: boolean; children: LocationOptionNode[] }
import { useReferenceOptions } from './reference-options';
import { locationsApi, type LocationOption } from '../api/locations';
import type { LocationContext } from '../api/types';
import { locationLabel } from './location';

export function useLocationOptions(model: Ref<LocationContext | null>, disabled: () => boolean | undefined, onError: (cause: unknown) => void) {
 const key = computed(() => model.value ? `${model.value.type}:${model.value.id}` : '');
 const catalog = useReferenceOptions<LocationOption>({
  value: () => key.value, selected: () => null, disabled,
  fetchPage: () => locationsApi.options, unavailable: () => locationLabel(model.value, true), onError,
 });
 const nodes = computed(() => {
  const roots: LocationOptionNode[] = []; const map = new Map<string, LocationOptionNode>();
  function add(context: LocationContext, selectable: boolean, parent?: LocationOptionNode) {
   const id = `${context.type}:${context.id}`; let node = map.get(id);
   if (!node) {
    node = { key: id, label: context[context.type]!.name + (context[context.type]!.code ? ` [${context[context.type]!.code}]` : ''), path: locationLabel(context, true), data: context, selectable: false, disabled: true, children: [] };
    map.set(id, node); (parent ? parent.children! : roots).push(node);
   }
   node.selectable = (node.selectable || selectable) && !disabled() && !catalog.loading.value && !catalog.failed.value;
   node.disabled = !node.selectable;
   return node;
  }
  for (const row of catalog.options.value) {
   if (!('context' in row)) continue;
   const { context, ancestorSelection } = row as LocationOption;
   const site: LocationContext = { type: 'site', id: context.site.id, site: context.site, room: null, zone: null };
   const root = add(site, ancestorSelection.site);
   let room: LocationOptionNode | undefined;
   if (context.room) room = add({ ...context, type: 'room', id: context.room.id, zone: null }, ancestorSelection.room, root);
   if (context.zone) add(context, !row.disabled, room ?? root);
  }
  return roots;
 });
 const flat = computed(() => {
  const result: { key: string; context: LocationContext; selectable: boolean }[] = [];
  function visit(rows: LocationOptionNode[]) { for (const row of rows) { result.push({ key: row.key!, context: row.data, selectable: row.selectable === true }); visit(row.children ?? []); } }
  visit(nodes.value); return result;
 });
 function choose(value: string) {
  if (disabled() || catalog.loading.value || catalog.failed.value) return;
  if (!value) { model.value = null; return; }
  const row = flat.value.find(row => row.key === value && row.selectable);
  if (row) model.value = row.context;
 }
 return { catalog, key, nodes, flat, choose };
}
