export interface LocationRef { id: string; name: string; code?: string | null }
export interface LocationContext {
  type: 'site' | 'room' | 'zone';
  id: string;
  site: LocationRef;
  room: LocationRef | null;
  zone: LocationRef | null;
}

export interface Page<T> { data: T[]; meta: { current_page: number; last_page: number; total: number; per_page: number } }
