<script setup lang="ts">
import { computed } from 'vue';
import { NConfigProvider, NNotificationProvider, darkTheme, enUS, frFR, type GlobalThemeOverrides } from 'naive-ui';
import { useI18n } from 'vue-i18n';
import { instanceTheme } from '../lib/theme';
import { appearanceIsDark } from '../lib/appearance';
const { locale } = useI18n();
const overrides = computed<GlobalThemeOverrides>(() => {
 const p = instanceTheme.value[appearanceIsDark.value ? 'dark' : 'light'];
 return { common: { primaryColor: p.accent, primaryColorHover: p.focus, primaryColorPressed: p.accent, primaryColorSuppl: p.accent,
 successColor: p.success, successColorHover: p.success, warningColor: p.warning, warningColorHover: p.warning, errorColor: p.danger, errorColorHover: p.danger, infoColor: p.info,
 bodyColor: p.canvas, cardColor: p.surface, modalColor: p.surface, popoverColor: p.surface, inputColor: p.surface, tableColor: p.surface, actionColor: p.subtle,
 borderColor: p.border, dividerColor: p.border, textColorBase: p.text, textColor1: p.text, textColor2: p.text, textColor3: p.muted, placeholderColor: p.muted,
 borderRadius: '8px', fontSize: '13px', fontFamily: 'Inter, ui-sans-serif, system-ui, sans-serif' }, Button: { textColorPrimary: p.accentText, textColorHoverPrimary: p.accentText, textColorPressedPrimary: p.accentText },
 Tag: { borderRadius: '999px', closeBorderRadius: '999px', heightTiny: '1.125rem', heightSmall: '1.25rem', heightMedium: '1.5rem', heightLarge: '1.75rem', fontSizeTiny: '.625rem', fontSizeSmall: '.65rem', fontSizeMedium: '.7rem', fontSizeLarge: '.75rem', color: p.subtle, colorBordered: p.subtle, textColor: p.text, border: `1px solid ${p.border}` },
 Notification: { width: 'min(24rem, calc(100vw - 2rem))' },
 Tabs: { tabGapMediumBar: '20px' } };
});
</script>
<template><NConfigProvider :theme="appearanceIsDark ? darkTheme : null" :theme-overrides="overrides" :locale="locale === 'fr' ? frFR : enUS"><NNotificationProvider placement="top-right" :max="4" container-class="feedback-stack" :container-style="{ zIndex: 6000 }" keep-alive-on-hover><slot /></NNotificationProvider></NConfigProvider></template>
