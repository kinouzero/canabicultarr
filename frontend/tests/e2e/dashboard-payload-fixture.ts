// Model the dashboard's metadata-only and bounded preview responses in browser fixtures.
export function dashboardPayload<T extends { location: { type: string; id: string } }>(rows: T[], url: URL) {
 const grouped = new Map<string, T[]>();
 for (const row of rows) {
  const key = `${row.location.type}:${row.location.id}`;
  if (!grouped.has(key)) grouped.set(key, []);
  grouped.get(key)!.push(row);
 }
 const requested = url.searchParams.getAll('preview_groups[]');
 const groups = [...grouped].filter(([key]) => !requested.length || requested.includes(key));
 return {
  data: url.searchParams.get('metadata_only') === '1' ? [] : groups.flatMap(([, plants]) => plants.slice(0, 10)),
  groups: Object.fromEntries(groups.map(([key, plants]) => [key, { location: plants[0]!.location, current_page: 1, last_page: Math.ceil(plants.length / 10), total: plants.length, per_page: 10 }])),
 };
}
