export function registerOfflineFallback(): void {
  if (!import.meta.env.PROD || !window.isSecureContext || !('serviceWorker' in navigator)) return;
  const register = () => {
    // Registration failure must not block online authentication or data access.
    void navigator.serviceWorker.register('/sw.js', { scope: '/', updateViaCache: 'none' }).catch(() => {});
  };
  if (document.readyState === 'complete') register();
  else window.addEventListener('load', register, { once: true });
}
