<script setup>
import { computed, ref, onMounted } from 'vue'
import { profileService } from '../services/profileService.js'
import { employeeService } from '../services/employeeService.js'
import { ApiError } from '../services/apiClient.js'
import { useStore } from '../store/index.js'

const { state, roleLabel: formatRoleLabel, logActivity, setCurrentUser, can, documentsByKey } = useStore()

/**
 * Employment details — department, joining date, designation, employment type,
 * probation status — belong to whoever administers staff records, not to the
 * person the record describes.
 *
 * So they follow the Employees permission rather than being locked outright:
 * HR and super admins can already change these for anybody through the
 * Employees screen, and greying them out here only on their own profile would
 * be an inconsistency, not a safeguard. A manager (view-only) and an ordinary
 * employee (no access) still get them read-only.
 *
 * Email stays locked for everyone regardless — it is the login identity, and
 * UpdateEmployeeRequest deliberately refuses it on the HR route too.
 */
const canEditEmployment = computed(() => can('Employees', 'create'))

// Fetched from /profile, which every role can reach. The previous source was
// state.employees, populated from /employees — a route an ordinary employee is
// forbidden from calling, so their own onboarding details were always blank
// here. It also matched on `item.id === state.currentUser.id`, comparing an
// employee id against a user id, which only ever agreed by coincidence.
const ownEmployee = ref(null)

const employee = computed(() => ownEmployee.value)
const isBlocked = computed(() => employee.value?.status === 'blocked')
const isDeveloperProfile = computed(() => state.currentUser.role === 'employee')
const roleLabel = computed(() => formatRoleLabel(state.currentUser.role))

const onboarding = computed(() => employee.value?.onboarding || {})
const personal = computed(() => onboarding.value.personal || {})
const technical = computed(() => onboarding.value.technical || {})
const documents = computed(() => documentsByKey(employee.value))

// Built as a function rather than inline, because the record arrives from the
// API after setup has already run. Without a rebuild the form would stay bound
// to the empty values it was constructed with, and saving would post blanks
// over whatever the employee actually had.
const buildForm = () => ({
  name: state.currentUser.name || employee.value?.name || '',
  email: employee.value?.email || state.currentUser.email || '',
  department: employee.value?.department || '',
  birthday: employee.value?.birthday || '',
  joiningDate: employee.value?.joiningDate || '',
  phone: personal.value.phone || '',
  cnic: personal.value.cnic || '',
  emergencyContact: personal.value.emergencyContact || '',
  bankAccount: personal.value.bankAccount || '',
  taxNumber: personal.value.taxNumber || '',
  address: personal.value.address || '',
  designation: technical.value.designation || employee.value?.department || '',
  experienceYears: technical.value.experienceYears || '',
  employmentType: technical.value.employmentType || 'Full-time',
  probationStatus: technical.value.probationStatus || 'Confirmed',
  primaryStack: technical.value.primaryStack || '',
  stacks: [...(technical.value.stacks || [])],
  skills: [...(technical.value.skills || [])],
  skillLevels: { ...(technical.value.skillLevels || {}) },
  currentlyLearning: [...(technical.value.currentlyLearning || [])],
  githubUrl: technical.value.githubUrl || '',
  portfolioUrl: technical.value.portfolioUrl || '',
  linkedinUrl: technical.value.linkedinUrl || '',
  photo: documents.value.photo || null,
  cv: documents.value.cv || null,
  offerLetter: documents.value.offerLetter || null,
  contract: documents.value.contract || null,
  certificates: documents.value.certificates || null,
  experienceLetters: documents.value.experienceLetters || null
})

const profileForm = ref(buildForm())

const securityForm = ref({
  currentPassword: '',
  newPassword: '',
  confirmPassword: ''
})

const statusMessage = ref('')
const passwordMessage = ref('')
const passwordError = ref('')
const openDropdown = ref(null)

const coreTechnologyOptions = [
  'JavaScript',
  'TypeScript',
  'HTML',
  'CSS',
  'Vue',
  'React',
  'Angular',
  'Node.js',
  'Express.js',
  'NestJS',
  'Laravel',
  'PHP',
  '.NET',
  'C#',
  'Java',
  'Spring Boot',
  'Python',
  'Django',
  'SQL',
  'MySQL',
  'PostgreSQL',
  'MongoDB',
  'Redis',
  'REST APIs',
  'GraphQL',
  'Docker',
  'AWS',
  'Azure',
  'CI/CD',
  'Git',
  'Testing',
  'QA Automation',
  'UI Design'
]

// Settings is the single source of truth for both lists. These used to union
// the configured values with a hardcoded set, so Settings could neither remove
// an option nor account for one, and admins saw choices they had never added.
// Values already stored on this profile are still appended, so an option
// retired in Settings does not silently vanish from the record that uses it.
const stackOptions = computed(() =>
  Array.from(new Set([
    ...state.settings.stacks,
    ...profileForm.value.stacks,
    ...(profileForm.value.primaryStack ? [profileForm.value.primaryStack] : [])
  ])).sort()
)

const designationOptions = computed(() =>
  Array.from(new Set([
    ...state.settings.designations,
    ...(profileForm.value.designation ? [profileForm.value.designation] : [])
  ])).sort()
)

const profileCompletionItems = computed(() => [
  { label: 'Full name', complete: Boolean(profileForm.value.name) },
  { label: 'Office email', complete: Boolean(profileForm.value.email) },
  { label: 'Phone number', complete: Boolean(profileForm.value.phone) },
  { label: 'CNIC / National ID', complete: Boolean(profileForm.value.cnic) },
  { label: 'Emergency contact', complete: Boolean(profileForm.value.emergencyContact) },
  { label: 'Bank account / IBAN', complete: Boolean(profileForm.value.bankAccount) },
  { label: 'Residential address', complete: Boolean(profileForm.value.address) },
  { label: 'Current designation', complete: Boolean(profileForm.value.designation) },
  { label: 'Primary stack', complete: Boolean(profileForm.value.primaryStack) },
  { label: 'Developer stacks', complete: profileForm.value.stacks.length > 0 },
  { label: 'Core technologies', complete: profileForm.value.skills.length > 0 },
  { label: 'GitHub / LinkedIn', complete: Boolean(profileForm.value.githubUrl || profileForm.value.linkedinUrl) },
  { label: 'CV / Resume', complete: Boolean(profileForm.value.cv) }
])

const profileCompletion = computed(() => {
  const completed = profileCompletionItems.value.filter(item => item.complete).length
  return Math.round((completed / profileCompletionItems.value.length) * 100)
})

const missingProfileItems = computed(() =>
  profileCompletionItems.value.filter(item => !item.complete).slice(0, 4)
)

const initials = computed(() =>
  profileForm.value.name
    .split(' ')
    .filter(Boolean)
    .map(part => part[0])
    .join('')
    .slice(0, 2)
    .toUpperCase() || 'U'
)

function fileName(file) {
  if (!file) return 'No file selected'
  return file.name || String(file)
}

function toggleSelection(collection, value) {
  const list = profileForm.value[collection]
  if (list.includes(value)) {
    profileForm.value[collection] = list.filter(item => item !== value)
    return
  }
  profileForm.value[collection].push(value)
}

function removeSelection(collection, value) {
  profileForm.value[collection] = profileForm.value[collection].filter(item => item !== value)
  if (collection === 'skills') {
    delete profileForm.value.skillLevels[value]
  }
}

function toggleDropdown(name) {
  openDropdown.value = openDropdown.value === name ? null : name
}

const isSaving = ref(false)
const saveError = ref('')

/**
 * This used to mutate the store and announce success without contacting the
 * server at all, so every edit was lost on the next refresh while the page
 * insisted it had saved.
 *
 * Only self-editable fields are sent. Department, designation, joining date,
 * employment type, probation status and email are HR's to set and are rejected
 * by the API regardless — they are rendered read-only above rather than being
 * posted and silently ignored.
 */
async function saveProfile() {
  if (isBlocked.value || isSaving.value) return

  const nextName = profileForm.value.name.trim()
  if (!nextName) {
    saveError.value = 'Name cannot be empty.'
    return
  }

  isSaving.value = true
  saveError.value = ''
  statusMessage.value = ''

  try {
    const saved = await profileService.update({
      name: nextName,
      birthday: profileForm.value.birthday || null,
      personal: {
        phone: profileForm.value.phone,
        cnic: profileForm.value.cnic,
        address: profileForm.value.address,
        emergencyContact: profileForm.value.emergencyContact,
        bankAccount: profileForm.value.bankAccount,
        taxNumber: profileForm.value.taxNumber
      },
      technical: {
        primaryStack: profileForm.value.primaryStack,
        experienceYears: profileForm.value.experienceYears === ''
          ? null
          : profileForm.value.experienceYears,
        stacks: [...profileForm.value.stacks],
        skills: [...profileForm.value.skills],
        skillLevels: { ...profileForm.value.skillLevels },
        currentlyLearning: [...profileForm.value.currentlyLearning],
        githubUrl: profileForm.value.githubUrl || null,
        portfolioUrl: profileForm.value.portfolioUrl || null,
        linkedinUrl: profileForm.value.linkedinUrl || null
      }
    })

    // The employment fields live on the employees table and are authorised by
    // the Employees module, so they go through that endpoint rather than
    // /profile — which refuses them by design, whoever is asking.
    const withEmployment = canEditEmployment.value && saved?.id
      ? await employeeService.update(saved.id, {
        department: profileForm.value.department || null,
        joiningDate: profileForm.value.joiningDate || null,
        designation: profileForm.value.designation || null,
        employmentType: profileForm.value.employmentType,
        probationStatus: profileForm.value.probationStatus
      })
      : saved

    // Files picked in this form. They were never sent before: the inputs
    // stored the File here and the save posted only text fields, then the
    // rebuild below threw the selection away.
    const pickedFiles = Object.fromEntries(
      ['photo', 'cv', 'offerLetter', 'contract', 'certificates', 'experienceLetters']
        .map(key => [key, profileForm.value[key]])
        .filter(([, file]) => file instanceof File)
    )
    const withDocuments = Object.keys(pickedFiles).length
      ? await profileService.uploadDocuments(pickedFiles)
      : withEmployment

    // Rebuilt from the response rather than from the form, so what is on screen
    // afterwards is what the server actually stored.
    ownEmployee.value = withDocuments
    profileForm.value = buildForm()

    setCurrentUser(
      state.currentUser.id,
      saved.name,
      state.currentUser.role,
      saved.email ?? state.currentUser.email,
      // Must be passed through: the parameter defaults to null, so omitting it
      // would clear the employee identity that "is this ticket mine?" and every
      // other self-scoped check depends on.
      state.currentUser.employeeId ?? saved.id ?? null
    )

    logActivity({
      module: 'profile',
      action: 'profile updated',
      details: `${saved.name} updated their profile details.`
    })
    statusMessage.value = 'Profile updated successfully.'
  } catch (error) {
    saveError.value = error instanceof ApiError
      ? error.message
      : 'Could not save your profile. Please check your connection and try again.'
  } finally {
    isSaving.value = false
  }
}

const changingPassword = ref(false)

/**
 * This validated the form, cleared it, and reported "Password updated for this
 * session" — without ever contacting the server. The wording was an admission:
 * nothing was persisted, so the next sign-in used the old password.
 */
async function updatePassword() {
  if (isBlocked.value || changingPassword.value) return
  passwordMessage.value = ''
  passwordError.value = ''

  if (!securityForm.value.currentPassword) {
    passwordError.value = 'Enter your current password.'
    return
  }
  if (!securityForm.value.newPassword || securityForm.value.newPassword.length < 8) {
    passwordError.value = 'New password must be at least 8 characters.'
    return
  }
  if (securityForm.value.newPassword !== securityForm.value.confirmPassword) {
    passwordError.value = 'New password and confirmation do not match.'
    return
  }

  changingPassword.value = true

  try {
    passwordMessage.value = await profileService.changePassword({
      currentPassword: securityForm.value.currentPassword,
      newPassword: securityForm.value.newPassword
    })
  } catch (error) {
    // The API answers 422 with a field message when the current password is
    // wrong, which is the case worth showing verbatim.
    passwordError.value = error instanceof ApiError
      ? (error.errors?.currentPassword?.[0] || error.errors?.newPassword?.[0] || error.message)
      : 'Could not update your password. Please try again.'
    return
  } finally {
    changingPassword.value = false
  }

  // Cleared only after it actually saved, so a failed attempt keeps what was
  // typed rather than making them start again.
  securityForm.value = { currentPassword: '', newPassword: '', confirmPassword: '' }

  logActivity({
    module: 'profile',
    action: 'password updated',
    details: `${state.currentUser.name} updated their password.`
  })
}

/**
 * The page resolves the signed-in user against the roster. Without loading it
 * that lookup fell through to seeded data.
 *
 * Non-fatal: roles reaching this page hold different permissions, and one 403
 * should not blank the whole view.
 */
onMounted(async () => {
  try {
    ownEmployee.value = await profileService.get()
    profileForm.value = buildForm()
  } catch (error) {
    // Unlike the old /employees call this is not expected to fail for anyone,
    // so a failure is worth showing rather than swallowing — otherwise the form
    // sits there empty looking like a profile with nothing in it.
    saveError.value = error instanceof ApiError
      ? error.message
      : 'Could not load your profile. Please refresh to try again.'
  }
})
</script>

<template>
  <div class="orbit-page space-y-6">
    <div class="orbit-page-header flex flex-col xl:flex-row xl:items-end justify-between gap-4">
      <div>
        <div class="text-sm text-slate-600 mt-1">Manage your account, contact, onboarding, and security details.</div>
      </div>
      <button v-if="!isBlocked" @click="saveProfile" :disabled="isSaving" class="orbit-button orbit-button-primary text-sm font-medium px-4 py-2">
        {{ isSaving ? 'Saving...' : 'Save profile' }}
      </button>
    </div>

    <section v-if="isBlocked" class="rounded-xl border border-rose-200 bg-rose-50 p-6 shadow-sm">
      <div class="text-lg font-extrabold text-rose-800">Profile access blocked</div>
      <div class="mt-2 text-sm leading-6 text-rose-700">
        Your profile has been blocked by administration. You cannot view or update profile details until HR or Super Admin restores access.
      </div>
    </section>

    <section v-else class="space-y-4">
      <div class="orbit-card p-5">
        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-5">
          <div class="flex items-center gap-4 min-w-0">
          <div class="w-16 h-16 rounded-2xl bg-slate-950 text-white flex items-center justify-center text-xl font-extrabold">
            {{ initials }}
          </div>
          <div class="min-w-0">
            <div class="text-lg font-extrabold text-slate-900 truncate">{{ profileForm.name }}</div>
            <div class="text-sm text-slate-600 truncate">{{ profileForm.email }}</div>
            <div class="profile-role-badge">{{ roleLabel }}</div>
          </div>
          </div>

        <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 lg:w-[420px]">
          <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
            <div class="text-[11px] uppercase tracking-wide text-slate-500 font-bold">Status</div>
            <div class="text-sm font-extrabold text-slate-900 mt-1 capitalize">{{ employee?.status || 'active' }}</div>
          </div>
          <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
            <div class="text-[11px] uppercase tracking-wide text-slate-500 font-bold">Role</div>
            <div class="text-sm font-extrabold text-slate-900 mt-1">{{ roleLabel }}</div>
          </div>
          <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
            <div class="text-[11px] uppercase tracking-wide text-slate-500 font-bold">Profile ID</div>
            <div class="text-sm font-extrabold text-slate-900 mt-1">#{{ state.currentUser.id }}</div>
          </div>
        </div>
        </div>

        <div v-if="statusMessage" class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-700">
          {{ statusMessage }}
        </div>
        <!-- A failed save used to be indistinguishable from a successful one,
             because nothing was ever sent. -->
        <div v-if="saveError" class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
          {{ saveError }}
        </div>
      </div>

      <!-- Effectiveness preview moved to the dashboard. -->
      <!-- Legacy effectiveness markup moved to EffectivenessSnapshot.
              <div class="text-xs text-slate-500 mt-1">{{ effectivenessPreview.confidence }} · preview only</div>
            </div>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div v-for="metric in effectivenessPreview.metrics" :key="metric.id" class="effectiveness-metric">
              <div class="flex items-start justify-between gap-3">
                <div>
                  <div class="text-sm font-bold text-slate-900">{{ metric.label }}</div>
                  <div class="text-xs text-slate-500 mt-1 leading-5">{{ metric.detail }}</div>
                </div>
                <div class="text-lg font-extrabold text-slate-900">{{ metric.score }}%</div>
              </div>
              <div class="effectiveness-progress mt-3"><div :style="{ width: metric.score + '%' }"></div></div>
            </div>
          </div>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-[minmax(0,1fr)_300px] gap-5 mt-5">
          <div class="effectiveness-trend-card">
            <div class="flex items-center justify-between gap-3">
              <div>
                <div class="text-sm font-bold text-slate-900">Six-month trend</div>
                <div class="text-xs text-slate-500 mt-1">Monthly snapshots will replace this preview when the backend is enabled.</div>
              </div>
              <span class="text-xs font-bold text-emerald-700">Improving</span>
            </div>
            <div class="effectiveness-bars">
              <div v-for="point in effectivenessPreview.trend" :key="point.label" class="effectiveness-bar-group">
                <div class="effectiveness-bar-value">{{ point.score }}</div>
                <div class="effectiveness-bar-track"><div class="effectiveness-bar" :style="{ height: point.score + '%' }"></div></div>
                <div class="text-[11px] font-semibold text-slate-500">{{ point.label }}</div>
              </div>
            </div>
          </div>

          <aside class="effectiveness-evidence">
            <div class="text-sm font-bold text-slate-900">How this will work</div>
            <ul class="mt-3 space-y-2 text-xs leading-5 text-slate-600">
              <li><span>1</span> Ticket delivery and deadlines</li>
              <li><span>2</span> Quality and returned work</li>
              <li><span>3</span> Ticket time against checked-in time</li>
              <li><span>4</span> Attendance, excluding approved leave</li>
            </ul>
            <div class="mt-4 rounded-lg bg-slate-100 px-3 py-2 text-xs font-semibold text-slate-600">Role-specific weights will be set by HR.</div>
          </aside>
        </div>
      -->

      <div v-if="isDeveloperProfile" class="grid grid-cols-1 gap-4">
        <section class="orbit-card p-5">
          <div class="flex items-start justify-between gap-4">
            <div>
              <div class="text-lg font-bold text-slate-900">Profile Completion</div>
              <div class="text-sm text-slate-600 mt-1">Keep this complete for HR, payroll, and project assignment.</div>
            </div>
            <div class="text-2xl font-extrabold text-slate-900">{{ profileCompletion }}%</div>
          </div>
          <div class="mt-4 h-3 rounded-full bg-slate-100 overflow-hidden border border-slate-200">
            <div class="h-full rounded-full bg-slate-950 transition-all" :style="{ width: `${profileCompletion}%` }"></div>
          </div>
          <div v-if="missingProfileItems.length" class="mt-4 space-y-2">
            <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Missing</div>
            <div class="flex flex-wrap gap-2">
              <span v-for="item in missingProfileItems" :key="item.label" class="company-hub-badge company-hub-badge-warning">
                {{ item.label }}
              </span>
            </div>
          </div>
          <div v-else class="mt-4 company-hub-badge company-hub-badge-success">Profile ready</div>
        </section>
      </div>

      <div class="space-y-4">
        <section class="orbit-card p-5">
          <div class="text-lg font-bold text-slate-900">Account Details</div>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
            <div>
              <label class="block">
              <span class="text-xs font-bold uppercase tracking-wide text-slate-500 block mb-1.5">Full name</span>
              <input v-model="profileForm.name" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500" />
              </label>
            </div>
            <div>
              <label class="block">
              <span class="text-xs font-bold uppercase tracking-wide text-slate-500 block mb-1.5">Office email</span>
              <input v-model="profileForm.email" type="email" class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-500 cursor-not-allowed" disabled />
              </label>
            </div>
            <div>
              <label class="block">
              <span class="text-xs font-bold uppercase tracking-wide text-slate-500 block mb-1.5">Department / designation</span>
              <select v-model="profileForm.department" :class="canEditEmployment ? 'w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500' : 'w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-500 cursor-not-allowed'" :disabled="!canEditEmployment">
                <option value="">Not specified</option>
                <option v-for="department in state.settings.designations" :key="department" :value="department">{{ department }}</option>
              </select>
              </label>
            </div>
            <div>
              <label class="block">
              <span class="text-xs font-bold uppercase tracking-wide text-slate-500 block mb-1.5">Joining date</span>
              <input v-model="profileForm.joiningDate" type="date" :class="canEditEmployment ? 'w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500' : 'w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-500 cursor-not-allowed'" :disabled="!canEditEmployment" />
              </label>
            </div>
            <div>
              <label class="block">
              <span class="text-xs font-bold uppercase tracking-wide text-slate-500 block mb-1.5">Birthday</span>
              <input v-model="profileForm.birthday" type="date" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500" />
              </label>
            </div>
            <div>
              <label class="block">
              <span class="text-xs font-bold uppercase tracking-wide text-slate-500 block mb-1.5">Phone number</span>
              <input v-model="profileForm.phone" type="tel" placeholder="+92 300 0000000" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500" />
              </label>
            </div>
          </div>
        </section>

        <section class="orbit-card p-5">
          <div class="text-lg font-bold text-slate-900">Personal Information</div>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
            <div>
              <label class="block">
              <span class="text-xs font-bold uppercase tracking-wide text-slate-500 block mb-1.5">CNIC / National ID</span>
              <input v-model="profileForm.cnic" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500" />
              </label>
            </div>
            <div>
              <label class="block">
              <span class="text-xs font-bold uppercase tracking-wide text-slate-500 block mb-1.5">Emergency contact</span>
              <input v-model="profileForm.emergencyContact" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500" />
              </label>
            </div>
            <div>
              <label class="block">
              <span class="text-xs font-bold uppercase tracking-wide text-slate-500 block mb-1.5">Bank account / IBAN</span>
              <input v-model="profileForm.bankAccount" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500" />
              </label>
            </div>
            <div>
              <label class="block">
              <span class="text-xs font-bold uppercase tracking-wide text-slate-500 block mb-1.5">Tax number</span>
              <input v-model="profileForm.taxNumber" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500" />
              </label>
            </div>
            <div class="md:col-span-2">
              <label class="block">
              <span class="text-xs font-bold uppercase tracking-wide text-slate-500 block mb-1.5">Residential address</span>
              <textarea v-model="profileForm.address" rows="3" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500"></textarea>
              </label>
            </div>
          </div>
        </section>

        <section class="orbit-card p-5">
          <div class="text-lg font-bold text-slate-900">Technical Profile</div>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
            <div>
              <label class="block">
              <span class="text-xs font-bold uppercase tracking-wide text-slate-500 block mb-1.5">Current designation</span>
              <select v-model="profileForm.designation" :class="canEditEmployment ? 'w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500' : 'w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-500 cursor-not-allowed'" :disabled="!canEditEmployment">
                <option value="">Select designation</option>
                <option v-for="designation in designationOptions" :key="designation" :value="designation">{{ designation }}</option>
              </select>
              </label>
            </div>
            <div>
              <label class="block">
              <span class="text-xs font-bold uppercase tracking-wide text-slate-500 block mb-1.5">Experience</span>
              <select v-model="profileForm.experienceYears" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500">
                <option value="">Not specified</option>
                <option value="0-1">0-1 years</option>
                <option value="1-3">1-3 years</option>
                <option value="3-5">3-5 years</option>
                <option value="5+">5+ years</option>
              </select>
              </label>
            </div>
            <div>
              <label class="block">
              <span class="text-xs font-bold uppercase tracking-wide text-slate-500 block mb-1.5">Primary stack</span>
              <select v-model="profileForm.primaryStack" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500">
                <option value="">Select primary stack</option>
                <option v-for="stack in stackOptions" :key="stack" :value="stack">{{ stack }}</option>
              </select>
              </label>
            </div>
            <div>
              <label class="block">
              <span class="text-xs font-bold uppercase tracking-wide text-slate-500 block mb-1.5">Employment type</span>
              <select v-model="profileForm.employmentType" :class="canEditEmployment ? 'w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500' : 'w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-500 cursor-not-allowed'" :disabled="!canEditEmployment">
                <option>Full-time</option>
                <option>Part-time</option>
                <option>Contract</option>
                <option>Internship</option>
                <option>Probation</option>
              </select>
              </label>
            </div>
            <div>
              <label class="block">
              <span class="text-xs font-bold uppercase tracking-wide text-slate-500 block mb-1.5">Probation status</span>
              <select v-model="profileForm.probationStatus" :class="canEditEmployment ? 'w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500' : 'w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-500 cursor-not-allowed'" :disabled="!canEditEmployment">
                <option>Confirmed</option>
                <option>On probation</option>
                <option>Extended probation</option>
                <option>Not applicable</option>
              </select>
              </label>
            </div>
            <div>
              <label class="text-xs font-bold uppercase tracking-wide text-slate-500 block mb-2">Developer stacks</label>
              <div class="relative">
                <div class="select2-field" @click="toggleDropdown('stacks')">
                  <div v-if="profileForm.stacks.length" class="select2-tags">
                    <button v-for="stack in profileForm.stacks" :key="stack" type="button" class="select2-tag" @click.stop="removeSelection('stacks', stack)">
                      {{ stack }}
                      <span>x</span>
                    </button>
                  </div>
                  <span v-else class="select2-placeholder">Select developer stacks</span>
                  <span class="select2-arrow">⌄</span>
                </div>
                <div v-if="openDropdown === 'stacks'" class="select2-menu">
                  <button
                    v-for="stack in stackOptions"
                    :key="stack"
                    type="button"
                    class="select2-option"
                    :class="{ selected: profileForm.stacks.includes(stack) }"
                    @click="toggleSelection('stacks', stack)"
                  >
                    <span>{{ stack }}</span>
                    <span v-if="profileForm.stacks.includes(stack)">✓</span>
                  </button>
                </div>
              </div>
            </div>
            <div>
              <label class="text-xs font-bold uppercase tracking-wide text-slate-500 block mb-2">Core technologies</label>
              <div class="relative">
                <div class="select2-field" @click="toggleDropdown('skills')">
                  <div v-if="profileForm.skills.length" class="select2-tags">
                    <button v-for="skill in profileForm.skills" :key="skill" type="button" class="select2-tag" @click.stop="removeSelection('skills', skill)">
                      {{ skill }}
                      <span>x</span>
                    </button>
                  </div>
                  <span v-else class="select2-placeholder">Select core technologies</span>
                  <span class="select2-arrow">⌄</span>
                </div>
                <div v-if="openDropdown === 'skills'" class="select2-menu">
                  <button
                    v-for="skill in coreTechnologyOptions"
                    :key="skill"
                    type="button"
                    class="select2-option"
                    :class="{ selected: profileForm.skills.includes(skill) }"
                    @click="toggleSelection('skills', skill)"
                  >
                    <span>{{ skill }}</span>
                    <span v-if="profileForm.skills.includes(skill)">✓</span>
                  </button>
                </div>
              </div>
            </div>
            <div>
              <label class="text-xs font-bold uppercase tracking-wide text-slate-500 block mb-2">Currently learning</label>
              <div class="relative">
                <div class="select2-field" @click="toggleDropdown('currentlyLearning')">
                  <div v-if="profileForm.currentlyLearning.length" class="select2-tags">
                    <button v-for="skill in profileForm.currentlyLearning" :key="skill" type="button" class="select2-tag" @click.stop="removeSelection('currentlyLearning', skill)">
                      {{ skill }}
                      <span>x</span>
                    </button>
                  </div>
                  <span v-else class="select2-placeholder">Select learning goals</span>
                  <span class="select2-arrow">v</span>
                </div>
                <div v-if="openDropdown === 'currentlyLearning'" class="select2-menu">
                  <button
                    v-for="skill in coreTechnologyOptions"
                    :key="skill"
                    type="button"
                    class="select2-option"
                    :class="{ selected: profileForm.currentlyLearning.includes(skill) }"
                    @click="toggleSelection('currentlyLearning', skill)"
                  >
                    <span>{{ skill }}</span>
                    <span v-if="profileForm.currentlyLearning.includes(skill)">Selected</span>
                  </button>
                </div>
              </div>
            </div>
            <div v-if="profileForm.skills.length" class="md:col-span-2 rounded-xl border border-slate-200 bg-slate-50 p-4">
              <div class="text-xs font-bold uppercase tracking-wide text-slate-500 mb-3">Technology proficiency</div>
              <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div v-for="skill in profileForm.skills" :key="skill" class="flex items-center justify-between gap-3 rounded-lg border border-slate-200 bg-white p-3">
                  <span class="text-sm font-bold text-slate-900">{{ skill }}</span>
                  <select v-model="profileForm.skillLevels[skill]" class="w-36 rounded-lg border border-slate-200 px-2 py-1.5 text-xs font-bold focus:outline-none focus:ring-1 focus:ring-emerald-500">
                    <option value="">Level</option>
                    <option>Beginner</option>
                    <option>Intermediate</option>
                    <option>Advanced</option>
                    <option>Expert</option>
                  </select>
                </div>
              </div>
            </div>
            <div>
              <label class="text-xs font-bold uppercase tracking-wide text-slate-500 block mb-1.5">GitHub URL</label>
              <input v-model="profileForm.githubUrl" type="url" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500" />
            </div>
            <div>
              <label class="block">
              <span class="text-xs font-bold uppercase tracking-wide text-slate-500 block mb-1.5">Portfolio URL</span>
              <input v-model="profileForm.portfolioUrl" type="url" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500" />
              </label>
            </div>
            <div>
              <label class="block">
              <span class="text-xs font-bold uppercase tracking-wide text-slate-500 block mb-1.5">LinkedIn URL</span>
              <input v-model="profileForm.linkedinUrl" type="url" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500" />
              </label>
            </div>
          </div>
        </section>

        <section class="orbit-card p-5">
          <div class="flex flex-col md:flex-row md:items-center justify-between gap-3">
            <div>
              <div class="text-lg font-bold text-slate-900">Documents</div>
              <div class="text-sm text-slate-600 mt-1">Keep employment and onboarding files attached to your profile.</div>
            </div>
            <div class="company-hub-badge" :class="profileForm.cv ? 'company-hub-badge-success' : 'company-hub-badge-warning'">
              {{ profileForm.cv ? 'CV uploaded' : 'CV missing' }}
            </div>
          </div>
          <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4 mt-4">
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
              <label class="block">
              <span class="text-xs font-bold uppercase tracking-wide text-slate-500 block mb-2">Profile photo</span>
              <input type="file" accept="image/*" @change="profileForm.photo = $event.target.files[0]" class="w-full text-sm text-slate-700" />
              </label>
              <div class="text-xs text-slate-500 mt-2">{{ fileName(profileForm.photo) }}</div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
              <label class="block">
              <span class="text-xs font-bold uppercase tracking-wide text-slate-500 block mb-2">CV / Resume</span>
              <input type="file" accept=".pdf,.doc,.docx" @change="profileForm.cv = $event.target.files[0]" class="w-full text-sm text-slate-700" />
              </label>
              <div class="text-xs text-slate-500 mt-2">{{ fileName(profileForm.cv) }}</div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
              <label class="block">
              <span class="text-xs font-bold uppercase tracking-wide text-slate-500 block mb-2">Offer letter</span>
              <input type="file" accept=".pdf,.doc,.docx" @change="profileForm.offerLetter = $event.target.files[0]" class="w-full text-sm text-slate-700" />
              </label>
              <div class="text-xs text-slate-500 mt-2">{{ fileName(profileForm.offerLetter) }}</div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
              <label class="block">
              <span class="text-xs font-bold uppercase tracking-wide text-slate-500 block mb-2">Employment contract</span>
              <input type="file" accept=".pdf,.doc,.docx" @change="profileForm.contract = $event.target.files[0]" class="w-full text-sm text-slate-700" />
              </label>
              <div class="text-xs text-slate-500 mt-2">{{ fileName(profileForm.contract) }}</div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
              <label class="block">
              <span class="text-xs font-bold uppercase tracking-wide text-slate-500 block mb-2">Certificates</span>
              <input type="file" accept=".pdf,.jpg,.jpeg,.png" @change="profileForm.certificates = $event.target.files[0]" class="w-full text-sm text-slate-700" />
              </label>
              <div class="text-xs text-slate-500 mt-2">{{ fileName(profileForm.certificates) }}</div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
              <label class="block">
              <span class="text-xs font-bold uppercase tracking-wide text-slate-500 block mb-2">Experience letters</span>
              <input type="file" accept=".pdf,.doc,.docx" @change="profileForm.experienceLetters = $event.target.files[0]" class="w-full text-sm text-slate-700" />
              </label>
              <div class="text-xs text-slate-500 mt-2">{{ fileName(profileForm.experienceLetters) }}</div>
            </div>
          </div>
        </section>

        <section class="orbit-card p-5">
          <div class="flex flex-col md:flex-row md:items-center justify-between gap-3">
            <div>
              <div class="text-lg font-bold text-slate-900">Security</div>
              <div class="text-sm text-slate-600 mt-1">Update your password for account access.</div>
            </div>
            <button @click="updatePassword" :disabled="changingPassword" class="bg-slate-950 text-white text-sm font-medium rounded-lg px-4 py-2 hover:bg-black transition-colors disabled:opacity-60 disabled:cursor-not-allowed">
              Update password
            </button>
          </div>
          <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mt-4">
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
              <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Account status</div>
              <div class="text-sm font-extrabold text-slate-900 mt-1 capitalize">{{ employee?.status || 'active' }}</div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
              <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Recent login</div>
              <div class="text-sm font-extrabold text-slate-900 mt-1">Current browser session</div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
              <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Two-factor auth</div>
              <div class="text-sm font-extrabold text-slate-900 mt-1">Not enabled</div>
            </div>
          </div>
          <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
            <div>
              <label class="block">
              <span class="text-xs font-bold uppercase tracking-wide text-slate-500 block mb-1.5">Current password</span>
              <input v-model="securityForm.currentPassword" type="password" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500" />
              </label>
            </div>
            <div>
              <label class="block">
              <span class="text-xs font-bold uppercase tracking-wide text-slate-500 block mb-1.5">New password</span>
              <input v-model="securityForm.newPassword" type="password" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500" />
              </label>
            </div>
            <div>
              <label class="block">
              <span class="text-xs font-bold uppercase tracking-wide text-slate-500 block mb-1.5">Confirm password</span>
              <input v-model="securityForm.confirmPassword" type="password" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500" />
              </label>
            </div>
          </div>
          <div v-if="passwordError" class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
            {{ passwordError }}
          </div>
          <div v-if="passwordMessage" class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-700">
            {{ passwordMessage }}
          </div>
        </section>
      </div>
    </section>
  </div>
</template>
