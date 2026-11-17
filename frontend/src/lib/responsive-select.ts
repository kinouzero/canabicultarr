import { h, nextTick, render, shallowReactive, type AppContext, type Directive, type HTMLAttributes } from 'vue';
import { NButton, NInput, NSelect, type InputInst, type SelectInst, type SelectOption, type SelectGroupOption } from 'naive-ui';
import AppTheme from '../components/AppTheme.vue';
import AppIcon from '../components/AppIcon.vue';

type Labels = { filter: string; empty: string; searchable?: boolean; query?: string; onSearch?: (query: string) => void; pending?: boolean; failed?: boolean; more?: { label: string; load: () => void | Promise<void> } };
const controllers = new WeakMap<HTMLSelectElement, ReturnType<typeof enhance>>();
const normalize = (value: string) => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase();

// Keep the hidden form control as the source of truth for the styled selector.
function enhance(select: HTMLSelectElement, initial: Labels, appContext?: AppContext) {
 let labels = initial;
 const touch = matchMedia('(pointer: coarse)');
 const host = document.createElement('span'); host.className = 'app-select-host';
 select.after(host);
 const originalId = select.id;
 const nativeFocus = select.focus.bind(select);
 let control: SelectInst | null = null;
 let searchControl: InputInst | null = null;
 select.dataset.selectMode = 'custom';
 select.classList.add('app-select-source');
 select.setAttribute('aria-hidden', 'true');
 select.tabIndex = -1;
 select.focus = () => control?.focus();
 const state = shallowReactive({ options: [] as SelectOption[], value: '', disabled: false, status: undefined as 'error' | undefined, label: '', describedBy: undefined as string | undefined, query: '', pending: false, searchable: true, remote: false, show: false, empty: '', more: undefined as Labels['more'] });
 function update(value: string | number | null) {
  const option = Array.from(select.options).find(option => option.value === String(value ?? ''));
  if (!option || option.disabled || select.disabled) return;
  select.value = option.value;
  select.dispatchEvent(new Event('change', { bubbles: true }));
  state.value = select.value;
 }
 function sync() {
  state.options = Array.from(select.options).filter(option => !option.hidden).map(option => ({ label: option.label, value: option.value, disabled: option.disabled || option.parentElement instanceof HTMLOptGroupElement && option.parentElement.disabled, icon: option.dataset.icon }));
  state.value = select.value; state.disabled = select.disabled;
  state.status = select.getAttribute('aria-invalid') === 'true' ? 'error' : undefined;
  state.label = select.getAttribute('aria-label') || (originalId ? document.querySelector(`label[for="${CSS.escape(originalId)}"]`)?.textContent?.trim() : '') || '';
  state.describedBy = select.getAttribute('aria-describedby') ?? undefined;
  state.pending = !!labels.pending; state.searchable = labels.searchable !== false;
  state.remote = !!labels.onSearch; state.empty = labels.empty; state.more = labels.more;
  if (state.disabled || labels.failed) state.show = false;
 }
 function search(query: string) { state.query = query; labels.onSearch?.(query); }
 async function loadMore() {
  if (state.pending) return;
  const before = document.activeElement;
  const retainFocus = before instanceof HTMLElement && before.getAttribute('data-select-more') === originalId;
  // The final page removes its action; move focus before that can close the menu.
  if (retainFocus) { if (searchControl && !touch.matches) searchControl.focus(); else control?.focus(); }
  const paginationFocus = document.activeElement;
  await state.more?.load(); await nextTick();
  if (retainFocus && state.show && (document.activeElement === document.body || document.activeElement === before || document.activeElement === paginationFocus)) document.querySelector<HTMLButtonElement>(`[data-select-more="${originalId}"]:not(:disabled)`)?.focus();
 }
 function syncAccessibility() {
  // Naive UI's nonfilterable selection label is focusable but has no combobox semantics.
  const target = host.querySelector<HTMLElement>('.n-base-selection-label');
  if (!target) return;
  for (const [name, value] of Object.entries({ role: 'combobox', 'aria-label': state.label, 'aria-haspopup': 'listbox', 'aria-expanded': String(state.show), 'aria-controls': `${originalId}-popup`, 'aria-invalid': String(state.status === 'error'), 'aria-disabled': String(state.disabled) })) target.setAttribute(name, value);
  if (state.describedBy) target.setAttribute('aria-describedby', state.describedBy); else target.removeAttribute('aria-describedby');
 }
 function visibility(show: boolean) {
  state.show = show && !state.disabled && !labels.failed;
  if (state.show) {
   if (!touch.matches) void nextTick(() => searchControl?.focus());
  } else {
   void nextTick(() => { if (!state.show && !state.disabled && !labels.failed && state.query) search(''); });
  }
 }
 const vnode = h(AppTheme, null, { default: () => h(NSelect, {
  ref: (value: unknown) => { control = value as SelectInst | null; },
  onVnodeMounted: () => { void nextTick(syncAccessibility); }, onVnodeUpdated: () => { void nextTick(syncAccessibility); },
  value: state.value, options: state.remote || !state.query ? state.options : state.options.filter(option => normalize(String(option.label ?? '')).includes(normalize(state.query))), disabled: state.disabled, status: state.status,
  to: select.closest<HTMLElement>('.app-dialog') ?? undefined,
  displayDirective: 'if', showOnFocus: false, filterable: false, remote: state.remote, loading: state.pending, show: state.show,
  inputProps: { 'aria-label': state.label, 'aria-describedby': state.describedBy },
  menuProps: { onKeydownCapture: (event: KeyboardEvent) => { if (event.key === 'Tab' && event.shiftKey && event.target instanceof HTMLElement && event.target.closest('[data-select-more]')) { event.preventDefault(); event.stopPropagation(); searchControl?.focus(); } }, id: `${originalId}-popup`, role: 'listbox', 'aria-label': state.label, 'data-select-popup': '' } as HTMLAttributes & { 'data-select-popup': string },
  nodeProps: (option: SelectOption | SelectGroupOption) => ({ role: 'option', 'aria-label': String(option.label ?? ''), 'aria-selected': option.value === state.value, 'aria-disabled': !!option.disabled }),
  'data-ui-select': originalId, 'aria-label': state.label, 'aria-describedby': state.describedBy,
  renderLabel: (option: SelectOption) => option.icon ? h('span', { class: 'select-option-label' }, [h(AppIcon, { name: String(option.icon) }), String(option.label ?? '')]) : String(option.label ?? ''),
  'onUpdate:value': update,
  'onUpdate:show': visibility,
  onBlur: () => select.dispatchEvent(new FocusEvent('blur', { bubbles: true })),
 }, { ...(state.searchable ? { header: () => h('div', { class: 'select-filter', onMousedown: (event: MouseEvent) => event.stopPropagation(), onKeydownCapture: (event: KeyboardEvent) => { if (event.key === ' ') event.stopPropagation(); if (event.key === 'Tab') { event.stopPropagation(); const more = document.querySelector<HTMLButtonElement>(`[data-select-more="${originalId}"]:not(:disabled)`); if (!event.shiftKey && more) { event.preventDefault(); more.focus(); } else { visibility(false); control?.focus(); } } if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); visibility(false); control?.focus(); } } }, [h(NInput, { ref: (value: unknown) => { searchControl = value as InputInst | null; }, value: state.query, placeholder: labels.filter, inputProps: { 'aria-label': labels.filter }, 'onUpdate:value': search })]) } : {}), empty: () => state.empty, ...(state.more ? { action: () => h(NButton, { 'data-select-more': originalId, text: true, 'aria-disabled': state.pending, 'aria-busy': state.pending, onKeydown: (event: KeyboardEvent) => { if (event.key === 'Enter' || event.key === ' ') event.stopPropagation(); }, onClick: loadMore }, { default: () => state.more?.label }) } : {}) }) });
 vnode.appContext = appContext ?? null;
 render(vnode, host);
 const observer = new MutationObserver(sync);
 observer.observe(select, { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ['disabled', 'hidden', 'label', 'value', 'aria-label', 'aria-invalid', 'aria-describedby'] });
 const label = originalId ? document.querySelector<HTMLLabelElement>(`label[for="${CSS.escape(originalId)}"]`) : null;
 function focusLabel(event: Event) { event.preventDefault(); control?.focus(); }
 label?.addEventListener('click', focusLabel);
 sync();
 void nextTick(() => { if (host.isConnected) sync(); });
 return { update(next: Labels) { labels = next; sync(); }, destroy() { observer.disconnect(); label?.removeEventListener('click', focusLabel); render(null, host); host.remove(); select.focus = nativeFocus; } };
}
export const responsiveSelect: Directive<HTMLSelectElement, Labels> = {
 mounted(element, binding) { controllers.set(element, enhance(element, binding.value, binding.instance?.$?.appContext)); },
 updated(element, binding) { controllers.get(element)?.update(binding.value); },
 unmounted(element) { controllers.get(element)?.destroy(); controllers.delete(element); },
};
