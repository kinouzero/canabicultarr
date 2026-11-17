import { phaseDefinition } from './lifecycle';
export function canRecordMaintenance(phase: string): boolean { return phaseDefinition(phase)?.maintenance ?? false; }

export const builtInMaintenanceTypes = ['topping', 'pruning'] as const;
export function maintenanceLabel(type: string, t: (key: string) => string): string {
 return (builtInMaintenanceTypes as readonly string[]).includes(type) || type === 'other' ? t(`plants.maintenance.types.${type}`) : type;
}
