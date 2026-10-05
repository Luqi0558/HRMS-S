import { computed, nextTick, onBeforeUnmount, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useStore } from '../store/index.js'
import { attendanceService } from '../services/attendanceService.js'
import { ApiError } from '../services/apiClient.js'

const videoRef = ref(null)
const showCameraModal = ref(false)
const dashboardWorkMode = ref('office')
const cameraError = ref('')
const cameraLoading = ref(false)
const checkingIn = ref(false)
const checkingOut = ref(false)
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
  const nowTick = ref(Date.now())

  function projectName(id) {
    return state.projects.find(project => project.id === id)?.name ?? ''
  }

  function getTicketProject(ticket) {
    return state.projects.find(project => project.id === ticket.projectId)?.name ?? '—'
  }

  function myTicketsRoute(filter = 'all') {
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

  function ticketAssigneeName(ticket) {
    return ticket?.assignee?.name ?? employeeName(ticket?.assigneeId)
  }

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

  const myTickets = computed(() =>
    state.tickets.filter(ticket => ticket.assigneeId === myEmployeeId.value)
  )

  const timesheetPeriod = ref('weekly')

  const dailyTimesheet = computed(() => {
    const dayShorts = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']
    const today = new Date()
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
    return isHR.value || isManager.value || isTeamLead.value ? 'My Timesheet Log' : 'Timesheet Log'
  })

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
          if (!row.checkOut && status !== 'late') status = 'active'
        }
        return {
          id: row.id,
          employeeId: row.employeeId,
          name: employee?.name ?? '-',
          department: employee?.department ?? '-',
          checkIn: row.checkIn,
          checkOut: row.checkOut,
          workMode: row.workMode || 'office',
          dayWorkMode: row.dayWorkMode || row.workMode || 'office',
          status,
          hours
        }
      })
  }


  const canSelfAttend = computed(() =>
    Boolean(state.currentUser.employeeId) && !isSuperAdmin.value
  )

  const dashboardAttendance = computed(() =>
    state.attendance.find(row => row.employeeId === state.currentUser.employeeId && row.date === todayKey())
  )
  const dashboardCheckedIn = computed(() => Boolean(dashboardAttendance.value?.isCheckedIn))
  const dashboardCheckInTime = computed(() => dashboardAttendance.value?.checkIn ?? null)
  const dashboardWorkModeLabel = computed(() =>
    dashboardAttendance.value?.workMode === 'remote' ? 'Working from home' : 'Working from office'
  )

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
    dashboardWorkMode.value = dashboardAttendance.value?.workMode || 'office'
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
      applyAttendanceRow(await attendanceService.checkIn({
        device: 'camera',
        photo,
        workMode: dashboardWorkMode.value
      }))
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
    dashboardWorkMode,
    dashboardWorkModeLabel,
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