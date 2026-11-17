export function localDateInput(date: Date): string {
 return new Date(+date - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
}
export function measurementWindow(period: string, from: string, to: string, now = new Date()): Record<string, string> | null {
 let start: Date, end: Date;
 if (period === 'custom') {
  start = new Date(from); end = new Date(to);
  if (!Number.isFinite(+start) || !Number.isFinite(+end) || +end <= +start || +end > +now || +end - +start > 31 * 86400000) return null;
 } else {
  const days = ({ '24h': 1, '7d': 7, '30d': 30 } as Record<string, number>)[period];
  if (!days) return null;
  end = now; start = new Date(+end - days * 86400000);
 }
 return { date_from: start.toISOString(), date_to: end.toISOString(), as_of: now.toISOString() };
}
