import { actionDefinition } from './lifecycle';
import { plantPhaseIcon } from './plant-phase-icon';
export function plantActionIcon(action: string | null | undefined): string {
 const configured = actionDefinition(action); if (configured) return configured.icon;
 if (action === 'harvest') return 'harvest';
 if (action === 'repot_growth' || action === 'repot_flowering') return 'pot';
 const phases: Record<string, string> = { sow: 'sowing', plant: 'germination', transfer_to_rack: 'rack_drying', transfer_to_containers: 'curing', start_maturation: 'maturation', matured: 'maturation', archive: 'archived' };
 return action && Object.hasOwn(phases, action) ? plantPhaseIcon(phases[action]) : 'leaf';
}
