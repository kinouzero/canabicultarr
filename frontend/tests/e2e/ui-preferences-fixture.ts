import type { Route } from '@playwright/test';
export function uiPreferencesFixture(initialAccount: string) {
 const accounts = new Map<string, Record<string, unknown>>();
 return {
  account: initialAccount,
  values(account?: string) { account ??= this.account; if (!accounts.has(account)) accounts.set(account, {}); return accounts.get(account)!; },
  async handle(route: Route) {
   if (route.request().method() === 'PATCH') {
    for (const entry of route.request().postDataJSON().entries) this.values()[entry.key] = entry.value;
    return route.fulfill({ status: 204 });
   }
   return route.fulfill({ json: { data: this.values() } });
  },
 };
}
