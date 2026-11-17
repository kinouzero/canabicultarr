import type { LifecycleStage } from '../../src/lib/lifecycle';
export const lifecycleStages: LifecycleStage[] = (() => {
 const ids = ['planned', 'sowing', 'germination', 'growth', 'flowering', 'hanging_drying', 'rack_drying', 'curing', 'maturation', 'archived'];
 const icons = ['clock', 'seed', 'sprout', 'leaf', 'flower', 'hanger', 'rack', 'jar', 'hourglass', 'archive'];
 const actions = ['sow', 'plant', 'repot_growth', 'repot_flowering', 'harvest', 'transfer_to_rack', 'transfer_to_containers', 'start_maturation', 'archive'];
 const actionIcons = ['seed', 'sprout', 'pot', 'pot', 'harvest', 'rack', 'jar', 'hourglass', 'archive'];
 return ids.map((id, i) => ({ id, name: null, icon: icons[i], watering: i >= 2 && i <= 4, maintenance: i >= 2 && i <= 4, flush: id === 'flowering', actions: i === 9 ? [] : [{ id: actions[i], name: null, icon: actionIcons[i], target: ids[i + 1], requires_harvest_weight: id === 'curing' }] }));
})();
export const lifecycleIcons = ['seed', 'sprout', 'leaf', 'flower', 'pot', 'scissors', 'harvest', 'hanger', 'rack', 'jar', 'hourglass', 'archive', 'clock', 'water', 'humidity', 'light', 'fan', 'pump', 'sensor', 'box', 'camera', 'journal', 'message', 'bell', 'refresh', 'arrow'];
