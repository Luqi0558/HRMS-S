<script setup>
import { computed, ref, onMounted } from 'vue'
import { projectService } from '../services/projectService.js'
import { employeeService } from '../services/employeeService.js'
import { financeService } from '../services/financeService.js'
import { useStore } from '../store/index.js'
import { useTableControls } from '../composables/useTableControls.js'

const {
  state,
  formatCurrency,
  projectReceived,
  projectPending,
  projectCommissionEarned,
  projectCommissionPaid,
  projectCommissionOwed,
  can,
  scopedList,
  setProjects,
  setEmployees,
  paymentsForModule,
  setModulePayments,
  addModulePayment,
  removeModulePayment
} = useStore()

const activeTab = ref('overview')
const selectedFinanceKey = ref(null)
const showProjectModal = ref(false)
const managerQuery = ref('')
const selectedManagerId = ref('all')
const expandedManagerIds = ref([])
const paymentStatusFilter = ref('all')
const commissionStatusFilter = ref('all')
const paymentForm = ref({
  amount: '',
  date: new Date().toISOString().slice(0, 10),
  method: 'Bank transfer',
  payer: '',
  reference: ''
})

const canAccessFinance = computed(() => can('Finance', 'finance'))
// Commission approval is an 'approve' right on Finance — only super_admin holds it.
const isSuperAdmin = computed(() => can('Finance', 'approve'))
const managers = computed(() => state.employees.filter(employee => employee.role === 'manager'))
const tabs = computed(() => [
  { id: 'overview', label: 'Overview' },
  ...(isSuperAdmin.value ? [{ id: 'managers', label: 'Managers' }] : []),
  { id: 'payments', label: 'Payments' },
  { id: 'commissions', label: 'Commissions' }
])

const financeCategories = computed(() => scopedList('Finance', state.projects, 'finance'))

const financeModules = computed(() =>
  financeCategories.value.flatMap(project =>
    (project.modules || []).map(module => ({
      ...module,
      module,
      payments: paymentsForModule(module.id),
      financeKey: `${project.id}-${module.id}`,
      projectId: project.id,
      categoryName: project.name,
      managerId: project.managerId
    }))
  )
)

const selectedProject = computed(() =>
  financeModules.value.find(project => project.financeKey === selectedFinanceKey.value) || null
)

const financeTotals = computed(() => {
  const projects = financeModules.value
  return {
    budget: projects.reduce((total, project) => total + Number(project.budget || 0), 0),
    received: projects.reduce((total, project) => total + projectReceived(project), 0),
    pending: projects.reduce((total, project) => total + projectPending(project), 0),
    commissionEarned: projects.reduce((total, project) => total + projectCommissionEarned(project), 0),
    commissionOwed: projects.reduce((total, project) => total + projectCommissionOwed(project), 0),
    commissionPaid: projects.reduce((total, project) => total + projectCommissionPaid(project), 0)
  }
})

const managerFinanceRows = computed(() => {
  return managers.value.map(manager => {
    const projects = state.projects
      .filter(project => project.managerId === manager.id)
      .flatMap(project =>
        (project.modules || []).map(module => ({
          ...module,
          module,
          payments: paymentsForModule(module.id),
          financeKey: `${project.id}-${module.id}`,
          projectId: project.id,
          categoryName: project.name,
          managerId: project.managerId
        }))
      )
    const budget = projects.reduce((total, project) => total + Number(project.budget || 0), 0)
    const received = projects.reduce((total, project) => total + projectReceived(project), 0)
    const pending = projects.reduce((total, project) => total + projectPending(project), 0)
    const earned = projects.reduce((total, project) => total + projectCommissionEarned(project), 0)
    const paid = projects.reduce((total, project) => total + projectCommissionPaid(project), 0)
    return {
      manager,
      projects,
      projectCount: projects.length,
      budget,
      received,
      pending,
      earned,
      paid,
      owed: Math.max(0, earned - paid)
    }
  })
})

const filteredManagerRows = computed(() => {
  const term = managerQuery.value.trim().toLowerCase()
  return managerFinanceRows.value.filter(row => {
    const matchesManager = selectedManagerId.value === 'all' || row.manager.id === Number(selectedManagerId.value)
    const matchesSearch = !term || `${row.manager.name} ${row.projects.map(project => `${project.name} ${project.categoryName}`).join(' ')}`.toLowerCase().includes(term)
    return matchesManager && matchesSearch
  })
})

const projectLedgerRows = computed(() => financeModules.value)
const paymentRows = computed(() => {
  const rows = financeModules.value.flatMap(project =>
    (project.payments || []).map(payment => ({ project, payment }))
  )
  return rows.sort((a, b) => new Date(b.payment.date).getTime() - new Date(a.payment.date).getTime())
})
const commissionRows = computed(() => financeModules.value.map(project => ({
  project,
  status: project.commission?.status || 'pending',
  earned: projectCommissionEarned(project),
  paid: projectCommissionPaid(project),
  owed: projectCommissionOwed(project)
})))

const filteredPayments = computed(() => {
  if (paymentStatusFilter.value === 'all') return paymentRows.value
  if (paymentStatusFilter.value === 'partial') {
    return paymentRows.value.filter(row => projectPending(row.project) > 0 && projectReceived(row.project) > 0)
  }
  if (paymentStatusFilter.value === 'paid') {
    return paymentRows.value.filter(row => projectPending(row.project) === 0)
  }
  return paymentRows.value
})

const filteredCommissions = computed(() => {
  if (commissionStatusFilter.value === 'all') return commissionRows.value
  return commissionRows.value.filter(row => row.status === commissionStatusFilter.value)
})

const paymentTable = useTableControls(
  filteredPayments,
  row => `${row.project.name} ${row.project.categoryName} ${employeeName(row.project.managerId)} ${row.payment.amount} ${row.payment.method} ${row.payment.reference} ${row.payment.payer}`,
  10
)
const commissionTable = useTableControls(
  filteredCommissions,
  row => `${row.project.name} ${row.project.categoryName} ${employeeName(row.project.managerId)} ${row.status} ${commissionLabel(row.project)}`,
  10
)
const projectLedgerTable = useTableControls(
  projectLedgerRows,
  project => `${project.name} ${project.categoryName} ${employeeName(project.managerId)} ${commissionLabel(project)} ${project.commission?.status || ''}`,
  10
)

const attentionItems = computed(() => {
  const pendingCommissions = commissionRows.value.filter(row => row.status === 'pending' && row.earned > 0)
  const approvedCommissions = commissionRows.value.filter(row => row.status === 'approved' && row.owed > 0)
  const pendingClientBalances = financeModules.value.filter(project => projectPending(project) > 0)
  const noPaymentProjects = financeModules.value.filter(project => projectReceived(project) === 0)
  return [
    {
      label: 'Commissions waiting approval',
      value: pendingCommissions.length,
      tone: 'orange',
      tab: 'commissions'
    },
    {
      label: 'Approved commissions to pay',
      value: approvedCommissions.length,
      tone: 'orange',
      tab: 'commissions'
    },
    {
      label: 'Modules with client balance',
      value: pendingClientBalances.length,
      tone: 'blue',
      tab: isSuperAdmin.value ? 'managers' : 'overview'
    },
    {
      label: 'Modules with no payments',
      value: noPaymentProjects.length,
      tone: 'slate',
      tab: isSuperAdmin.value ? 'managers' : 'overview'
    }
  ]
})

function employeeName(employeeId) {
  return state.employees.find(employee => employee.id === employeeId)?.name ?? 'N/A'
}

function commissionLabel(project) {
  const commission = project?.commission
  if (!commission) return 'No commission set'
  if (commission.type === 'fixed') return `${formatCurrency(commission.value)} fixed`
  return `${commission.value}% of received amount`
}

function projectPaymentStatus(project) {
  if (Number(project?.budget || 0) <= 0) return 'no budget'
  if (projectPending(project) === 0) return 'fully paid'
  if (projectReceived(project) === 0) return 'no payment'
  return 'partial'
}

function resetPaymentForm() {
  paymentForm.value = {
    amount: '',
    date: new Date().toISOString().slice(0, 10),
    method: 'Bank transfer',
    payer: '',
    reference: ''
  }
}

function openProject(financeKey) {
  selectedFinanceKey.value = financeKey
  financeError.value = ''
  resetPaymentForm()
  showProjectModal.value = true
}

function closeProject() {
  selectedFinanceKey.value = null
  showProjectModal.value = false
  financeError.value = ''
  resetPaymentForm()
}

function isManagerExpanded(managerId) {
  return expandedManagerIds.value.includes(managerId)
}

function toggleManager(managerId) {
  if (isManagerExpanded(managerId)) {
    expandedManagerIds.value = expandedManagerIds.value.filter(id => id !== managerId)
    return
  }
  expandedManagerIds.value = [...expandedManagerIds.value, managerId]
}

const savingPayment = ref(false)
const removingPaymentId = ref(null)
const decidingCommissionId = ref(null)
const financeError = ref('')

/**
 * Logs a client payment on the server.
 *
 * This used to push an object into the module in memory — nothing was saved,
 * so the payment, and the commission it earned, vanished on refresh. The API
 * now records it, writes the audit entry and tells the super admin and the
 * project's manager.
 */
async function logPayment() {
  const project = selectedProject.value
  const amount = Number(paymentForm.value.amount)
  if (!project || amount <= 0 || !paymentForm.value.date || savingPayment.value) return

  savingPayment.value = true
  financeError.value = ''
  try {
    addModulePayment(await financeService.logPayment(project.id, {
      amount,
      date: paymentForm.value.date,
      method: paymentForm.value.method,
      payer: paymentForm.value.payer.trim() || null,
      reference: paymentForm.value.reference.trim() || null
    }))
    resetPaymentForm()
  } catch (error) {
    financeError.value = error?.message || 'Could not log that payment. Please try again.'
  } finally {
    savingPayment.value = false
  }
}

/** Removes a payment logged in error. Super admin only — see FinanceService. */
async function removePayment(payment) {
  if (!isSuperAdmin.value || removingPaymentId.value) return
  if (!window.confirm(`Remove the ${formatCurrency(payment.amount)} payment logged on ${payment.date}?`)) return

  removingPaymentId.value = payment.id
  financeError.value = ''
  try {
    await financeService.removePayment(payment.id)
    removeModulePayment(payment.id)
  } catch (error) {
    financeError.value = error?.message || 'Could not remove that payment. Please try again.'
  } finally {
    removingPaymentId.value = null
  }
}

/** Puts the server's copy of a module back into the project it belongs to. */
function updateStoredModule(module) {
  const project = state.projects.find(item => item.id === module?.projectId)
  const index = project?.modules?.findIndex(item => item.id === module.id) ?? -1
  if (index >= 0) project.modules[index] = { ...project.modules[index], ...module }
}

/**
 * Approves or settles a commission through the API.
 *
 * The endpoint existed — the Super Admin dashboard uses it — but this page
 * only flipped the status in memory, so an approval here was lost on refresh
 * and the manager was never told.
 */
async function updateCommissionStatus(project, status) {
  if (!can('Finance', 'approve') || !project?.commission || decidingCommissionId.value) return

  decidingCommissionId.value = project.id
  financeError.value = ''
  try {
    updateStoredModule(await projectService.decideModuleCommission(project.id, status))
  } catch (error) {
    financeError.value = error?.message || 'Could not update that commission. Please try again.'
  } finally {
    decidingCommissionId.value = null
  }
}
/**
 * Every figure on this page is derived from projects and their modules, so
 * reading whatever happened to be in the store was how a finance total could
 * silently be built from stale or seeded data.
 *
 * Non-fatal: roles reaching this page hold different permissions, and one 403
 * should not blank the whole view.
 */
onMounted(async () => {
  const [projects, employees, payments] = await Promise.allSettled([
    projectService.list(),
    employeeService.list(),
    financeService.payments()
  ])

  if (projects.status === 'fulfilled') setProjects(projects.value)
  if (employees.status === 'fulfilled') setEmployees(employees.value.data)
  if (payments.status === 'fulfilled') setModulePayments(payments.value)
  else financeError.value = 'Client payments could not be loaded, so received and commission figures may read low.'
})
</script>

<template>
  <div v-if="canAccessFinance" class="orbit-page space-y-6">
    <div class="orbit-page-header flex flex-col lg:flex-row lg:items-end justify-between gap-4">
      <div>
        <div class="text-sm text-slate-600 mt-1">
          {{ isSuperAdmin ? 'Company-wide module budgets, payments, and manager commissions' : 'Your module budgets, payments, and commission summary' }}
        </div>
      </div>
      <div class="orbit-tab-list flex flex-wrap gap-2">
        <button
          v-for="tab in tabs"
          :key="tab.id"
          @click="activeTab = tab.id"
          :class="activeTab === tab.id ? 'orbit-tab-active' : 'orbit-tab-idle'"
          class="orbit-tab px-4 py-2 text-sm font-semibold"
        >
          {{ tab.label }}
        </button>
      </div>
    </div>

    <div v-if="financeError && !showProjectModal" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700" role="alert">
      {{ financeError }}
    </div>

    <section class="grid grid-cols-1 md:grid-cols-3 xl:grid-cols-6 gap-4">
      <div class="orbit-stat-card p-4">
        <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Total budgets</div>
        <div class="text-xl font-extrabold text-slate-900 mt-2">{{ formatCurrency(financeTotals.budget) }}</div>
      </div>
      <div class="orbit-stat-card p-4">
        <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Received</div>
        <div class="text-xl font-extrabold text-emerald-700 mt-2">{{ formatCurrency(financeTotals.received) }}</div>
      </div>
      <div class="orbit-stat-card p-4">
        <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Pending</div>
        <div class="text-xl font-extrabold text-orange-600 mt-2">{{ formatCurrency(financeTotals.pending) }}</div>
      </div>
      <div class="orbit-stat-card p-4">
        <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Commission earned</div>
        <div class="text-xl font-extrabold text-slate-900 mt-2">{{ formatCurrency(financeTotals.commissionEarned) }}</div>
      </div>
      <div class="orbit-stat-card p-4">
        <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Commission owed</div>
        <div class="text-xl font-extrabold text-orange-600 mt-2">{{ formatCurrency(financeTotals.commissionOwed) }}</div>
      </div>
      <div class="orbit-stat-card p-4">
        <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Commission paid</div>
        <div class="text-xl font-extrabold text-emerald-700 mt-2">{{ formatCurrency(financeTotals.commissionPaid) }}</div>
      </div>
    </section>

    <section v-if="activeTab === 'overview'" class="space-y-4">
      <div class="orbit-card overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-200">
          <div class="text-sm font-bold text-slate-900">Needs attention</div>
          <div class="text-xs text-slate-600 mt-1">Quick shortcuts to the finance work that needs review.</div>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-3 p-4">
          <button
            v-for="item in attentionItems"
            :key="item.label"
            @click="activeTab = item.tab"
            class="rounded-xl border border-slate-200 bg-slate-50 p-4 text-left hover:bg-white hover:border-slate-300 transition-colors"
          >
            <div class="text-xs font-bold uppercase tracking-wide text-slate-500">{{ item.label }}</div>
            <div class="text-2xl font-extrabold text-slate-900 mt-2">{{ item.value }}</div>
          </button>
        </div>
      </div>

      <!-- "My ledger" is for roles with finance access but no approval rights. -->
      <section v-if="canAccessFinance && !isSuperAdmin" class="orbit-table-card overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-200">
          <div class="text-sm font-bold text-slate-900">My module financial ledger</div>
          <div class="text-xs text-slate-600 mt-1">Open a module/client project to log payments and review commission.</div>
        </div>
        <div class="px-4 py-3 border-b border-slate-200 flex items-center justify-between gap-3">
          <input v-model="projectLedgerTable.query.value" type="search" placeholder="Filter modules..." class="table-filter-input" />
          <div class="table-count">{{ projectLedgerTable.filteredRows.value.length }} records</div>
        </div>
        <div class="table-scroll" @scroll="projectLedgerTable.loadMoreRows">
          <table class="orbit-data-table w-full text-sm">
            <thead>
              <tr class="border-b border-slate-200 text-left bg-slate-50/80">
                <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Module / Project</th>
                <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Budget</th>
                <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Received</th>
                <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Pending</th>
                <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Commission</th>
                <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Status</th>
                <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide text-right">Action</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="project in projectLedgerTable.visibleRows.value" :key="project.financeKey" class="border-b border-slate-200 last:border-0 hover:bg-slate-50/60 transition-colors">
                <td class="px-4 py-3">
                  <div class="text-slate-900 font-bold">{{ project.name }}</div>
                  <div class="text-xs text-slate-500">{{ project.categoryName }} / {{ commissionLabel(project) }}</div>
                </td>
                <td class="px-4 py-3 text-slate-700">{{ formatCurrency(project.budget) }}</td>
                <td class="px-4 py-3 text-emerald-700 font-bold">{{ formatCurrency(projectReceived(project)) }}</td>
                <td class="px-4 py-3 text-orange-600 font-bold">{{ formatCurrency(projectPending(project)) }}</td>
                <td class="px-4 py-3 text-slate-700">{{ formatCurrency(projectCommissionEarned(project)) }}</td>
                <td class="px-4 py-3">
                  <span class="inline-flex rounded-full bg-slate-100 px-3 py-1 text-xs font-bold capitalize text-slate-700">{{ projectPaymentStatus(project) }}</span>
                </td>
                <td class="px-4 py-3 text-right">
                  <button @click="openProject(project.financeKey)" class="text-sm border border-slate-200 bg-white text-slate-700 font-medium rounded-lg px-3 py-2 hover:bg-slate-50 transition-colors">
                    Open
                  </button>
                </td>
              </tr>
              <tr v-if="projectLedgerTable.filteredRows.value.length === 0">
                <td colspan="7" class="px-4 py-8 text-center text-sm text-slate-500">No finance records available.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </section>

    <section v-if="activeTab === 'managers' && isSuperAdmin" class="space-y-4">
      <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-3">
          <div>
            <div class="text-sm font-bold text-slate-900">Manager finance overview</div>
            <div class="text-xs text-slate-600 mt-1">Grouped by manager so each person owns their own project ledger.</div>
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 lg:w-[520px]">
            <input v-model="managerQuery" type="search" placeholder="Search manager or project..." class="table-filter-input" />
            <select v-model="selectedManagerId" class="table-filter-input">
              <option value="all">All managers</option>
              <option v-for="manager in managers" :key="manager.id" :value="manager.id">{{ manager.name }}</option>
            </select>
          </div>
        </div>
      </div>

      <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
        <div v-for="row in filteredManagerRows" :key="row.manager.id" class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm">
          <div class="px-4 py-3 border-b border-slate-200 flex items-start justify-between gap-3">
            <div>
              <div class="text-base font-bold text-slate-900">{{ row.manager.name }}</div>
              <div class="text-xs text-slate-600 mt-1">{{ row.projectCount }} modules / {{ formatCurrency(row.pending) }} pending from clients</div>
            </div>
            <div class="flex items-start gap-3">
              <div class="text-right">
                <div class="text-[10px] uppercase tracking-wide text-slate-500 font-bold">Owed</div>
                <div class="text-base font-extrabold text-orange-600">{{ formatCurrency(row.owed) }}</div>
              </div>
              <button @click="toggleManager(row.manager.id)" class="text-sm border border-slate-200 bg-white text-slate-700 font-medium rounded-lg px-3 py-2 hover:bg-slate-50 transition-colors">
                {{ isManagerExpanded(row.manager.id) ? 'Hide' : 'View' }}
              </button>
            </div>
          </div>
          <div v-if="isManagerExpanded(row.manager.id)" class="border-t border-slate-200">
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-2 p-4">
              <div class="rounded-lg bg-slate-50 border border-slate-200 p-3">
                <div class="text-[10px] uppercase tracking-wide text-slate-500 font-bold">Budget</div>
                <div class="text-sm font-extrabold text-slate-900 mt-1">{{ formatCurrency(row.budget) }}</div>
              </div>
              <div class="rounded-lg bg-slate-50 border border-slate-200 p-3">
                <div class="text-[10px] uppercase tracking-wide text-slate-500 font-bold">Received</div>
                <div class="text-sm font-extrabold text-emerald-700 mt-1">{{ formatCurrency(row.received) }}</div>
              </div>
              <div class="rounded-lg bg-slate-50 border border-slate-200 p-3">
                <div class="text-[10px] uppercase tracking-wide text-slate-500 font-bold">Earned</div>
                <div class="text-sm font-extrabold text-slate-900 mt-1">{{ formatCurrency(row.earned) }}</div>
              </div>
              <div class="rounded-lg bg-slate-50 border border-slate-200 p-3">
                <div class="text-[10px] uppercase tracking-wide text-slate-500 font-bold">Paid</div>
                <div class="text-sm font-extrabold text-emerald-700 mt-1">{{ formatCurrency(row.paid) }}</div>
              </div>
            </div>
            <div class="divide-y divide-slate-200 border-t border-slate-200">
              <div v-for="project in row.projects" :key="project.financeKey" class="grid grid-cols-1 md:grid-cols-[1fr_auto] gap-3 px-4 py-3">
                <div class="min-w-0">
                  <div class="text-sm font-bold text-slate-900 truncate">{{ project.name }}</div>
                  <div class="text-xs text-slate-500 mt-1">
                    {{ project.categoryName }} / {{ formatCurrency(projectReceived(project)) }} received / {{ formatCurrency(projectPending(project)) }} pending / {{ commissionLabel(project) }}
                  </div>
                </div>
                <button @click="openProject(project.financeKey)" class="text-sm border border-slate-200 bg-white text-slate-700 font-medium rounded-lg px-3 py-2 hover:bg-slate-50 transition-colors">
                  Open
                </button>
              </div>
              <div v-if="row.projects.length === 0" class="px-4 py-6 text-sm text-slate-500 text-center">No modules assigned to this manager.</div>
            </div>
          </div>
        </div>
        <div v-if="filteredManagerRows.length === 0" class="bg-white border border-slate-200 rounded-xl p-6 text-sm text-slate-500">
          No managers or modules match this filter.
        </div>
      </div>
    </section>

    <section v-if="activeTab === 'payments'" class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm">
      <div class="px-4 py-3 border-b border-slate-200">
        <div class="text-sm font-bold text-slate-900">Payment audit trail</div>
        <div class="text-xs text-slate-600 mt-1">Every received client payment with module, parent category, manager, method, and reference.</div>
      </div>
      <div class="px-4 py-3 border-b border-slate-200 grid grid-cols-1 md:grid-cols-[1fr_220px_auto] gap-2">
        <input v-model="paymentTable.query.value" type="search" placeholder="Filter payments..." class="table-filter-input" />
        <select v-model="paymentStatusFilter" class="table-filter-input">
          <option value="all">All payments</option>
          <option value="partial">Modules partially paid</option>
          <option value="paid">Modules fully paid</option>
        </select>
        <div class="table-count">{{ paymentTable.filteredRows.value.length }} records</div>
      </div>
      <div class="table-scroll" @scroll="paymentTable.loadMoreRows">
        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-slate-200 text-left bg-slate-50/80">
              <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Date</th>
              <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Module / Project</th>
              <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Manager</th>
              <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Amount</th>
              <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Method</th>
              <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Reference</th>
              <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide text-right">Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in paymentTable.visibleRows.value" :key="`${row.project.financeKey}-${row.payment.id}`" class="border-b border-slate-200 last:border-0 hover:bg-slate-50/60 transition-colors">
              <td class="px-4 py-3 text-slate-700">{{ row.payment.date }}</td>
              <td class="px-4 py-3">
                <div class="text-slate-900 font-bold">{{ row.project.name }}</div>
                <div class="text-xs text-slate-500">{{ row.project.categoryName }}</div>
              </td>
              <td class="px-4 py-3 text-slate-700">{{ employeeName(row.project.managerId) }}</td>
              <td class="px-4 py-3 text-emerald-700 font-bold">{{ formatCurrency(row.payment.amount) }}</td>
              <td class="px-4 py-3 text-slate-700">{{ row.payment.method }}</td>
              <td class="px-4 py-3 text-slate-600">
                {{ row.payment.reference }}
                <span class="block text-xs text-slate-500">{{ row.payment.payer || 'Client' }}</span>
              </td>
              <td class="px-4 py-3 text-right">
                <button @click="openProject(row.project.financeKey)" class="text-sm border border-slate-200 bg-white text-slate-700 font-medium rounded-lg px-3 py-2 hover:bg-slate-50 transition-colors">
                  Open
                </button>
              </td>
            </tr>
            <tr v-if="paymentTable.filteredRows.value.length === 0">
              <td colspan="7" class="px-4 py-8 text-center text-sm text-slate-500">No payments match this filter.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <section v-if="activeTab === 'commissions'" class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm">
      <div class="px-4 py-3 border-b border-slate-200">
        <div class="text-sm font-bold text-slate-900">Commission approvals</div>
        <div class="text-xs text-slate-600 mt-1">Review earned commission and move it through pending, approved, and paid.</div>
      </div>
      <div class="px-4 py-3 border-b border-slate-200 grid grid-cols-1 md:grid-cols-[1fr_200px_auto] gap-2">
        <input v-model="commissionTable.query.value" type="search" placeholder="Filter commissions..." class="table-filter-input" />
        <select v-model="commissionStatusFilter" class="table-filter-input">
          <option value="all">All statuses</option>
          <option value="pending">Pending</option>
          <option value="approved">Approved</option>
          <option value="paid">Paid</option>
        </select>
        <div class="table-count">{{ commissionTable.filteredRows.value.length }} records</div>
      </div>
      <div class="table-scroll" @scroll="commissionTable.loadMoreRows">
        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-slate-200 text-left bg-slate-50/80">
              <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Module / Project</th>
              <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Manager</th>
              <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Earned</th>
              <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Paid</th>
              <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Owed</th>
              <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Status</th>
              <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide text-right">Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in commissionTable.visibleRows.value" :key="row.project.financeKey" class="border-b border-slate-200 last:border-0 hover:bg-slate-50/60 transition-colors">
              <td class="px-4 py-3">
                <div class="text-slate-900 font-bold">{{ row.project.name }}</div>
                <div class="text-xs text-slate-500">{{ row.project.categoryName }} / {{ commissionLabel(row.project) }}</div>
              </td>
              <td class="px-4 py-3 text-slate-700">{{ employeeName(row.project.managerId) }}</td>
              <td class="px-4 py-3 text-slate-700">{{ formatCurrency(row.earned) }}</td>
              <td class="px-4 py-3 text-emerald-700 font-bold">{{ formatCurrency(row.paid) }}</td>
              <td class="px-4 py-3 text-orange-600 font-bold">{{ formatCurrency(row.owed) }}</td>
              <td class="px-4 py-3">
                <span class="inline-flex rounded-full bg-slate-100 px-3 py-1 text-xs font-bold capitalize text-slate-700">{{ row.status }}</span>
              </td>
              <td class="px-4 py-3">
                <div class="flex justify-end gap-2">
                  <button
                    v-if="isSuperAdmin"
                    @click="updateCommissionStatus(row.project, 'approved')"
                    :disabled="decidingCommissionId !== null || row.status === 'approved' || row.status === 'paid' || row.earned <= 0"
                    class="text-xs font-semibold rounded-lg px-3 py-2 bg-emerald-600 text-white hover:bg-emerald-700 disabled:bg-slate-300 disabled:cursor-not-allowed"
                  >
                    Approve
                  </button>
                  <button
                    v-if="isSuperAdmin"
                    @click="updateCommissionStatus(row.project, 'paid')"
                    :disabled="decidingCommissionId !== null || row.status !== 'approved'"
                    class="text-xs font-semibold rounded-lg px-3 py-2 bg-slate-950 text-white hover:bg-black disabled:bg-slate-300 disabled:cursor-not-allowed"
                  >
                    Mark paid
                  </button>
                  <button @click="openProject(row.project.financeKey)" class="text-xs border border-slate-200 bg-white text-slate-700 font-medium rounded-lg px-3 py-2 hover:bg-slate-50 transition-colors">
                    Open
                  </button>
                </div>
              </td>
            </tr>
            <tr v-if="commissionTable.filteredRows.value.length === 0">
              <td colspan="7" class="px-4 py-8 text-center text-sm text-slate-500">No commissions match this filter.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <Teleport to="body">
    <div v-if="showProjectModal && selectedProject" class="orbit-modal-backdrop fixed inset-0 z-50 flex items-center justify-center px-4 py-4 overflow-y-auto" @click.self="closeProject">
      <div class="orbit-modal p-6 w-full max-w-4xl max-h-[calc(100vh-2rem)] overflow-y-auto">
        <div class="flex items-start justify-between gap-4 mb-5">
          <div>
            <div class="font-semibold text-[15px] text-slate-900">Module financials</div>
            <div class="text-sm text-slate-600 mt-1">{{ selectedProject.categoryName }} / {{ selectedProject.name }} / {{ commissionLabel(selectedProject) }}</div>
          </div>
          <button @click="closeProject" class="text-slate-500 hover:text-slate-900 text-xl leading-none">x</button>
        </div>

        <div class="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-5">
          <div class="rounded-lg bg-slate-50 border border-slate-200 p-3">
            <div class="text-[10px] uppercase tracking-wide text-slate-500 font-bold">Budget</div>
            <div class="text-lg font-extrabold text-slate-900 mt-1">{{ formatCurrency(selectedProject.budget) }}</div>
          </div>
          <div class="rounded-lg bg-slate-50 border border-slate-200 p-3">
            <div class="text-[10px] uppercase tracking-wide text-slate-500 font-bold">Received</div>
            <div class="text-lg font-extrabold text-emerald-700 mt-1">{{ formatCurrency(projectReceived(selectedProject)) }}</div>
          </div>
          <div class="rounded-lg bg-slate-50 border border-slate-200 p-3">
            <div class="text-[10px] uppercase tracking-wide text-slate-500 font-bold">Pending</div>
            <div class="text-lg font-extrabold text-orange-600 mt-1">{{ formatCurrency(projectPending(selectedProject)) }}</div>
          </div>
          <div class="rounded-lg bg-slate-50 border border-slate-200 p-3">
            <div class="text-[10px] uppercase tracking-wide text-slate-500 font-bold">Commission owed</div>
            <div class="text-lg font-extrabold text-slate-900 mt-1">{{ formatCurrency(projectCommissionOwed(selectedProject)) }}</div>
          </div>
          <div class="rounded-lg bg-slate-50 border border-slate-200 p-3">
            <div class="text-[10px] uppercase tracking-wide text-slate-500 font-bold">Status</div>
            <div class="text-sm font-extrabold text-slate-900 mt-2 capitalize">{{ selectedProject.commission?.status || 'pending' }}</div>
          </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-[1fr_280px] gap-4">
          <div class="rounded-xl border border-slate-200 p-4">
            <div class="text-sm font-bold text-slate-900 mb-3">Log client payment</div>
            <div class="grid grid-cols-1 md:grid-cols-5 gap-2">
              <input v-model="paymentForm.amount" type="number" min="0" placeholder="Amount" class="text-sm border border-slate-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-1 focus:ring-emerald-500 text-slate-700" />
              <input v-model="paymentForm.date" type="date" class="text-sm border border-slate-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-1 focus:ring-emerald-500 text-slate-700" />
              <select v-model="paymentForm.method" class="text-sm border border-slate-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-1 focus:ring-emerald-500 text-slate-700">
                <option>Bank transfer</option>
                <option>Cheque</option>
                <option>Cash</option>
                <option>JazzCash</option>
                <option>EasyPaisa</option>
                <option>International wire</option>
              </select>
              <input v-model="paymentForm.payer" type="text" placeholder="Client / payer" class="text-sm border border-slate-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-1 focus:ring-emerald-500 text-slate-700" />
              <input v-model="paymentForm.reference" type="text" placeholder="Reference #" class="text-sm border border-slate-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-1 focus:ring-emerald-500 text-slate-700" />
            </div>
            <button @click="logPayment" :disabled="savingPayment || !paymentForm.amount || Number(paymentForm.amount) <= 0 || !paymentForm.date" class="mt-3 bg-emerald-600 text-white text-sm font-semibold rounded-lg px-4 py-2 hover:bg-emerald-700 disabled:bg-slate-300 disabled:cursor-not-allowed transition-colors">
              {{ savingPayment ? 'Logging…' : 'Log payment' }}
            </button>
            <div v-if="financeError" class="mt-3 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-700" role="alert">
              {{ financeError }}
            </div>

            <div class="mt-4 rounded-xl border border-slate-200 overflow-hidden">
              <div class="px-4 py-3 text-sm font-bold text-slate-900 border-b border-slate-200">Payment timeline</div>
              <div v-if="selectedProject.payments?.length" class="p-4 space-y-3 max-h-[280px] overflow-y-auto modal-scroll-hidden">
                <div v-for="payment in selectedProject.payments || []" :key="payment.id" class="relative pl-8">
                  <div class="absolute left-2 top-2 bottom-[-14px] w-px bg-slate-200"></div>
                  <div class="absolute left-0 top-1.5 h-4 w-4 rounded-full bg-emerald-500 ring-4 ring-emerald-100"></div>
                  <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-2">
                      <div>
                        <div class="text-sm font-extrabold text-slate-900">{{ formatCurrency(payment.amount) }}</div>
                        <div class="text-xs font-semibold text-slate-600 mt-1">{{ payment.payer || 'Client' }} - {{ payment.method }}</div>
                      </div>
                      <div class="flex items-center gap-2">
                        <div class="text-xs font-bold text-slate-500">{{ payment.date }}</div>
                        <button
                          v-if="isSuperAdmin"
                          @click="removePayment(payment)"
                          :disabled="removingPaymentId !== null"
                          class="text-[11px] font-bold text-rose-600 hover:text-rose-700 disabled:opacity-50"
                        >
                          {{ removingPaymentId === payment.id ? 'Removing…' : 'Remove' }}
                        </button>
                      </div>
                    </div>
                    <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs">
                      <div class="rounded-lg border border-slate-200 bg-white p-2">
                        <div class="font-bold uppercase tracking-wide text-slate-500">Reference</div>
                        <div class="font-extrabold text-slate-900 mt-1 truncate">{{ payment.reference || '-' }}</div>
                      </div>
                      <div class="rounded-lg border border-slate-200 bg-white p-2">
                        <div class="font-bold uppercase tracking-wide text-slate-500">Logged by</div>
                        <div class="font-extrabold text-slate-900 mt-1">{{ payment.loggedByName || employeeName(payment.loggedById) }}</div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
              <div v-else class="app-empty-state m-4">
                <div class="app-empty-title">No client payments logged</div>
                <div class="app-empty-copy">Payments added here will build the module finance audit trail.</div>
              </div>
            </div>
          </div>

          <div class="rounded-xl border border-slate-200 p-4">
            <div class="text-sm font-bold text-slate-900">Manager commission</div>
            <div class="text-xs text-slate-600 mt-1">{{ employeeName(selectedProject.managerId) }}</div>
            <div class="mt-4 space-y-3">
              <div class="rounded-lg bg-slate-50 border border-slate-200 p-3">
                <div class="text-[10px] uppercase tracking-wide text-slate-500 font-bold">Earned</div>
                <div class="text-lg font-extrabold text-slate-900 mt-1">{{ formatCurrency(projectCommissionEarned(selectedProject)) }}</div>
              </div>
              <div class="rounded-lg bg-slate-50 border border-slate-200 p-3">
                <div class="text-[10px] uppercase tracking-wide text-slate-500 font-bold">Paid</div>
                <div class="text-lg font-extrabold text-emerald-700 mt-1">{{ formatCurrency(projectCommissionPaid(selectedProject)) }}</div>
              </div>
            </div>
            <div v-if="isSuperAdmin" class="mt-4 grid grid-cols-2 gap-2">
              <button @click="updateCommissionStatus(selectedProject, 'approved')" :disabled="decidingCommissionId !== null || selectedProject.commission?.status === 'approved' || selectedProject.commission?.status === 'paid' || projectCommissionEarned(selectedProject) <= 0" class="text-sm font-semibold rounded-lg px-3 py-2 bg-emerald-600 text-white hover:bg-emerald-700 disabled:bg-slate-300 disabled:cursor-not-allowed">
                Approve
              </button>
              <button @click="updateCommissionStatus(selectedProject, 'paid')" :disabled="decidingCommissionId !== null || selectedProject.commission?.status !== 'approved'" class="text-sm font-semibold rounded-lg px-3 py-2 bg-slate-950 text-white hover:bg-black disabled:bg-slate-300 disabled:cursor-not-allowed">
                Mark paid
              </button>
            </div>
            <div v-else class="mt-4 rounded-lg bg-slate-50 border border-slate-200 p-3 text-xs text-slate-600">
              Only Super Admin can approve or mark commission as paid.
            </div>
          </div>
        </div>
      </div>
    </div>
    </Teleport>
  </div>

  <div v-else class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
    <div class="text-lg font-semibold text-slate-900">Finance is not available for this role</div>
    <div class="text-sm text-slate-600 mt-1">Developers and HR do not have access to module budgets, payments, or commission records.</div>
  </div>
</template>
