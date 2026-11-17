import { phaseDefinition } from './lifecycle';
const phaseIcons: Record<string, string> = {
 planned: 'clock', sowing: 'seed', germination: 'sprout', growth: 'leaf',
 flowering: 'flower', hanging_drying: 'hanger', rack_drying: 'rack',
 curing: 'jar', maturation: 'hourglass', archived: 'archive', flush: 'water',
};
export function plantPhaseIcon(phase: string | null | undefined): string {
 return (phase ? phaseDefinition(phase)?.icon : undefined) ?? (phase && Object.hasOwn(phaseIcons, phase) ? phaseIcons[phase as keyof typeof phaseIcons] : 'refresh');
}
