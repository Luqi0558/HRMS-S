<script setup>
import { computed, ref } from 'vue'
import { useStore } from '../store/index.js'
import { useTableControls } from '../composables/useTableControls.js'

const {
  state,
  can,
  formatCurrency,
  currencyCode,
  formatDate,
  pettyCashTotalFloat,
  pettyCashTotalSpent,
  pettyCashBalance,
  pettyCashIsLow,
  pettyCashNeedsApproval,
  pettyCashPendingExpenses,
  pettyCashPendingTopUps,
  pettyCashByCategory,
  logActivity,
  pushNotification
} = useStore()

// HR records and reconciles; only an approver signs off top-ups and
// over-threshold spend. Both see the module.
const canAccess = computed(() => can('Petty Cash', 'view'))
const canRecord = computed(() => can('Petty Cash', 'create'))
const canApprove = computed(() => can('Petty Cash', 'approve'))

const activeTab = ref('expenses')
const showExpenseForm = ref(false)
const showTopUpForm = ref(false)
const showReconcileForm = ref(false)
const expenseError = ref('')
const topUpError = ref('')
const reconcileError = ref('')

const balance = computed(() => pettyCashBalance())
const totalFloat = computed(() => pettyCashTotalFloat())
const totalSpent = computed(() => pettyCashTotalSpent())
const isLow = computed(() => pettyCashIsLow())
const categoryTotals = computed(() => pettyCashByCategory())
const pendingExpenses = computed(() => pettyCashPendingExpenses())
const pendingTopUps = computed(() => pettyCashPendingTopUps())

const expenseForm = ref({ amount: '', date: todayValue(), category: 'Office supplies', payee: '', description: '', receiptName: '' })
const topUpForm = ref({ amount: '', date: todayValue(), reference: '', note: '' })
const reconcileForm = ref({ countedAmount: '', note: '' })

const sortedExpenses = computed(() =>
  [...state.pettyCashExpenses].sort((a, b) => (b.date || '').localeCompare(a.date || ''))
)
const sortedTopUps = computed(() =>
  [...state.pettyCashTopUps].sort((a, b) => (b.date || '').localeCompare(a.date || ''))
)
const sortedReconciliations = computed(() =>
  [...state.pettyCashReconciliations].sort((a, b) => (b.date || '').localeCompare(a.date || ''))
)

const expenseTable = useTableControls(
  sortedExpenses,
  expense => `${expense.category} ${expense.payee} ${expense.description} ${expense.status} ${expense.amount}`,
  10
)

// Live preview so HR knows before saving whether this entry needs sign-off.
const expenseNeedsApproval = computed(() => pettyCashNeedsApproval(expenseForm.value.amount))

function todayValue() {
  return new Date().toISOString().slice(0, 10)
}

function employeeName(id) {
  if (id === 99) return 'Admin User'
  return state.employees.find(employee => employee.id === Number(id))?.name || '-'
}

function statusClass(status) {
  if (status === 'approved' || status === 'recorded') return 'bg-emerald-100 text-emerald-700'
  if (status === 'pending') return 'bg-amber-100 text-amber-700'
  return 'bg-rose-100 text-rose-700'
}

function nextId(list) {
  return list.reduce((max, item) => Math.max(max, item.id), 0) + 1
}

function openExpenseForm() {
  expenseError.value = ''
  expenseForm.value = { amount: '', date: todayValue(), category: state.settings.pettyCash.categories[0], payee: '', description: '', receiptName: '' }
  showExpenseForm.value = true
}

function openTopUpForm() {
  topUpError.value = ''
  topUpForm.value = { amount: '', date: todayValue(), reference: '', note: '' }
  showTopUpForm.value = true
}

function openReconcileForm() {
  reconcileError.value = ''
  reconcileForm.value = { countedAmount: '', note: '' }
  showReconcileForm.value = true
}

function validateExpense() {
  const amount = Number(expenseForm.value.amount || 0)
  if (!expenseForm.value.amount) return 'Please enter the amount spent.'
  if (Number.isNaN(amount) || amount <= 0) return 'Amount must be greater than zero.'
  if (!expenseForm.value.date) return 'Please enter the expense date.'
  if (expenseForm.value.date > todayValue()) return 'Expense date cannot be in the future.'
  if (!expenseForm.value.payee.trim()) return 'Please enter who was paid.'
  if (!expenseForm.value.description.trim()) return 'Please describe what this was for.'
  // Spending more than the tin holds means the ledger no longer reflects reality.
  if (amount > balance.value) {
    return `Only ${formatCurrency(balance.value)} remains in petty cash. Request a top-up first.`
  }
  return ''
}

function saveExpense() {
  expenseError.value = validateExpense()
  if (expenseError.value) return
  const amount = Number(expenseForm.value.amount)
  const needsApproval = pettyCashNeedsApproval(amount)
  state.pettyCashExpenses.unshift({
    id: nextId(state.pettyCashExpenses),
    amount,
    date: expenseForm.value.date,
    category: expenseForm.value.category,
    payee: expenseForm.value.payee.trim(),
    description: expenseForm.value.description.trim(),
    receiptName: expenseForm.value.receiptName,
    loggedById: state.currentUser.id,
    status: needsApproval ? 'pending' : 'recorded',
    approvedById: null,
    approvedAt: null
  })
  logActivity({
    module: 'petty cash',
    action: 'expense recorded',
    details: `${state.currentUser.name} recorded ${formatCurrency(amount)} for ${expenseForm.value.category}.`
  })
  if (needsApproval) {
    pushNotification({
      title: 'Petty cash expense needs approval',
      message: `${formatCurrency(amount)} for ${expenseForm.value.category} is above the approval limit.`,
      type: 'finance',
      roles: ['super_admin'],
      route: { name: 'petty-cash' }
    })
  }
  showExpenseForm.value = false
}

function validateTopUp() {
  const amount = Number(topUpForm.value.amount || 0)
  if (!topUpForm.value.amount) return 'Please enter the top-up amount.'
  if (Number.isNaN(amount) || amount <= 0) return 'Amount must be greater than zero.'
  if (!topUpForm.value.date) return 'Please enter the top-up date.'
  if (!topUpForm.value.reference.trim()) return 'Please add a reference so this can be traced.'
  return ''
}

function saveTopUp() {
  topUpError.value = validateTopUp()
  if (topUpError.value) return
  const amount = Number(topUpForm.value.amount)
  // An approver's own request is settled immediately; HR's awaits sign-off.
  const autoApproved = canApprove.value
  state.pettyCashTopUps.unshift({
    id: nextId(state.pettyCashTopUps),
    amount,
    date: topUpForm.value.date,
    reference: topUpForm.value.reference.trim(),
    note: topUpForm.value.note.trim(),
    requestedById: state.currentUser.id,
    status: autoApproved ? 'approved' : 'pending',
    approvedById: autoApproved ? state.currentUser.id : null,
    approvedAt: autoApproved ? new Date().toISOString() : null
  })
  logActivity({
    module: 'petty cash',
    action: autoApproved ? 'top-up added' : 'top-up requested',
    details: `${state.currentUser.name} ${autoApproved ? 'added' : 'requested'} a ${formatCurrency(amount)} petty cash top-up.`
  })
  if (!autoApproved) {
    pushNotification({
      title: 'Petty cash top-up requested',
      message: `${state.currentUser.name} requested ${formatCurrency(amount)} for petty cash.`,
      type: 'finance',
      roles: ['super_admin'],
      route: { name: 'petty-cash' }
    })
  }
  showTopUpForm.value = false
}

function approveTopUp(topUp) {
  if (!canApprove.value || topUp.status !== 'pending') return
  topUp.status = 'approved'
  topUp.approvedById = state.currentUser.id
  topUp.approvedAt = new Date().toISOString()
  logActivity({
    module: 'petty cash',
    action: 'top-up approved',
    details: `${state.currentUser.name} approved a ${formatCurrency(topUp.amount)} top-up.`
  })
  pushNotification({
    title: 'Petty cash top-up approved',
    message: `${formatCurrency(topUp.amount)} has been added to petty cash.`,
    type: 'finance',
    userIds: [topUp.requestedById],
    route: { name: 'petty-cash' }
  })
}

function rejectTopUp(topUp) {
  if (!canApprove.value || topUp.status !== 'pending') return
  topUp.status = 'rejected'
  topUp.approvedById = state.currentUser.id
  topUp.approvedAt = new Date().toISOString()
  logActivity({
    module: 'petty cash',
    action: 'top-up rejected',
    details: `${state.currentUser.name} rejected a ${formatCurrency(topUp.amount)} top-up.`
  })
}

function approveExpense(expense) {
  if (!canApprove.value || expense.status !== 'pending') return
  expense.status = 'recorded'
  expense.approvedById = state.currentUser.id
  expense.approvedAt = new Date().toISOString()
  logActivity({
    module: 'petty cash',
    action: 'expense approved',
    details: `${state.currentUser.name} approved ${formatCurrency(expense.amount)} for ${expense.category}.`
  })
}

function rejectExpense(expense) {
  if (!canApprove.value || expense.status !== 'pending') return
  expense.status = 'rejected'
  expense.approvedById = state.currentUser.id
  expense.approvedAt = new Date().toISOString()
  logActivity({
    module: 'petty cash',
    action: 'expense rejected',
    details: `${state.currentUser.name} rejected ${formatCurrency(expense.amount)} for ${expense.category}.`
  })
}

function validateReconcile() {
  if (reconcileForm.value.countedAmount === '') return 'Please enter the cash you counted.'
  const counted = Number(reconcileForm.value.countedAmount)
  if (Number.isNaN(counted) || counted < 0) return 'Counted cash cannot be negative.'
  return ''
}

const reconcileVariance = computed(() => {
  if (reconcileForm.value.countedAmount === '') return null
  return Number(reconcileForm.value.countedAmount) - balance.value
})

function saveReconciliation() {
  reconcileError.value = validateReconcile()
  if (reconcileError.value) return
  const counted = Number(reconcileForm.value.countedAmount)
  const variance = counted - balance.value
  state.pettyCashReconciliations.unshift({
    id: nextId(state.pettyCashReconciliations),
    date: todayValue(),
    countedAmount: counted,
    bookBalance: balance.value,
    variance,
    note: reconcileForm.value.note.trim(),
    byId: state.currentUser.id
  })
  logActivity({
    module: 'petty cash',
    action: 'reconciliation recorded',
    details: `${state.currentUser.name} counted ${formatCurrency(counted)} against a book balance of ${formatCurrency(balance.value)} (variance ${formatCurrency(variance)}).`
  })
  // A mismatch means cash is missing or unrecorded — always escalate it.
  if (variance !== 0) {
    pushNotification({
      title: 'Petty cash variance found',
      message: `Counted cash differs from the ledger by ${formatCurrency(Math.abs(variance))}.`,
      type: 'finance',
      roles: ['super_admin'],
      route: { name: 'petty-cash' }
    })
  }
  showReconcileForm.value = false
}
</script>

<template>
  <div v-if="canAccess" class="space-y-6">
    <div class="flex flex-col lg:flex-row lg:items-end justify-between gap-4">
      <div>
        <div class="text-sm text-slate-600 mt-1">Office float, day-to-day expenses, and cash reconciliation.</div>
      </div>
      <div class="flex flex-wrap gap-2">
        <button v-if="canRecord" @click="openExpenseForm()" class="bg-emerald-600 text-white text-sm font-medium rounded-lg px-4 py-2 hover:bg-emerald-700 transition-colors">
          Record expense
        </button>
        <button v-if="canRecord" @click="openTopUpForm()" class="bg-slate-950 text-white text-sm font-medium rounded-lg px-4 py-2 hover:bg-black transition-colors">
          {{ canApprove ? 'Add top-up' : 'Request top-up' }}
        </button>
        <button v-if="canRecord" @click="openReconcileForm()" class="bg-white border border-slate-200 text-slate-700 text-sm font-medium rounded-lg px-4 py-2 hover:bg-slate-50 transition-colors">
          Reconcile
        </button>
      </div>
    </div>

    <!-- Balance summary -->
    <section class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
      <div class="kpi-tile bg-white rounded-xl border border-slate-200 shadow-sm" :class="isLow ? 'border-amber-300' : ''">
        <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Cash in hand</div>
        <div class="text-2xl font-extrabold mt-2" :class="isLow ? 'text-amber-700' : 'text-slate-900'">{{ formatCurrency(balance) }}</div>
        <div v-if="isLow" class="text-xs font-semibold text-amber-700 mt-1">Below {{ formatCurrency(state.settings.pettyCash.lowBalanceThreshold) }} — request a top-up</div>
        <div v-else class="text-xs text-slate-600 mt-1">Available to spend</div>
      </div>
      <div class="kpi-tile bg-white rounded-xl border border-slate-200 shadow-sm">
        <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Total float</div>
        <div class="kpi-value text-slate-900 mt-2">{{ formatCurrency(totalFloat) }}</div>
        <div class="text-xs text-slate-600 mt-1">Approved top-ups</div>
      </div>
      <div class="kpi-tile bg-white rounded-xl border border-slate-200 shadow-sm">
        <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Total spent</div>
        <div class="kpi-value text-slate-900 mt-2">{{ formatCurrency(totalSpent) }}</div>
        <div class="text-xs text-slate-600 mt-1">{{ state.pettyCashExpenses.length }} entries</div>
      </div>
      <div class="kpi-tile bg-white rounded-xl border border-slate-200 shadow-sm">
        <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Awaiting approval</div>
        <div class="text-2xl font-extrabold mt-2" :class="(pendingExpenses.length + pendingTopUps.length) ? 'text-amber-700' : 'text-slate-900'">
          {{ pendingExpenses.length + pendingTopUps.length }}
        </div>
        <div class="text-xs text-slate-600 mt-1">Over {{ formatCurrency(state.settings.pettyCash.approvalThreshold) }} needs sign-off</div>
      </div>
    </section>

    <!-- Approver queue -->
    <section v-if="canApprove && (pendingTopUps.length || pendingExpenses.length)" class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
      <div class="px-4 py-3 border-b border-slate-200">
        <div class="text-sm font-bold text-slate-900">Waiting for your approval</div>
        <div class="text-xs text-slate-600 mt-1">Top-ups add to the float; expenses above the limit settle once approved.</div>
      </div>
      <div class="divide-y divide-slate-200 max-h-[24rem] overflow-y-auto">
        <div v-for="topUp in pendingTopUps" :key="`t-${topUp.id}`" class="flex items-center justify-between gap-3 px-4 py-3 flex-wrap">
          <div class="min-w-0">
            <div class="text-sm font-semibold text-slate-900">Top-up · {{ formatCurrency(topUp.amount) }}</div>
            <div class="text-xs text-slate-600 mt-0.5">{{ topUp.reference }} · requested by {{ employeeName(topUp.requestedById) }}</div>
          </div>
          <div class="flex gap-2 flex-shrink-0">
            <button @click="approveTopUp(topUp)" class="text-xs font-bold rounded-lg px-3 py-2 bg-emerald-600 text-white hover:bg-emerald-700 transition-colors">Approve</button>
            <button @click="rejectTopUp(topUp)" class="text-xs font-bold rounded-lg px-3 py-2 border border-slate-200 text-slate-700 hover:bg-slate-50 transition-colors">Reject</button>
          </div>
        </div>
        <div v-for="expense in pendingExpenses" :key="`e-${expense.id}`" class="flex items-center justify-between gap-3 px-4 py-3 flex-wrap">
          <div class="min-w-0">
            <div class="text-sm font-semibold text-slate-900">{{ expense.category }} · {{ formatCurrency(expense.amount) }}</div>
            <div class="text-xs text-slate-600 mt-0.5">{{ expense.payee }} · logged by {{ employeeName(expense.loggedById) }}</div>
          </div>
          <div class="flex gap-2 flex-shrink-0">
            <button @click="approveExpense(expense)" class="text-xs font-bold rounded-lg px-3 py-2 bg-emerald-600 text-white hover:bg-emerald-700 transition-colors">Approve</button>
            <button @click="rejectExpense(expense)" class="text-xs font-bold rounded-lg px-3 py-2 border border-slate-200 text-slate-700 hover:bg-slate-50 transition-colors">Reject</button>
          </div>
        </div>
      </div>
    </section>

    <!-- Tabs -->
    <div class="flex flex-wrap gap-2">
      <button
        v-for="tab in [{ id: 'expenses', label: 'Expenses' }, { id: 'topups', label: 'Top-ups' }, { id: 'reconciliation', label: 'Reconciliation' }]"
        :key="tab.id"
        @click="activeTab = tab.id"
        :class="activeTab === tab.id ? 'bg-slate-950 text-white' : 'bg-white text-slate-700 border border-slate-200 hover:bg-slate-50'"
        class="text-sm font-medium rounded-lg px-4 py-2 transition-colors"
      >
        {{ tab.label }}
      </button>
    </div>

    <!-- Expenses -->
    <section v-if="activeTab === 'expenses'" class="grid grid-cols-1 xl:grid-cols-3 gap-4">
      <div class="xl:col-span-2 bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
        <div class="table-tools">
          <input v-model="expenseTable.query.value" type="search" placeholder="Filter expenses..." class="table-filter-input" />
          <div class="table-count">{{ expenseTable.filteredRows.value.length }} records</div>
        </div>
        <div class="table-scroll" @scroll="expenseTable.loadMoreRows">
          <table class="w-full text-sm">
            <thead>
              <tr class="border-b border-slate-200 text-left bg-slate-50/80">
                <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Date</th>
                <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Category</th>
                <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Payee</th>
                <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Amount</th>
                <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Receipt</th>
                <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Status</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="expense in expenseTable.visibleRows.value" :key="expense.id" class="border-b border-slate-200 last:border-0 hover:bg-slate-50/60 transition-colors">
                <td class="px-4 py-3 text-slate-700">{{ formatDate(expense.date) }}</td>
                <td class="px-4 py-3 text-slate-900 font-medium">{{ expense.category }}</td>
                <td class="px-4 py-3 text-slate-700">{{ expense.payee }}</td>
                <td class="px-4 py-3 text-slate-900 font-semibold">{{ formatCurrency(expense.amount) }}</td>
                <td class="px-4 py-3 text-slate-600 text-xs">{{ expense.receiptName || '—' }}</td>
                <td class="px-4 py-3">
                  <span class="rounded-full px-2 py-0.5 text-[11px] font-bold capitalize" :class="statusClass(expense.status)">{{ expense.status }}</span>
                </td>
              </tr>
              <tr v-if="expenseTable.filteredRows.value.length === 0">
                <td colspan="6" class="px-4 py-8 text-center text-sm text-slate-500">No expenses recorded yet.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-200 text-sm font-bold text-slate-900">Spend by category</div>
        <div class="divide-y divide-slate-200 max-h-[24rem] overflow-y-auto">
          <div v-for="row in categoryTotals" :key="row.category" class="flex items-center justify-between gap-3 px-4 py-3">
            <div class="text-sm text-slate-700">{{ row.category }}</div>
            <div class="text-sm font-bold text-slate-900">{{ formatCurrency(row.amount) }}</div>
          </div>
          <div v-if="categoryTotals.length === 0" class="px-4 py-6 text-center text-sm font-semibold text-slate-500">Nothing spent yet.</div>
        </div>
      </div>
    </section>

    <!-- Top-ups -->
    <section v-else-if="activeTab === 'topups'" class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-slate-200 text-left bg-slate-50/80">
            <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Date</th>
            <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Reference</th>
            <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Amount</th>
            <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Requested by</th>
            <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Status</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="topUp in sortedTopUps" :key="topUp.id" class="border-b border-slate-200 last:border-0 hover:bg-slate-50/60 transition-colors">
            <td class="px-4 py-3 text-slate-700">{{ formatDate(topUp.date) }}</td>
            <td class="px-4 py-3 text-slate-900 font-medium">{{ topUp.reference }}</td>
            <td class="px-4 py-3 text-slate-900 font-semibold">{{ formatCurrency(topUp.amount) }}</td>
            <td class="px-4 py-3 text-slate-700">{{ employeeName(topUp.requestedById) }}</td>
            <td class="px-4 py-3">
              <span class="rounded-full px-2 py-0.5 text-[11px] font-bold capitalize" :class="statusClass(topUp.status)">{{ topUp.status }}</span>
            </td>
          </tr>
          <tr v-if="sortedTopUps.length === 0">
            <td colspan="5" class="px-4 py-8 text-center text-sm text-slate-500">No top-ups recorded yet.</td>
          </tr>
        </tbody>
      </table>
    </section>

    <!-- Reconciliation -->
    <section v-else class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
      <div class="px-4 py-3 border-b border-slate-200">
        <div class="text-sm font-bold text-slate-900">Reconciliation history</div>
        <div class="text-xs text-slate-600 mt-1">Counted cash against the ledger. A variance means cash is missing or a receipt was never recorded.</div>
      </div>
      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-slate-200 text-left bg-slate-50/80">
            <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Date</th>
            <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Book balance</th>
            <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Counted</th>
            <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Variance</th>
            <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">By</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in sortedReconciliations" :key="row.id" class="border-b border-slate-200 last:border-0">
            <td class="px-4 py-3 text-slate-700">{{ formatDate(row.date) }}</td>
            <td class="px-4 py-3 text-slate-700">{{ formatCurrency(row.bookBalance) }}</td>
            <td class="px-4 py-3 text-slate-900 font-semibold">{{ formatCurrency(row.countedAmount) }}</td>
            <td class="px-4 py-3 font-bold" :class="row.variance === 0 ? 'text-emerald-700' : 'text-rose-600'">
              {{ row.variance === 0 ? 'Matched' : formatCurrency(row.variance) }}
            </td>
            <td class="px-4 py-3 text-slate-700">{{ employeeName(row.byId) }}</td>
          </tr>
          <tr v-if="sortedReconciliations.length === 0">
            <td colspan="5" class="px-4 py-8 text-center text-sm text-slate-500">No reconciliation recorded yet.</td>
          </tr>
        </tbody>
      </table>
    </section>

    <!-- Expense modal -->
    <Teleport to="body">
      <div v-if="showExpenseForm" class="fixed inset-0 z-50 bg-black/40 backdrop-blur-[2px] flex items-center justify-center px-4 py-4 overflow-y-auto" @click.self="showExpenseForm = false">
        <div class="bg-white rounded-xl p-6 w-full max-w-lg border border-slate-200 shadow-2xl max-h-[calc(100vh-2rem)] overflow-y-auto">
          <div class="text-lg font-bold text-slate-900">Record expense</div>
          <div class="text-xs text-slate-600 mt-1">Cash in hand: {{ formatCurrency(balance) }}</div>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
            <div>
              <label class="block">
              <span class="text-sm font-medium text-slate-700 block mb-1.5">Amount</span>
              <input v-model="expenseForm.amount" type="number" min="0" :placeholder="`${currencyCode()} amount`" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500" />
              </label>
            </div>
            <div>
              <label class="block">
              <span class="text-sm font-medium text-slate-700 block mb-1.5">Date</span>
              <input v-model="expenseForm.date" type="date" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500" />
              </label>
            </div>
            <div>
              <label class="block">
              <span class="text-sm font-medium text-slate-700 block mb-1.5">Category</span>
              <select v-model="expenseForm.category" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500">
                <option v-for="category in state.settings.pettyCash.categories" :key="category">{{ category }}</option>
              </select>
              </label>
            </div>
            <div>
              <label class="block">
              <span class="text-sm font-medium text-slate-700 block mb-1.5">Paid to</span>
              <input v-model="expenseForm.payee" type="text" placeholder="Vendor or person" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500" />
              </label>
            </div>
            <div class="md:col-span-2">
              <label class="block">
              <span class="text-sm font-medium text-slate-700 block mb-1.5">What was this for?</span>
              <textarea v-model="expenseForm.description" rows="3" placeholder="Short description" class="w-full resize-none rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500"></textarea>
              </label>
            </div>
            <div class="md:col-span-2">
              <label class="block">
              <span class="text-sm font-medium text-slate-700 block mb-1.5">Receipt</span>
              <input type="file" accept=".pdf,image/*" @change="expenseForm.receiptName = $event.target.files[0]?.name || ''" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm" />
              </label>
            </div>
          </div>

          <div v-if="expenseNeedsApproval" class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-800">
            Above {{ formatCurrency(state.settings.pettyCash.approvalThreshold) }} — this will wait for Super Admin approval.
          </div>
          <div v-if="expenseError" class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
            {{ expenseError }}
          </div>

          <div class="flex gap-2 mt-5">
            <button @click="showExpenseForm = false" class="flex-1 text-sm border border-slate-200 rounded-lg py-2 hover:bg-slate-50 transition-colors text-slate-700">Cancel</button>
            <button @click="saveExpense" class="flex-1 bg-emerald-600 text-white text-sm font-medium rounded-lg py-2 hover:bg-emerald-700 transition-colors">Save expense</button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Top-up modal -->
    <Teleport to="body">
      <div v-if="showTopUpForm" class="fixed inset-0 z-50 bg-black/40 backdrop-blur-[2px] flex items-center justify-center px-4 py-4 overflow-y-auto" @click.self="showTopUpForm = false">
        <div class="bg-white rounded-xl p-6 w-full max-w-md border border-slate-200 shadow-2xl">
          <div class="text-lg font-bold text-slate-900">{{ canApprove ? 'Add top-up' : 'Request top-up' }}</div>
          <div class="text-xs text-slate-600 mt-1">
            {{ canApprove ? 'Added to the float immediately.' : 'Added to the float once a Super Admin approves.' }}
          </div>
          <div class="space-y-4 mt-4">
            <div>
              <label class="block">
              <span class="text-sm font-medium text-slate-700 block mb-1.5">Amount</span>
              <input v-model="topUpForm.amount" type="number" min="0" :placeholder="`${currencyCode()} amount`" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500" />
              </label>
            </div>
            <div>
              <label class="block">
              <span class="text-sm font-medium text-slate-700 block mb-1.5">Date</span>
              <input v-model="topUpForm.date" type="date" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500" />
              </label>
            </div>
            <div>
              <label class="block">
              <span class="text-sm font-medium text-slate-700 block mb-1.5">Reference</span>
              <input v-model="topUpForm.reference" type="text" placeholder="Cheque no, transfer ref, or 'cash from safe'" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500" />
              </label>
            </div>
            <div>
              <label class="block">
              <span class="text-sm font-medium text-slate-700 block mb-1.5">Note</span>
              <textarea v-model="topUpForm.note" rows="2" placeholder="Optional" class="w-full resize-none rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500"></textarea>
              </label>
            </div>
          </div>
          <div v-if="topUpError" class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
            {{ topUpError }}
          </div>
          <div class="flex gap-2 mt-5">
            <button @click="showTopUpForm = false" class="flex-1 text-sm border border-slate-200 rounded-lg py-2 hover:bg-slate-50 transition-colors text-slate-700">Cancel</button>
            <button @click="saveTopUp" class="flex-1 bg-slate-950 text-white text-sm font-medium rounded-lg py-2 hover:bg-black transition-colors">
              {{ canApprove ? 'Add top-up' : 'Send request' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Reconcile modal -->
    <Teleport to="body">
      <div v-if="showReconcileForm" class="fixed inset-0 z-50 bg-black/40 backdrop-blur-[2px] flex items-center justify-center px-4 py-4 overflow-y-auto" @click.self="showReconcileForm = false">
        <div class="bg-white rounded-xl p-6 w-full max-w-md border border-slate-200 shadow-2xl">
          <div class="text-lg font-bold text-slate-900">Reconcile petty cash</div>
          <div class="text-xs text-slate-600 mt-1">Count the physical cash and enter it below.</div>

          <div class="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-4">
            <div class="flex items-center justify-between text-sm">
              <span class="text-slate-600">Book balance</span>
              <span class="font-bold text-slate-900">{{ formatCurrency(balance) }}</span>
            </div>
          </div>

          <div class="mt-4">
            <label class="block">
            <span class="text-sm font-medium text-slate-700 block mb-1.5">Cash counted</span>
            <input v-model="reconcileForm.countedAmount" type="number" min="0" :placeholder="`${currencyCode()} amount`" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500" />
            </label>
          </div>

          <div v-if="reconcileVariance !== null" class="mt-3 rounded-lg px-3 py-2 text-sm font-semibold" :class="reconcileVariance === 0 ? 'border border-emerald-200 bg-emerald-50 text-emerald-700' : 'border border-rose-200 bg-rose-50 text-rose-700'">
            <span v-if="reconcileVariance === 0">Matches the ledger.</span>
            <span v-else-if="reconcileVariance < 0">Short by {{ formatCurrency(Math.abs(reconcileVariance)) }} — cash missing or a receipt not recorded.</span>
            <span v-else>Over by {{ formatCurrency(reconcileVariance) }} — an expense may have been recorded twice.</span>
          </div>

          <div class="mt-4">
            <label class="block">
            <span class="text-sm font-medium text-slate-700 block mb-1.5">Note</span>
            <textarea v-model="reconcileForm.note" rows="2" placeholder="Explain any variance" class="w-full resize-none rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500"></textarea>
            </label>
          </div>

          <div v-if="reconcileError" class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
            {{ reconcileError }}
          </div>

          <div class="flex gap-2 mt-5">
            <button @click="showReconcileForm = false" class="flex-1 text-sm border border-slate-200 rounded-lg py-2 hover:bg-slate-50 transition-colors text-slate-700">Cancel</button>
            <button @click="saveReconciliation" class="flex-1 bg-emerald-600 text-white text-sm font-medium rounded-lg py-2 hover:bg-emerald-700 transition-colors">Save reconciliation</button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>

  <div v-else class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
    <div class="text-lg font-semibold text-slate-900">Petty cash is available to HR and Super Admin only</div>
  </div>
</template>
