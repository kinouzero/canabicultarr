import {
  browserSupportsWebAuthn,
  startAuthentication,
  startRegistration,
  type PublicKeyCredentialCreationOptionsJSON,
  type PublicKeyCredentialRequestOptionsJSON,
} from '@simplewebauthn/browser';
import { ApiError, apiRequest, type AuthUser } from './auth';

export interface Passkey {
  id: string;
  name: string;
  createdAt: string;
  lastUsedAt: string | null;
}

export function supportsPasskeys() {
  return window.isSecureContext && browserSupportsWebAuthn();
}

async function credentialJSON(operation: () => Promise<unknown>) {
  try {
    return JSON.stringify(await operation());
  } catch (cause) {
    const cancelled = cause instanceof Error && ['NotAllowedError', 'AbortError'].includes(cause.name);
    throw new ApiError(0, cancelled ? 'auth.errors.passkeyCancelled' : 'auth.errors.passkeyFailed');
  }
}

export const passkeysApi = {
  list: (signal?: AbortSignal) => apiRequest<{ keys: Passkey[]; recentlyVerified: boolean }>('account/passkeys', undefined, { signal }),
  async authenticate() {
    const optionsJSON = await apiRequest<PublicKeyCredentialRequestOptionsJSON>('auth/passkeys/options', {});
    const credential = await credentialJSON(() => startAuthentication({ optionsJSON }));
    return apiRequest<AuthUser>('auth/passkeys/login', { credential });
  },
  async register(name: string, signal: AbortSignal) {
    const optionsJSON = await apiRequest<PublicKeyCredentialCreationOptionsJSON>('account/passkeys/options', {}, { signal });
    const credential = await credentialJSON(() => startRegistration({ optionsJSON }));
    signal.throwIfAborted();
    return apiRequest<Passkey>('account/passkeys', { name, credential }, { signal });
  },
  remove: (id: string, signal: AbortSignal) => apiRequest<void>(`account/passkeys/${encodeURIComponent(id)}`, {}, { method: 'DELETE', signal }),
};
