import { apiRequest } from './auth';
import type { PlantPhase } from './plants';

export interface InstallationOverview {
  generatedAt: string;
  plants: { total: number; active: number; planned: number; archived: number; byPhase: Record<PlantPhase, number>; lifecycleDue: number; wateringDue: number; due: number; harvested: number; harvestWeightGrams: string };
  strains: { active: number; archived: number; inUse: number };
  records: { documents: number; archivedDocuments: number; journalEntries: number; recentJournalEntries: number };
  sources: Record<'homeAssistant' | 'homebridge' | 'mqtt' | 'http', { total: number; enabled: number; lastReceivedAt: string | null }>;
  locations: { sites: number; rooms: number; zones: number };
  assets: { total: number; active: number; maintenance: number; retired: number };
  sensors: { total: number; enabled: number; withReadings: number; withoutReadings: number; linked: number; unlinked: number };
  collection: { enabledLinks: number; pausedLinks: number; lastAttemptAt: string | null; lastSuccessAt: string | null };
  alerts: { active: number; unacknowledged: number; info: number; warning: number; critical: number; enabledRules: number };
  inventory: { activeItems: number; lowStockItems: number | null };
}
export type DashboardModule = 'plants' | 'catalog' | 'documents' | 'events' | 'ha' | 'mqtt' | 'http' | 'locations' | 'assets' | 'sensors' | 'measurements' | 'alerts' | 'inventory';
export const dashboardApi = { read: () => apiRequest<InstallationOverview>('dashboard') };
