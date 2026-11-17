import type { LocationContext } from '../../src/api/types';
export interface FixtureLocationNode { id: string; name: string; kind: string; canSelect: boolean; children: FixtureLocationNode[] }
export function locationOptions(tree: FixtureLocationNode[]) {
 const rows: { id: string; name: string; detail: string; disabled: boolean; context: LocationContext; ancestorSelection: { site: boolean; room: boolean } }[] = [];
 function visit(nodes: FixtureLocationNode[], parent?: LocationContext, siteAllowed = false, roomAllowed = false) {
  for (const node of nodes) {
   const kind = node.kind as LocationContext['type']; const reference = { id: node.id, name: node.name };
   const context: LocationContext = { type: kind, id: node.id, site: kind === 'site' ? reference : parent!.site, room: kind === 'room' ? reference : parent?.room ?? null, zone: kind === 'zone' ? reference : null };
   const ancestorSelection = { site: kind === 'site' ? node.canSelect : siteAllowed, room: kind === 'room' ? node.canSelect : roomAllowed };
   rows.push({ id: `${kind}:${node.id}`, name: [context.site.name, context.room?.name, context.zone?.name].filter(Boolean).join(' / '), detail: '', disabled: !node.canSelect, context, ancestorSelection });
   visit(node.children, context, ancestorSelection.site, ancestorSelection.room);
  }
 }
 visit(tree); return rows;
}
export function locationOptionPage(tree: FixtureLocationNode[], url: URL) {
 const search = (url.searchParams.get('search') ?? '').toLowerCase(); const page = Number(url.searchParams.get('page') ?? 1);
 const rows = locationOptions(tree).filter(row => !search || row.id.toLowerCase() === search || row.name.toLowerCase().includes(search));
 return { data: rows.slice((page - 1) * 20, page * 20), meta: { current_page: page, last_page: Math.max(1, Math.ceil(rows.length / 20)), total: rows.length, per_page: 20 } };
}
