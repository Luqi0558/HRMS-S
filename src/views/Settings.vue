<script setup>
import { computed, onMounted, ref } from 'vue'
import { useStore } from '../store/index.js'
import ConfirmDialog from '../components/ConfirmDialog.vue'
import { settingsService } from '../services/settingsService.js'

const { state, can, logActivity, pushNotification, setServerSettings } = useStore()

/**
 * Which sections are stored server-side, keyed by the label the save buttons
 * pass. The rest (Company, Petty cash) are still local and belong to later
 * phases — saving those stays an in-memory change until their API exists.
 */
const SERVER_SECTIONS = {
  Attendance: 'attendance',
  'Leave policy': 'leave',
  'Claims and finance': 'claims'
}

const saving = ref('')

const canAccess = computed(() => can('Settings', 'view'))
const newDesignation = ref('')
const newStack = ref('')
const designationPresets = [
  'Junior Software Engineer',
  'Associate Software Engineer',
  'Software Engineer',
  'Senior Software Engineer',
  'Team Lead',
  'Project Manager',
  'QA Engineer',
  'Senior QA Engineer',
  'UI/UX Designer',
  'DevOps Engineer',
  'Bidder',
  'Intern'
]
const stackPresets = ['MERN', 'MEAN', '.NET', 'Angular', 'React', 'Vue', 'Laravel', 'Node.js', 'QA Automation', 'DevOps', 'UI/UX']

// Settings drive real behaviour — grace minutes decide who is marked late,
// thresholds decide what needs approval — so a bad value silently corrupts
// those rules. Previously every save was accepted without any check.
const settingsError = ref('')
const settingsSaved = ref('')

// The server holds attendance, leave and claims; the rest of state.settings is
// still local, so this merges those three in rather than replacing the object.
onMounted(async () => {
  try {
    setServerSettings(await settingsService.all())
  } catch (error) {
    settingsError.value = error?.message || 'Unable to load company settings.'
  }
})

function nonNegative(value) {
  const number = Number(value)
  return !Number.isNaN(number) && number >= 0
}

function validateSection(section) {
  const { company, attendance, leave, claims, pettyCash } = state.settings

  if (section === 'Company') {
    if (!String(company.name || '').trim()) return 'Company name cannot be empty.'
    if (!String(company.timezone || '').trim()) return 'Timezone cannot be empty.'
  }

  if (section === 'Attendance') {
    if (!attendance.startTime) return 'Start time is required.'
    if (!nonNegative(attendance.graceMinutes)) return 'Grace minutes cannot be negative.'
    if (Number(attendance.graceMinutes) > 240) return 'Grace minutes looks too large — use 240 or less.'
    if (attendance.endTime && attendance.endTime <= attendance.startTime) {
      return 'End time must be after start time.'
    }
  }

  if (section === 'Leave policy') {
    for (const [key, label] of [['annualDays', 'Annual days'], ['sickDays', 'Sick days'], ['casualDays', 'Casual days']]) {
      if (!nonNegative(leave[key])) return `${label} cannot be negative.`
      if (Number(leave[key]) > 365) return `${label} cannot exceed 365.`
    }
  }

  if (section === 'Claims and finance') {
    if (!nonNegative(claims.annualMedicalLimit)) return 'Annual medical limit cannot be negative.'
    // Capped at 7 digits: the dashboard stat tiles are sized for values below
    // 1,000,000, and a larger limit would overflow those cards.
    if (Number(claims.annualMedicalLimit) >= 1000000) return 'Annual medical limit must be under 1,000,000.'
    if (!nonNegative(claims.receiptRequiredAbove)) return 'Receipt threshold cannot be negative.'
    if (Number(claims.receiptRequiredAbove) > Number(claims.annualMedicalLimit) && Number(claims.annualMedicalLimit) > 0) {
      return 'Receipt threshold cannot exceed the annual medical limit.'
    }
  }

  if (section === 'Petty cash') {
    if (!nonNegative(pettyCash.approvalThreshold)) return 'Approval threshold cannot be negative.'
    if (!nonNegative(pettyCash.lowBalanceThreshold)) return 'Low balance warning cannot be negative.'
  }

  return ''
}

async function saveSettings(section) {
  settingsSaved.value = ''
  settingsError.value = validateSection(section)
  if (settingsError.value) return

  // Attendance, leave and claims are policy the API enforces — grace minutes
  // decide who is marked late, the allowance decides which claims are
  // refused — so those must reach the server. Company and petty cash have no
  // endpoint yet and stay in memory.
  const group = SERVER_SECTIONS[section]
  if (group) {
    saving.value = section
    try {
      setServerSettings({ [group]: await settingsService.putGroup(group, state.settings[group]) })
    } catch (error) {
      settingsError.value = error?.message || `Unable to save ${section.toLowerCase()} settings.`
      return
    } finally {
      saving.value = ''
    }
  }

  logActivity({
    module: 'settings',
    action: 'settings updated',
    details: `${section} settings were updated.`
  })
  pushNotification({
    title: 'Settings updated',
    message: `${section} settings were updated by ${state.currentUser.name}.`,
    type: 'settings',
    roles: ['super_admin', 'hr', 'manager'],
    route: { name: 'settings' }
  })
  settingsSaved.value = `${section} settings saved.`
}

function addDesignation() {
  const value = newDesignation.value.trim()
  if (!value || state.settings.designations.includes(value)) return
  state.settings.designations.push(value)
  logActivity({ module: 'settings', action: 'designation added', details: `${value} was added to designations.` })
  newDesignation.value = ''
}

// Designations and stacks are referenced by existing employee records, so
// removal is confirmed rather than instant.
const pendingRemoval = ref(null)

function askRemoveDesignation(value) {
  const inUse = state.employees.filter(employee => employee.department === value).length
  pendingRemoval.value = {
    kind: 'designation',
    value,
    title: 'Remove designation?',
    message: inUse > 0
      ? `${value} is currently assigned to ${inUse} ${inUse === 1 ? 'employee' : 'employees'}. Removing it will not change their profiles, but it will no longer be selectable.`
      : `${value} will no longer be selectable when inviting or editing employees.`
  }
}

function askRemoveStack(value) {
  pendingRemoval.value = {
    kind: 'stack',
    value,
    title: 'Remove stack?',
    message: `${value} will no longer be selectable during onboarding.`
  }
}

function confirmRemoval() {
  const request = pendingRemoval.value
  if (!request) return
  if (request.kind === 'designation') {
    state.settings.designations = state.settings.designations.filter(item => item !== request.value)
    logActivity({ module: 'settings', action: 'designation removed', details: `${request.value} was removed from designations.` })
  } else {
    state.settings.stacks = state.settings.stacks.filter(item => item !== request.value)
    logActivity({ module: 'settings', action: 'stack removed', details: `${request.value} was removed from onboarding stacks.` })
  }
  pendingRemoval.value = null
}

function addStack() {
  const value = newStack.value.trim()
  if (!value || state.settings.stacks.includes(value)) return
  state.settings.stacks.push(value)
  logActivity({ module: 'settings', action: 'stack added', details: `${value} was added to onboarding stacks.` })
  newStack.value = ''
}


function addPresetDesignation(value) {
  if (state.settings.designations.includes(value)) return
  state.settings.designations.push(value)
  logActivity({ module: 'settings', action: 'designation preset added', details: `${value} was added to designations.` })
}

function addPresetStack(value) {
  if (state.settings.stacks.includes(value)) return
  state.settings.stacks.push(value)
  logActivity({ module: 'settings', action: 'stack preset added', details: `${value} was added to onboarding stacks.` })
}
</script>

<template>
  <div v-if="canAccess" class="space-y-6">
    <div>
      <div class="text-sm text-slate-600 mt-1">Configure company policies, approval rules, and onboarding options.</div>
    </div>

    <div v-if="settingsError" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">
      {{ settingsError }}
    </div>
    <div v-else-if="settingsSaved" class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">
      {{ settingsSaved }}
    </div>

    <section class="grid grid-cols-1 xl:grid-cols-2 gap-4">
      <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm">
        <div class="text-lg font-bold text-slate-900">Company</div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
          <div>
            <label class="block">
            <span class="text-sm font-medium text-slate-700 block mb-1.5">Company name</span>
            <input v-model="state.settings.company.name" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500" />
            </label>
          </div>
          <div>
            <label class="block">
            <span class="text-sm font-medium text-slate-700 block mb-1.5">Timezone</span>
            <input v-model="state.settings.company.timezone" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500" />
            </label>
          </div>
          <div class="md:col-span-2">
            <label class="block">
            <span class="text-sm font-medium text-slate-700 block mb-1.5">Work week
              <span class="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-amber-700">Backend required</span></span>
            <input v-model="state.settings.company.workWeek" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500" />
            </label>
          </div>
        </div>
        <button @click="saveSettings('Company')" class="mt-4 bg-emerald-600 text-white text-sm font-medium rounded-lg px-4 py-2 hover:bg-emerald-700 transition-colors">Save company</button>
      </div>

      <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm">
        <div class="text-lg font-bold text-slate-900">Attendance</div>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
          <div>
            <label class="block">
            <span class="text-sm font-medium text-slate-700 block mb-1.5">Start time</span>
            <input v-model="state.settings.attendance.startTime" type="time" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500" />
            </label>
          </div>
          <div>
            <label class="block">
            <span class="text-sm font-medium text-slate-700 block mb-1.5">End time
              <span class="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-amber-700">Backend required</span></span>
            <input v-model="state.settings.attendance.endTime" type="time" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500" />
            </label>
          </div>
          <div>
            <label class="block">
            <span class="text-sm font-medium text-slate-700 block mb-1.5">Grace minutes</span>
            <input v-model.number="state.settings.attendance.graceMinutes" type="number" min="0" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500" />
            </label>
          </div>
        </div>
        <label class="mt-4 flex items-center gap-2 text-sm font-medium text-slate-700">
          <input v-model="state.settings.attendance.cameraCheckinRequired" type="checkbox" class="rounded border-slate-300" />
          Camera check-in required
        </label>
        <button @click="saveSettings('Attendance')" :disabled="saving === 'Attendance'" class="mt-4 bg-emerald-600 text-white text-sm font-medium rounded-lg px-4 py-2 hover:bg-emerald-700 transition-colors disabled:opacity-60 disabled:cursor-not-allowed">{{ saving === 'Attendance' ? 'Saving...' : 'Save attendance' }}</button>
      </div>

      <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm">
        <div class="text-lg font-bold text-slate-900">Leave Policy</div>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
          <div>
            <label class="text-sm font-medium text-slate-700 block mb-1.5">Annual days</label>
            <input v-model.number="state.settings.leave.annualDays" type="number" min="0" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500" />
          </div>
          <div>
            <label class="block">
            <span class="text-sm font-medium text-slate-700 block mb-1.5">Sick days</span>
            <input v-model.number="state.settings.leave.sickDays" type="number" min="0" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500" />
            </label>
          </div>
          <div>
            <label class="block">
            <span class="text-sm font-medium text-slate-700 block mb-1.5">Casual days</span>
            <input v-model.number="state.settings.leave.casualDays" type="number" min="0" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500" />
            </label>
          </div>
        </div>
        <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-3">
          <label class="flex items-center gap-2 text-sm font-medium text-slate-700">
            <input v-model="state.settings.leave.halfDayEnabled" type="checkbox" class="rounded border-slate-300" />
            Half-day leave enabled
          </label>
          <label class="flex items-center gap-2 text-sm font-medium text-slate-700">
            <input v-model="state.settings.leave.twoStepApproval" type="checkbox" class="rounded border-slate-300" />
            Two-step approval required
          </label>
        </div>
        <button @click="saveSettings('Leave policy')" :disabled="saving === 'Leave policy'" class="mt-4 bg-emerald-600 text-white text-sm font-medium rounded-lg px-4 py-2 hover:bg-emerald-700 transition-colors disabled:opacity-60 disabled:cursor-not-allowed">{{ saving === 'Leave policy' ? 'Saving...' : 'Save leave policy' }}</button>
      </div>

      <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm">
        <div class="text-lg font-bold text-slate-900">Claims & Finance</div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
          <div>
            <label class="text-sm font-medium text-slate-700 block mb-1.5">Annual medical limit</label>
            <input v-model.number="state.settings.claims.annualMedicalLimit" type="number" min="0" max="999999" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500" />
            <div class="text-xs text-slate-500 mt-1">Maximum 999,999.</div>
          </div>
          <div>
            <label class="block">
            <span class="text-sm font-medium text-slate-700 block mb-1.5">Receipt required above</span>
            <input v-model.number="state.settings.claims.receiptRequiredAbove" type="number" min="0" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500" />
            </label>
          </div>
          <div>
            <label class="block">
            <span class="text-sm font-medium text-slate-700 block mb-1.5">Default commission type
              <span class="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-amber-700">Backend required</span></span>
            <select v-model="state.settings.finance.defaultCommissionType" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500">
              <option value="percent">Percent</option>
              <option value="fixed">Fixed</option>
            </select>
            </label>
          </div>
          <div>
            <label class="block">
            <span class="text-sm font-medium text-slate-700 block mb-1.5">Default commission value</span>
            <input v-model.number="state.settings.finance.defaultCommissionValue" type="number" min="0" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500" />
            </label>
          </div>
        </div>
        <label class="mt-4 flex items-center gap-2 text-sm font-medium text-slate-700">
          <input v-model="state.settings.finance.superAdminApprovalRequired" type="checkbox" class="rounded border-slate-300" />
          Super Admin approval required for commission
          <span class="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-amber-700">Backend required</span>
        </label>
        <button @click="saveSettings('Claims and finance')" :disabled="saving === 'Claims and finance'" class="mt-4 bg-emerald-600 text-white text-sm font-medium rounded-lg px-4 py-2 hover:bg-emerald-700 transition-colors disabled:opacity-60 disabled:cursor-not-allowed">{{ saving === 'Claims and finance' ? 'Saving...' : 'Save claims & finance' }}</button>
      </div>

      <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm">
        <div class="text-lg font-bold text-slate-900">Petty Cash</div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
          <div>
            <label class="text-sm font-medium text-slate-700 block mb-1.5">Approval required above</label>
            <input v-model.number="state.settings.pettyCash.approvalThreshold" type="number" min="0" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500" />
            <div class="text-xs text-slate-500 mt-1">Expenses over this wait for Super Admin sign-off.</div>
          </div>
          <div>
            <label class="block">
            <span class="text-sm font-medium text-slate-700 block mb-1.5">Low balance warning</span>
            <input v-model.number="state.settings.pettyCash.lowBalanceThreshold" type="number" min="0" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500" />
            </label>
            <div class="text-xs text-slate-500 mt-1">Prompts a top-up before the tin runs dry.</div>
          </div>
        </div>
        <button @click="saveSettings('Petty cash')" class="mt-4 bg-emerald-600 text-white text-sm font-medium rounded-lg px-4 py-2 hover:bg-emerald-700 transition-colors">Save petty cash</button>
      </div>
    </section>

    <section class="grid grid-cols-1 xl:grid-cols-2 gap-4">
      <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm">
        <div class="text-lg font-bold text-slate-900">Designations</div>
        <div class="flex gap-2 mt-4">
          <input v-model="newDesignation" placeholder="Add designation" @keyup.enter="addDesignation" class="flex-1 rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500" />
          <button @click="addDesignation" class="bg-slate-950 text-white text-sm font-medium rounded-lg px-4 py-2 hover:bg-black transition-colors">Add</button>
        </div>
        <div class="flex flex-wrap gap-2 mt-4">
          <span v-for="designation in state.settings.designations" :key="designation" class="settings-chip inline-flex items-center gap-2 rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-700">
            {{ designation }}
            <button @click="askRemoveDesignation(designation)" class="text-slate-500 hover:text-rose-600">x</button>
          </span>
        </div>
        <div class="mt-5 border-t border-slate-200 pt-4">
          <div class="text-xs font-bold uppercase tracking-wide text-slate-500 mb-3">Recommended software company roles</div>
          <div class="flex flex-wrap gap-2">
            <button
              v-for="preset in designationPresets"
              :key="preset"
              @click="addPresetDesignation(preset)"
              :disabled="state.settings.designations.includes(preset)"
              class="rounded-full border border-slate-200 px-3 py-1 text-xs font-bold text-slate-700 hover:bg-slate-50 disabled:opacity-45 disabled:cursor-not-allowed transition-colors"
            >
              {{ preset }}
            </button>
          </div>
        </div>
      </div>

      <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm">
        <div class="text-lg font-bold text-slate-900">Developer Stacks</div>
        <div class="flex gap-2 mt-4">
          <input v-model="newStack" placeholder="Add stack" @keyup.enter="addStack" class="flex-1 rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500" />
          <button @click="addStack" class="bg-slate-950 text-white text-sm font-medium rounded-lg px-4 py-2 hover:bg-black transition-colors">Add</button>
        </div>
        <div class="flex flex-wrap gap-2 mt-4">
          <span v-for="stack in state.settings.stacks" :key="stack" class="settings-chip inline-flex items-center gap-2 rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-700">
            {{ stack }}
            <button @click="askRemoveStack(stack)" class="text-slate-500 hover:text-rose-600">x</button>
          </span>
        </div>
        <div class="mt-5 border-t border-slate-200 pt-4">
          <div class="text-xs font-bold uppercase tracking-wide text-slate-500 mb-3">Recommended developer stacks</div>
          <div class="flex flex-wrap gap-2">
            <button
              v-for="preset in stackPresets"
              :key="preset"
              @click="addPresetStack(preset)"
              :disabled="state.settings.stacks.includes(preset)"
              class="rounded-full border border-slate-200 px-3 py-1 text-xs font-bold text-slate-700 hover:bg-slate-50 disabled:opacity-45 disabled:cursor-not-allowed transition-colors"
            >
              {{ preset }}
            </button>
          </div>
        </div>
      </div>
    </section>

    <ConfirmDialog
      :open="!!pendingRemoval"
      :title="pendingRemoval?.title || ''"
      :message="pendingRemoval?.message || ''"
      confirm-label="Remove"
      @confirm="confirmRemoval"
      @cancel="pendingRemoval = null"
    />
  </div>

  <div v-else class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
    <div class="text-lg font-semibold text-slate-900">Settings are available for Super Admin only</div>
  </div>
</template>
