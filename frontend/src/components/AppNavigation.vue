<script setup lang="ts">
import { useDisclosureState } from '../lib/disclosure-preferences';
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { siteBranding } from '../lib/branding';
import AppearanceSwitch from './AppearanceSwitch.vue';
import AppIcon from './AppIcon.vue';
import BrandMark from './BrandMark.vue';
import UserAvatar from './UserAvatar.vue';
import NotificationBell from './NotificationBell.vue';
import type { ApiError } from '../api/auth';
import type { AppPanel, NavigationGroup, NavigationItem } from '../navigation';
const props = defineProps<{ userId: string; active: AppPanel; groups: NavigationGroup[]; name: string; avatarUrl?: string; busy: boolean }>();
const emit = defineEmits<{ select: [panel: AppPanel]; logout: []; installationHelp: []; accessLost: [cause: ApiError] }>();
const { t } = useI18n();
const disclosurePreferences = useDisclosureState('navigation');
const expanded = computed(() => props.groups.flatMap(group => group.items).filter(item => item.workspace && disclosurePreferences.isOpen(item.workspace, item.workspace === 'culture' || selected(item))).map(item => item.workspace!));
function selected(item: NavigationItem) { return item.panels?.includes(props.active) ?? item.id === props.active; }
function toggle(item: NavigationItem) {
 const key = item.workspace!;
 disclosurePreferences.set(key, !expanded.value.includes(key));
}
const open = ref(false); const trigger = ref<HTMLButtonElement | null>(null); const drawer = ref<HTMLElement | null>(null);
const previousOverflow = document.body.style.overflow;
const mobile = window.matchMedia('(max-width: 1000px)');
function close(restore = true) { open.value = false; if (restore) void nextTick(() => trigger.value?.focus()); }
function select(panel: AppPanel) { close(false); emit('select', panel); }
function resize() { if (!mobile.matches) close(false); }
function keydown(event: KeyboardEvent) {
 if (!open.value || !mobile.matches) return;
 if (event.key === 'Escape') { event.preventDefault(); close(); }
 if (event.key === 'Tab') {
  const elements = [...(drawer.value?.querySelectorAll<HTMLElement>('button:not(:disabled), a[href]') ?? [])];
  const first = elements[0], last = elements.at(-1);
  if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
  else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
 }
}
watch(open, async value => { document.body.style.overflow = value ? 'hidden' : previousOverflow; if (value) { await nextTick(); drawer.value?.querySelector<HTMLButtonElement>('button')?.focus(); } });
watch(() => props.active, () => close(false));
onMounted(() => { document.addEventListener('keydown', keydown); mobile.addEventListener('change', resize); });
onUnmounted(() => { document.body.style.overflow = previousOverflow; document.removeEventListener('keydown', keydown); mobile.removeEventListener('change', resize); });
</script>
<template>
 <Teleport to="#header-navigation" defer><button ref="trigger" class="menu-trigger" :aria-label="t('navigation.open')" :aria-expanded="open" aria-controls="app-navigation" @click="open = true"><AppIcon name="menu" /></button></Teleport>
 <div v-if="open" class="menu-scrim" @click="close()" aria-hidden="true" />
 <aside id="app-navigation" ref="drawer" class="side-navigation" :class="{ 'is-open': open }" :role="open ? 'dialog' : undefined" :aria-modal="open ? true : undefined" :aria-label="t('navigation.main')">
  <div class="navigation-brand"><BrandMark /><div><strong>{{ siteBranding.siteTitle }}</strong><small v-if="siteBranding.siteSubtitle">{{ siteBranding.siteSubtitle }}</small></div><button class="close-menu" :aria-label="t('navigation.close')" @click="close()"><AppIcon name="close" /></button></div>
  <nav :aria-label="t('navigation.main')">
   <section v-for="group in groups" :key="group.label" class="navigation-group">
    <h2>{{ t(group.label) }}</h2>
    <ul class="navigation-items">
     <li v-for="item in group.items" :key="item.workspace ?? item.id">
      <button class="primary-link" :data-testid="`nav-${item.workspace ?? item.id}`" :aria-current="selected(item) ? (item.children ? 'true' : 'page') : undefined" :aria-expanded="item.children ? expanded.includes(item.workspace!) : undefined" :aria-controls="item.children ? `submenu-${item.workspace}` : undefined" :class="{ selected: selected(item) }" :disabled="busy" @click="item.children ? toggle(item) : select(item.id)">
       <AppIcon :name="item.icon" /><span>{{ t(item.label) }}</span>
       <AppIcon v-if="item.children" name="chevron" class="submenu-chevron" :class="{ expanded: expanded.includes(item.workspace!) }" />
       <span v-else-if="selected(item)" class="active-dot" aria-hidden="true" />
      </button>
      <ul v-if="item.children && expanded.includes(item.workspace!)" :id="`submenu-${item.workspace}`" class="navigation-children" :aria-label="t(item.label)">
       <li v-for="child in item.children" :key="child.id"><button :data-testid="`nav-item-${child.id}`" :aria-current="selected(child) ? 'page' : undefined" :class="{ selected: selected(child) }" :disabled="busy" @click="select(child.id)"><AppIcon :name="child.icon" /><span>{{ t(child.label) }}</span><span v-if="selected(child)" class="active-dot" aria-hidden="true" /></button></li>
      </ul>
     </li>
    </ul>
   </section>
  </nav>
  <AppearanceSwitch />
  <button id="navigation-install-help" class="installation-help" :disabled="busy" @click="close(false); emit('installationHelp')"><AppIcon name="help" /><span>{{ t('pwa.installHelp') }}</span></button>
  <div class="navigation-footer"><button class="profile-link" data-testid="nav-account" :aria-label="t('navigation.account')" :aria-current="active === 'account' ? 'page' : undefined" :disabled="busy" @click="select('account')"><UserAvatar :name="name" :url="avatarUrl" /><span><strong>{{ name }}</strong><small>{{ t('navigation.account') }}</small></span></button><NotificationBell :key="userId" :disabled="busy" @opened="close(false)" @closed="mobile.matches && trigger?.focus()" @select="select" @access-lost="emit('accessLost', $event)" /><button class="sign-out" :aria-label="t('auth.logout')" :disabled="busy" @click="close(false); emit('logout')"><AppIcon name="logout" /></button></div>
 </aside>
</template>
<style scoped>
.side-navigation { position: fixed; inset: 0 auto 0 0; width: 16rem; z-index: 40; display: flex; flex-direction: column; background: var(--theme-surface, #fff); border-right: 1px solid var(--theme-border, #e3eae5); color: var(--theme-text, #173e32); }
.navigation-brand { display: flex; align-items: center; gap: var(--space-3); padding: var(--space-8) var(--space-5) var(--space-6); flex-shrink: 0; } svg { width: 1.2rem; height: 1.2rem; flex-shrink: 0; }
.navigation-brand > div { min-width: 0; flex: 1; overflow-wrap: anywhere; }.navigation-brand strong { font-size: 1rem; letter-spacing: -.04em; }.navigation-brand small { display: block; margin-top: var(--space-1); color: var(--theme-muted, #63745e); font-size: .7rem; }
nav { flex: 1; min-height: 0; overflow-y: auto; overscroll-behavior: contain; padding: var(--space-1) var(--space-4) var(--space-4); scrollbar-width: thin; }.navigation-group { margin: 0 0 var(--ui-content-gap); }.navigation-group h2 { margin: var(--space-1-5) var(--space-3); font-size: .62rem; font-weight: 700; letter-spacing: .11em; text-transform: uppercase; color: var(--theme-muted, #63745e); }
button { font: inherit; cursor: pointer; color: inherit; border: 0; background: transparent; }.navigation-group button { width: 100%; display: flex; align-items: center; gap: var(--space-3); padding: var(--space-3) var(--space-3); text-align: left; font-size: .8rem; line-height: 1.4; border-radius: .65rem; transition: background .15s; }.navigation-items, .navigation-children { list-style: none; margin: 0; padding: 0; }.navigation-items > li { margin: var(--space-1) 0; }.navigation-children { margin: var(--space-1) 0 var(--space-2) var(--ui-content-gap); padding-left: var(--space-2); border-left: 1px solid var(--theme-border, #dce7d8); }.navigation-children button { font-size: .76rem; padding: var(--space-2) var(--space-3); }.submenu-chevron { margin-left: auto; transition: transform .15s; }.submenu-chevron.expanded { transform: rotate(180deg); }.navigation-group button:hover { background: var(--theme-subtle, #f2f6f1); }.navigation-group button.selected { background: var(--theme-subtle, #e8f0e3); font-weight: 650; color: var(--theme-text, #204733); }.active-dot { width: .4rem; height: .4rem; background: var(--theme-accent-fill, #497845); border-radius: 100%; margin-left: auto; flex-shrink: 0; }
.installation-help { display: flex; align-items: center; gap: var(--space-3); padding: var(--space-3) var(--space-6); text-align: left; font-size: .75rem; flex-shrink: 0; }.installation-help:hover { background: var(--theme-subtle, #f2f6f1); }.navigation-footer { display: flex; gap: var(--space-1-5); align-items: center; padding: var(--space-4); border-top: 1px solid var(--theme-border, #e8ede8); }.profile-link { display: flex; gap: var(--space-3); align-items: center; text-align: left; flex: 1; min-width: 0; }.profile-link > span:last-child { min-width: 0; }.profile-link strong { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: .75rem; }.profile-link small { display: block; color: var(--theme-muted, #63745e); font-size: .67rem; margin-top: var(--space-1); }.sign-out { padding: var(--space-2); }
button:focus-visible { outline: 2px solid var(--theme-focus, #668e54); outline-offset: 2px; }button:disabled { opacity: .5; cursor: wait; }.menu-trigger, .close-menu, .menu-scrim { display: none; }
@media (max-width: 1000px) { .side-navigation { visibility: hidden; transform: translateX(-100%); width: min(19rem, 85vw); }.side-navigation.is-open { visibility: visible; transform: translateX(0); }.menu-trigger { display: grid; place-items: center; position: relative; flex-shrink: 0; width: var(--app-control-height); height: var(--app-control-height); padding: var(--space-2); color: var(--theme-text); border-radius: .65rem; border: 1px solid var(--theme-border, #dce5da); background: var(--theme-surface, #fff); z-index: 20; }.menu-trigger > svg { width: 1.15rem; height: 1.15rem; }.close-menu { display: grid; padding: var(--space-1-5); margin-left: auto; }.menu-scrim { display: block; position: fixed; inset: 0; background: var(--theme-overlay, #12241b66); z-index: 35; }.navigation-brand { padding: var(--space-5); }.navigation-group button { min-height: 2.6rem; } }
</style>
