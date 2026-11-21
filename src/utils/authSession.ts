// =====================================================================
// AUTH SESSION HELPER & SAFE apiFetch WRAPPER (HttpOnly Cookie + CSRF)
// Menggunakan cookie sesi HttpOnly murni (tanpa penyimpanan token sesi
// di localStorage/sessionStorage agar aman dari pencurian XSS) serta
// proteksi CSRF double-submit (X-Requested-With + X-Sumas-CSRF).
// =====================================================================

const LEGACY_SESSION_TOKEN_KEY = 'sumas_session_token';
const CSRF_COOKIE_NAME = 'sumas_csrf';

export function clearStoredSessionToken(): void {
  try {
    window.localStorage.removeItem(LEGACY_SESSION_TOKEN_KEY);
  } catch (_) {}
  try {
    window.sessionStorage.removeItem(LEGACY_SESSION_TOKEN_KEY);
  } catch (_) {}
}

// Hapus sisa token sesi lama di Web Storage jika masih ada dari versi sebelumnya
if (typeof window !== 'undefined') {
  clearStoredSessionToken();
}

export function getStoredSessionToken(): string | null {
  return null;
}

export function setStoredSessionToken(_token?: string | null, _rememberMe = true): void {
  clearStoredSessionToken();
}

export function getCsrfCookieToken(): string | null {
  if (typeof document === 'undefined' || !document.cookie) return null;
  try {
    const prefix = `${CSRF_COOKIE_NAME}=`;
    const parts = document.cookie.split(';');
    for (const part of parts) {
      const trimmed = part.trim();
      if (trimmed.startsWith(prefix)) {
        const value = decodeURIComponent(trimmed.slice(prefix.length)).trim();
        return value || null;
      }
    }
  } catch (_) {}
  return null;
}

function isSameOriginApiRequest(input: RequestInfo | URL): boolean {
  try {
    const rawUrl =
      typeof input === 'string'
        ? input
        : input instanceof URL
          ? input.toString()
          : input.url;
    if (!rawUrl) return false;
    if (rawUrl.startsWith('/api/')) return true;
    const parsed = new URL(rawUrl, window.location.origin);
    return parsed.origin === window.location.origin && parsed.pathname.startsWith('/api/');
  } catch {
    return false;
  }
}

export function apiFetch(input: RequestInfo | URL, init?: RequestInit): Promise<Response> {
  if (!isSameOriginApiRequest(input)) {
    return globalThis.fetch(input, init);
  }

  const baseHeaders =
    init?.headers ?? (input instanceof Request ? input.headers : undefined);
  const headers = new Headers(baseHeaders);

  if (!headers.has('X-Requested-With')) {
    headers.set('X-Requested-With', 'XMLHttpRequest');
  }

  const csrfToken = getCsrfCookieToken();
  if (csrfToken && !headers.has('X-Sumas-CSRF')) {
    headers.set('X-Sumas-CSRF', csrfToken);
  }

  const nextInit: RequestInit = {
    ...init,
    credentials: init?.credentials ?? 'same-origin',
    headers,
  };

  return globalThis.fetch(input, nextInit);
}
