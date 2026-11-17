// Apply the saved/system appearance before the application and its styles load.
(() => {
 let choice = 'system';
 try { const saved = localStorage.getItem('appearance'); if (['system', 'light', 'dark'].includes(saved)) choice = saved; } catch {}
 const dark = choice === 'dark' || choice === 'system' && matchMedia('(prefers-color-scheme: dark)').matches;
 document.documentElement.classList.toggle('app-dark', dark);
 document.documentElement.style.colorScheme = dark ? 'dark' : 'light';
 document.querySelector('meta[name="theme-color"]')?.setAttribute('content', dark ? '#111812' : '#173e32');
})();
