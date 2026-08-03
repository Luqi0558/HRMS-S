import { computed, nextTick, onBeforeUnmount, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useStore } from '../store/index.js'

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

  function employeeName(id) {
    return state.employees.find(employee => employee.id === id)?.name ?? '—'
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
  const myTickets = computed(() =>
    state.tickets.filter(ticket => ticket.assigneeId === state.currentUser.id)
  )

  // --- weekly timesheet (employee, manager, HR, team lead) ------------------
  const dailyTimesheet = computed(() => {
    const dayShorts = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']
    const today = new Date()
    const startOfWeek = new Date(today)
    startOfWeek.setDate(today.getDate() - today.getDay())

    const result = []
    for (let index = 0; index < 7; index += 1) {
      const date = new Date(startOfWeek)
      date.setDate(startOfWeek.getDate() + index)
      const year = date.getFullYear()
      const month = String(date.getMonth() + 1).padStart(2, '0')
      const day = String(date.getDate()).padStart(2, '0')
      const dayIndex = date.getDay()
      result.push({
        day: dayShorts[dayIndex],
        date: formatCompanyDate(date, { day: 'numeric', month: 'short' }),
        dateStr: `${year}-${month}-${day}`,
        attendanceSeconds: 0,
        loggedSeconds: 0,
        isWeekend: dayIndex === 0 || dayIndex === 6
      })
    }

    state.attendance
      .filter(row => row.employeeId === state.currentUser.id)
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
    if (isHR.value || isManager.value || isTeamLead.value) return 'My Weekly Timesheet'
    return 'Weekly Timesheet'
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
  const canSelfAttend = computed(() => isEmployee.value || isManager.value || isHR.value)
  const dashboardAttendance = computed(() =>
    state.attendance.find(row => row.employeeId === state.currentUser.id && row.date === todayKey())
  )
  const dashboardCheckedIn = computed(() =>
    Boolean(dashboardAttendance.value && !dashboardAttendance.value.checkOut)
  )
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

  function capturePhoto() {
    if (!videoRef.value || !videoRef.value.videoWidth || !videoRef.value.videoHeight) return ''
    const canvas = document.createElement('canvas')
    canvas.width = videoRef.value.videoWidth
    canvas.height = videoRef.value.videoHeight
    const context = canvas.getContext('2d')
    if (!context) return ''
    context.drawImage(videoRef.value, 0, 0, canvas.width, canvas.height)
    return canvas.toDataURL('image/jpeg', 0.88)
  }

  function dashboardCheckInWithPhoto() {
    if (!canSelfAttend.value || dashboardCheckedIn.value) return
    const photo = capturePhoto()
    if (!photo) {
      cameraError.value = 'Unable to capture the photo. Please try again.'
      return
    }
    // 24h "HH:MM" and the configured grace period — this previously hardcoded
    // `checkIn >= '09:30'` against a locale-formatted string, which ignored
    // settings.attendance and broke under 12-hour formats.
    const checkIn = store.clockValue()
    const status = store.attendanceStatusFor(checkIn)
    if (dashboardAttendance.value) {
      Object.assign(dashboardAttendance.value, {
        checkIn, checkOut: null, device: 'camera', status, checkInPhoto: photo, wfh: false
      })
    } else {
      state.attendance.unshift({
        id: Date.now(),
        employeeId: state.currentUser.id,
        date: todayKey(),
        checkIn,
        checkOut: null,
        device: 'camera',
        status,
        checkInPhoto: photo,
        wfh: false
      })
    }
    closeCamera()
  }

  function dashboardCheckOut() {
    if (!canSelfAttend.value || !dashboardAttendance.value) return
    dashboardAttendance.value.checkOut = store.clockValue()
    dashboardAttendance.value.device = dashboardAttendance.value.device || 'web'
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
    ledgerRowsFor
  }
}
