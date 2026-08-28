// Single place every API call goes through. Uses fetch rather than adding an
// HTTP library — the app needs headers, JSON and error mapping, all of which
// fetch already does.

// Exported so the realtime client can point Pusher's channel authorisation at
// the same origin, with the same tenant header.
export const API_URL = import.meta.env.VITE_API_URL || 'http://127.0.0.1:8000/api'
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

/**
 * How long to wait before giving up on a request.
 *
 * fetch() has no default timeout: a server that accepts the connection and
 * then stalls leaves the promise pending forever. That is exactly what happens
 * when the office RDP pins the CPU and every PHP worker is busy — and it is
 * worse than an error, because every retry and backoff path in the app sits
 * after the await and so never runs. The poll would not slow down, it would
 * stop dead until the page was reloaded.
 */
const DEFAULT_TIMEOUT = 15000

async function request(path, { method = 'GET', body, headers = {}, auth = true, timeout = DEFAULT_TIMEOUT } = {}) {
  const token = auth ? getToken() : null
  const isFormData = body instanceof FormData

  const controller = new AbortController()
  const timer = setTimeout(() => controller.abort(), timeout)

  let response
  try {
    response = await fetch(`${API_URL}${path}`, {
      method,
      signal: controller.signal,
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
  } catch (error) {
    // Surfaced as an ApiError with status 0 so callers treat a timeout the
    // same as any other failure — counting it, backing off, and trying again —
    // rather than hanging on a promise that will never settle.
    throw new ApiError(
      error?.name === 'AbortError'
        ? 'The server did not respond in time.'
        : 'Could not reach the server.',
      { status: 0, code: error?.name === 'AbortError' ? 'timeout' : 'network' }
    )
  } finally {
    clearTimeout(timer)
  }

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

/**
 * Fetches a binary response — a stored receipt or document.
 *
 * Separate from request() because that one reads the body as text and parses
 * it as JSON, which would corrupt a PDF or image. Auth, tenant scoping and the
 * 401 handling are the same; only the body handling differs. An error response
 * is still JSON, so it is parsed as such to keep the message.
 */
async function requestBlob(path, { headers = {}, timeout = DEFAULT_TIMEOUT } = {}) {
  const token = getToken()

  const controller = new AbortController()
  const timer = setTimeout(() => controller.abort(), timeout)

  let response
  try {
    response = await fetch(`${API_URL}${path}`, {
      signal: controller.signal,
      headers: {
        // Sent even though the success path returns a file: it decides what an
        // ERROR looks like. Without it Laravel treats the request as a browser
        // navigation and renders HTML, and an unauthenticated one used to be a
        // 500 rather than a 401. Every other call already sends this; this one
        // was the exception, and the attendance ledger requests one per photo.
        Accept: 'application/json',
        'X-Tenant': getTenant(),
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
        ...headers
      }
    })
  } catch (error) {
    throw new ApiError(
      error?.name === 'AbortError'
        ? 'The download did not complete in time.'
        : 'Could not reach the server.',
      { status: 0, code: error?.name === 'AbortError' ? 'timeout' : 'network' }
    )
  } finally {
    clearTimeout(timer)
  }

  if (!response.ok) {
    if (response.status === 401) {
      setToken(null)
      if (onUnauthenticated) onUnauthenticated()
    }

    let payload = null
    try {
      payload = await response.json()
    } catch {
      /* a non-JSON error body leaves the status to speak for itself */
    }

    throw new ApiError(
      payload?.message || `Request failed (${response.status})`,
      { status: response.status, code: payload?.error, body: payload }
    )
  }

  return response.blob()
}

export const apiClient = {
  get: (path, options) => request(path, { ...options, method: 'GET' }),
  blob: (path, options) => requestBlob(path, options),
  post: (path, body, options) => request(path, { ...options, method: 'POST', body }),
  put: (path, body, options) => request(path, { ...options, method: 'PUT', body }),
  patch: (path, body, options) => request(path, { ...options, method: 'PATCH', body }),
  delete: (path, options) => request(path, { ...options, method: 'DELETE' })
}
