// Representative API-shaped data for documentation; never used to fill real comments.
export function commentExample(t: (key: string) => string) {
 const name = t('commentTemplates.examplePlant'); const date = '2026-11-10T12:00:00.000Z';
 const location = { type: 'zone', id: 'zone-id', name: t('commentTemplates.exampleZone'), site: { id: 'site-id', name: t('commentTemplates.exampleSite') }, room: { id: 'room-id', name: t('commentTemplates.exampleRoom') }, zone: { id: 'zone-id', name: t('commentTemplates.exampleZone') } };
 const durations = { sowing: 3, germination: 7, growth: 21, flowering: 56, hanging_drying: 7, rack_drying: 7, curing: 14, flush: 14 };
 const attachment = { id: 'file-id', title: 'Photo', originalName: 'photo.jpg', mediaType: 'image/jpeg', sizeBytes: 120000 };
 const action = { id: 'harvest', kind: 'harvest', name: t('commentTemplates.exampleAction'), comment: '', occurredAt: date, fromPhase: 'flowering', toPhase: 'hanging_drying', withProducts: false, maintenance: { type: 'topping' }, harvestWeightGrams: '80', attachments: [attachment] };
 const event = { ...action, id: 'event-id', recordedAt: date, actorId: 'user-id', watering: { withProducts: false, duringFlush: true }, fields: ['phase'], snapshot: { name, strain_id: 'strain-id', location, previous_location: null, lifecycle_action: { id: 'harvest', name: action.name, icon: 'harvest', target: 'hanging_drying', requires_harvest_weight: false }, lifecycle_stage: { id: 'flowering', name: null, icon: 'flower', watering: true, maintenance: true, flush: true } } };
 return {
  plant: { id: 'plant-id', name, notes: '', phase: 'flowering', startedAt: date, phaseStartedAt: date, phaseDueAt: date, day: 22, week: 4, isOverdue: false, nextAction: 'harvest', harvestWeightGrams: '80', version: 1, canManage: true, canDelete: true, hasOwnPhoto: true, photoUrl: '/photo', summarySensorIds: ['sensor-id'],
   strain: { id: 'strain-id', name: t('commentTemplates.exampleStrain'), classification: 'hybrid', genetics: 'hybrid', lineage: '', thc: '18%', cbd: '1%', aroma: '', reportedEffects: '', notes: '', sourceUrl: '', theoreticalYield: '450', durationOverrides: durations, seedStock: { id: 'stock-id', name: t('commentTemplates.exampleStrain'), quantity: '12.000', isArchived: false, scoped: false }, version: 1, isArchived: false, canManage: true },
   location, durationSnapshot: { durations, settings_version: 1, strain_version: 1 },
   stageDefinition: { id: 'flowering', name: null, icon: 'flower', watering: true, maintenance: true, flush: true, actions: [event.snapshot.lifecycle_action] }, availableActions: [event.snapshot.lifecycle_action],
   watering: { status: 'alternate', last: { occurredAt: date, withProducts: true }, nextWithProducts: false, flushStartsAt: date, flushDays: 14, need: { needed: true, reason: 'moisture', alertDays: 3, moistureThreshold: 35, measurement: { percent: 30, observedAt: date }, timerDueAt: date } },
   devices: [{ id: 'device-id', name: t('commentTemplates.exampleDevice'), status: 'active' }],
   measurements: [{ sensorId: 'sensor-id', sensorName: t('commentTemplates.exampleSensor'), device: { id: 'device-id', name: t('commentTemplates.exampleDevice') }, measurementType: { name: t('commentTemplates.exampleSensor'), nature: 'substrate_moisture', decimalPlaces: 0 }, value: '30', unit: '%', observedAt: date, isEnabled: true, isStale: false }],
   events: [event], photos: [{ id: 'photo-id', name: 'Photo', url: '/photo', createdAt: date, isCover: true }],
  }, action,
 };
}
export function commentVariableGroup(path: string): string {
 if (path.startsWith('date.')) return 'date';
 if (path.startsWith('action.')) return 'action';
 const key = path.split('.')[1];
 if (['strain', 'location', 'watering', 'measurements', 'devices', 'events', 'photos'].includes(key ?? '')) return key!;
 if (['durationSnapshot', 'availableActions', 'stageDefinition'].includes(key ?? '')) return 'cycle';
 return 'plant';
}
