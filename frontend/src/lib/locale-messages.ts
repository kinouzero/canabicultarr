type MergeMessages = (locale: string, messages: Record<string, object>) => void;
type ModuleMessages = Record<string, Record<string, object>>;

// The merge function belongs to one composer; weak keys keep separate app/test
// instances isolated and allow discarded composers and hot-reloaded dictionaries to be collected.
const registrations = new WeakMap<MergeMessages, Map<string, Map<string, WeakSet<object>>>>();

export function registerModuleMessages(merge: MergeMessages, messages: ModuleMessages): void {
 let locales = registrations.get(merge);
 if (!locales) { locales = new Map(); registrations.set(merge, locales); }
 for (const [locale, namespaces] of Object.entries(messages)) {
  let registered = locales.get(locale);
  if (!registered) { registered = new Map(); locales.set(locale, registered); }
  const pending = Object.fromEntries(Object.entries(namespaces).filter(([name, dictionary]) => !registered.get(name)?.has(dictionary)));
  if (!Object.keys(pending).length) continue;
  merge(locale, pending);
  for (const [name, dictionary] of Object.entries(pending)) {
   let dictionaries = registered.get(name);
   if (!dictionaries) { dictionaries = new WeakSet(); registered.set(name, dictionaries); }
   dictionaries.add(dictionary);
  }
 }
}
