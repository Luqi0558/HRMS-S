import { vi } from 'vitest'

/**
 * Everything a view touches that is not the view itself.
 *
 * The point of the smoke test is to exercise RENDER, so the API is stubbed
 * rather than mocked per-call: each endpoint returns an empty-but-correctly-
 * shaped response. A view that only renders when data happens to be present is
 * a view that breaks on an empty database, which is exactly the state the
 * store now starts in.
 */

/** Shapes every endpoint returns, keyed by the path they are requested at. */
const EMPTY_RESPONSES = {
  '/me/permissions': [],
  '/employees': { data: [], meta: {} },
  '/projects': { data: [] },
  '/tickets': { data: [] },
  '/attendance': { data: [], meta: {} },
  '/attendance/today': { attendance: null },
  '/leave-requests': { data: [], meta: {} },
  '/leave-requests/balance': { balance: {} },
  '/medical-claims': { data: [], meta: {} },
  '/medical-claims/usage': { usage: { limit: 0, used: 0, remaining: 0 } },
  '/salary-slip-requests': { data: [], meta: {} },
  '/salary-slips': { data: [], meta: {} },
  '/salary-slips/cost': { cost: { period: '2026-01-01', employees: 0, total: 0 } },
  '/petty-cash': { summary: {}, topUps: [], expenses: [], reconciliations: [] },
  '/messages': { data: [], contacts: [], hasMore: false, oldestId: 0 },
  '/sync': null,
  '/presence': { presence: [] },
  '/settings': { settings: { attendance: {}, leave: {}, claims: {}, pettyCash: {} } },
  '/assets': { data: [] },
  '/company-hub': { announcements: [], policies: [] },
  '/activity-logs': { data: [], modules: [], meta: {} }
}

function responseFor(path) {
  const clean = path.split('?')[0]

  if (clean in EMPTY_RESPONSES) return EMPTY_RESPONSES[clean]

  // Anything unlisted gets a shape that satisfies the common accessors, so a
  // new endpoint does not fail the smoke test before it has been added here.
  return { data: [], meta: {} }
}

vi.mock('../src/services/apiClient.js', () => ({
  API_URL: 'http://localhost/api',
  ApiError: class ApiError extends Error {},
  getToken: () => 'test-token',
  setToken: () => {},
  getTenant: () => 'test',
  setTenant: () => {},
  setUnauthenticatedHandler: () => {},
  apiClient: {
    get: vi.fn(path => Promise.resolve(responseFor(path))),
    post: vi.fn(() => Promise.resolve({})),
    put: vi.fn(() => Promise.resolve({})),
    patch: vi.fn(() => Promise.resolve({})),
    delete: vi.fn(() => Promise.resolve({})),
    blob: vi.fn(() => Promise.resolve(new Blob()))
  }
}))

// Pusher opens a real socket on construction; the views do not care whether it
// connected, only that creating the client does not throw.
vi.mock('laravel-echo', () => ({
  default: class Echo {
    private() { return this.chainable() }
    join() { return this.chainable() }
    leave() {}
    disconnect() {}
    chainable() {
      const self = {
        listen: () => self,
        subscribed: () => self,
        error: () => self,
        here: () => self,
        joining: () => self,
        leaving: () => self,
        stopListening: () => self
      }
      return self
    }
  }
}))

vi.mock('pusher-js', () => ({ default: class Pusher {} }))
