import { computed, nextTick, onBeforeUnmount, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useStore } from '../store/index.js'
import { attendanceService } from '../services/attendanceService.js'
import { ApiError } from '../services/apiClient.js'

// Shared dashboard state and helpers.
//
// Dashboard.vue previously held five role dashboards in a single 3,200-line
// file with one 1,380-line script block, so every role's logic was in scope for
// every other role's template. That is how the super-admin `v-else` fallthrough
// bug survived. Each role now has its own component; anything genuinely shared
// lives here.
// Camera state is module-level, not per-call: a role dashboard calls
// openCamera() while CameraCheckInModal renders the result, and they must share
// one instance. Per-call refs would leave the modal watching a ref nobody sets.
const videoRef = ref(null)
const showCameraModal = ref(false)
const cameraError = ref('')
const cameraLoading = ref(false)
// In-flight guards, so a double-tap cannot send two punches — the API answers
// the second with a 409 and the user would see an error for a check-in that
// actually worked.
const checkingIn = ref(false)
const checkingOut = ref(false)
// Check-out has no modal of its own to show a failure in, so its error surfaces
// on the dashboard instead.
const attendanceError = ref('')
let cameraStream = null

export function useDashboard() {
  const router = useRouter()
  const store = useStore()
  const { state, todayKey, formatDate: formatCompanyDate } = store

  // --- role flags -----------------------------------------------------------
  const isEmployee = computed(() => state.currentUser.role === 'employee')
  const isManager = computed(() => state.currentUser.role === 'manager')
  const isHR = computed(() => state.currentUser.role === 'hr')
  const isTeamLead = computed(() => state.currentUser.role === 'team_lead')
  const isSuperAdmin = computed(() => state.currentUser.role === 'super_admin')

  /**
   * Whose records these are. Attendance rows and ticket assignees reference the
   * employees table; currentUser.id is the user id.
   */
  const myEmployeeId = computed(() => state.currentUser.employeeId ?? state.currentUser.id)

  // Ticks once a minute so "hours so far today" stays live without each
  // component owning its own timer.
  const nowTick = ref(Date.now())

  // --- naming/formatting helpers -------------------------------------------
  function projectName(id) {
    return state.projects.find(project => project.id === id)?.name ?? ''
  }

  function getTicketProject(ticket) {
    return state.projects.find(project => project.id === ticket.projectId)?.name ?? '—'
  }

  /**
   * Where a dashboard tile should send someone to see the tickets behind it.
   *
   * Straight to the board when they work on exactly one module — which is the
   * common case for an intern or a QA covering one area — and to the assigned
   * modules list otherwise, since guessing which of several they meant would be
   * worse than letting them pick.
   *
   * @param {string} filter one of the board's ticket filters: open, completed,
   *   overdue, review, or all
   */
  function myTicketsRoute(filter = 'all') {
    // store.myModules, not a bare call: this composable destructures only a few
    // names from the store and reaches the rest through `store`.
    const mine = store.myModules()
    const query = filter === 'all' ? {} : { tickets: filter }

    if (mine.length === 1 && mine[0].projectSlug && mine[0].slug) {
      return {
        name: 'project-module-detail',
        params: { projectSlug: mine[0].projectSlug, moduleSlug: mine[0].slug },
        query
      }
    }

    return { name: 'projects', query: { view: 'modules', filter: 'assigned', ...query } }
  }

  function employeeName(id) {
    return state.employees.find(employee => employee.id === id)?.name ?? '—'
  }

  /**
   * A ticket's assignee by name, preferring what the API embedded on it.
   *
   * state.employees is populated from /employees, which only Employees-module
   * holders may call — a team lead has no such row, so resolving the id through
   * the roster always missed and every line read "—". The ticket payload has
   * carried `assignee` all along.
   */
  function ticketAssigneeName(ticket) {
    return ticket?.assignee?.name ?? employeeName(ticket?.assigneeId)
  }

  // Default "12 Jul 2026" rendering. Use formatCompanyDate directly when a
  // component needs its own Intl options.
  function formatDate(value) {
    return formatCompanyDate(value, { month: 'short', day: 'numeric', year: 'numeric' })
  }

  function statusBadgeClass(status) {
    if (status === 'approved' || status === 'paid' || status === 'done' || status === 'generated') return 'company-hub-badge-success'
    if (status === 'pending') return 'company-hub-badge-warning'
    if (status === 'rejected' || status === 'overdue') return 'company-hub-badge-neutral'
    return 'company-hub-badge-muted'
  }

  function timeToMinutes(value) {
    if (!value) return null
    const [hours, minutes] = String(value).split(':').map(Number)
    if (Number.isNaN(hours) || Number.isNaN(minutes)) return null
    return (hours * 60) + minutes
  }

  function currentTimeMinutes() {
    const now = new Date(nowTick.value)
    return (now.getHours() * 60) + now.getMinutes()
  }

  // Days until the next occurrence of a month/day — birthdays and work
  // anniversaries, which recur annually.
  function daysUntilMonthDay(value) {
    if (!value) return 999
    const now = new Date()
    const source = new Date(value)
    let target = new Date(now.getFullYear(), source.getMonth(), source.getDate())
    if (target < new Date(now.getFullYear(), now.getMonth(), now.getDate())) {
      target = new Date(now.getFullYear() + 1, source.getMonth(), source.getDate())
    }
    return Math.ceil((target - now) / 86400000)
  }

  // --- navigation -----------------------------------------------------------
  function goToRoute(route) {
    if (!route) return
    router.push(route)
  }

  function projectDetailRoute(projectId) {
    const project = state.projects.find(item => item.id === projectId)
    return project ? { name: 'project-detail', params: { projectSlug: project.slug } } : { name: 'projects' }
  }

  function moduleDashboardRoute(module) {
    const project = state.projects.find(item => (item.modules || []).some(entry => entry.id === module.id))
    if (!project) return { name: 'projects' }
    return {
      name: 'project-module-detail',
      params: { projectSlug: project.slug, moduleSlug: module.slug }
    }
  }

  // --- the current user's own work (every role has some) --------------------
  // employeeId, not currentUser.id: assigneeId references the employees table
  // while currentUser.id is the user id. They agree only when the two tables
  // happen to have been filled in step.
  const myTickets = computed(() =>
    state.tickets.filter(ticket => ticket.assigneeId === myEmployeeId.value)
  )

  // --- weekly timesheet (employee, manager, HR, team lead) ------------------
  /**
   * Which span the timesheet card is showing. Module-level rather than
   * per-component so the choice survives moving between dashboards.
   */
  const timesheetPeriod = ref('weekly')

  const dailyTimesheet = computed(() => {
    const dayShorts = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']
    const today = new Date()

    // Weekly starts on the Sunday just gone; monthly on the 1st. Both run to
    // the end of their period, so the rest of the month reads as empty days
    // rather than the axis stopping at today.
    const monthly = timesheetPeriod.value === 'monthly'
    const start = monthly
      ? new Date(today.getFullYear(), today.getMonth(), 1)
      : new Date(new Date(today).setDate(today.getDate() - today.getDay()))
    const span = monthly
      ? new Date(today.getFullYear(), today.getMonth() + 1, 0).getDate()
      : 7

    const result = []
    for (let index = 0; index < span; index += 1) {
      const date = new Date(start)
      date.setDate(start.getDate() + index)
      const year = date.getFullYear()
      const month = String(date.getMonth() + 1).padStart(2, '0')
      const day = String(date.getDate()).padStart(2, '0')
      const dayIndex = date.getDay()
      result.push({
        day: dayShorts[dayIndex],
        // The month view labels by number: "Aug 4" does not fit 31 times.
        dayOfMonth: date.getDate(),
        date: formatCompanyDate(date, { day: 'numeric', month: 'short' }),
        dateStr: `${year}-${month}-${day}`,
        attendanceSeconds: 0,
        loggedSeconds: 0,
        isWeekend: dayIndex === 0 || dayIndex === 6
      })
    }

    state.attendance
      .filter(row => row.employeeId === myEmployeeId.value)
      .forEach(row => {
        if (!row.checkIn || !row.checkOut) return
        const start = timeToMinutes(row.checkIn)
        const end = timeToMinutes(row.checkOut)
        const dayRecord = result.find(day => day.dateStr === row.date)
        if (dayRecord && start !== null && end !== null) {
          dayRecord.attendanceSeconds = (end - start) * 60
        }
      })

    myTickets.value.forEach(ticket => {
      ;(ticket.timeLogs || []).forEach(log => {
        if (!log.start || !log.end) return
        const start = new Date(log.start)
        const year = start.getFullYear()
        const month = String(start.getMonth() + 1).padStart(2, '0')
        const day = String(start.getDate()).padStart(2, '0')
        const dayRecord = result.find(entry => entry.dateStr === `${year}-${month}-${day}`)
        if (dayRecord) {
          dayRecord.loggedSeconds += (new Date(log.end) - start) / 1000
        }
      })
    })

    return result
  })

  const weeklyTimesheetMaxHours = computed(() => {
    const maxSeconds = Math.max(
      0,
      ...dailyTimesheet.value.flatMap(day => [day.attendanceSeconds, day.loggedSeconds])
    )
    return Math.max(9, Math.ceil(maxSeconds / 3600))
  })

  const timesheetTitle = computed(() => {
    // Roles that also oversee others see their OWN hours here, so say so.
    return isHR.value || isManager.value || isTeamLead.value ? 'My Timesheet Log' : 'Timesheet Log'
  })

  // --- punch ledger ---------------------------------------------------------
  // Which employees appear: super admin and HR see everyone, an employee sees
  // only themselves, and a manager/lead sees their own team.
  function ledgerRowsFor(visibleIds) {
    return state.attendance
      .filter(row => row.date === todayKey())
      .filter(row => !visibleIds || visibleIds.has(row.employeeId))
      .map(row => {
        const employee = state.employees.find(item => item.id === row.employeeId)
        let hours = '-'
        let status = row.status
        if (row.checkIn) {
          const start = timeToMinutes(row.checkIn)
          const end = row.checkOut ? timeToMinutes(row.checkOut) : currentTimeMinutes()
          const minutes = Math.max(0, (end ?? 0) - (start ?? 0))
          hours = `${Math.floor(minutes / 60)}h ${String(minutes % 60).padStart(2, '0')}m`
          if (!row.checkOut) status = 'active'
        }
        return {
          id: row.id,
          employeeId: row.employeeId,
          name: employee?.name ?? '-',
          department: employee?.department ?? '-',
          checkIn: row.checkIn,
          checkOut: row.checkOut,
          status,
          hours
        }
      })
  }

  // --- self check-in (employee, manager, HR all have this widget) -----------
  // Shared here rather than duplicated per role component.
  // Team leads were missing from this list, so their dashboard had no check-in
  // widget at all — they are staff who come to the office like everyone else.
  // Super admins are excluded deliberately: the role is an administrative
  // account rather than a person on the attendance register.
  // Anyone with an employee record punches in, EXCEPT the super admin: that is
  // an administrative account rather than somebody on the attendance register.
  // Keyed on the employee record rather than a list of roles, so a new role
  // does not need this touched again.
  const canSelfAttend = computed(() =>
    Boolean(state.currentUser.employeeId) && !isSuperAdmin.value
  )
  // employeeId, not currentUser.id: attendance rows reference the employees
  // table while currentUser.id is the user id.
  const dashboardAttendance = computed(() =>
    state.attendance.find(row => row.employeeId === state.currentUser.employeeId && row.date === todayKey())
  )
  // From the server's open-session flag. A day can hold several sessions now,
  // so having a last check-out no longer means the day is over — you are
  // checked in only while a session is still open.
  const dashboardCheckedIn = computed(() => Boolean(dashboardAttendance.value?.isCheckedIn))
  const dashboardCheckInTime = computed(() => dashboardAttendance.value?.checkIn ?? null)

  function clearCamera() {
    if (cameraStream) {
      cameraStream.getTracks().forEach(track => track.stop())
      cameraStream = null
    }
    if (videoRef.value) videoRef.value.srcObject = null
  }

  onBeforeUnmount(clearCamera)

  async function openCamera() {
    if (!canSelfAttend.value || dashboardCheckedIn.value) return
    cameraError.value = ''
    showCameraModal.value = true
    cameraLoading.value = true
    await nextTick()
    try {
      if (!navigator.mediaDevices?.getUserMedia) {
        throw new Error('Camera is not supported in this browser.')
      }
      cameraStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false })
      if (videoRef.value) {
        videoRef.value.srcObject = cameraStream
        await videoRef.value.play()
      }
    } catch (error) {
      cameraError.value = error?.message || 'Camera permission is required to check in.'
    } finally {
      cameraLoading.value = false
    }
  }

  function closeCamera() {
    clearCamera()
    showCameraModal.value = false
    cameraLoading.value = false
  }

  /**
   * The frame as a JPEG Blob.
   *
   * A Blob rather than the data URL this used to return: the photo is uploaded
   * as a file now, and base64 is about a third larger for no benefit.
   */
  function capturePhoto() {
    if (!videoRef.value || !videoRef.value.videoWidth || !videoRef.value.videoHeight) {
      return Promise.resolve(null)
    }
    const canvas = document.createElement('canvas')
    canvas.width = videoRef.value.videoWidth
    canvas.height = videoRef.value.videoHeight
    const context = canvas.getContext('2d')
    if (!context) return Promise.resolve(null)
    context.drawImage(videoRef.value, 0, 0, canvas.width, canvas.height)

    return new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', 0.88))
  }

  /**
   * Records the punch on the server, then reflects what it returned.
   *
   * This used to write straight into state.attendance and close the modal
   * without contacting the API at all — so the photo was captured, the UI said
   * "checked in", and the whole thing was gone on the next refresh. The server
   * also decides `status` (present vs late) from the company's start time and
   * grace period, which is why the row is taken from the response rather than
   * computed here.
   */
  async function dashboardCheckInWithPhoto() {
    if (!canSelfAttend.value || dashboardCheckedIn.value || checkingIn.value) return

    const photo = await capturePhoto()
    if (!photo) {
      cameraError.value = 'Unable to capture the photo. Please try again.'
      return
    }

    checkingIn.value = true
    cameraError.value = ''

    try {
      applyAttendanceRow(await attendanceService.checkIn({ device: 'camera', photo }))
      closeCamera()
    } catch (error) {
      cameraError.value = error instanceof ApiError
        ? error.message
        : 'Could not record your check-in. Please try again.'
    } finally {
      checkingIn.value = false
    }
  }

  async function dashboardCheckOut() {
    if (!canSelfAttend.value || !dashboardAttendance.value || checkingOut.value) return

    checkingOut.value = true
    attendanceError.value = ''

    try {
      applyAttendanceRow(await attendanceService.checkOut())
    } catch (error) {
      attendanceError.value = error instanceof ApiError
        ? error.message
        : 'Could not record your check-out. Please try again.'
    } finally {
      checkingOut.value = false
    }
  }

  /**
   * Puts the server's row into the store, replacing today's if present.
   *
   * Matched on the row id, not on employeeId: the old code keyed new rows by
   * `state.currentUser.id`, which is a user id, while attendance rows carry an
   * employee id — the two only agree by coincidence.
   */
  function applyAttendanceRow(row) {
    if (!row) return
    const index = state.attendance.findIndex(item => item.id === row.id)
    if (index === -1) state.attendance.unshift(row)
    else state.attendance[index] = { ...state.attendance[index], ...row }
  }

  return {
    ...store,
    router,
    canSelfAttend,
    dashboardAttendance,
    dashboardCheckedIn,
    dashboardCheckInTime,
    videoRef,
    showCameraModal,
    cameraError,
    cameraLoading,
    checkingIn,
    checkingOut,
    attendanceError,
    openCamera,
    closeCamera,
    capturePhoto,
    dashboardCheckInWithPhoto,
    dashboardCheckOut,
    isEmployee,
    isManager,
    isHR,
    isTeamLead,
    isSuperAdmin,
    nowTick,
    projectName,
    getTicketProject,
    employeeName,
    myTicketsRoute,
    ticketAssigneeName,
    formatDate,
    // The store's option-accepting formatter, for components that need a
    // different date shape than the default above.
    formatCompanyDate,
    statusBadgeClass,
    timeToMinutes,
    currentTimeMinutes,
    daysUntilMonthDay,
    goToRoute,
    projectDetailRoute,
    moduleDashboardRoute,
    myTickets,
    dailyTimesheet,
    weeklyTimesheetMaxHours,
    timesheetTitle,
    timesheetPeriod,
    ledgerRowsFor
  }
}
