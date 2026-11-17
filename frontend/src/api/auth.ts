export interface LocationGrant { type: 'site' | 'room' | 'zone'; id: string; name: string }
export interface AuthUser {
  pendingEmail?: string | null;
  id: string;
  name: string;
  email: string;
  avatarUrl?: string;
  permissions: string[];
  isActive: boolean;
  oidcLinked: boolean;
  inventoryLocationAccess?: { id: string; name: string }[] | null;
  locationAccess?: LocationGrant[] | null;
  permissionRoles: { id: string; name: string; permissions?: import('./permissions').DelegatedPermission[] }[];
}

export interface SetupInput {
  token: string;
  name: string;
  email: string;
  password: string;
  password_confirmation: string;
  locale?: 'en' | 'fr';
}

export class ApiError extends Error {
  readonly status: number;
  readonly code: string;
  readonly fields: Record<string, string[]>;

  constructor(status: number, code: string, fields: Record<string, string[]> = {}) {
    super(code);
    this.status = status; this.code = code; this.fields = fields;
  }
}

export async function apiResponse(path: string, options: RequestInit = {}): Promise<Response> {
  const response = await fetch(`/api/v1/${path}`, { ...options, credentials: 'same-origin', cache: 'no-store' });
  if (!response.ok) {
    const payload = await response.json().catch(() => ({}));
    throw new ApiError(response.status, payload.error?.code ?? 'auth.errors.requestFailed', payload.errors ?? {});
  }
  return response;
}

function csrfToken(): string | undefined {
  const cookie = document.cookie.split('; ').find(value => value.startsWith('XSRF-TOKEN='));
  try { return cookie ? decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) || undefined : undefined; }
  catch { return undefined; }
}

export async function apiRequest<T>(path: string, body?: object, options: { method?: 'POST' | 'PATCH' | 'DELETE'; raw?: boolean; signal?: AbortSignal } = {}): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json' };
  if (body) {
    let csrf = csrfToken();
    if (!csrf) {
      const csrfResponse = await fetch('/api/v1/auth/csrf', { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' }, signal: options.signal });
      if (!csrfResponse.ok) {
        throw new ApiError(csrfResponse.status, csrfResponse.status === 429 ? 'auth.errors.rateLimited' : 'auth.errors.sessionExpired');
      }
      csrf = csrfToken();
    }
    if (csrf) headers['X-XSRF-TOKEN'] = csrf;
    if (!(body instanceof FormData)) headers['Content-Type'] = 'application/json';
  }
  const response = await apiResponse(path, {
    method: options.method ?? (body ? 'POST' : 'GET'),
    signal: options.signal,
    headers,
    body: body instanceof FormData ? body : body ? JSON.stringify(body) : undefined,
  });
  if (response.status === 204) return undefined as T;
  const payload = await response.json().catch(() => ({}));
  return (options.raw ? payload : payload.data) as T;
}

export const authApi = {
  oidcStatus: (signal?: AbortSignal) => apiRequest<{ enabled: boolean; result: string | null }>('auth/oidc', undefined, { signal }),
  setupOidc: (token: string) => apiRequest<{ url: string }>('setup/oidc', { token }),
  linkOidc: () => apiRequest<{ url: string }>('auth/oidc/link', {}),
  status: (signal?: AbortSignal) => apiRequest<{ requiresSetup: boolean; localEnabled: boolean; registrationEnabled: boolean }>('setup', undefined, { signal }),
  user: (signal?: AbortSignal) => apiRequest<AuthUser>('auth/user', undefined, { signal }),
  setup: (data: SetupInput) => apiRequest<{ activationRequired: true }>('setup', data),
  register: (data: Omit<SetupInput, 'token'>) => apiRequest<{ activationRequired: true }>('auth/register', data),
  confirmEmailChange: (email: string, token: string) => apiRequest<void>('auth/email-change/confirm', { email, token }),
  confirmActivation: (email: string, token: string) => apiRequest<void>('auth/activation/confirm', { email, token }),
  resendActivation: (email: string, locale: 'en' | 'fr') => apiRequest<void>('auth/activation/resend', { email, locale }),
  login: (email: string, password: string) => apiRequest<AuthUser>('auth/login', { email, password }),
  logout: () => apiRequest<void>('auth/logout', {}),
};
