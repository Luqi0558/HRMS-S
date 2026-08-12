import { describe, it, expect, beforeEach } from 'vitest'
import { useStore } from '../src/store/index.js'

/**
 * The seeded grid, captured at import time.
 *
 * setRolePermissions REPLACES state.rolePermissions with the API's rows, and
 * the suites below call it — so by the time they finish, the seed is gone. It
 * has to be snapshotted before any of that runs.
 */
const SEEDED_PERMISSIONS = [...useStore().state.rolePermissions]

/**
 * The client-side half of the authorization model.
 *
 * scopedList decides which records a role may act on, and it has to agree with
 * ScopeResolver on the API. When it is stricter, the server sends data the
 * client then hides — which looks exactly like having no data at all, and is
 * how a team lead came to see "no project sections assigned" while leading two
 * of them.
 */
describe('scopedList', () => {
  const { state, scopedList, setCurrentUser, setRolePermissions } = useStore()

  // Imran leads two modules of a project that Zara manages. Ids are employee
  // ids, which is what every record below refers to.
  const ZARA = 3
  const IMRAN = 7
  const AYESHA = 1

  const project = {
    id: 1,
    name: 'Internal',
    managerId: ZARA,
    teamMembers: [AYESHA, IMRAN],
    modules: [
      { id: 1, name: 'Orbit HRMS', leadId: null, teamMembers: [AYESHA] },
      { id: 2, name: 'Attendance System', leadId: IMRAN, teamMembers: [AYESHA, IMRAN] }
    ]
  }

  beforeEach(() => {
    state.projects = [project]
  })

  /**
   * Order matters and mirrors the real flow: setRolePermissions stamps every
   * row with whoever is currently signed in, so the user has to be set first —
   * exactly as login is followed by the permissions fetch.
   */
  function signIn(userId, name, role, employeeId, scope) {
    setCurrentUser(userId, name, role, `${name.split(' ')[0].toLowerCase()}@test.com`, employeeId)
    setRolePermissions([
      { module: 'Projects', view: true, create: false, approve: true, finance: false, scope }
    ])
  }

  it('shows a team lead the project whose modules they lead', () => {
    signIn(99, 'Imran Shah', 'team_lead', IMRAN, 'owned')

    expect(scopedList('Projects', state.projects)).toHaveLength(1)
  })

  it('shows a manager the project they manage', () => {
    signIn(98, 'Zara Nadeem', 'manager', ZARA, 'owned')

    expect(scopedList('Projects', state.projects)).toHaveLength(1)
  })

  it('shows an employee a project they are a member of', () => {
    signIn(97, 'Ayesha Khan', 'employee', AYESHA, 'team')

    expect(scopedList('Projects', state.projects)).toHaveLength(1)
  })

  it('hides a project from a lead who leads none of its modules', () => {
    signIn(96, 'Outsider', 'team_lead', 404, 'owned')

    expect(scopedList('Projects', state.projects)).toHaveLength(0)
  })

  /**
   * Records hold employee ids; currentUser.id is a user id. They agree only
   * when the two tables were filled in step, so a mismatch must not change the
   * answer.
   */
  it('matches on the employee id, not the user id', () => {
    // User id deliberately collides with Zara's EMPLOYEE id.
    signIn(ZARA, 'Imran Shah', 'team_lead', IMRAN, 'owned')
    expect(scopedList('Projects', state.projects)).toHaveLength(1)

    // And a user whose user id matches the manager's employee id, but whose
    // own employee id owns nothing, still sees nothing.
    signIn(ZARA, 'Nobody', 'team_lead', 404, 'owned')
    expect(scopedList('Projects', state.projects)).toHaveLength(0)
  })
})

/**
 * Pages that hand over the whole company are gated on scope, not on merely
 * holding the module. A lead or manager reading their own people through the
 * API is a different question from being given the register for everybody.
 */
describe('scopeFor', () => {
  const { scopeFor, setCurrentUser, setRolePermissions } = useStore()

  function signIn(role, rows) {
    setCurrentUser(1, 'Someone', role, 'someone@test.com', 1)
    setRolePermissions(rows)
  }

  it('reports the scope a role holds on a module', () => {
    signIn('hr', [{ module: 'Attendance', view: true, scope: 'all' }])
    expect(scopeFor('Attendance')).toBe('all')

    signIn('manager', [{ module: 'Attendance', view: true, scope: 'owned' }])
    expect(scopeFor('Attendance')).toBe('owned')
  })

  it('reports null when the role holds the module at no scope', () => {
    signIn('team_lead', [{ module: 'Projects', view: true, scope: 'owned' }])
    expect(scopeFor('Attendance')).toBeNull()
  })

  /**
   * The distinction the attendance page depends on: holding the module is not
   * the same as holding it company-wide.
   */
  it('separates holding a module from holding it company-wide', () => {
    signIn('manager', [{ module: 'Attendance', view: true, scope: 'owned' }])

    expect(scopeFor('Attendance')).not.toBeNull()
    expect(scopeFor('Attendance') === 'all').toBe(false)
  })
})

/**
 * The seeded fallback grid must cover every role the API can return.
 *
 * The router guard runs on the first navigation, before App.vue has fetched
 * anything, so a role missing from this grid is refused every page — including
 * whichever page it is redirected to, which is a loop rather than a dead end.
 * That is what sent QA from /dashboard to /profile and back on refresh.
 */
describe('seeded permission fallback', () => {
  const { state, can, setCurrentUser } = useStore()

  // Every role the backend's RolePermissionSeeder defines.
  const BACKEND_ROLES = ['employee', 'manager', 'hr', 'team_lead', 'qa', 'intern', 'super_admin']

  beforeEach(() => {
    state.rolePermissions = [...SEEDED_PERMISSIONS]
  })

  it('covers every role the API can hand back', () => {
    const seeded = new Set(state.rolePermissions.map(row => row.role))

    for (const role of BACKEND_ROLES) {
      expect(seeded.has(role), `no seeded rows for '${role}'`).toBe(true)
    }
  })

  /**
   * The specific loop: whichever page the guard falls back to has to be one the
   * same guard would allow.
   */
  it('lets every role reach both fallback destinations', () => {
    for (const role of BACKEND_ROLES) {
      setCurrentUser(1, 'Someone', role, 'someone@test.com', 1)

      const reachable = can('Dashboard', 'view') || can('Profile', 'view')
      expect(reachable, `'${role}' can reach neither dashboard nor profile`).toBe(true)
    }
  })
})
