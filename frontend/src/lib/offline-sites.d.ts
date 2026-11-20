export interface OfflineSitesSnapshot {
  sites: { id: string; name: string }[];
  truncated: boolean;
  journalSiteIds: string[];
  journal?: OfflineJournalEntry[];
  journalCursor?: string;
  plantCatalog?: OfflinePlantCatalog;
  accountId: string;
  deviceId: string;
  version: string;
  savedAt: string;
  expiresAt: string;
}

interface OfflinePlantLocation { type: 'site' | 'room' | 'zone'; id: string }

export interface OfflinePlantCatalog {
  creation?: {
    locations: (OfflinePlantLocation & { name: string })[];
    strains: { id: string; name: string }[];
  };
  version: string;
  truncated: boolean;
  plants: {
    id: string;
    name: string;
    strain: string;
    phase: string;
    phaseName: string | null;
    version: number;
    canPhotograph: boolean;
    canMaintain: boolean;
    canWater: boolean;
    waterLiters?: string | null;
    wateringRecipe?: {
      validUntil: string | null;
      products: { id: string; name: string; unit: string; dose: string; balances: { id: string; name: string }[] }[];
    } | null;
    location: string[];
  }[];
}

export interface OfflineFileInfo {
  id: string;
  title: string;
  name: string;
  size: number;
  mediaType: string;
  sha256: string;
}

export interface OfflineFile extends OfflineFileInfo {
  base64: string;
}

export interface OfflineFileSelection {
  ticket: string;
  expiresAt: string;
  files: OfflineFile[];
}

interface OfflineLibraryMetadata {
  ticket: string;
  expiresAt: number;
  count: number;
}

export interface OfflineSitesMetadata {
  plantVersion?: string;
  accountId: string;
  deviceId: string;
  version: string;
  expiresAt: number;
}

export interface OfflineJournalNote {
  kind?: 'note';
  operation_id: string;
  site_id: string;
  title: string;
  body: string;
  occurred_at: string;
}

export interface OfflineJournalEntry extends Omit<OfflineJournalNote, 'operation_id' | 'kind'> {
  type: 'upsert';
  id: string;
}

export type OfflineJournalChange = OfflineJournalEntry | { type: 'remove'; id: string };

interface OfflineAttachment {
  id: string;
  name: string;
  size: number;
  base64: string;
}

interface OfflinePlantAction {
  attachment?: OfflineAttachment;
  operation_id: string;
  plant_id: string;
  plant_name: string;
  version: number;
  comment: string;
  occurred_at: string;
}

export interface OfflineMaintenanceDraft extends OfflinePlantAction {
  kind: 'maintenance';
  type: string;
}

export interface OfflineWateringDraft extends OfflinePlantAction {
  kind: 'watering';
  water_liters?: string;
  fertilizers?: { inventory_item_id: string; balance_id: string; unit: string; dose_per_liter: string; name: string }[];
}

export interface OfflineCreationDraft {
  kind: 'creation';
  operation_id: string;
  name: string;
  strain_id: string;
  strain_name: string;
  location: OfflinePlantLocation;
  location_name: string;
}

export interface OfflinePhotoDraft {
  kind: 'photo';
  operation_id: string;
  plant_id: string;
  plant_name: string;
  version: number;
  attachment: OfflineAttachment;
}

export type OfflineDraft = (OfflineJournalNote | OfflineMaintenanceDraft | OfflineWateringDraft | OfflineCreationDraft | OfflinePhotoDraft) & {
  rejection?: string;
};

export interface OfflineOutboxMetadata {
  accountId: string;
  deviceId: string;
  count: number;
}

export function readOfflineState(): Promise<{ revision: number; metadata: OfflineSitesMetadata | null; outbox: OfflineOutboxMetadata | null; library: OfflineLibraryMetadata | null }>;
export function unlockOfflineSites(passphrase: string, includeFiles?: boolean): Promise<(Omit<OfflineSitesSnapshot, 'expiresAt'> & { expiresAt: number; revision: number; files: OfflineFile[]; pendingCount: number }) | null>;
export function readOfflineDrafts(passphrase: string): Promise<{ accountId: string; deviceId: string; drafts: OfflineDraft[] } | null>;
export function updateOfflineDrafts(ids: string[], passphrase: string, rejected?: ReadonlyMap<string, string>, signal?: AbortSignal): Promise<void>;
export function clearOfflineDirectory(revision?: number): Promise<void>;
export function saveOfflineSites(snapshot: OfflineSitesSnapshot, passphrase: string, revision: number, selection?: OfflineFileSelection): Promise<void>;
export function clearOfflineSites(revision?: number): Promise<void>;
export function watchOfflineSitesChange(listener: () => void): () => void;

export function clearOfflineLibrary(revision?: number): Promise<boolean>;
export function encodeOfflineBytes(buffer: ArrayBuffer): string;
export function decodeOfflineBytes(encoded: string): Uint8Array<ArrayBuffer>;
