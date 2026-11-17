import type { StockBalance, StockUnit } from '../api/inventory';
export interface ConsumableProduct { id: string; name: string | null; unit: StockUnit | null; quantity: string; available: boolean; balances: StockBalance[]; capacityLiters?: string | null }
export interface ConsumptionRow { product: ConsumableProduct; quantity: string; balanceId: string }
export interface PlantInventoryDraft { waterLiters: string; rows: ConsumptionRow[]; container?: { product: ConsumableProduct; balanceId: string; previous: '' | 'recover' | 'discard' }; currentContainer?: { inventoryItemId: string; name: string; capacityLiters: string } | null }
export interface FertilizerUsage { inventory_item_id: string; balance_id: string; unit: StockUnit; dose_per_liter: string }
export interface FertilizerWateringInput { water_liters?: string; fertilizers?: FertilizerUsage[] }
export function emptyInventoryDraft(): PlantInventoryDraft { return { waterLiters: '', rows: [] }; }
export function validDose(value: string) { return /^\d{1,9}(?:\.\d{1,3})?$/.test(value) && Number(value) > 0 && Number(value) <= 1000000; }
function units(value: string) { const [whole, fraction = ''] = value.split('.'); return BigInt(whole) * 1000n + BigInt(fraction.padEnd(3, '0')); }
export function fertilizerAmount(dose: string, liters: string): string | null {
 if (!validDose(dose) || !validDose(liters)) return null;
 const amount = (units(dose) * units(liters) + 500n) / 1000n;
 if (amount <= 0n || amount > 999999999999n) return null;
 return `${amount / 1000n}.${String(amount % 1000n).padStart(3, '0')}`;
}
export function fertilizerPayload(draft: PlantInventoryDraft, withFertilizer: boolean): FertilizerWateringInput {
 if (!withFertilizer) return draft.waterLiters ? { water_liters: draft.waterLiters } : {};
 return { ...(draft.waterLiters ? { water_liters: draft.waterLiters } : {}), fertilizers: draft.rows.map(row => ({ inventory_item_id: row.product.id, balance_id: row.balanceId, unit: row.product.unit!, dose_per_liter: row.quantity })) };
}

export interface StockRecipe { inventory_item_id: string; quantity: string }
export interface SubstrateUsage { inventory_item_id: string; balance_id: string; unit: StockUnit; quantity: string }
export function substratePayload(draft: PlantInventoryDraft): SubstrateUsage[] { return draft.rows.map(row => ({ inventory_item_id: row.product.id, balance_id: row.balanceId, unit: row.product.unit!, quantity: row.quantity })); }

export interface ContainerUsage { inventory_item_id: string; balance_id: string; previous: string | null }
export function containerPayload(draft: PlantInventoryDraft): ContainerUsage | null { return draft.container ? { inventory_item_id: draft.container.product.id, balance_id: draft.container.balanceId, previous: draft.container.previous || null } : null; }

export function stockCommentContext(draft: PlantInventoryDraft, watering: boolean, withFertilizer = true) {
 return { waterLiters: draft.waterLiters || null, products: (watering && !withFertilizer ? [] : draft.rows).map(row => ({ inventoryItemId: row.product.id, name: row.product.name, unit: row.product.unit, quantity: watering ? fertilizerAmount(row.quantity, draft.waterLiters) : row.quantity, ...(watering ? { dosePerLiter: row.quantity } : {}) })), container: draft.container ? { name: draft.container.product.name, capacityLiters: draft.container.product.capacityLiters, previous: draft.container.previous } : null };
}
