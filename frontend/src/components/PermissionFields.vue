<script setup lang="ts">
import { useId } from 'vue';
import { useI18n } from 'vue-i18n';
import AppIcon from './AppIcon.vue';
import AppSwitch from './AppSwitch.vue';
import { delegatedPermissions, permissionLabel, permissionIcon, type DelegatedPermission } from '../api/permissions';
const props = withDefaults(defineProps<{ modelValue: DelegatedPermission[]; inherited?: DelegatedPermission[]; disabled?: boolean }>(), { inherited: () => [] });
const emit = defineEmits<{ 'update:modelValue': [value: DelegatedPermission[]] }>();
const { t } = useI18n(); const id = useId();
function change(permission: DelegatedPermission, checked: boolean) {
 if (props.disabled || props.inherited.includes(permission)) return;
 emit('update:modelValue', checked ? [...new Set([...props.modelValue, permission])] : props.modelValue.filter(value => value !== permission));
}
</script>
<template>
 <div class="permission-grid">
  <AppSwitch v-for="permission in delegatedPermissions" :key="permission" :id="`${id}-${permission}`" class="permission-choice" :class="{ inherited: inherited.includes(permission) }" :model-value="modelValue.includes(permission) || inherited.includes(permission)" :disabled="disabled || inherited.includes(permission)" @update:model-value="change(permission, $event)">
   <span class="permission-label"><AppIcon :name="permissionIcon(permission)" /><span>{{ t(permissionLabel(permission)) }}<small v-if="inherited.includes(permission)">{{ t('users.inheritedPermission') }}</small></span></span>
  </AppSwitch>
 </div>
</template>
<style scoped>
.permission-grid { display: grid; grid-template-columns: repeat(2,minmax(0,1fr)); gap: var(--space-2); }.permission-choice { display: flex; align-items: center; gap: var(--ui-control-gap); padding: var(--ui-compact-inset); border: 1px solid var(--theme-border); border-radius: .65rem; font-size: .76rem; cursor: pointer; min-width: 0; background: var(--theme-surface); }.permission-label { display: flex; align-items: center; gap: var(--ui-control-gap); min-width: 0; }.permission-label > svg { width: 1rem; height: 1rem; flex-shrink: 0; color: var(--theme-muted); }.permission-choice small { display: block; font-size: .65rem; color: var(--theme-muted); margin-top: var(--space-1); }.permission-choice.inherited { background: var(--theme-subtle); }@media(max-width:600px){ .permission-grid { grid-template-columns: minmax(0,1fr); } }
</style>
