<script setup>
import { ref, computed, nextTick, onBeforeUnmount, onMounted, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useStore } from '../store/index.js'
import { useTableControls } from '../composables/useTableControls.js'
import { attendanceService } from '../services/attendanceService.js'
import { settingsService } from '../services/settingsService.js'
import { employeeService } from '../services/employeeService.js'

const route = useRoute()
const {
  state, can, scopeFor, todayKey, formatDate, formatTime,
  setAttendance, upsertAttendance, setServerSettings, setEmployees
} = useStore()
const loadError = ref('')
const punchError = ref('')
const punching = ref(false)

/**
 * The ledger names employees, so it needs both. Each fetch is independent and
 * non-fatal: an employee holds no Employees permission but must still see
 * their own punch history.
 */
async function loadAttendance() {
  const [records, settings, employees] = await Promise.allSettled([
    attendanceService.list(),
    settingsService.all(),
    employeeService.list()
  ])

  if (records.status === 'fulfilled') setAttendance(records.value)
  else loadError.value = records.reason?.message || 'Unable to load attendance.'

  if (settings.status === 'fulfilled') setServerSettings(settings.value)
  if (employees.status === 'fulfilled') setEmployees(employees.value.data)
}

/**
 * Object URLs for check-in photos, keyed by attendance id.
 *
 * The images cannot be linked directly: they live on a private disk and are
 * served through the same permission checks as the row, so an <img src> would
 * arrive without the bearer token. Each is fetched once and cached here.
 */
const photoUrls = ref({})

// The row whose photo is open full size, or null. Only rows the API already
// served a photo for can get here, so no extra permission check is needed —
// the thumbnail existing is itself the proof this user may see it.
const viewingPhoto = ref(null)

function onPhotoKeydown(event) {
  if (event.key === 'Escape') viewingPhoto.value = null
}

function openPhoto(row) {
  if (!photoUrls.value[row.id]) return
  viewingPhoto.value = row
}

async function loadPhotos() {
  const pending = state.attendance.filter(
    row => row.hasCheckInPhoto && !photoUrls.value[row.id]
  )

  await Promise.all(pending.map(async row => {
    const url = await attendanceService.photoUrl(row.id)
    if (url) photoUrls.value[row.id] = url
  }))
}

// Runs whenever the ledger changes, so a fresh punch or a filter that pulls in
// new rows gets its thumbnails without a reload.
watch(() => state.attendance.map(row => row.id).join(','), loadPhotos, { immediate: true })

// Object URLs are held by the browser until explicitly released.
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onPhotoKeydown)
  Object.values(photoUrls.value).forEach(URL.revokeObjectURL)
  photoUrls.value = {}
})

onMounted(() => {
  loadAttendance()
  window.addEventListener('keydown', onPhotoKeydown)
})
const videoRef = ref(null)
const showCameraModal = ref(false)
const cameraError = ref('')
const cameraLoading = ref(false)
const attendanceDateFilter = ref('all')
const attendanceStatusFilter = ref('all')
const attendanceEmployeeFilter = ref('all')
const attendancePresenceFilter = ref('all')
let cameraStream = null

// Company-wide scope, not merely 'view'. This page is the whole punch ledger
// with an export; a manager or team lead holds Attendance at 'owned' so they
// can read their own people through the API, which is not the same as being
// handed the register for everybody.
const canAccessAttendance = computed(() => scopeFor('Attendance') === 'all')
const cameraRequired = computed(() => state.settings.attendance.cameraCheckinRequired !== false)
const canViewTeam = computed(() => canAccessAttendance.value)
// employeeId, not currentUser.id. Attendance rows reference the employees
// table; currentUser.id is the user id. They only agree by coincidence, and
// when they did not this found nothing — so `checkedIn` stayed false, the
// Check out button never appeared, and nothing stopped a second check-in.
const todayAttendance = computed(() =>
  state.attendance.find(a => a.employeeId === state.currentUser.employeeId && a.date === todayKey())
)
// From the server's open-session flag, not from check_out being empty. A day
// can now hold several sessions, so "has a last check-out" no longer means the
// day is finished — you are checked in only while a session is still open.
const checkedIn = computed(() => Boolean(todayAttendance.value?.isCheckedIn))
const checkInTime = computed(() => todayAttendance.value?.checkIn ?? null)

function formatNow() {
  return formatTime()
}

function currentDateLabel() {
  return formatDate(new Date(), { weekday: 'long', month: 'long', day: 'numeric' })
}

function clearCamera() {
  if (cameraStream) {
    cameraStream.getTracks().forEach(track => track.stop())
    cameraStream = null
  }
  if (videoRef.value) {
    videoRef.value.srcObject = null
  }
}

onBeforeUnmount(clearCamera)

async function openCamera() {
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

/** The frame as a JPEG Blob, ready to upload. */
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

async function checkInWithPhoto() {
  if (checkedIn.value) return

  // When camera check-in is switched off in Settings, skip capture entirely.
  let photo = null
  if (cameraRequired.value) {
    if (!showCameraModal.value) {
      await openCamera()
    }
    if (cameraError.value || !videoRef.value) return

    photo = await capturePhoto()
    if (!photo) {
      cameraError.value = 'Unable to capture the photo. Please try again.'
      return
    }
  }

  // The server stamps the time and decides present vs late from the company's
  // start time and grace period — the browser clock is not authoritative and
  // would let anyone punch in on time by changing it.
  punchError.value = ''
  punching.value = true
  try {
    // The photo is uploaded with the punch. It used to be kept in this tab
    // only — the API stored that a check-in happened but not the image — so
    // HR opening the same record saw "No photo" for a check-in that was
    // photographed, and the evidence vanished on refresh.
    const record = await attendanceService.checkIn({
      device: cameraRequired.value ? 'camera' : 'laptop',
      photo
    })
    upsertAttendance(record)
    closeCamera()
  } catch (error) {
    punchError.value = error?.message || 'Unable to check in.'
  } finally {
    punching.value = false
  }
}

async function checkOut() {
  if (!todayAttendance.value) return

  punchError.value = ''
  punching.value = true
  try {
    upsertAttendance(await attendanceService.checkOut())
  } catch (error) {
    punchError.value = error?.message || 'Unable to check out.'
  } finally {
    punching.value = false
  }
}

const ledgerRows = computed(() =>
  state.attendance.map(a => {
    const emp = state.employees.find(e => e.id === a.employeeId)
    // Taken from the API, which differences the two stored times. The old
    // version assumed a 15:30 finish for anyone who had not checked out yet,
    // so a row that was still open reported a worked total that nobody had
    // worked. Still open now reads as a dash and a status of 'active'.
    let hours = '—'
    let status = a.status
    if (a.checkIn && typeof a.workedSeconds === 'number') {
      hours = `${Math.floor(a.workedSeconds / 3600)}h ${String(Math.floor((a.workedSeconds % 3600) / 60)).padStart(2, '0')}m`
    }
    if (a.checkIn && !a.checkOut) status = 'active'
    return { id: a.id, name: a.employeeName ?? emp?.name ?? '—', checkIn: a.checkIn, checkOut: a.checkOut, status, hours, photo: photoUrls.value[a.id] ?? null }
  })
)
const filteredAttendanceRows = computed(() =>
  state.attendance.filter(row => {
    const matchesDate = attendanceDateFilter.value === 'all' || row.date === attendanceDateFilter.value
    const matchesStatus = attendanceStatusFilter.value === 'all' || row.status === attendanceStatusFilter.value
    const matchesEmployee = attendanceEmployeeFilter.value === 'all' || row.employeeId === Number(attendanceEmployeeFilter.value)
    const matchesPresence = attendancePresenceFilter.value === 'all'
      || (attendancePresenceFilter.value === 'checked-in' && Boolean(row.checkIn))
      || (attendancePresenceFilter.value === 'active' && row.checkIn && !row.checkOut)
      || (attendancePresenceFilter.value === 'missing-checkout' && row.checkIn && !row.checkOut)
    return matchesDate && matchesStatus && matchesEmployee && matchesPresence
  })
)

const attendanceSummary = computed(() => ({
  total: filteredAttendanceRows.value.length,
  present: filteredAttendanceRows.value.filter(row => row.status === 'present').length,
  late: filteredAttendanceRows.value.filter(row => row.status === 'late').length,
  active: filteredAttendanceRows.value.filter(row => row.checkIn && !row.checkOut).length
}))

const attendanceDates = computed(() => Array.from(new Set(state.attendance.map(row => row.date))).sort().reverse())

const attendanceTable = useTableControls(
  filteredAttendanceRows,
  row => {
    const employee = state.employees.find(e => e.id === row.employeeId)
    return `${employee?.name || ''} ${row.checkIn || ''} ${row.checkOut || ''} ${row.device || ''} ${row.status || ''}`
  }
)

function applyRouteFilters(query = route.query) {
  attendanceDateFilter.value = query.date === 'today' ? todayKey() : query.date || 'all'
  attendanceStatusFilter.value = query.status || 'all'
  attendancePresenceFilter.value = query.presence || 'all'
  attendanceEmployeeFilter.value = query.employeeId || 'all'
  attendanceTable.query.value = query.search || ''
}

watch(
  () => route.query,
  query => applyRouteFilters(query),
  { immediate: true }
)

const myAttendanceRows = computed(() =>
  state.attendance
    // employeeId, not currentUser.id: attendance rows reference the employees
    // table while currentUser.id is the user id.
    .filter(a => a.employeeId === state.currentUser.employeeId)
    .map(a => ({
      ...a,
      photo: photoUrls.value[a.id] ?? null
    }))
)

function employeeName(id) {
  return state.employees.find(e => e.id === id)?.name ?? ''
}

function exportAttendanceReport() {
  const header = ['Employee', 'Date', 'Check in', 'Check out', 'Device', 'Status']
  const rows = filteredAttendanceRows.value.map(a => [
    employeeName(a.employeeId),
    a.date,
    a.checkIn ?? '',
    a.checkOut ?? '',
    a.device ?? '',
    a.status ?? ''
  ])
  const csv = [header, ...rows]
    .map(row => row.map(value => `"${String(value).replaceAll('"', '""')}"`).join(','))
    .join('\n')
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = `attendance-report-${todayKey()}.csv`
  link.click()
  URL.revokeObjectURL(url)
}
</script>

<template>
  <div v-if="canAccessAttendance" class="space-y-6">
    <div class="flex items-start justify-between gap-4">
      <div>
        <div class="text-sm text-slate-600 mt-1">Team attendance oversight and export</div>
      </div>
      <button @click="exportAttendanceReport" class="bg-emerald-600 text-white text-sm font-medium rounded-lg px-4 py-2 hover:bg-emerald-700 transition-colors shadow-sm">
        Export Excel
      </button>
    </div>

    <div v-if="loadError" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
      {{ loadError }}
    </div>

    <section class="grid grid-cols-2 lg:grid-cols-4 gap-4">
      <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
        <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Records</div>
        <div class="text-2xl font-extrabold text-slate-900 mt-2">{{ attendanceSummary.total }}</div>
      </div>
      <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
        <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Present</div>
        <div class="text-2xl font-extrabold text-emerald-600 mt-2">{{ attendanceSummary.present }}</div>
      </div>
      <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
        <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Late</div>
        <div class="text-2xl font-extrabold text-orange-600 mt-2">{{ attendanceSummary.late }}</div>
      </div>
      <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
        <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Active now</div>
        <div class="text-2xl font-extrabold text-blue-600 mt-2">{{ attendanceSummary.active }}</div>
      </div>
    </section>

    <div v-if="!canAccessAttendance" class="bg-white border border-slate-200 rounded-xl p-5 flex items-center justify-between shadow-sm">
      <div>
        <div class="text-sm font-medium text-slate-900 mb-1">Your check-in</div>
        <div class="text-xs text-slate-600" v-if="!checkedIn">Camera capture is required before check-in</div>
        <div class="text-xs text-slate-600" v-else>Checked in at <span class="font-mono text-teal">{{ checkInTime }}</span></div>
        <div v-if="punchError" class="text-xs text-rose-600 mt-1">{{ punchError }}</div>
      </div>
      <button
        v-if="checkedIn"
        @click="checkOut"
        :disabled="punching"
        class="flex items-center gap-2 text-sm font-medium rounded-lg px-4 py-2 transition-colors bg-rose-100 text-rose-700 hover:bg-rose-200 disabled:opacity-60 disabled:cursor-not-allowed"
      >
        <svg viewBox="0 0 24 24" class="w-4 h-4" fill="currentColor"><path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2ZM9 6c0-1.66 1.34-3 3-3s3 1.34 3 3v2H9V6Z"/></svg>
        Check out
      </button>
      <button
        v-else
        @click="cameraRequired ? openCamera() : checkInWithPhoto()"
        :disabled="punching"
        class="flex items-center gap-2 text-sm font-medium rounded-lg px-4 py-2 transition-colors bg-emerald-600 text-white hover:bg-emerald-700 disabled:opacity-60 disabled:cursor-not-allowed"
      >
        <svg viewBox="0 0 24 24" class="w-4 h-4" fill="currentColor"><path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2ZM9 6c0-1.66 1.34-3 3-3s3 1.34 3 3v2H9V6Z"/></svg>
        Check in
      </button>
    </div>

    <div v-if="!canAccessAttendance" class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
      <div class="flex items-center justify-between gap-3 mb-3">
        <div>
          <div class="text-sm font-medium text-slate-900">Today's attendance</div>
          <div class="text-xs text-slate-600">{{ currentDateLabel() }}</div>
        </div>
        <div class="text-xs text-slate-500">{{ todayAttendance ? 'Record saved with photo' : 'No check-in yet' }}</div>
      </div>
      <div v-if="myAttendanceRows.length" class="space-y-3">
        <div v-for="row in myAttendanceRows" :key="row.id" class="flex items-center justify-between gap-3 rounded-lg border border-slate-200 p-3">
          <div class="flex items-center gap-3 min-w-0">
            <div class="w-12 h-12 rounded-lg overflow-hidden bg-slate-100 border border-slate-200 flex items-center justify-center shrink-0">
              <img v-if="row.photo" :src="row.photo" alt="Check-in photo" class="w-full h-full object-cover" />
              <span v-else class="text-[10px] text-slate-500">{{ a.hasCheckInPhoto ? 'Loading...' : 'No photo' }}</span>
            </div>
            <div class="min-w-0">
              <div class="text-sm font-semibold text-slate-900">{{ row.checkOut ? 'Checked out' : 'Checked in' }}</div>
              <div class="text-xs text-slate-600">In: <span class="font-mono text-slate-900">{{ row.checkIn }}</span> · Out: <span class="font-mono text-slate-900">{{ row.checkOut ?? '—' }}</span></div>
            </div>
          </div>
          <span class="text-[11px] px-2 py-1 rounded-full" :class="row.status === 'late' ? 'bg-rose-100 text-rose-700' : 'bg-emerald-100 text-emerald-700'">
            {{ row.status === 'late' ? 'Late' : 'On time' }}
          </span>
        </div>
      </div>
      <div v-else class="text-sm text-slate-500 italic">No attendance record for today.</div>
    </div>


    <div v-if="canViewTeam" class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm">
      <div class="table-tools">
        <input v-model="attendanceTable.query.value" type="search" placeholder="Filter attendance..." class="table-filter-input" />
        <select v-model="attendanceDateFilter" class="table-filter-input max-w-[180px]">
          <option value="all">All dates</option>
          <option v-for="date in attendanceDates" :key="date" :value="date">{{ date }}</option>
        </select>
        <select v-model="attendanceStatusFilter" class="table-filter-input max-w-[180px]">
          <option value="all">All statuses</option>
          <option value="present">Present</option>
          <option value="late">Late</option>
        </select>
        <select v-model="attendancePresenceFilter" class="table-filter-input max-w-[190px]">
          <option value="all">All punch states</option>
          <option value="checked-in">Checked in</option>
          <option value="active">Active now</option>
          <option value="missing-checkout">Missing checkout</option>
        </select>
        <select v-model="attendanceEmployeeFilter" class="table-filter-input max-w-[220px]">
          <option value="all">All employees</option>
          <option v-for="employee in state.employees" :key="employee.id" :value="employee.id">{{ employee.name }}</option>
        </select>
        <div class="table-count">{{ attendanceTable.filteredRows.value.length }} records</div>
      </div>
      <div class="table-scroll" @scroll="attendanceTable.loadMoreRows">
      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-slate-200 text-left bg-slate-50/80">
            <th class="px-4 py-3 font-medium text-slate-600 text-xs uppercase tracking-wide">Employee</th>
            <th class="px-4 py-3 font-medium text-slate-600 text-xs uppercase tracking-wide">Check in</th>
            <th class="px-4 py-3 font-medium text-slate-600 text-xs uppercase tracking-wide">Check out</th>
            <th class="px-4 py-3 font-medium text-slate-600 text-xs uppercase tracking-wide">Device</th>
            <th class="px-4 py-3 font-medium text-slate-600 text-xs uppercase tracking-wide">Photo</th>
            <th class="px-4 py-3 font-medium text-slate-600 text-xs uppercase tracking-wide">Status</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="a in attendanceTable.visibleRows.value" :key="a.id" class="border-b border-slate-200 last:border-0 hover:bg-slate-50/60 transition-colors">
            <td class="px-4 py-3 text-slate-900 font-medium">{{ state.employees.find(e => e.id === a.employeeId)?.name }}</td>
            <td class="px-4 py-3 font-mono text-xs text-slate-700">{{ a.checkIn }}</td>
            <td class="px-4 py-3 font-mono text-xs text-slate-700">{{ a.checkOut ?? '—' }}</td>
            <td class="px-4 py-3 text-slate-900 capitalize">{{ a.device }}</td>
            <td class="px-4 py-2.5">
              <div class="w-10 h-10 rounded-lg overflow-hidden bg-slate-100 border border-slate-200 flex items-center justify-center">
                <img v-if="photoUrls[a.id]" :src="photoUrls[a.id]" alt="check-in photo" class="w-full h-full object-cover cursor-zoom-in hover:opacity-80 transition-opacity" @click="openPhoto(a)" />
                <span v-else class="text-[10px] text-slate-500">No photo</span>
              </div>
            </td>
            <td class="px-4 py-2.5">
              <span
                class="text-[11px] px-2 py-0.5 rounded"
                :class="a.status === 'late' ? 'bg-rose-100 text-rose-700' : 'bg-emerald-100 text-emerald-700'"
              >
                {{ a.status === 'late' ? 'Late' : 'On time' }}
              </span>
            </td>
          </tr>
        </tbody>
      </table>
      </div>
    </div>

    <Teleport to="body">
      <div v-if="showCameraModal" class="fixed inset-0 z-50 bg-black/40 backdrop-blur-[2px] flex items-center justify-center px-4 py-4 overflow-y-auto" @click.self="closeCamera">
        <div class="w-full max-w-lg rounded-2xl bg-white shadow-2xl border border-slate-200 overflow-hidden">
          <div class="p-4 border-b border-slate-200 flex items-center justify-between gap-3">
            <div>
              <div class="text-sm font-semibold text-slate-900">Camera check-in</div>
              <div class="text-xs text-slate-600">Take a photo before marking attendance</div>
            </div>
            <button @click="closeCamera" class="text-slate-500 hover:text-slate-900 text-lg leading-none">âœ•</button>
          </div>
          <div class="p-4 space-y-3">
            <div class="rounded-xl overflow-hidden bg-slate-100 border border-slate-200 aspect-[4/3] flex items-center justify-center">
              <video ref="videoRef" autoplay playsinline class="w-full h-full object-cover"></video>
              <div v-if="cameraLoading" class="absolute text-sm text-slate-600">Starting camera...</div>
            </div>
            <div v-if="cameraError || punchError" class="text-sm text-rose-600 bg-rose-50 border border-rose-200 rounded-lg px-3 py-2">{{ cameraError || punchError }}</div>
            <div class="flex items-center justify-end gap-2">
              <button @click="closeCamera" class="text-sm border border-slate-200 rounded-lg px-4 py-2 hover:bg-slate-50 transition-colors text-slate-700 font-medium">Cancel</button>
              <button @click="checkInWithPhoto" :disabled="punching" class="bg-emerald-600 text-white text-sm font-medium rounded-lg px-4 py-2 hover:bg-emerald-700 transition-colors disabled:opacity-60 disabled:cursor-not-allowed">{{ punching ? 'Checking in...' : 'Capture &amp; check in' }}</button>
            </div>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Full-size check-in photo. Teleported so the backdrop is not clipped by
         the table's overflow, and closed on backdrop click or Escape. -->
    <Teleport to="body">
      <div
        v-if="viewingPhoto"
        class="fixed inset-0 z-50 bg-black/70 backdrop-blur-sm flex items-center justify-center p-4"
        @click.self="viewingPhoto = null"
      >
        <div class="max-w-lg w-full rounded-2xl bg-white border border-slate-200 shadow-2xl overflow-hidden">
          <div class="flex items-start justify-between gap-4 px-5 py-4 border-b border-slate-200">
            <div class="min-w-0">
              <div class="text-[11px] uppercase tracking-[0.18em] text-slate-500 font-semibold">Check-in photo</div>
              <div class="text-lg font-bold text-slate-900 truncate">{{ viewingPhoto.employeeName ?? employeeName(viewingPhoto.employeeId) }}</div>
              <div class="text-xs text-slate-600 mt-0.5">
                {{ formatDate(viewingPhoto.date) }} &middot; checked in {{ viewingPhoto.checkIn }}
              </div>
            </div>
            <button @click="viewingPhoto = null" class="text-slate-500 hover:text-slate-900 text-xl leading-none shrink-0">&times;</button>
          </div>
          <img :src="photoUrls[viewingPhoto.id]" alt="Check-in photo" class="w-full max-h-[70vh] object-contain bg-slate-900" />
        </div>
      </div>
    </Teleport>
  </div>
  <div v-else class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
    <div class="text-lg font-semibold text-slate-900">Attendance is handled on your dashboard</div>
    <div class="text-sm text-slate-600 mt-1">This page is available only for HR and super admin oversight.</div>
  </div>
</template>
