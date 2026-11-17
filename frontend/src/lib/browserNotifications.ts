export function browserPushSupported(): boolean {
  return import.meta.env.PROD && window.isSecureContext && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
}
async function registration(): Promise<ServiceWorkerRegistration> {
  return new Promise((resolve, reject) => {
    const timeout = setTimeout(() => reject(new Error('notifications.errors.workerUnavailable')), 4000);
    navigator.serviceWorker.ready.then(value => { clearTimeout(timeout); resolve(value); }, () => { clearTimeout(timeout); reject(new Error('notifications.errors.workerUnavailable')); });
  });
}
async function capabilities(worker: ServiceWorker) {
  const channel = new MessageChannel();
  try {
    await new Promise<void>((resolve, reject) => {
      const timeout = setTimeout(() => reject(new Error('notifications.errors.workerUnavailable')), 2500);
      channel.port1.onmessage = event => { clearTimeout(timeout); event.data?.pushVersion === 1 ? resolve() : reject(new Error('notifications.errors.workerUnavailable')); };
      worker.postMessage({ type: 'canabicultarr.push-capabilities' }, [channel.port2]);
    });
  } finally { channel.port1.close(); channel.port2.close(); }
}
function publicKey(value: string): Uint8Array<ArrayBuffer> {
  const text = atob(value.replace(/-/g, '+').replace(/_/g, '/'));
  return Uint8Array.from(text, char => char.charCodeAt(0));
}
export async function subscribeBrowser(key: string): Promise<{ subscription: PushSubscription; created: boolean }> {
  if (!browserPushSupported()) throw new Error('notifications.errors.browserUnsupported');
  // Request directly from the user's click, before asynchronous registration work.
  const permission = Notification.permission === 'default' ? await Notification.requestPermission() : Notification.permission;
  if (permission !== 'granted') throw new Error('notifications.errors.permissionDenied');
  const ready = await registration();
  if (!ready.active) throw new Error('notifications.errors.workerUnavailable');
  await capabilities(ready.active);
  const expected = publicKey(key);
  let subscription = await ready.pushManager.getSubscription();
  const existing = subscription?.options.applicationServerKey;
  if (subscription && (!existing || new Uint8Array(existing).toString() !== expected.toString())) { await subscription.unsubscribe(); subscription = null; }
  const created = !subscription;
  subscription ??= await ready.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: expected });
  return { subscription, created };
}
export async function localBrowserSubscription(): Promise<PushSubscription | null> {
  if (!('serviceWorker' in navigator)) return null;
  try { return await (await navigator.serviceWorker.getRegistration('/'))?.pushManager.getSubscription() ?? null; } catch { return null; }
}
export async function unsubscribeLocalBrowser(): Promise<void> {
  try { await (await localBrowserSubscription())?.unsubscribe(); } catch { /* Server session binding already removes this device on logout. */ }
}
