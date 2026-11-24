// Apply the saved/system appearance before the application and its styles load.
(() => {
 const systemTheme = matchMedia('(prefers-color-scheme: dark)');
 let choice = 'system';
 try { const saved = localStorage.getItem('appearance'); if (['system', 'light', 'dark'].includes(saved)) choice = saved; } catch {}
 const dark = choice === 'dark' || choice === 'system' && systemTheme.matches;
 document.documentElement.classList.toggle('app-dark', dark);
 document.documentElement.style.colorScheme = dark ? 'dark' : 'light';
 document.querySelector('meta[name="theme-color"]')?.setAttribute('content', dark ? '#111812' : '#173e32');

 // Pick the installation artwork from the device theme, independently of the app theme.
 for (const link of document.querySelectorAll('link[data-light-href]')) {
  const darkHref = link.getAttribute('href');
  const lightHref = link.dataset.lightHref;
  const updateIcon = () => {
   const href = systemTheme.matches ? darkHref : lightHref;
   if (link.getAttribute('href') !== href) link.setAttribute('href', href);
  };
  updateIcon();
  systemTheme.addEventListener('change', updateIcon);
 }
})();
