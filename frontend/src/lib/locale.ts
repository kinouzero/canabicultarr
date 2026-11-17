export type AppLocale = 'en' | 'fr';

export function resolveLocale(saved: string | null, languages: readonly string[]): AppLocale {
 if (saved === 'en' || saved === 'fr') return saved;
 for (const language of languages) {
  const base = language.toLowerCase().split('-')[0];
  if (base === 'en' || base === 'fr') return base;
 }
 return 'en';
}
