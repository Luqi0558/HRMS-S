# Orbit HRMS — Enhancement Status

**Stack:** Vue 3 + Vite + Tailwind, ~13.5k LOC, 20 views, 5 roles (`employee`, `manager`, `hr`,
`super_admin`, plus `team_lead` as a data-only example). Frontend-first by design — data from
`src/data/mock.js`, state in `src/store/index.js`. The backend (Laravel) is a later phase.

**Everything that can be done without a backend is now done.** What remains all requires the API.

---

## ✅ Done

- **Table-driven permissions.** `can(module, action, resource)` and `scopedList()` in
  `src/store/index.js` are now the single entry point for access checks — 56 call sites.
  Permission checks dropped 96 → 0; the 34 remaining `currentUser.role` references are role
  *labels*, workflow chain data, and view shaping, not access control.
- **Ownership/scope model.** `rolePermissions` gained a `scope` column
  (`all` / `owned` / `team` / `self`), expanded 11 → 39 rows. The ownership rule that was
  copy-pasted 6× in `ProjectDetail.vue` now lives in one helper.
- **Access Control page is real.** Toggling a permission or scope in the UI changes actual
  behaviour. It was previously rendered but read by nothing.
- **Sidebar no longer drifts.** Nav visibility reads the same table as the pages, replacing a
  second parallel hardcoded role list.
- **Locale/currency centralised.** `PKR` and `en-US` are gone from all views. `formatCurrency()`
  drives 91 call sites from `settings.company`; switching currency re-formats the whole app.
- **Route guards.** `router/index.js` has a global `beforeEach` reading `meta.module` + `can()`,
  so a typed URL can no longer reach a page the sidebar hides. Unauthorised navigation redirects
  to a page the role *can* open.
- **Timezone-aware dates.** All 23 raw `toLocaleDateString` / `toLocaleTimeString` /
  `toLocaleString` calls now route through `formatDate()` / `formatTime()` / `formatDateTime()`
  and honour `settings.company.timezone` — including the Topbar clock.
- **Settings actually do something.** `graceMinutes` + `startTime` drive late marking,
  `cameraCheckinRequired` toggles photo capture, `receiptRequiredAbove` and `annualMedicalLimit`
  validate claims, `twoStepApproval` collapses the leave chain to one step, `halfDayEnabled`
  hides the half-day option. The 4 that genuinely need a backend (`workWeek`, `endTime`,
  `superAdminApprovalRequired`, `defaultCommissionType`) are labelled **"Backend required"** in
  the UI so nobody flips a dead switch during a demo.
- **Custom roles are data-only.** `setCurrentUserRole` validates against the permission table
  instead of a hardcoded whitelist, and the login dropdown builds itself from `availableRoles`.
- **Team Lead role.** A working engineer who also runs a squad: keeps their own tickets and timer
  like an employee, and owns the modules where `module.leadId` matches them. Gets a hybrid
  dashboard (my tickets, squad board with live timers, review queue, overdue, capacity) with **no**
  budgets, commissions, or company-wide attendance.
- **Leave chain reworked: Team Lead → HR.** Stage-one approval moved from the manager to the Team
  Lead, who has the sprint and task context. Managers keep full visibility of their team's leave
  but no longer approve it; HR (and super admin) still hold final sign-off. A lead may only
  approve for members of a module they actually lead. The record field is still `managerApproval`
  so timelines and payload shapes stay intact — only the owner of that stage changed.
- **Dashboard fallthrough bug fixed.** The role chain ended in a bare `v-else` that served the
  full super-admin finance dashboard to *any* role outside employee/manager/HR. Now explicitly
  `v-else-if="isSuperAdmin"`, with a neutral permission-driven fallback for other roles.
- **Form validation on the money/leave paths.** Leave (date order, half-day same-day), medical
  claims (amount, annual limit, receipt threshold), and payroll (negative values, deductions vs
  earnings) validate with visible inline errors instead of silent `return`s.
- **Identity validation.** Employee invite now enforces email *format* and *uniqueness*
  (case- and whitespace-insensitive) — email is the login identity, so a duplicate would create two
  accounts indistinguishable at sign-in. Asset serial numbers are likewise unique, with the edit
  path excluded so a record cannot clash with itself. Password minimum length (8) on accept-invite.
  Also fixed employee id generation: `state.employees.length + 1` collided whenever a record was
  removed, so it now derives from the highest existing id.
- **No more silent form failures.** Every create/edit form now explains why a save was rejected
  instead of returning silently: project sections and modules reject duplicate names (per section,
  with the edit path excluded so a record can't clash with itself), adding a developer already on a
  team says so rather than no-op'ing, ticket creation explains the non-obvious "assignee must be on
  this module" rule, and module budgets reject negatives. Errors clear when a form reopens.
- **Petty Cash module (HR + Super Admin).** Office running costs, kept separate from Finance
  (which tracks client revenue). Float top-ups, categorised expenses with receipts, a live running
  balance, and a reconciliation step comparing counted cash against the ledger — the variance check
  is what makes it a control rather than a spreadsheet. **HR records and reconciles but cannot
  approve**: the person holding the cash must not sign off their own replenishment or their own
  over-threshold spend. Spend above a configurable limit waits for Super Admin. Balance rules:
  only *approved* top-ups add to the float, and a *pending* expense still reduces the balance
  (the cash already left the tin) — only an explicit rejection returns it.
- **Super admin dashboard restructured: Money In / Money Out / Net.** The old top row was four
  client-revenue cards under a section titled *"Company Finance Snapshot"* — which showed only
  incoming money while payroll, medical claims, and petty cash appeared nowhere. It read as the
  whole financial picture and wasn't. Now: four cards (Money in, Money out, Net position, Needs
  you), then paired In/Out breakdowns, then a petty cash strip with a low-balance warning. That
  section is renamed **Project Finance Detail**, which is what it actually contains.
  **Commission moved to the expense side** — it is owed *out* to a manager, not earned *in* from a
  client, so grouping it with revenue put it on the wrong side of the ledger.
- **Destructive actions confirmed.** Blocking an employee, removing a designation, and removing a
  stack now go through a shared `ConfirmDialog` instead of firing on a single click. The
  designation prompt reports how many employees currently hold it. Unblocking is restorative so it
  stays instant, and edits to *unsaved* form drafts are not prompted — cancelling already undoes
  them. Also removed `removeDeveloperFromProject`, which was dead code no template ever called.
- **Form labels associated with their inputs.** 86 labels across 12 views were siblings of their
  controls with no `for`/`id` pairing, so clicking a label did nothing and screen readers announced
  the fields unlabelled. Converted to wrapping `<label>` + `<span>`, which associates them without
  needing ids. Label typography moved onto the span so spacing is unchanged.
- **Verified, not assumed.** 532 assertions across ten suites: 224 confirming `can()` matches the
  original hardcoded logic, 87 on guard/sidebar agreement, 35 on settings + locale switching, 20
  pinning each role to its own dashboard, 22 on Team Lead squad scoping, 25 on the leave chain
  (who approves each stage, squad-only approval, both `twoStepApproval` modes), 33 on identity
  validation, 24 on form validation, 36 on petty cash (balance arithmetic, threshold, variance,
  and that HR cannot self-approve), 22 on the company money position (in/out split, commission on
  the expense side, only approved claims counted, rejected spend excluded). The harness caught
  three real bugs — super admin wrongly
  granted personal actions (`SUPER_ADMIN_EXCLUDED`), employees able to create project sections,
  and the dashboard fallthrough above.

---

## 🔴 Critical UI/UX — frontend, no backend needed

Found by auditing the codebase, ordered by cost of delay.

1. **`Dashboard.vue` is 3,216 lines — 24% of the entire frontend in one file.** It holds five role
   dashboards in a single `v-if/v-else-if` chain. This is a maintainability problem that has
   already produced UX bugs: the super-admin fallthrough (any unrecognised role saw the full
   finance dashboard) hid there, and petty cash was missing from the dashboard until it was
   spotted by eye. **Split each role into its own component** — `EmployeeDashboard.vue`,
   `TeamLeadDashboard.vue`, etc. Nothing changes visually; the chance of the next invisible bug
   drops sharply. Do this before adding a sixth role.

2. **Mobile is untested, and the sidebar has no mobile mode.** 208 responsive utilities mean grids
   reflow, but `Sidebar.vue` is a fixed `w-64` / `w-20` with no drawer or overlay — on a phone it
   consumes most of the screen. HR approving leave from a phone is a realistic scenario. Needs a
   hamburger + off-canvas drawer below `md`, then a pass through the main flows at 375px.

3. **Tables can truncate silently.** `useTableControls` loads 10 rows and appends on scroll, inside
   a `max-h` container. A user who does not scroll *within that box* may believe they are seeing
   every record. Some tables show a count, others don't. Make the "showing X of Y" count
   consistent across every table, or switch to explicit pagination.

4. **Only 4 aria attributes app-wide.** Labels are now associated (above), but modals lack focus
   trapping, custom dropdowns in `Profile.vue` have no keyboard support, and status is conveyed by
   colour alone in several places. Relevant commercially: accessibility appears in procurement
   checklists when selling to other companies.

5. **No loading or error states.** Everything is instant because the data is local. When the API
   lands, every table and form needs a skeleton/spinner and a failure path. Worth designing the
   pattern once, alongside the first real endpoint, rather than 20 times.

---

## ⏸ Deferred to the Laravel phase (correctly not done yet)

1. **Real authentication.** `Login.vue` is a role dropdown with no password. Needs Sanctum/JWT,
   token storage, refresh, logout.
2. **Server-side enforcement.** Every rule in `rolePermissions` — and the new route guard — is UX
   only. **The frontend now *looks* authoritative, which is exactly the risk: someone must
   consciously re-implement all of it server-side rather than assume the UI covers it.** Mirror
   the table as Laravel policies/gates; the `can(module, action, resource)` shape maps directly.
3. **Trusted identity.** `currentUser` (incl. `role`) is read from `localStorage`; editing one
   DevTools key grants super_admin and the guard will happily let them through. Role must come
   from the server.
4. **Multi-tenancy.** No `tenantId`/`companyId` anywhere. Every table, query, and route needs
   tenant scoping — **the most expensive item here if deferred past the first customer.**
   `rolePermissions` will also need to be per-tenant, not global.
5. **Audit integrity.** `logActivity()` writes to memory and is caller-optional, so approvals and
   payroll can happen unlogged. Needs server-side immutable logs.
6. **Persist settings + permissions.** Both live in memory and reset on reload. The Access Control
   table and Settings page need to persist via the API.
7. **API error/loading states + automated tests.** No error handling, and the three verification
   suites are scratch scripts rather than a committed test setup — worth promoting to Vitest
   alongside the API, starting with payroll and finance calculations.
