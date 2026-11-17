import type { ObjectDirective } from 'vue';
import { MasonryGrid } from '@egjs/grid';

const grids = new WeakMap<HTMLElement, { grid: MasonryGrid; cleanup: () => void }>();

function mount(element: HTMLElement) {
  element.classList.add('masonry-ready');
  const mobile = matchMedia('(max-width: 700px)');
  const gap = parseFloat(getComputedStyle(element).columnGap) || 14;
  const minimumColumnSize = 20 * parseFloat(getComputedStyle(document.documentElement).fontSize);
  const columns = () => mobile.matches ? 1 : Math.max(1, Math.floor((element.clientWidth + gap) / (minimumColumnSize + gap)));
  const grid = new MasonryGrid(element, {
   align: 'stretch',
   column: columns(),
   gap,
   useResizeObserver: true,
   observeChildren: true,
  });
  const resize = () => { const next = columns(); if (grid.column !== next) { grid.column = next; grid.renderItems(); } };
  const observer = new ResizeObserver(resize);
  observer.observe(element);
  mobile.addEventListener('change', resize);
  grids.set(element, { grid, cleanup: () => { mobile.removeEventListener('change', resize); observer.disconnect(); } });
  grid.syncElements();
}
function destroy(element: HTMLElement) {
  const state = grids.get(element);
  state?.cleanup(); state?.grid.destroy();
  grids.delete(element); element.classList.remove('masonry-ready');
}
export const masonry: ObjectDirective<HTMLElement, boolean> = {
 mounted(element, { value }) { if (value) mount(element); },
 updated(element) { grids.get(element)?.grid.syncElements(); },
 beforeUnmount: destroy,
};
