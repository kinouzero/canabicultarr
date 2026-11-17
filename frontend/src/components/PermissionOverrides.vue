<script setup lang="ts">
import { useId } from 'vue';
import { useI18n } from 'vue-i18n';
import AppIcon from './AppIcon.vue';
import { delegatedPermissions, permissionLabel, permissionIcon, type DelegatedPermission } from '../api/permissions';
const props = defineProps<{ grants: DelegatedPermission[]; denials: DelegatedPermission[]; inherited: DelegatedPermission[]; disabled?: boolean }>();
const emit = defineEmits<{ 'update:grants': [value: DelegatedPermission[]]; 'update:denials': [value: DelegatedPermission[]] }>();
const id = useId(); const { t } = useI18n();
function change(permission: DelegatedPermission, mode: string) {
 emit('update:grants', [...props.grants.filter(value => value !== permission), ...(mode === 'allow' ? [permission] : [])]);
 emit('update:denials', [...props.denials.filter(value => value !== permission), ...(mode === 'deny' ? [permission] : [])]);
}
</script>
<template>
 <div class="permission-overrides">
  <div v-for="permission in delegatedPermissions" :key="permission" class="permission-override">
   <label :for="`${id}-${permission}`"><AppIcon :name="permissionIcon(permission)" /><span>{{ t(permissionLabel(permission)) }}<small>{{ t(inherited.includes(permission) ? 'users.profileAllows' : 'users.profileDenies') }}</small></span></label>
   <select :id="`${id}-${permission}`" :value="denials.includes(permission) ? 'deny' : grants.includes(permission) ? 'allow' : 'inherit'" :disabled="disabled" v-app-select="{ filter: t('selectControl.filter'), empty: t('selectControl.empty'), searchable: false }" @change="change(permission, ($event.target as HTMLSelectElement).value)">
    <option value="inherit">{{ t('users.overrideInherit') }}</option><option value="allow">{{ t('users.overrideAllow') }}</option><option value="deny">{{ t('users.overrideDeny') }}</option>
   </select>
  </div>
 </div>
</template>
<style scoped>
.permission-overrides { display: grid; gap: var(--space-3); }
.permission-override { display: grid; grid-template-columns: minmax(0, 1fr) minmax(9rem, .7fr); align-items: center; gap: var(--space-3); }
.permission-override > label { display: flex !important; align-items: center; gap: var(--space-2); min-width: 0; }
.permission-override svg { width: 1rem; height: 1rem; flex-shrink: 0; color: var(--theme-muted); }
.permission-override small { display: block; font-size: .68rem; color: var(--theme-muted); margin-top: var(--space-0-5); }
.permission-override select { width: 100%; min-width: 0; }
@media(max-width: 420px) { .permission-override { grid-template-columns: minmax(0, 1fr); gap: var(--space-1-5); } }
</style>
