import { apiRequest } from './auth';
import type { Page } from './types';
export const inventoryItemTypes = ['seeds', 'fertilizer', 'substrate', 'container', 'other'] as const;
export type InventoryItemType = typeof inventoryItemTypes[number];
export type StockUnit = 'piece' | 'g' | 'kg' | 'ml' | 'l' | 'm';
export type MovementKind = 'receipt' | 'issue' | 'transfer' | 'count';
export interface InventoryItem { id: string; name: string; sku: string | null; description: string | null; type: InventoryItemType; unit: StockUnit; unitLocked: boolean; decimalPlaces: number; capacityLiters?: string | null; defaultDosePerLiter?: string | null; totalQuantity: string; threshold: string | null; isLowStock: boolean; isArchived: boolean; version: number; canManage: boolean; canEditCatalog?: boolean }
export interface ItemInput { name: string; sku: string | null; description: string | null; type: InventoryItemType; unit: StockUnit; threshold: string | null; decimal_places: number; capacity_liters?: string | null; default_dose_per_liter?: string | null; version?: number; is_archived: boolean }
export interface StorageLocation { id: string; name: string; description?: string | null; isArchived?: boolean }
export interface StockLocationSnapshot { id: string; name?: string; site?: { name: string }; room?: { name: string } | null; zone?: { name: string } | null }
export const storageLocationsApi = {
 list: (search = '', page = 1, state = 'active', perPage = 20) => apiRequest<Page<StorageLocation> & { canManage: boolean }>(`inventory/locations?${new URLSearchParams({ search, page: String(page), state, per_page: String(perPage) })}`, undefined, { raw: true }),
 save: (input: { name: string; description: string | null; is_archived: boolean }, id?: string) => apiRequest<StorageLocation>(`inventory/locations${id ? '/' + encodeURIComponent(id) : ''}`, input, id ? { method: 'PATCH' } : undefined),
};
export interface StockBalance { id: string; quantity: string; location: StorageLocation }
export interface StockMovement { id: string; requestId: string; version: number; kind: MovementKind; quantity: string; reason: string; itemName: string; unit: StockUnit; source: StockLocationSnapshot | null; destination: StockLocationSnapshot | null; sourceBefore: string | null; sourceAfter: string | null; destinationBefore: string | null; destinationAfter: string | null; totalAfter: string | null; recordedAt: string; actor: { id: string; name: string | null } }
export interface MovementInput { kind: MovementKind; quantity: string; reason: string; version: number; request_id: string; source?: Pick<StorageLocation, 'id'>; destination?: Pick<StorageLocation, 'id'> }
export const inventoryApi = {
 list: (filters: Record<string, string>, page = 1) => apiRequest<Page<InventoryItem> & { canEditCatalog?: boolean }>(`inventory/items?${new URLSearchParams({ ...filters, page: String(page) })}`, undefined, { raw: true }),
 create: (input: ItemInput) => apiRequest<InventoryItem>('inventory/items', input),
 update: (id: string, input: Partial<ItemInput>) => apiRequest<InventoryItem>(`inventory/items/${encodeURIComponent(id)}`, input, { method: 'PATCH' }),
 show: (id: string) => apiRequest<InventoryItem>(`inventory/items/${encodeURIComponent(id)}`),
 balances: (id: string, page = 1) => apiRequest<Page<StockBalance>>(`inventory/items/${encodeURIComponent(id)}/balances?page=${page}`, undefined, { raw: true }),
 movements: (id: string, page = 1) => apiRequest<Page<StockMovement>>(`inventory/items/${encodeURIComponent(id)}/movements?page=${page}`, undefined, { raw: true }),
 record: (id: string, input: MovementInput) => apiRequest<StockMovement>(`inventory/items/${encodeURIComponent(id)}/movements`, input),
};
