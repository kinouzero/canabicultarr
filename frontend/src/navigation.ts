export type AppPanel = 'instanceSettings' | 'catalogs' | 'users' | 'home' | 'account' | 'accountPreferences' | 'accountAccess' | 'overview' | 'plants' | 'catalog' | 'cycleSettings' | 'locations' | 'assets' | 'sensors' | 'events' | 'documents' | 'inventory' | 'measurements' | 'alerts' | 'notifications' | 'accountNotifications' | 'security' | 'profiles' | 'ha' | 'homebridge' | 'http' | 'mqtt' | 'sources' | 'bluetooth';
export interface NavigationItem { id: AppPanel; label: string; icon: string; accessibleLabel?: string; workspace?: string; children?: NavigationItem[]; panels?: AppPanel[] }
export interface BreadcrumbItem extends NavigationItem { alternatives?: NavigationItem[] }
export interface NavigationGroup { label: string; items: NavigationItem[] }
export interface WorkspaceSection extends NavigationItem { panels: AppPanel[] }
export interface Workspace { id: string; label: string; description: string; icon: string; panels: NavigationItem[]; sections?: WorkspaceSection[] }
