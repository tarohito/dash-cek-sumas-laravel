// =====================================================================
// AUTH SESSION HELPER & SAFE apiFetch WRAPPER (HttpOnly Cookie + CSRF)
// Menggunakan cookie sesi HttpOnly murni (tanpa penyimpanan token sesi
// di localStorage/sessionStorage agar aman dari pencurian XSS) serta
// proteksi CSRF double-submit (X-Requested-With + X-Sumas-CSRF).
// =====================================================================

const LEGACY_SESSION_TOKEN_KEY = 'sumas_session_token';
const CSRF_COOKIE_NAME = 'sumas_csrf';

let inMemoryCsrfToken: string | null = null;
let inflightCsrfRefresh: Promise<string | null> | null = null;

function normalizeCsrfToken(token?: string | null): string | null {
  if (typeof token !== 'string') return null;
  const trimmed = token.trim();
  return /^[A-Za-z0-9_-]{32,256}$/.test(trimmed) ? trimmed : null;
}

export function setCsrfToken(token?: string | null): void {
  inMemoryCsrfToken = normalizeCsrfToken(token);
}

export function clearStoredSessionToken(): void {
  inMemoryCsrfToken = null;
  inflightCsrfRefresh = null;
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
        return normalizeCsrfToken(value);
      }
    }
  } catch (_) {}
  return null;
}

export function getActiveCsrfToken(): string | null {
  return inMemoryCsrfToken || getCsrfCookieToken();
}

function resolveRequestPathname(input: RequestInfo | URL): string {
  try {
    const rawUrl =
      typeof input === 'string'
        ? input
        : input instanceof URL
          ? input.toString()
          : input.url;
    return new URL(rawUrl, typeof window !== 'undefined' ? window.location.origin : 'http://localhost').pathname;
  } catch {
    return '';
  }
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

function syncCsrfFromResponse(res: Response): void {
  const headerToken = normalizeCsrfToken(res.headers.get('X-Sumas-CSRF'));
  if (headerToken) {
    setCsrfToken(headerToken);
  }
}

async function refreshCsrfTokenFromMe(): Promise<string | null> {
  if (inflightCsrfRefresh) {
    return inflightCsrfRefresh;
  }
  inflightCsrfRefresh = (async () => {
    try {
      const res = await globalThis.fetch('/api/auth/me', {
        method: 'GET',
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      });
      syncCsrfFromResponse(res);
      const data = await res.clone().json().catch(() => null);
      if (data && typeof data.csrfToken === 'string') {
        const normalized = normalizeCsrfToken(data.csrfToken);
        if (normalized) {
          setCsrfToken(normalized);
        }
      }
      return getActiveCsrfToken();
    } catch {
      return null;
    } finally {
      inflightCsrfRefresh = null;
    }
  })();
  return inflightCsrfRefresh;
}

async function executeApiFetch(
  input: RequestInfo | URL,
  init: RequestInit | undefined,
  forceOverrideCsrf = false
): Promise<Response> {
  const baseHeaders =
    init?.headers ?? (input instanceof Request ? input.headers : undefined);
  const headers = new Headers(baseHeaders);

  if (!headers.has('X-Requested-With')) {
    headers.set('X-Requested-With', 'XMLHttpRequest');
  }

  const csrfToken = getActiveCsrfToken();
  if (csrfToken && (forceOverrideCsrf || !headers.has('X-Sumas-CSRF'))) {
    headers.set('X-Sumas-CSRF', csrfToken);
  }

  const nextInit: RequestInit = {
    ...init,
    credentials: init?.credentials ?? 'same-origin',
    headers,
  };

  const res = await globalThis.fetch(input, nextInit);
  syncCsrfFromResponse(res);
  return res;
}

export async function apiFetch(input: RequestInfo | URL, init?: RequestInit): Promise<Response> {
  if (!isSameOriginApiRequest(input)) {
    return globalThis.fetch(input, init);
  }

  const res = await executeApiFetch(input, init, false);

  const method = String(init?.method ?? (input instanceof Request ? input.method : 'GET')).toUpperCase();
  const pathname = resolveRequestPathname(input);
  const isMutating = method !== 'GET' && method !== 'HEAD' && method !== 'OPTIONS';

  if (
    res.status === 403 &&
    isMutating &&
    pathname !== '/api/auth/login' &&
    pathname !== '/api/auth/logout'
  ) {
    const body = await res.clone().json().catch(() => null);
    const errorMsg = typeof body?.error === 'string' ? body.error.toLowerCase() : '';
    if (errorMsg.includes('csrf')) {
      const refreshed = await refreshCsrfTokenFromMe();
      if (refreshed) {
        return executeApiFetch(input, init, true);
      }
    }
  }

  return res;
}
