// Single place every API call goes through. Uses fetch rather than adding an
// HTTP library — the app needs headers, JSON and error mapping, all of which
// fetch already does.

const API_URL = import.meta.env.VITE_API_URL || 'http://127.0.0.1:8000/api'
const DEFAULT_TENANT = import.meta.env.VITE_TENANT || 'orbit'

const tokenStorageKey = 'orbit-auth-token'
const tenantStorageKey = 'orbit-tenant'

// Called when the API rejects our token, so the app can clear its session and
// send the user back to login. Set by the router to avoid a circular import.
let onUnauthenticated = null

export function setUnauthenticatedHandler(handler) {
  onUnauthenticated = handler
}

export function getToken() {
  try {
    return window.localStorage.getItem(tokenStorageKey)
  } catch {
    // Private browsing / storage disabled. Treated as signed out rather than
    // crashing the app on boot.
    return null
  }
}

export function setToken(token) {
  try {
    if (token) window.localStorage.setItem(tokenStorageKey, token)
    else window.localStorage.removeItem(tokenStorageKey)
  } catch {
    /* storage unavailable — the session simply will not survive a reload */
  }
}

export function getTenant() {
  try {
    return window.localStorage.getItem(tenantStorageKey) || DEFAULT_TENANT
  } catch {
    return DEFAULT_TENANT
  }
}

/**
 * Overrides the build-time tenant. Only needed once one deployment serves
 * several companies; until then VITE_TENANT is enough.
 */
export function setTenant(tenant) {
  try {
    if (tenant) window.localStorage.setItem(tenantStorageKey, tenant)
    else window.localStorage.removeItem(tenantStorageKey)
  } catch {
    /* ignore */
  }
}

/**
 * Thrown for any non-2xx response. Carries the parsed body so callers can read
 * `errors` (Laravel validation) or `error` (our domain codes) without
 * re-parsing or string-matching the message.
 */
export class ApiError extends Error {
  constructor(message, { status, code, errors, body } = {}) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.code = code
    this.errors = errors || {}
    this.body = body
  }

  /** First validation message for a field, if the API returned one. */
  fieldError(field) {
    return this.errors?.[field]?.[0] || null
  }
}

async function request(path, { method = 'GET', body, headers = {}, auth = true } = {}) {
  const token = auth ? getToken() : null
  const isFormData = body instanceof FormData

  const response = await fetch(`${API_URL}${path}`, {
    method,
    headers: {
      Accept: 'application/json',
      // Let the browser set Content-Type for FormData so it can add the
      // multipart boundary — setting it manually breaks file uploads.
      ...(isFormData ? {} : { 'Content-Type': 'application/json' }),
      'X-Tenant': getTenant(),
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...headers
    },
    body: isFormData ? body : (body ? JSON.stringify(body) : undefined)
  })

  // 204 and empty bodies are valid; treat an unparseable body as empty rather
  // than throwing a JSON error that hides the real status.
  let payload = null
  const text = await response.text()
  if (text) {
    try {
      payload = JSON.parse(text)
    } catch {
      payload = { message: text }
    }
  }

  if (!response.ok) {
    // A rejected token means the session is over — clear it before the caller
    // sees the error, so no later request retries with a token we know is dead.
    if (response.status === 401 && auth) {
      setToken(null)
      if (onUnauthenticated) onUnauthenticated()
    }

    throw new ApiError(
      payload?.message || `Request failed (${response.status})`,
      {
        status: response.status,
        code: payload?.error,
        errors: payload?.errors,
        body: payload
      }
    )
  }

  return payload
}

export const apiClient = {
  get: (path, options) => request(path, { ...options, method: 'GET' }),
  post: (path, body, options) => request(path, { ...options, method: 'POST', body }),
  put: (path, body, options) => request(path, { ...options, method: 'PUT', body }),
  patch: (path, body, options) => request(path, { ...options, method: 'PATCH', body }),
  delete: (path, options) => request(path, { ...options, method: 'DELETE' })
}
