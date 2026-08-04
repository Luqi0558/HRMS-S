<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useStore } from '../store/index.js'
import { useTableControls } from '../composables/useTableControls.js'
import { medicalClaimService } from '../services/medicalClaimService.js'
import { settingsService } from '../services/settingsService.js'
import { employeeService } from '../services/employeeService.js'

const route = useRoute()
const {
  state, can, formatCurrency, currencyCode, pushNotification, logActivity,
  setMedicalClaims, upsertMedicalClaim, setServerSettings, setEmployees
} = useStore()
const loadError = ref('')
const submitting = ref(false)
const decidingId = ref(null)
// Limit, used and remaining for the year, as the API counts them. This is the
// same tally that refuses an over-limit claim.
const usage = ref(null)

async function loadClaimData() {
  const [claims, allowance, settings, employees] = await Promise.allSettled([
    medicalClaimService.list(),
    medicalClaimService.usage(),
    settingsService.all(),
    employeeService.list()
  ])

  if (claims.status === 'fulfilled') setMedicalClaims(claims.value)
  else loadError.value = claims.reason?.message || 'Unable to load claims.'

  if (allowance.status === 'fulfilled') usage.value = allowance.value
  if (settings.status === 'fulfilled') setServerSettings(settings.value)
  if (employees.status === 'fulfilled') setEmployees(employees.value.data)
}

onMounted(loadClaimData)
// Reviewers hold the approve right; applicants hold create on their own claims.
const canAccess = computed(() => can('Medical Request', 'approve'))
const canApply = computed(() => can('Medical Request', 'create'))
const statusFlow = ['pending', 'view', 'processing', 'approved']
const form = ref({
  type: '',
  amount: '',
  provider: '',
  expenseDate: '',
  description: '',
  receipt: null
})
const reviewNotes = ref({})
const claimError = ref('')
const showApplyModal = ref(false)
const claimContextFilter = ref('all')
function matchesClaimContext(claim) {
  if (claimContextFilter.value === 'mine') return claim.employeeId === state.currentUser.id
  if (claimContextFilter.value === 'pending') return claim.status === 'pending'
  if (claimContextFilter.value === 'processing') return claim.status === 'processing' || claim.status === 'view'
  if (claimContextFilter.value === 'approved') return claim.status === 'approved'
  if (claimContextFilter.value === 'rejected') return claim.status === 'rejected'
  return true
}
const myClaims = computed(() => state.medicalClaims.filter(c => c.employeeId === state.currentUser.id && matchesClaimContext(c)))
const allClaims = computed(() => state.medicalClaims.filter(matchesClaimContext))
const myClaimsTable = useTableControls(
  myClaims,
  claim => `${claim.type} ${claim.amount} ${claim.submittedAt} ${claim.status}`
)
const allClaimsTable = useTableControls(
  allClaims,
  claim => `${employeeName(claim.employeeId)} ${claim.type} ${claim.provider || ''} ${claim.description || ''} ${claim.amount} ${claim.submittedAt} ${claim.status}`
)

function applyRouteFilters(query = route.query) {
  claimContextFilter.value = query.filter || 'all'
  myClaimsTable.query.value = query.search || ''
  allClaimsTable.query.value = query.search || ''
}

watch(
  () => route.query,
  query => applyRouteFilters(query),
  { immediate: true }
)

function employeeName(id) {
  return state.employees.find(e => e.id === id)?.name ?? ''
}

async function openReceipt(claim) {
  claimError.value = ''
  try {
    await medicalClaimService.downloadReceipt(claim.id)
  } catch (error) {
    claimError.value = error?.message || 'Unable to open that receipt.'
  }
}

function nextStatus(currentStatus) {
  const index = statusFlow.indexOf(currentStatus)
  return statusFlow[Math.min(index + 1, statusFlow.length - 1)] || 'view'
}

/**
 * Advances a claim one step through the review flow.
 *
 * 'view' and 'processing' are review states this screen tracks locally — the
 * API models only pending, approved, rejected and paid, because those are the
 * ones that decide whether money moves. Reaching 'approved' is therefore the
 * step that has to be recorded server-side; the two before it are not.
 */
async function moveNext(claim) {
  const target = nextStatus(claim.status)

  if (target === 'approved') {
    claimError.value = ''
    decidingId.value = claim.id
    try {
      upsertMedicalClaim(await medicalClaimService.decide(claim.id, 'approved', {
        comment: reviewNotes.value[claim.id] || undefined
      }))
      // An approval consumes allowance, so the widget is now stale.
      usage.value = await medicalClaimService.usage().catch(() => usage.value)
    } catch (error) {
      claimError.value = error?.message || 'Unable to approve that claim.'
      return
    } finally {
      decidingId.value = null
    }
  } else {
    claim.status = target
  }

  if (!claim.timeline) claim.timeline = []
  claim.timeline.unshift({
    status: claim.status,
    note: reviewNotes.value[claim.id] || '',
    by: state.currentUser.name,
    at: new Date().toISOString()
  })
  logActivity({
    module: 'medical',
    action: 'claim status updated',
    details: `${employeeName(claim.employeeId)}'s ${claim.type} moved to ${claim.status}.`,
    targetId: claim.id,
    targetType: 'medicalClaim'
  })
  pushNotification({
    title: 'Medical claim updated',
    message: `${claim.type} is now ${claim.status}.`,
    type: 'medical',
    userIds: [claim.employeeId],
    route: { name: 'medical-claims' }
  })
  reviewNotes.value[claim.id] = ''
}

async function rejectClaim(claim) {
  const note = reviewNotes.value[claim.id] || 'No reason provided.'

  claimError.value = ''
  decidingId.value = claim.id
  try {
    upsertMedicalClaim(await medicalClaimService.decide(claim.id, 'rejected', { comment: note }))
  } catch (error) {
    claimError.value = error?.message || 'Unable to reject that claim.'
    return
  } finally {
    decidingId.value = null
  }

  if (!claim.timeline) claim.timeline = []
  claim.timeline.unshift({
    status: 'rejected',
    note,
    by: state.currentUser.name,
    at: new Date().toISOString()
  })
  logActivity({
    module: 'medical',
    action: 'claim rejected',
    details: `${employeeName(claim.employeeId)}'s ${claim.type} was rejected. Reason: ${note}`,
    targetId: claim.id,
    targetType: 'medicalClaim'
  })
  pushNotification({
    title: 'Medical claim rejected',
    message: `${claim.type} was rejected. ${note}`,
    type: 'medical',
    userIds: [claim.employeeId],
    route: { name: 'medical-claims' }
  })
  reviewNotes.value[claim.id] = ''
}

// Receipt threshold comes from settings.claims.receiptRequiredAbove, so
// changing it in Settings changes what the form accepts.
const receiptThreshold = computed(() => Number(state.settings.claims.receiptRequiredAbove || 0))
const receiptRequired = computed(() =>
  receiptThreshold.value > 0 && Number(form.value.amount || 0) > receiptThreshold.value
)
async function applyMedicalClaim() {
  claimError.value = ''
  if (!form.value.type || !form.value.amount || !form.value.expenseDate || !form.value.description) {
    claimError.value = 'Please fill in type, amount, expense date, and description.'
    return
  }
  if (Number(form.value.amount) <= 0) {
    claimError.value = 'Amount must be greater than zero.'
    return
  }
  // Checked against what is left, not the headline limit — earlier approved
  // claims have already consumed part of it. Falls back to the limit before
  // the usage figure has loaded.
  const remaining = usage.value ? Number(usage.value.remaining) : Number(state.settings.claims.annualMedicalLimit || 0)
  if (remaining > 0 && Number(form.value.amount) > remaining) {
    claimError.value = `Amount exceeds your remaining medical allowance of ${formatCurrency(remaining)}.`
    return
  }
  if (receiptRequired.value && !form.value.receipt) {
    claimError.value = `A receipt is required for claims above ${formatCurrency(receiptThreshold.value)}.`
    return
  }
  // The API re-checks the receipt threshold and the remaining allowance. The
  // checks above are the fast path; this is the one that decides.
  submitting.value = true
  try {
    upsertMedicalClaim(await medicalClaimService.create({
      type: form.value.type,
      amount: Number(form.value.amount),
      description: form.value.description,
      incurredOn: form.value.expenseDate,
      receipt: form.value.receipt || undefined
    }))
    usage.value = await medicalClaimService.usage().catch(() => usage.value)
  } catch (error) {
    claimError.value = error?.message || 'Unable to submit that claim.'
    return
  } finally {
    submitting.value = false
  }

  pushNotification({
    title: 'New medical claim submitted',
    message: `${state.currentUser.name} submitted a ${form.value.type} for ${formatCurrency(form.value.amount)}.`,
    type: 'medical',
    roles: ['hr', 'super_admin'],
    route: { name: 'medical-claims' }
  })
  logActivity({
    module: 'medical',
    action: 'claim submitted',
    details: `${state.currentUser.name} submitted ${form.value.type} for ${formatCurrency(form.value.amount)}.`
  })
  claimError.value = ''
  form.value = { type: '', amount: '', provider: '', expenseDate: '', description: '', receipt: null }
  showApplyModal.value = false
}
</script>

<template>
  <div v-if="canApply" class="space-y-6">
    <div class="flex items-start justify-between gap-4">
      <div>
        <div class="text-sm text-slate-600 mt-1">Track your submitted medical reimbursement requests</div>
      </div>
      <button @click="showApplyModal = true" class="bg-emerald-600 text-white text-sm font-medium rounded-lg px-5 py-2 hover:bg-emerald-700 transition-colors shadow-sm">
        Apply medical request
      </button>
    </div>

    <div v-if="loadError || (claimError && !showApplyModal)" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
      {{ loadError || claimError }}
    </div>

    <div v-if="usage" class="grid grid-cols-1 sm:grid-cols-3 gap-4">
      <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
        <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Annual allowance</div>
        <div class="text-2xl font-extrabold text-slate-900 mt-2">{{ formatCurrency(usage.limit) }}</div>
      </div>
      <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
        <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Claimed this year</div>
        <div class="text-2xl font-extrabold text-slate-900 mt-2">{{ formatCurrency(usage.used) }}</div>
      </div>
      <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
        <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Remaining</div>
        <div class="text-2xl font-extrabold text-teal mt-2">{{ formatCurrency(usage.remaining) }}</div>
      </div>
    </div>

    <div class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm">
      <div class="px-4 py-3 border-b border-slate-200 text-sm font-semibold text-slate-900">My medical requests</div>
      <div class="table-tools">
        <input v-model="myClaimsTable.query.value" type="search" placeholder="Filter medical requests..." class="table-filter-input" />
        <select v-model="claimContextFilter" class="table-filter-input max-w-[210px]">
          <option value="all">All claims</option>
          <option value="mine">My claims</option>
          <option value="pending">Pending</option>
          <option value="processing">Processing</option>
          <option value="approved">Approved</option>
          <option value="rejected">Rejected</option>
        </select>
        <div class="table-count">{{ myClaimsTable.filteredRows.value.length }} records</div>
      </div>
      <div class="table-scroll" @scroll="myClaimsTable.loadMoreRows">
      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-slate-200 text-left bg-slate-50/80">
            <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Claim</th>
            <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Amount</th>
            <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Submitted</th>
            <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Status</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="claim in myClaimsTable.visibleRows.value" :key="claim.id" class="border-b border-slate-200 last:border-0">
            <td class="px-4 py-3 text-slate-900 font-medium">{{ claim.type }}</td>
            <td class="px-4 py-3 text-slate-700">{{ formatCurrency(claim.amount) }}</td>
            <td class="px-4 py-3 text-slate-700">{{ claim.submittedAt }}</td>
            <td class="px-4 py-3 capitalize text-slate-700">{{ claim.status }}</td>
          </tr>
          <tr v-if="myClaimsTable.filteredRows.value.length === 0">
            <td colspan="4" class="px-4 py-8 text-center text-sm text-slate-500">No medical requests submitted yet.</td>
          </tr>
        </tbody>
      </table>
      </div>
    </div>

    <Teleport to="body">
      <div v-if="showApplyModal" class="fixed inset-0 z-50 bg-black/40 backdrop-blur-[2px] flex items-center justify-center px-4 py-4 overflow-y-auto" @click.self="showApplyModal = false">
        <div class="bg-white border border-slate-200 rounded-xl p-6 shadow-2xl w-full max-w-2xl max-h-[calc(100vh-2rem)] overflow-y-auto">
          <div class="flex items-start justify-between gap-4 mb-5">
            <div>
              <div class="text-lg font-bold text-slate-900">Apply medical request</div>
              <div class="text-sm text-slate-600 mt-1">Submit this request to HR and super admin for review.</div>
            </div>
            <button @click="showApplyModal = false" class="text-slate-500 hover:text-slate-900 text-xl leading-none">x</button>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="block">
              <span class="text-sm font-medium text-slate-700 block mb-1.5">Claim type</span>
              <select v-model="form.type" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500">
                <option value="">Select claim type</option>
                <option>Medical reimbursement</option>
                <option>Pharmacy claim</option>
                <option>Lab test</option>
                <option>Consultation fee</option>
                <option>Hospital expense</option>
              </select>
              </label>
            </div>
            <div>
              <label class="block">
              <span class="text-sm font-medium text-slate-700 block mb-1.5">Amount</span>
              <input v-model="form.amount" type="number" min="0" :placeholder="`${currencyCode()} amount`" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500" />
              </label>
            </div>
            <div>
              <label class="block">
              <span class="text-sm font-medium text-slate-700 block mb-1.5">Hospital / provider</span>
              <input v-model="form.provider" type="text" placeholder="Clinic, hospital, pharmacy" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500" />
              </label>
            </div>
            <div>
              <label class="block">
              <span class="text-sm font-medium text-slate-700 block mb-1.5">Expense date</span>
              <input v-model="form.expenseDate" type="date" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500" />
              </label>
            </div>
            <div class="md:col-span-2">
              <label class="block">
              <span class="text-sm font-medium text-slate-700 block mb-1.5">Explanation</span>
              <textarea v-model="form.description" rows="4" placeholder="Explain the treatment, expense, and why you are requesting reimbursement." class="w-full resize-none rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500"></textarea>
              </label>
            </div>
            <div class="md:col-span-2">
              <label class="block">
              <span class="text-sm font-medium text-slate-700 block mb-1.5">Receipt / bill
                <span v-if="receiptRequired" class="text-rose-600 font-semibold">(required)</span></span>
              <input type="file" accept=".pdf,image/*" @change="form.receipt = $event.target.files[0]" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm" />
              </label>
              <div v-if="receiptThreshold > 0" class="text-xs text-slate-500 mt-1">
                Required for claims above {{ formatCurrency(receiptThreshold) }}.
              </div>
            </div>
          </div>

          <div v-if="claimError" class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
            {{ claimError }}
          </div>

          <div class="flex items-center justify-end gap-2 mt-5">
            <button @click="showApplyModal = false" class="text-sm border border-slate-200 rounded-lg px-4 py-2 hover:bg-slate-50 transition-colors text-slate-700 font-medium">Cancel</button>
            <button @click="applyMedicalClaim" :disabled="submitting" class="bg-emerald-600 text-white text-sm font-medium rounded-lg px-5 py-2 hover:bg-emerald-700 transition-colors disabled:opacity-60 disabled:cursor-not-allowed">
              Apply
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>

  <div v-else-if="canAccess" class="space-y-6">
    <div>
      <div class="text-sm text-slate-600 mt-1">Review employee medical reimbursement requests</div>
    </div>

    <div class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm">
      <div class="table-tools">
        <input v-model="allClaimsTable.query.value" type="search" placeholder="Filter medical claims..." class="table-filter-input" />
        <select v-model="claimContextFilter" class="table-filter-input max-w-[210px]">
          <option value="all">All claims</option>
          <option value="pending">Pending</option>
          <option value="processing">Processing</option>
          <option value="approved">Approved</option>
          <option value="rejected">Rejected</option>
        </select>
        <div class="table-count">{{ allClaimsTable.filteredRows.value.length }} records</div>
      </div>
      <div class="table-scroll" @scroll="allClaimsTable.loadMoreRows">
      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-slate-200 text-left bg-slate-50/80">
            <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Employee</th>
            <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Claim</th>
            <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Provider</th>
            <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Explanation</th>
            <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Amount</th>
            <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Submitted</th>
            <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Status</th>
            <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Action</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="claim in allClaimsTable.visibleRows.value" :key="claim.id" class="border-b border-slate-200 last:border-0 hover:bg-slate-50/60 transition-colors">
            <td class="px-4 py-3 text-slate-900 font-medium">{{ employeeName(claim.employeeId) }}</td>
            <td class="px-4 py-3 text-slate-700">
              {{ claim.type }}
              <button
                v-if="claim.hasReceipt"
                @click="openReceipt(claim)"
                class="block mt-1 text-xs font-medium text-teal hover:underline"
              >
                View receipt
              </button>
            </td>
            <td class="px-4 py-3 text-slate-700">{{ claim.provider || '—' }}</td>
            <td class="px-4 py-3 text-slate-700 max-w-xs">{{ claim.description || '—' }}</td>
            <td class="px-4 py-3 text-slate-700">{{ formatCurrency(claim.amount) }}</td>
            <td class="px-4 py-3 text-slate-700">{{ claim.submittedAt }}</td>
            <td class="px-4 py-3 min-w-[260px]">
              <select v-model="claim.status" class="text-sm capitalize border border-slate-200 rounded-lg px-3 py-2 bg-white text-slate-700 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                <option v-for="status in [...statusFlow, 'rejected']" :key="status" :value="status">{{ status }}</option>
              </select>
              <input v-model="reviewNotes[claim.id]" placeholder="Review note / rejection reason" class="mt-2 w-full text-xs border border-slate-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-1 focus:ring-emerald-500" />
              <div v-if="claim.timeline?.length" class="mt-2 text-xs text-slate-500">
                Last: {{ claim.timeline[0].status }} by {{ claim.timeline[0].by }}
              </div>
            </td>
            <td class="px-4 py-3">
              <div class="flex flex-wrap gap-2">
                <button
                  @click="moveNext(claim)"
                  :disabled="claim.status === 'approved' || claim.status === 'rejected' || decidingId === claim.id"
                  class="text-sm font-medium rounded-lg px-3 py-2 bg-emerald-600 text-white hover:bg-emerald-700 disabled:bg-slate-300 disabled:cursor-not-allowed transition-colors"
                >
                  Move next
                </button>
                <button
                  @click="rejectClaim(claim)"
                  :disabled="claim.status === 'approved' || claim.status === 'rejected' || decidingId === claim.id"
                  class="text-sm font-medium rounded-lg px-3 py-2 bg-rose-600 text-white hover:bg-rose-700 disabled:bg-slate-300 disabled:cursor-not-allowed transition-colors"
                >
                  Reject
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
      </div>
    </div>
  </div>
  <div v-else class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
    <div class="text-lg font-semibold text-slate-900">Medical claims are not available for this role</div>
  </div>
</template>
