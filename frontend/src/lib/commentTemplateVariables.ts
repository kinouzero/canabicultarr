import { formatDateTime } from './date-time.ts';
// Expand values only: templates never execute expressions, HTML or JavaScript.
export function commentVariables(record: unknown, locale: string, now = new Date(), action: unknown = {}): Record<string, string> {
 const values: Record<string, string> = { 'date.today': formatDateTime(now, locale, 'date'), 'date.now': formatDateTime(now, locale), 'date.iso': now.toISOString() };
 function walk(value: unknown, path: string, depth: number) {
  if (depth > 12 || ['__proto__', 'constructor', 'prototype'].includes(path.split('.').at(-1) ?? '')) return;
  if (value === null || value === undefined) { values[path] = ''; return; }
  if (typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean') { values[path] = String(value); return; }
  if (Array.isArray(value)) { values[`${path}.length`] = String(value.length); values[path] = value.map(item => typeof item === 'object' && item !== null && 'name' in item ? String(item.name) : typeof item === 'object' ? '' : String(item)).filter(Boolean).join(', '); }
  if (typeof value === 'object') for (const [key, entry] of Object.entries(value)) walk(entry, `${path}.${key}`, depth + 1);
 }
 walk(record, 'plant', 0);
 walk(action, 'action', 0);
 return values;
}
export function expandCommentTemplate(body: string, values: Record<string, string>) {
 const missing = new Set<string>();
 const text = body.replace(/{{\s*([A-Za-z][A-Za-z0-9_.]*)\s*}}/g, (token, key: string) => {
  if (!Object.hasOwn(values, key)) { missing.add(key); return token; }
  return values[key]!;
 });
 return { text, missing: [...missing] };
}
