<script setup>
import { computed, onMounted, ref } from 'vue'
import { useStore } from '../store/index.js'
import ConfirmDialog from '../components/ConfirmDialog.vue'
import { settingsService } from '../services/settingsService.js'

const { state, can, logActivity, setServerSettings } = useStore()

/**
 * Which sections are stored server-side, keyed by the label the save buttons
 * pass. Every section now persists; the map exists because the buttons pass a
 * human label and the API takes a group key.
 */
const SERVER_SECTIONS = {
  Company: ['company'],
  Attendance: ['attendance'],
  'Leave policy': ['leave'],
  'Claims and finance': ['claims', 'finance'],
  // The API has accepted this group since petty cash was built; the mapping
  // was missing, so the approval threshold appeared to save and reverted on
  // refresh — while the API went on enforcing the old value.
  'Petty cash': ['pettyCash'],
  Integrations: ['integrations']
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

// Merged per group rather than replacing state.settings wholesale: 'company',
// designations and stacks are not in this response, and a replace would erase
// them.
onMounted(async () => {
  // Integrations is a third call because it is not in /settings: that endpoint
  // is readable by everyone and the Teams webhook URL is a credential, so the
  // API serves it only behind the Settings permission. allSettled keeps the
  // page working for anyone who cannot read it.
  const [groups, lists, integrations] = await Promise.allSettled([
    settingsService.all(),
    settingsService.lists(),
    settingsService.integrations()
  ])

  if (groups.status === 'fulfilled') setServerSettings(groups.value)
  else settingsError.value = groups.reason?.message || 'Unable to load company settings.'

  // The dropdown options live in their own tables. They were seeded from the
  // start but never served, so this page carried hardcoded copies and every
  // add or removal was lost on refresh.
  if (lists.status === 'fulfilled') setServerSettings(lists.value)

  if (integrations.status === 'fulfilled') {
    setServerSettings({ integrations: integrations.value })
  }
})

function nonNegative(value) {
  const number = Number(value)
  return !Number.isNaN(number) && number >= 0
}

function validateSection(section) {
  const { company, attendance, leave, claims, finance, pettyCash } = state.settings

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
    if (!['percent', 'fixed'].includes(finance.defaultCommissionType)) return 'Choose a valid default commission type.'
    if (!nonNegative(finance.defaultCommissionValue)) return 'Default commission value cannot be negative.'
    if (finance.defaultCommissionType === 'percent' && Number(finance.defaultCommissionValue) > 100) {
      return 'Default commission percentage cannot exceed 100.'
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

  // These groups are policy the API enforces — grace minutes decide who is
  // marked late, the thresholds decide what needs approval — so a change that
  // only reached memory would leave the UI and the API disagreeing about the
  // rule.
  const groups = SERVER_SECTIONS[section] || []
  if (groups.length) {
    saving.value = section
    try {
      const savedGroups = await Promise.all(
        groups.map(async group => [group, await settingsService.putGroup(group, state.settings[group])])
      )
      setServerSettings(Object.fromEntries(savedGroups))
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
  settingsSaved.value = `${section} settings saved.`
}

async function addDesignation() {
  const value = newDesignation.value.trim()
  if (!value || state.settings.designations.includes(value)) return

  if (await addListItem('designations', value)) newDesignation.value = ''
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

async function confirmRemoval() {
  const request = pendingRemoval.value
  if (!request) return

  const type = request.kind === 'designation' ? 'designations' : 'stacks'

  settingsError.value = ''
  try {
    // Deactivated, not deleted: the employees who hold this designation keep
    // it on their record. The API says how many still do.
    const { lists } = await settingsService.removeListItem(type, request.value)
    setServerSettings(lists)
  } catch (error) {
    settingsError.value = error?.message || 'Unable to remove that option.'
    pendingRemoval.value = null
    return
  }

  logActivity({
    module: 'settings',
    action: request.kind === 'designation' ? 'designation removed' : 'stack removed',
    details: `${request.value} was removed from ${request.kind === 'designation' ? 'designations' : 'onboarding stacks'}.`
  })
  pendingRemoval.value = null
}

async function addStack() {
  const value = newStack.value.trim()
  if (!value || state.settings.stacks.includes(value)) return

  if (await addListItem('stacks', value)) newStack.value = ''
}

async function addListItem(type, value) {
  settingsError.value = ''
  try {
    setServerSettings(await settingsService.addListItem(type, value))
  } catch (error) {
    settingsError.value = error?.message || `Unable to add that ${type === 'stacks' ? 'stack' : 'designation'}.`
    return false
  }

  logActivity({
    module: 'settings',
    action: type === 'stacks' ? 'stack added' : 'designation added',
    details: `${value} was added to ${type === 'stacks' ? 'onboarding stacks' : 'designations'}.`
  })
  return true
}


async function addPresetDesignation(value) {
  if (state.settings.designations.includes(value)) return
  await addListItem('designations', value)
}

async function addPresetStack(value) {
  if (state.settings.stacks.includes(value)) return
  await addListItem('stacks', value)
}
</script>

<template>
  <div v-if="canAccess" class="orbit-page space-y-6">
    <div class="orbit-page-header">
      <div class="text-sm text-slate-600 mt-1">Configure company policies, approval rules, and onboarding options.</div>
    </div>

    <div v-if="settingsError" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">
      {{ settingsError }}
    </div>
    <div v-else-if="settingsSaved" class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">
      {{ settingsSaved }}
    </div>

    <section class="grid grid-cols-1 xl:grid-cols-2 gap-4">
      <div class="orbit-card p-5">
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
            <span class="text-sm font-medium text-slate-700 block mb-1.5">Work week</span>
            <input v-model="state.settings.company.workWeek" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500" />
            </label>
          </div>
        </div>
        <button @click="saveSettings('Company')" class="mt-4 bg-emerald-600 text-white text-sm font-medium rounded-lg px-4 py-2 hover:bg-emerald-700 transition-colors">Save company</button>
      </div>

      <div class="orbit-card p-5">
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
            <span class="text-sm font-medium text-slate-700 block mb-1.5">End time</span>
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

      <div class="orbit-card p-5">
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

      <div class="orbit-card p-5">
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
            <span class="text-sm font-medium text-slate-700 block mb-1.5">Default commission type</span>
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
        </label>
        <button @click="saveSettings('Claims and finance')" :disabled="saving === 'Claims and finance'" class="mt-4 bg-emerald-600 text-white text-sm font-medium rounded-lg px-4 py-2 hover:bg-emerald-700 transition-colors disabled:opacity-60 disabled:cursor-not-allowed">{{ saving === 'Claims and finance' ? 'Saving...' : 'Save claims & finance' }}</button>
      </div>

      <div class="orbit-card p-5">
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

      <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm mt-6">
        <div class="text-lg font-bold text-slate-900">Integrations</div>
        <div class="text-sm text-slate-600 mt-1">Post to Microsoft Teams channels.</div>
        <div class="text-xs text-slate-500 mt-2">
          Each URL comes from the channel it posts to: in Teams, channel &hellip; &rarr; Workflows &rarr;
          &ldquo;Post to a channel when a webhook request is received&rdquo;. A workflow is tied to the channel it was
          created in, so the two need separate URLs. Leave either blank to switch that one off.
        </div>
        <div class="grid grid-cols-1 gap-4 mt-4">
          <div>
            <label class="block">
              <span class="text-sm font-medium text-slate-700 block mb-1.5">Attendance channel</span>
              <input v-model="state.settings.integrations.teamsAttendanceWebhookUrl" type="url" placeholder="https://prod-00.westus.logic.azure.com/workflows/..." class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500" />
            </label>
            <div class="text-xs text-slate-500 mt-1">Check-outs, with work duration and effected hours.</div>
          </div>
          <div>
            <label class="block">
              <span class="text-sm font-medium text-slate-700 block mb-1.5">Announcements channel</span>
              <input v-model="state.settings.integrations.teamsAnnouncementsWebhookUrl" type="url" placeholder="https://prod-00.westus.logic.azure.com/workflows/..." class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500" />
            </label>
            <div class="text-xs text-slate-500 mt-1">Anything published from Company Hub.</div>
          </div>
        </div>
        <button @click="saveSettings('Integrations')" class="mt-4 bg-emerald-600 text-white text-sm font-medium rounded-lg px-4 py-2 hover:bg-emerald-700 transition-colors">Save integrations</button>
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
