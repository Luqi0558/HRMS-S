<script setup>
import { computed, onMounted, ref } from 'vue'
import { useStore } from '../store/index.js'
import { useTableControls } from '../composables/useTableControls.js'
import { payrollService } from '../services/payrollService.js'
import { employeeService } from '../services/employeeService.js'

const {
  state, can, formatCurrency, currencyCode, formatDate,
  formatDateTime: formatCompanyDateTime, logActivity,
  setSalarySlipRequests, upsertSalarySlipRequest, setEmployees
} = useStore()

const loadError = ref('')
const busyRowId = ref(null)
const busyRunId = ref(null)
const payrollRuns = ref([])
const salaryHistoryByEmployee = ref({})
const salarySetupRow = ref(null)
const salarySetupDraft = ref(null)
const salarySetupError = ref('')
const savingSalary = ref(false)
// Payroll figures are money — validate before they reach the record. Also
// carries API failures back to the reviewer.
const payrollError = ref('')

/**
 * Requests carry their issued slip, so one call covers both. Employees are
 * needed to name the rows; that fetch is non-fatal because an employee holds
 * no Employees permission but must still see their own payslip.
 */
async function loadPayroll() {
  const calls = [
    payrollService.requests(),
    employeeService.list()
  ]
  if (state.currentUser.role === 'hr' || state.currentUser.role === 'super_admin') calls.push(payrollService.runs())

  const [requests, employees, runs] = await Promise.allSettled(calls)

  if (requests.status === 'fulfilled') setSalarySlipRequests(requests.value)
  else loadError.value = requests.reason?.message || 'Unable to load payroll.'

  if (employees.status === 'fulfilled') {
    setEmployees(employees.value.data)
    if (['hr', 'super_admin'].includes(state.currentUser.role)) {
      await loadSalaryHistories(employees.value.data)
    }
  }
  if (runs?.status === 'fulfilled') payrollRuns.value = runs.value
}

onMounted(loadPayroll)

const canAccess = computed(() => can('Payroll', 'view'))
// Separation of duties: HR prepares and records payment; Super Admin performs
// the independent release. Other staff only see their own issued slips.
const canManagePayroll = computed(() => state.currentUser.role === 'hr' && can('Payroll', 'create'))
const canApprovePayroll = computed(() => state.currentUser.role === 'super_admin' && can('Payroll Approval', 'approve'))
const currentEmployeeId = computed(() => state.currentUser.employeeId ?? state.currentUser.id)
const selectedMonth = ref('July 2026')
const selectedPeriod = ref({ from: '2026-07-01', to: '2026-07-31' })
const selectedPayroll = ref(null)
const editingPayroll = ref(null)
const payrollDraft = ref(null)
const paymentPayroll = ref(null)
const paymentDraft = ref(null)
const selectedPayrollIds = ref([])

const monthPeriods = {
  'July 2026': { from: '2026-07-01', to: '2026-07-31' },
  'June 2026': { from: '2026-06-01', to: '2026-06-30' },
  'May 2026': { from: '2026-05-01', to: '2026-05-31' }
}

function syncSelectedMonth() {
  const period = monthPeriods[selectedMonth.value]
  if (period) selectedPeriod.value = { ...period }
  selectedPayrollIds.value = []
}

const selectedRun = computed(() => {
  const period = selectedPeriod.value.from.slice(0, 7) + '-01'
  return payrollRuns.value.find(run => run.period === period) || null
})

function selectedRunLabel() {
  return selectedRun.value?.periodLabel || selectedMonth.value
}

function runStatusLabel(status) {
  return status ? status.charAt(0).toUpperCase() + status.slice(1) : 'Not opened'
}

function runStatusClass(status) {
  if (status === 'paid') return 'company-hub-badge-success'
  if (status === 'released') return 'company-hub-badge-info'
  if (status === 'submitted') return 'company-hub-badge-warning'
  return 'company-hub-badge-neutral'
}

const baseSalaryByRole = {
  employee: 95000,
  manager: 180000,
  hr: 130000
}

const statusFlow = ['requested', 'processing', 'generated', 'approved', 'paid']

function salaryForPeriod(employeeId, period = selectedPeriod.value.from) {
  return (salaryHistoryByEmployee.value[employeeId] || [])
    .find(salary => salary.effectiveFrom && salary.effectiveFrom <= period) || null
}

async function loadSalaryHistories(employees) {
  const activeEmployees = (employees || []).filter(employee => employee.status === 'active')
  const results = await Promise.allSettled(
    activeEmployees.map(employee => payrollService.salaries(employee.id))
  )

  salaryHistoryByEmployee.value = activeEmployees.reduce((histories, employee, index) => {
    histories[employee.id] = results[index]?.status === 'fulfilled' ? results[index].value : []
    return histories
  }, {})
}

async function refreshSalaryHistory(employeeId) {
  salaryHistoryByEmployee.value = {
    ...salaryHistoryByEmployee.value,
    [employeeId]: await payrollService.salaries(employeeId)
  }
}

function defaultPayrollDetails(employee, salary = salaryForPeriod(employee.id)) {
  const baseSalary = salary ? Number(salary.baseSalary) : (employee.salary || baseSalaryByRole[employee.role] || 90000)
  return {
    baseSalary,
    houseAllowance: salary ? Number(salary.houseAllowance) : Math.round(baseSalary * 0.12),
    medicalAllowance: salary ? Number(salary.medicalAllowance) : Math.round(baseSalary * 0.06),
    bonus: 0,
    taxDeduction: Math.round(baseSalary * 0.05),
    attendanceDeduction: 0,
    advanceDeduction: 0,
    notes: ''
  }
}

function payrollValues(employee, request, salary) {
  const details = {
    ...defaultPayrollDetails(employee, salary),
    ...(request?.details || {})
  }
  const allowances = Number(details.houseAllowance || 0) + Number(details.medicalAllowance || 0) + Number(details.bonus || 0)
  const deductions = Number(details.taxDeduction || 0) + Number(details.attendanceDeduction || 0) + Number(details.advanceDeduction || 0)
  return {
    ...details,
    allowances,
    deductions,
    netPay: Number(details.baseSalary || 0) + allowances - deductions
  }
}

const payrollRows = computed(() =>
  state.employees
    .filter(employee => employee.status === 'active')
    .map(employee => {
      const request = state.salarySlipRequests.find(item => item.employeeId === employee.id && item.month === selectedMonth.value)
      const salary = salaryForPeriod(employee.id)
      const values = payrollValues(employee, request, salary)
      return {
        id: `${selectedMonth.value}-${employee.id}`,
        employee,
        month: selectedMonth.value,
        ...values,
        slipNo: request?.slipNo || '',
        // Read by mark-paid, reopen, PDF download and bulk pay. It lives on
        // the request; without copying it here every one of those found no
        // slip and stopped, or tried to issue a second one.
        slipId: request?.slipId || null,
        generatedAt: request?.generatedAt || '',
        generatedById: request?.generatedById || null,
        paidAt: request?.paidAt || '',
        approvedAt: request?.approvedAt || '',
        approvedById: request?.approvedById || null,
        attendanceSummary: request?.attendanceSummary || null,
        period: request?.period || { ...selectedPeriod.value },
        payment: request?.payment || null,
        history: request?.history || [],
        status: request?.status || 'draft',
        salaryConfigured: Boolean(salary),
        requested: Boolean(request),
        request
      }
    })
    .filter(row => {
      if (canManagePayroll.value || canApprovePayroll.value) return true
      return row.employee.id === currentEmployeeId.value && ['generated', 'approved', 'paid'].includes(row.status)
    })
)

const payrollTable = useTableControls(
  payrollRows,
  row => `${row.employee.name} ${row.employee.email} ${row.employee.department} ${row.month} ${row.status}`,
  10
)

const selectedRows = computed(() =>
  payrollRows.value.filter(row => selectedPayrollIds.value.includes(row.id))
)

const selectableRows = computed(() =>
  payrollTable.visibleRows.value.filter(row => row.status !== 'paid' && row.salaryConfigured)
)

const allVisibleSelected = computed(() =>
  selectableRows.value.length > 0 && selectableRows.value.every(row => selectedPayrollIds.value.includes(row.id))
)

const totals = computed(() => ({
  employees: payrollRows.value.length,
  gross: payrollRows.value.reduce((total, row) => total + row.baseSalary + row.allowances, 0),
  deductions: payrollRows.value.reduce((total, row) => total + row.deductions, 0),
  net: payrollRows.value.reduce((total, row) => total + row.netPay, 0),
  paid: payrollRows.value.filter(row => row.status === 'paid').length,
  pendingRequests: state.salarySlipRequests.filter(request => !['approved', 'paid'].includes(request.status)).length
}))

function employeeName(id) {
  return state.employees.find(employee => employee.id === id)?.name || '-'
}

function statusLabel(status) {
  if (status === 'draft') return 'Draft'
  if (status === 'pending') return 'Pending'
  if (status === 'requested') return 'Requested'
  if (status === 'processing') return 'Processing'
  if (status === 'generated') return 'Generated'
  if (status === 'approved') return 'Approved'
  if (status === 'paid') return 'Paid'
  return status
}

function statusClass(status) {
  if (status === 'paid') return 'company-hub-badge-success'
  if (status === 'approved' || status === 'generated') return 'company-hub-badge-info'
  if (status === 'processing') return 'company-hub-badge-warning'
  if (status === 'requested' || status === 'pending') return 'company-hub-badge-neutral'
  return 'company-hub-badge-muted'
}

/**
 * What the payroll editor can do for a row, or null when it cannot edit it.
 *
 * - 'generate': no slip yet. Saving issues the slip with the edited figures.
 * - 'amend': the slip is open for correction (generated, draft run).
 * - null: released or paid. A released slip must be reopened first; its
 *   figures are an authorisation already given, and editing them in place
 *   used to change only what this screen showed.
 */
function payrollEditMode(row) {
  if (!row || row.status === 'paid') return null
  if (row.slipId) return row.status === 'generated' ? 'amend' : null
  return 'generate'
}

/**
 * Issues the slip for a row.
 *
 * The API issues it by approving the employee's request, so a row with no
 * request yet needs one raised first. Adjustments the reviewer made in the
 * editor ride along as overrides — they apply to this month's slip only and
 * leave the standing salary alone.
 */
async function generateSlip(row, openDetail = Boolean(selectedPayroll.value), figures = null) {
  if (!canManagePayroll.value || row.status === 'paid') return
  if (!row.salaryConfigured) {
    payrollError.value = 'Record the employee’s official salary before generating a payslip.'
    openSalarySetup(row)
    return
  }

  payrollError.value = ''
  busyRowId.value = row.id
  try {
    let request = row.request

    // A draft is the editor's local placeholder — its id is a timestamp, not
    // a record the API knows about, so a real request still has to be raised.
    if (!request || request.draft) {
      const draftId = request?.id
      const period = row.period?.from || selectedPeriod.value.from

      try {
        request = await payrollService.requestSlip(period, row.employee.id)
      } catch (error) {
        // 409 means a request for that month already exists — the employee
        // raised it themselves. Re-read and use theirs rather than failing.
        if (error?.status !== 409) throw error

        setSalarySlipRequests(await payrollService.requests())
        request = state.salarySlipRequests.find(
          item => item.employeeId === row.employee.id && item.month === row.month && !item.draft
        )
        if (!request) throw error
      }

      // Drop the placeholder so the row resolves to the real request.
      if (draftId) {
        const index = state.salarySlipRequests.findIndex(item => item.id === draftId)
        if (index !== -1) state.salarySlipRequests.splice(index, 1)
      }

      upsertSalarySlipRequest(request)
    }

    upsertSalarySlipRequest(await payrollService.decideRequest(request.id, 'approved', figures || {
      baseSalary: row.baseSalary,
      houseAllowance: row.houseAllowance,
      medicalAllowance: row.medicalAllowance,
      bonus: row.bonus,
      taxDeduction: row.taxDeduction,
      advanceDeduction: row.advanceDeduction,
      notes: row.notes || undefined
    }))
  } catch (error) {
    payrollError.value = error?.message || 'Unable to generate that slip.'
    return false
  } finally {
    busyRowId.value = null
  }

  logActivity({
    module: 'salary',
    action: 'payroll generated',
    details: `${employeeName(row.employee.id)} payroll for ${row.month} was generated.`,
    targetId: row.employee.id,
    targetType: 'employee'
  })

  const updatedRow = payrollRows.value.find(item => item.id === row.id) || row
  if (openDetail) selectedPayroll.value = updatedRow
  return true
}

function generatedByName(row) {
  const id = row.generatedById || row.request?.generatedById
  return state.employees.find(employee => employee.id === id)?.name || state.currentUser.name || 'HR / Super Admin'
}

function approvedByName(row) {
  const id = row.approvedById || row.request?.approvedById
  return state.employees.find(employee => employee.id === id)?.name || '-'
}

function formatDateTime(value) {
  return formatCompanyDateTime(value)
}

function paidDateLabel(row) {
  return row.payment?.paidDate || (row.paidAt ? formatDate(row.paidAt) : '-')
}

function ensureGeneratedForAction(row) {
  if (!row.slipNo && row.status !== 'paid') {
    generateSlip(row, true)
    return payrollRows.value.find(item => item.id === row.id) || row
  }
  return payrollRows.value.find(item => item.id === row.id) || row
}

function printSlip(row) {
  selectedPayroll.value = ensureGeneratedForAction(row)
  setTimeout(() => window.print(), 0)
}

async function downloadPdf(row) {
  let current = payrollRows.value.find(item => item.id === row.id) || row
  if (!current.slipId) {
    await generateSlip(current, true)
    current = payrollRows.value.find(item => item.id === row.id) || current
  }

  if (!current.slipId) return

  payrollError.value = ''
  try {
    await payrollService.downloadSlip(current.slipId)
  } catch (error) {
    payrollError.value = error?.message || 'Unable to download that payslip.'
  }
}

function todayInputValue() {
  const date = new Date()
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

function openDatePicker(event) {
  const input = event.currentTarget
  if (typeof input.showPicker === 'function') {
    try {
      input.showPicker()
    } catch {
      input.focus()
    }
    return
  }
  input.focus()
}

function openPaymentModal(row) {
  if (!canManagePayroll.value || row.status !== 'approved') return
  const currentRow = payrollRows.value.find(item => item.id === row.id) || row
  paymentPayroll.value = currentRow
  paymentDraft.value = {
    method: currentRow.payment?.method || 'Bank transfer',
    reference: currentRow.payment?.reference || '',
    paidDate: currentRow.payment?.paidDate || todayInputValue(),
    notes: currentRow.payment?.notes || ''
  }
}

async function confirmPaid() {
  if (!paymentPayroll.value || !paymentDraft.value) return
  const row = paymentPayroll.value
  if (row.status !== 'approved' || !row.slipId) return

  payrollError.value = ''
  busyRowId.value = row.id
  try {
    const slip = await payrollService.markPaid(row.slipId, {
      method: paymentDraft.value.method,
      reference: paymentDraft.value.reference.trim(),
      paidDate: paymentDraft.value.paidDate,
      notes: paymentDraft.value.notes.trim()
    })
    upsertSalarySlipRequest({ ...row.request, slip })
  } catch (error) {
    payrollError.value = error?.message || 'Unable to record that payment.'
    return
  } finally {
    busyRowId.value = null
  }

  logActivity({
    module: 'salary',
    action: 'payroll paid',
    details: `${employeeName(row.employee.id)} payroll for ${row.month} was marked paid via ${paymentDraft.value.method}.`,
    targetId: row.employee.id,
    targetType: 'employee'
  })
  const updatedRow = payrollRows.value.find(item => item.id === row.id) || row
  selectedPayroll.value = updatedRow
  paymentPayroll.value = null
  paymentDraft.value = null
  await refreshRuns()
}

async function reopenPayroll(row) {
  if (!canManagePayroll.value || !row.slipId) return

  payrollError.value = ''
  busyRowId.value = row.id
  try {
    const slip = await payrollService.reopenSlip(row.slipId)
    upsertSalarySlipRequest({ ...row.request, slip })
  } catch (error) {
    payrollError.value = error?.message || 'Unable to reopen that payroll.'
    return
  } finally {
    busyRowId.value = null
  }

  logActivity({
    module: 'salary',
    action: 'payroll reopened',
    details: `${employeeName(row.employee.id)} payroll for ${row.month} was reopened for correction.`,
    targetId: row.employee.id,
    targetType: 'employee'
  })
  const updatedRow = payrollRows.value.find(item => item.id === row.id) || row
  if (selectedPayroll.value?.id === row.id) selectedPayroll.value = updatedRow
}

function openPayrollEditor(row) {
  if (!canManagePayroll.value || !payrollEditMode(row)) return
  payrollError.value = ''
  editingPayroll.value = row
  payrollDraft.value = {
    baseSalary: row.baseSalary,
    houseAllowance: row.houseAllowance,
    medicalAllowance: row.medicalAllowance,
    bonus: row.bonus,
    taxDeduction: row.taxDeduction,
    advanceDeduction: row.advanceDeduction,
    notes: row.notes || ''
  }
}

/**
 * A monthly adjustment is not an employment salary.  This modal creates the
 * auditable standing rate the payroll service needs before it can issue a
 * payslip.  The proposed values are only a starting point for HR to confirm.
 */
function openSalarySetup(row) {
  if (!canManagePayroll.value || row.status === 'paid') return

  salarySetupError.value = ''
  salarySetupRow.value = row
  salarySetupDraft.value = {
    baseSalary: row.baseSalary,
    houseAllowance: row.houseAllowance,
    medicalAllowance: row.medicalAllowance,
    effectiveFrom: row.period?.from || selectedPeriod.value.from,
    reason: 'Initial salary record'
  }
}

async function saveSalarySetup() {
  const row = salarySetupRow.value
  const draft = salarySetupDraft.value
  if (!row || !draft || savingSalary.value) return

  const numericFields = ['baseSalary', 'houseAllowance', 'medicalAllowance']
  for (const field of numericFields) {
    const value = Number(draft[field])
    if (Number.isNaN(value) || value < 0 || (field === 'baseSalary' && value === 0)) {
      salarySetupError.value = field === 'baseSalary'
        ? 'Base salary must be greater than zero.'
        : 'Allowances must be zero or greater.'
      return
    }
  }
  if (!draft.effectiveFrom) {
    salarySetupError.value = 'Choose the date from which this salary applies.'
    return
  }

  savingSalary.value = true
  salarySetupError.value = ''
  try {
    await payrollService.setSalary(row.employee.id, {
      baseSalary: Number(draft.baseSalary),
      houseAllowance: Number(draft.houseAllowance),
      medicalAllowance: Number(draft.medicalAllowance),
      effectiveFrom: draft.effectiveFrom,
      reason: draft.reason.trim() || 'Initial salary record'
    })
    await refreshSalaryHistory(row.employee.id)
    const refreshedRow = payrollRows.value.find(item => item.id === row.id)
    if (selectedPayroll.value?.id === row.id && refreshedRow) selectedPayroll.value = refreshedRow
    salarySetupRow.value = null
    salarySetupDraft.value = null
    payrollError.value = ''
  } catch (error) {
    salarySetupError.value = error?.message || 'Unable to record the salary.'
  } finally {
    savingSalary.value = false
  }
}

const draftTotals = computed(() => {
  if (!payrollDraft.value) return { allowances: 0, deductions: 0, netPay: 0 }
  const allowances = Number(payrollDraft.value.houseAllowance || 0) + Number(payrollDraft.value.medicalAllowance || 0) + Number(payrollDraft.value.bonus || 0)
  const deductions = Number(payrollDraft.value.taxDeduction || 0) + Number(payrollDraft.value.advanceDeduction || 0)
  return {
    allowances,
    deductions,
    netPay: Number(payrollDraft.value.baseSalary || 0) + allowances - deductions
  }
})

function validatePayrollDraft(draft) {
  const fields = [
    ['baseSalary', 'Base salary'],
    ['houseAllowance', 'House allowance'],
    ['medicalAllowance', 'Medical allowance'],
    ['bonus', 'Bonus'],
    ['taxDeduction', 'Tax deduction'],
    ['advanceDeduction', 'Advance deduction']
  ]
  for (const [key, label] of fields) {
    const value = Number(draft[key] || 0)
    if (Number.isNaN(value)) return `${label} must be a number.`
    if (value < 0) return `${label} cannot be negative.`
  }
  if (Number(draft.baseSalary || 0) <= 0) return 'Base salary must be greater than zero.'
  const earnings = Number(draft.baseSalary || 0) + Number(draft.houseAllowance || 0)
    + Number(draft.medicalAllowance || 0) + Number(draft.bonus || 0)
  const deductions = Number(draft.taxDeduction || 0) + Number(draft.advanceDeduction || 0)
  if (deductions > earnings) return 'Total deductions cannot exceed total earnings.'
  return ''
}

const savingPayroll = ref(false)

/**
 * Saves the editor's figures on the server.
 *
 * This used to write them into the request held in the browser — inventing
 * a request with a timestamp id and a "processing" status when there was
 * none — so the figures were gone on refresh, and on an issued slip the
 * screen showed a net pay the employee's downloaded slip did not have.
 * Now a row with no slip has it generated with these figures, and an open
 * slip is corrected through the API. A released slip cannot reach here.
 */
async function savePayrollDetails() {
  const row = editingPayroll.value
  if (!row || !payrollDraft.value || savingPayroll.value) return
  payrollError.value = validatePayrollDraft(payrollDraft.value)
  if (payrollError.value) return

  const mode = payrollEditMode(row)
  if (!mode) {
    payrollError.value = 'Reopen this slip for correction before changing it.'
    return
  }

  const figures = {
    baseSalary: Number(payrollDraft.value.baseSalary || 0),
    houseAllowance: Number(payrollDraft.value.houseAllowance || 0),
    medicalAllowance: Number(payrollDraft.value.medicalAllowance || 0),
    bonus: Number(payrollDraft.value.bonus || 0),
    taxDeduction: Number(payrollDraft.value.taxDeduction || 0),
    advanceDeduction: Number(payrollDraft.value.advanceDeduction || 0),
    notes: payrollDraft.value.notes || null
  }

  savingPayroll.value = true
  try {
    if (mode === 'generate') {
      // generateSlip reports its own error into payrollError.
      if (!await generateSlip(row, selectedPayroll.value?.id === row.id, figures)) return
    } else {
      const { request } = await payrollService.amendSlip(row.slipId, figures)
      if (request) upsertSalarySlipRequest(request)
    }
  } catch (error) {
    payrollError.value = error?.message || 'Unable to save those payroll details.'
    return
  } finally {
    savingPayroll.value = false
  }

  const updatedRow = payrollRows.value.find(item => item.id === row.id)
  if (selectedPayroll.value?.id === row.id && updatedRow) {
    selectedPayroll.value = updatedRow
  }
  editingPayroll.value = null
  payrollDraft.value = null
}

function togglePayrollSelection(rowId) {
  if (selectedPayrollIds.value.includes(rowId)) {
    selectedPayrollIds.value = selectedPayrollIds.value.filter(id => id !== rowId)
  } else {
    selectedPayrollIds.value = [...selectedPayrollIds.value, rowId]
  }
}

function toggleAllVisiblePayroll() {
  if (allVisibleSelected.value) {
    const visibleIds = new Set(selectableRows.value.map(row => row.id))
    selectedPayrollIds.value = selectedPayrollIds.value.filter(id => !visibleIds.has(id))
    return
  }
  selectedPayrollIds.value = [...new Set([...selectedPayrollIds.value, ...selectableRows.value.map(row => row.id)])]
}

async function generateSelected() {
  for (const row of selectedRows.value.filter(item => item.status !== 'paid')) {
    await generateSlip(row, false)
  }
  await refreshRuns()
}

async function refreshRuns() {
  if (!canManagePayroll.value && !canApprovePayroll.value) return
  try {
    payrollRuns.value = await payrollService.runs()
  } catch (error) {
    payrollError.value = error?.message || 'Unable to refresh the payroll run.'
  }
}

async function openPayrollRun() {
  if (!canManagePayroll.value || selectedRun.value) return

  payrollError.value = ''
  busyRunId.value = 'open'
  try {
    const run = await payrollService.openRun(selectedPeriod.value.from)
    payrollRuns.value = [run, ...payrollRuns.value.filter(item => item.id !== run.id)]
  } catch (error) {
    payrollError.value = error?.message || 'Unable to open this payroll run.'
  } finally {
    busyRunId.value = null
  }
}

async function submitPayrollRun() {
  const run = selectedRun.value
  if (!canManagePayroll.value || !run || run.status !== 'draft') return

  payrollError.value = ''
  busyRunId.value = run.id
  try {
    const updated = await payrollService.submitRun(run.id)
    payrollRuns.value = payrollRuns.value.map(item => item.id === updated.id ? updated : item)
  } catch (error) {
    payrollError.value = error?.message || 'Unable to submit this payroll run.'
  } finally {
    busyRunId.value = null
  }
}

async function releasePayrollRun() {
  const run = selectedRun.value
  if (!canApprovePayroll.value || !run || run.status !== 'submitted') return

  payrollError.value = ''
  busyRunId.value = run.id
  try {
    const updated = await payrollService.releaseRun(run.id)
    payrollRuns.value = payrollRuns.value.map(item => item.id === updated.id ? updated : item)
    await loadPayroll()
  } catch (error) {
    payrollError.value = error?.message || 'Unable to release this payroll run.'
  } finally {
    busyRunId.value = null
  }
}

async function markSelectedPaid() {
  payrollError.value = ''

  for (const row of selectedRows.value.filter(item => item.status === 'approved' && item.slipId)) {
    busyRowId.value = row.id
    try {
      const slip = await payrollService.markPaid(row.slipId, {
        method: 'Bank transfer',
        reference: `BULK-${selectedMonth.value.replace(/\s/g, '').toUpperCase()}`,
        paidDate: todayInputValue(),
        notes: 'Bulk payroll payment'
      })
      upsertSalarySlipRequest({ ...row.request, slip })
    } catch (error) {
      payrollError.value = `${employeeName(row.employee.id)}: ${error?.message || 'payment failed'}`
    } finally {
      busyRowId.value = null
    }
  }

  await refreshRuns()
}

</script>

<template>
  <div v-if="canAccess" class="orbit-page space-y-6">
    <div class="orbit-page-header flex flex-col xl:flex-row xl:items-end justify-between gap-4">
      <div>
        <div class="text-sm text-slate-600 mt-1">
          {{ canManagePayroll ? 'Prepare monthly payroll, review calculated attendance adjustments, and record released payments.' : canApprovePayroll ? 'Review and release HR-prepared payroll for payment.' : 'View, print, and download your issued salary slips.' }}
        </div>
        <div v-if="false" class="payroll-run-summary">
          <span class="company-hub-badge" :class="runStatusClass(selectedRun?.status)">
            {{ runStatusLabel(selectedRun?.status) }}
          </span>
          <span v-if="selectedRun">
            {{ selectedRun.slipCount }} generated slip{{ selectedRun.slipCount === 1 ? '' : 's' }} · {{ selectedRun.paidSlipCount }} paid.
            {{ canManagePayroll ? 'Complete the register, then submit it for independent release.' : 'The complete submitted register can be released for payment.' }}
          </span>
          <span v-else>No payroll run has been opened for this month.</span>
        </div>
      </div>
      <div class="flex flex-wrap items-end gap-2">
        <label class="block">
          <span class="mb-1 block text-[11px] font-bold uppercase tracking-wide text-slate-500">Month</span>
          <select v-model="selectedMonth" class="table-filter-input max-w-[180px]" @change="syncSelectedMonth">
            <option>July 2026</option>
            <option>June 2026</option>
            <option>May 2026</option>
          </select>
        </label>
        <label v-if="canManagePayroll" class="block">
          <span class="mb-1 block text-[11px] font-bold uppercase tracking-wide text-slate-500">From</span>
          <input v-model="selectedPeriod.from" type="date" class="table-filter-input max-w-[160px] cursor-pointer" @click="openDatePicker" @focus="openDatePicker" />
        </label>
        <label v-if="canManagePayroll" class="block">
          <span class="mb-1 block text-[11px] font-bold uppercase tracking-wide text-slate-500">To</span>
          <input v-model="selectedPeriod.to" type="date" class="table-filter-input max-w-[160px] cursor-pointer" @click="openDatePicker" @focus="openDatePicker" />
        </label>
        <div v-if="false" class="payroll-run-actions flex flex-wrap items-center gap-2">
          <button
            v-if="canManagePayroll && !selectedRun"
            @click="openPayrollRun"
            :disabled="busyRunId === 'open'"
            class="orbit-button orbit-button-secondary text-xs font-bold px-3 py-2 disabled:bg-slate-300 disabled:cursor-not-allowed"
          >
            {{ busyRunId === 'open' ? 'Opening...' : 'Open payroll run' }}
          </button>
          <button
            v-if="canManagePayroll && selectedRun?.status === 'draft'"
            @click="submitPayrollRun"
            :disabled="busyRunId === selectedRun.id"
            class="orbit-button orbit-button-primary text-xs font-bold px-3 py-2 disabled:bg-slate-300 disabled:cursor-not-allowed"
          >
            {{ busyRunId === selectedRun.id ? 'Submitting...' : 'Submit for release' }}
          </button>
          <button
            v-if="canApprovePayroll && selectedRun?.status === 'submitted'"
            @click="releasePayrollRun"
            :disabled="busyRunId === selectedRun.id"
            class="orbit-button orbit-button-primary text-xs font-bold px-3 py-2 disabled:bg-slate-300 disabled:cursor-not-allowed"
          >
            {{ busyRunId === selectedRun.id ? 'Releasing...' : 'Release payroll' }}
          </button>
        </div>
      </div>
    </div>

    <section v-if="canManagePayroll || canApprovePayroll" class="orbit-table-card p-4">
      <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
        <div>
          <div class="text-sm font-bold text-slate-900">{{ selectedRunLabel() }} payroll run</div>
          <div class="text-xs text-slate-600 mt-1">
            <template v-if="selectedRun">
              {{ selectedRun.slipCount }} generated slip{{ selectedRun.slipCount === 1 ? '' : 's' }} ·
              {{ selectedRun.paidSlipCount }} paid ·
              {{ canManagePayroll ? 'Prepare every record, then submit the month for independent release.' : 'Release the complete submitted month; individual rows cannot be approved separately.' }}
            </template>
            <template v-else>
              No run has been opened for this month.
            </template>
          </div>
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <span class="company-hub-badge" :class="runStatusClass(selectedRun?.status)">
            {{ runStatusLabel(selectedRun?.status) }}
          </span>
          <button
            v-if="canManagePayroll && !selectedRun"
            @click="openPayrollRun"
            :disabled="busyRunId === 'open'"
            class="text-xs bg-slate-950 text-white font-bold rounded-lg px-3 py-2 hover:bg-black disabled:bg-slate-300 disabled:cursor-not-allowed transition-colors"
          >
            {{ busyRunId === 'open' ? 'Opening...' : 'Open payroll run' }}
          </button>
          <button
            v-if="canManagePayroll && selectedRun?.status === 'draft'"
            @click="submitPayrollRun"
            :disabled="busyRunId === selectedRun.id"
            class="text-xs bg-emerald-600 text-white font-bold rounded-lg px-3 py-2 hover:bg-emerald-700 disabled:bg-slate-300 disabled:cursor-not-allowed transition-colors"
          >
            {{ busyRunId === selectedRun.id ? 'Submitting...' : 'Submit for release' }}
          </button>
          <button
            v-if="canApprovePayroll && selectedRun?.status === 'submitted'"
            @click="releasePayrollRun"
            :disabled="busyRunId === selectedRun.id"
            class="text-xs bg-emerald-600 text-white font-bold rounded-lg px-3 py-2 hover:bg-emerald-700 disabled:bg-slate-300 disabled:cursor-not-allowed transition-colors"
          >
            {{ busyRunId === selectedRun.id ? 'Releasing...' : 'Release payroll' }}
          </button>
        </div>
      </div>
    </section>

    <section v-if="canManagePayroll" class="grid grid-cols-1 md:grid-cols-3 xl:grid-cols-6 gap-4">
      <div class="orbit-stat-card p-4">
        <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Employees</div>
        <div class="text-2xl font-extrabold text-slate-900 mt-2">{{ totals.employees }}</div>
      </div>
      <div v-if="loadError || (payrollError && !editingPayroll)" class="col-span-full rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
        {{ loadError || payrollError }}
      </div>
      <div class="orbit-stat-card p-4">
        <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Gross payroll</div>
        <div class="text-xl font-extrabold text-slate-900 mt-2">{{ formatCurrency(totals.gross) }}</div>
      </div>
      <div class="orbit-stat-card p-4">
        <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Deductions</div>
        <div class="text-xl font-extrabold text-rose-600 mt-2">{{ formatCurrency(totals.deductions) }}</div>
      </div>
      <div class="orbit-stat-card p-4">
        <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Net payable</div>
        <div class="text-xl font-extrabold text-emerald-600 mt-2">{{ formatCurrency(totals.net) }}</div>
      </div>
      <div class="orbit-stat-card p-4">
        <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Paid slips</div>
        <div class="text-2xl font-extrabold text-slate-900 mt-2">{{ totals.paid }}</div>
      </div>
      <div class="orbit-stat-card p-4">
        <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Requests</div>
        <div class="text-2xl font-extrabold text-slate-900 mt-2">{{ totals.pendingRequests }}</div>
      </div>
    </section>

    <section class="orbit-table-card overflow-hidden">
      <div class="px-4 py-3 border-b border-slate-200">
        <div class="text-sm font-bold text-slate-900">Monthly Payroll Register</div>
        <div class="text-xs text-slate-600 mt-1">Prepare slips while the run is a draft. Submit and release the full month before recording payments.</div>
      </div>
      <div class="table-tools">
        <input v-model="payrollTable.query.value" type="search" placeholder="Search payroll..." class="table-filter-input" />
        <div v-if="canManagePayroll" class="flex flex-wrap items-center gap-2">
          <div class="table-count">{{ selectedRows.length }} selected</div>
          <button @click="generateSelected" :disabled="selectedRows.length === 0" class="text-xs bg-slate-950 text-white font-bold rounded-lg px-3 py-2 hover:bg-black disabled:bg-slate-300 disabled:cursor-not-allowed transition-colors">Generate selected</button>
          <button @click="markSelectedPaid" :disabled="selectedRows.length === 0" class="text-xs bg-emerald-600 text-white font-bold rounded-lg px-3 py-2 hover:bg-emerald-700 disabled:bg-slate-300 disabled:cursor-not-allowed transition-colors">Pay selected</button>
        </div>
        <div class="table-count">{{ payrollTable.filteredRows.value.length }} records</div>
      </div>
      <div class="table-scroll" @scroll="payrollTable.loadMoreRows">
        <table class="orbit-data-table w-full text-sm">
          <thead>
            <tr class="border-b border-slate-200 text-left bg-slate-50/80">
              <th v-if="canManagePayroll" class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">
                <input type="checkbox" :checked="allVisibleSelected" @change="toggleAllVisiblePayroll" />
              </th>
              <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Employee</th>
              <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Base</th>
              <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Allowances</th>
              <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Deductions</th>
              <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Net pay</th>
              <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Status</th>
              <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide text-right">Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in payrollTable.visibleRows.value" :key="row.id" class="border-b border-slate-200 last:border-0 hover:bg-slate-50/60 transition-colors">
              <td v-if="canManagePayroll" class="px-4 py-3">
                <input type="checkbox" :checked="selectedPayrollIds.includes(row.id)" :disabled="row.status === 'paid' || !row.salaryConfigured" @change="togglePayrollSelection(row.id)" />
              </td>
              <td class="px-4 py-3">
                <div class="font-bold text-slate-900">{{ row.employee.name }}</div>
                <div class="text-xs text-slate-500">{{ row.employee.department }}</div>
              </td>
              <td class="px-4 py-3 text-slate-700">{{ formatCurrency(row.baseSalary) }}</td>
              <td class="px-4 py-3 text-slate-700">{{ formatCurrency(row.allowances) }}</td>
              <td class="px-4 py-3 text-slate-700">{{ formatCurrency(row.deductions) }}</td>
              <td class="px-4 py-3 font-bold text-slate-900">{{ formatCurrency(row.netPay) }}</td>
              <td class="px-4 py-3">
                <span class="company-hub-badge capitalize" :class="statusClass(row.status)">
                  {{ statusLabel(row.status) }}
                </span>
              </td>
              <td class="px-4 py-3">
                <div class="flex justify-end gap-2">
                  <button @click="selectedPayroll = row" class="text-xs border border-slate-200 bg-white text-slate-700 font-bold rounded-lg px-3 py-2 hover:bg-slate-50 transition-colors">View</button>
                  <button
                    v-if="canManagePayroll"
                    @click="openPayrollEditor(row)"
                    :disabled="!payrollEditMode(row)"
                    :title="payrollEditMode(row) ? '' : (row.status === 'paid' ? 'A paid slip cannot be changed.' : 'Reopen this slip for correction to change it.')"
                    class="text-xs border border-slate-200 bg-white text-slate-700 font-bold rounded-lg px-3 py-2 hover:bg-slate-50 disabled:bg-slate-100 disabled:text-slate-400 disabled:cursor-not-allowed transition-colors"
                  >
                    Edit
                  </button>
                  <button
                    v-if="canManagePayroll && !row.salaryConfigured"
                    @click="openSalarySetup(row)"
                    :disabled="busyRowId === row.id"
                    class="text-xs border border-amber-300 bg-amber-50 text-amber-800 font-bold rounded-lg px-3 py-2 hover:bg-amber-100 disabled:opacity-60 disabled:cursor-not-allowed transition-colors"
                  >
                    Set salary
                  </button>
                  <button
                    v-if="canManagePayroll && row.salaryConfigured && !row.slipId"
                    @click="generateSlip(row, false)"
                    :disabled="busyRowId === row.id"
                    class="text-xs bg-slate-950 text-white font-bold rounded-lg px-3 py-2 hover:bg-black disabled:bg-slate-300 disabled:cursor-not-allowed transition-colors"
                  >
                    {{ busyRowId === row.id ? 'Working...' : 'Generate' }}
                  </button>
                  <button
                    v-if="canManagePayroll && row.status !== 'paid'"
                    @click="openPaymentModal(row)"
                    :disabled="row.status !== 'approved' || busyRowId === row.id"
                    class="text-xs bg-emerald-600 text-white font-bold rounded-lg px-3 py-2 hover:bg-emerald-700 disabled:bg-slate-300 disabled:cursor-not-allowed transition-colors"
                  >
                    Paid
                  </button>
                  <button
                    v-if="canManagePayroll && row.status === 'approved'"
                    @click="reopenPayroll(row)"
                    :disabled="busyRowId === row.id"
                    class="text-xs border border-slate-200 bg-white text-slate-700 font-bold rounded-lg px-3 py-2 hover:bg-slate-50 disabled:opacity-60 disabled:cursor-not-allowed transition-colors"
                  >
                    Reopen
                  </button>
                </div>
              </td>
            </tr>
            <tr v-if="payrollTable.filteredRows.value.length === 0">
              <td :colspan="canManagePayroll ? 8 : 7" class="px-4 py-8 text-center text-sm text-slate-500">
                {{ payrollTable.query.value.trim() ? 'No payroll records match your search.' : 'No payroll records are available for this period yet.' }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <section v-if="canManagePayroll" class="orbit-table-card overflow-hidden">
      <div class="px-4 py-3 border-b border-slate-200">
        <div class="text-sm font-bold text-slate-900">Prepared payroll records</div>
        <div class="text-xs text-slate-600 mt-1">Each record belongs to its monthly run; release happens once for the complete register.</div>
      </div>
      <div class="table-scroll">
        <table class="orbit-data-table w-full text-sm">
          <thead>
            <tr class="border-b border-slate-200 text-left bg-slate-50/80">
              <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Employee</th>
              <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Month</th>
              <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Status</th>
              <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Monthly run</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="request in state.salarySlipRequests" :key="request.id" class="border-b border-slate-200 last:border-0">
              <td class="px-4 py-3 font-bold text-slate-900">{{ employeeName(request.employeeId) }}</td>
              <td class="px-4 py-3 text-slate-700">{{ request.month }}</td>
              <td class="px-4 py-3">
                <span class="company-hub-badge capitalize" :class="statusClass(request.status)">
                  {{ statusLabel(request.status) }}
                </span>
              </td>
              <td class="px-4 py-3">
                <span class="text-xs font-semibold text-slate-600">
                  {{ request.payrollRunId ? 'Included in run' : 'Legacy record' }}
                </span>
              </td>
            </tr>
            <tr v-if="state.salarySlipRequests.length === 0">
              <td colspan="4" class="px-4 py-8 text-center text-sm text-slate-500">No payroll records have been prepared yet.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <Teleport to="body">
      <div v-if="selectedPayroll" class="fixed inset-0 z-50 bg-black/40 backdrop-blur-[2px] flex items-center justify-center px-4 py-4 overflow-y-auto" @click.self="selectedPayroll = null">
        <div class="bg-white rounded-xl p-6 w-full max-w-2xl border border-slate-200 shadow-2xl max-h-[calc(100vh-2rem)] overflow-y-auto modal-scroll-hidden">
          <div class="flex items-start justify-between gap-4 mb-5">
            <div>
              <div class="text-lg font-extrabold text-slate-900">Payroll Detail</div>
              <div class="text-sm text-slate-600 mt-1">{{ selectedPayroll.employee.name }} · {{ selectedPayroll.month }}</div>
            </div>
            <button @click="selectedPayroll = null" class="text-slate-500 hover:text-slate-900 text-xl leading-none">x</button>
          </div>
          <div v-if="selectedPayroll.status === 'paid'" class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm font-semibold text-emerald-700">
            This payroll is paid and locked. Reopen it before making corrections.
          </div>
          <div v-if="selectedPayroll.slipNo" class="mb-4 rounded-xl border border-slate-200 bg-slate-50 p-3">
            <div class="text-[11px] uppercase tracking-wide text-slate-500 font-bold">Generated slip</div>
            <div class="mt-1 text-sm font-extrabold text-slate-900">{{ selectedPayroll.slipNo }}</div>
            <div class="mt-1 text-xs text-slate-600">Generated {{ new Date(selectedPayroll.generatedAt).toLocaleString() }}</div>
          </div>

          <div class="payslip-document mb-4 rounded-xl border border-slate-200 bg-white p-5">
            <div class="flex items-start justify-between gap-4 border-b border-slate-200 pb-4">
              <div>
                <div class="text-[11px] uppercase tracking-[0.18em] text-slate-500 font-bold">Orbit HRMS</div>
                <div class="text-2xl font-extrabold text-slate-900">Salary Slip</div>
                <div class="mt-1 text-sm text-slate-600">Payroll document for {{ selectedPayroll.month }}</div>
              </div>
              <div class="text-right">
                <div class="text-[11px] uppercase tracking-wide text-slate-500 font-bold">Slip no.</div>
                <div class="text-sm font-extrabold text-slate-900">{{ selectedPayroll.slipNo || 'Not generated yet' }}</div>
                <div class="mt-2 text-[11px] uppercase tracking-wide text-slate-500 font-bold">Status</div>
                <div class="mt-1">
                  <span class="company-hub-badge capitalize" :class="statusClass(selectedPayroll.status)">
                    {{ statusLabel(selectedPayroll.status) }}
                  </span>
                </div>
              </div>
            </div>

            <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
              <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                <div class="text-[11px] uppercase tracking-wide text-slate-500 font-bold mb-3">Employee information</div>
                <div class="space-y-2 text-sm">
                  <div class="flex justify-between gap-3"><span class="text-slate-600">Name</span><span class="font-bold text-slate-900">{{ selectedPayroll.employee.name }}</span></div>
                  <div class="flex justify-between gap-3"><span class="text-slate-600">Email</span><span class="font-bold text-slate-900 text-right">{{ selectedPayroll.employee.email }}</span></div>
                  <div class="flex justify-between gap-3"><span class="text-slate-600">Department</span><span class="font-bold text-slate-900">{{ selectedPayroll.employee.department }}</span></div>
                  <div class="flex justify-between gap-3"><span class="text-slate-600">Role</span><span class="font-bold text-slate-900 capitalize">{{ selectedPayroll.employee.role }}</span></div>
                </div>
              </div>
              <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                <div class="text-[11px] uppercase tracking-wide text-slate-500 font-bold mb-3">Slip information</div>
                <div class="space-y-2 text-sm">
                  <div class="flex justify-between gap-3"><span class="text-slate-600">Month</span><span class="font-bold text-slate-900">{{ selectedPayroll.month }}</span></div>
                  <div class="flex justify-between gap-3"><span class="text-slate-600">Period</span><span class="font-bold text-slate-900 text-right">{{ selectedPayroll.period.from }} to {{ selectedPayroll.period.to }}</span></div>
                  <div class="flex justify-between gap-3"><span class="text-slate-600">Generated by</span><span class="font-bold text-slate-900">{{ generatedByName(selectedPayroll) }}</span></div>
                  <div class="flex justify-between gap-3"><span class="text-slate-600">Generated at</span><span class="font-bold text-slate-900 text-right">{{ formatDateTime(selectedPayroll.generatedAt) }}</span></div>
                  <div class="flex justify-between gap-3"><span class="text-slate-600">Approved by</span><span class="font-bold text-slate-900">{{ approvedByName(selectedPayroll) }}</span></div>
                  <div class="flex justify-between gap-3"><span class="text-slate-600">Approved at</span><span class="font-bold text-slate-900 text-right">{{ formatDateTime(selectedPayroll.approvedAt) }}</span></div>
                  <div class="flex justify-between gap-3"><span class="text-slate-600">Paid date</span><span class="font-bold text-slate-900">{{ paidDateLabel(selectedPayroll) }}</span></div>
                </div>
              </div>
            </div>

            <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
              <div class="rounded-xl border border-slate-200 bg-white p-4">
                <div class="text-[11px] uppercase tracking-wide text-slate-500 font-bold mb-3">Earnings</div>
                <div class="space-y-2 text-sm">
                  <div class="flex justify-between gap-3"><span class="text-slate-600">Base salary</span><span class="font-bold text-slate-900">{{ formatCurrency(selectedPayroll.baseSalary) }}</span></div>
                  <div class="flex justify-between gap-3"><span class="text-slate-600">House allowance</span><span class="font-bold text-slate-900">{{ formatCurrency(selectedPayroll.houseAllowance) }}</span></div>
                  <div class="flex justify-between gap-3"><span class="text-slate-600">Medical allowance</span><span class="font-bold text-slate-900">{{ formatCurrency(selectedPayroll.medicalAllowance) }}</span></div>
                  <div class="flex justify-between gap-3"><span class="text-slate-600">Bonus / adjustment</span><span class="font-bold text-slate-900">{{ formatCurrency(selectedPayroll.bonus) }}</span></div>
                  <div class="flex justify-between gap-3 border-t border-slate-200 pt-2"><span class="font-bold text-slate-900">Gross pay</span><span class="font-extrabold text-slate-900">{{ formatCurrency(selectedPayroll.baseSalary + selectedPayroll.allowances) }}</span></div>
                </div>
              </div>
              <div class="rounded-xl border border-slate-200 bg-white p-4">
                <div class="text-[11px] uppercase tracking-wide text-slate-500 font-bold mb-3">Deductions</div>
                <div class="space-y-2 text-sm">
                  <div class="flex justify-between gap-3"><span class="text-slate-600">Tax deduction</span><span class="font-bold text-slate-900">{{ formatCurrency(selectedPayroll.taxDeduction) }}</span></div>
                  <div class="flex justify-between gap-3"><span class="text-slate-600">Attendance deduction</span><span class="font-bold text-slate-900">{{ formatCurrency(selectedPayroll.attendanceDeduction) }}</span></div>
                  <div v-if="selectedPayroll.attendanceSummary" class="mt-2 rounded-lg border border-teal-100 bg-teal-50 px-3 py-2 text-xs leading-relaxed text-teal-900">
                    <div class="font-bold">Attendance calculation</div>
                    {{ selectedPayroll.attendanceSummary.absentDays }} absent day(s) ·
                    {{ selectedPayroll.attendanceSummary.halfDays }} half day(s) ·
                    {{ formatCurrency(selectedPayroll.attendanceSummary.dailyRate) }} daily rate
                    <div class="mt-1 text-teal-700">{{ selectedPayroll.attendanceSummary.rule }}</div>
                  </div>
                  <div class="flex justify-between gap-3"><span class="text-slate-600">Advance deduction</span><span class="font-bold text-slate-900">{{ formatCurrency(selectedPayroll.advanceDeduction) }}</span></div>
                  <div class="flex justify-between gap-3 border-t border-slate-200 pt-2"><span class="font-bold text-slate-900">Total deductions</span><span class="font-extrabold text-slate-900">{{ formatCurrency(selectedPayroll.deductions) }}</span></div>
                </div>
              </div>
            </div>

            <div class="mt-4 rounded-xl bg-slate-950 p-4 text-white">
              <div class="text-[11px] uppercase tracking-wide text-slate-300 font-bold">Net salary payable</div>
              <div class="mt-1 text-3xl font-extrabold">{{ formatCurrency(selectedPayroll.netPay) }}</div>
            </div>
          </div>

          <div v-if="selectedPayroll.paidAt || selectedPayroll.notes" class="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700">
            <div v-if="selectedPayroll.paidAt"><span class="font-bold">Paid:</span> {{ new Date(selectedPayroll.paidAt).toLocaleString() }}</div>
            <div v-if="selectedPayroll.payment" class="mt-1">
              <span class="font-bold">Payment:</span>
              {{ selectedPayroll.payment.method }}
              <span v-if="selectedPayroll.payment.reference">- {{ selectedPayroll.payment.reference }}</span>
              <span v-if="selectedPayroll.payment.paidDate">({{ selectedPayroll.payment.paidDate }})</span>
            </div>
            <div v-if="selectedPayroll.payment?.notes" class="mt-1"><span class="font-bold">Payment notes:</span> {{ selectedPayroll.payment.notes }}</div>
            <div v-if="selectedPayroll.notes" class="mt-1"><span class="font-bold">Notes:</span> {{ selectedPayroll.notes }}</div>
          </div>

          <div class="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-4">
            <div class="text-[11px] uppercase tracking-wide text-slate-500 font-bold mb-3">Payroll audit timeline</div>
            <div v-if="selectedPayroll.history.length === 0" class="text-sm text-slate-500">No payroll activity recorded yet.</div>
            <div v-else class="space-y-3">
              <div v-for="event in selectedPayroll.history" :key="event.id" class="flex items-start justify-between gap-4 border-b border-slate-200 pb-2 last:border-0 last:pb-0">
                <div>
                  <div class="text-sm font-bold text-slate-900">{{ event.action }}</div>
                  <div class="text-xs text-slate-600 mt-0.5">{{ event.detail }}</div>
                </div>
                <div class="text-right text-[11px] text-slate-500">
                  <div class="font-bold text-slate-700">{{ event.by }}</div>
                  <div>{{ formatDateTime(event.at) }}</div>
                </div>
              </div>
            </div>
          </div>

          <div class="mt-5 flex flex-wrap gap-2">
            <button @click="selectedPayroll = null" class="flex-1 text-sm border border-slate-200 rounded-lg py-2 hover:bg-slate-50 transition-colors text-slate-700">Close</button>
            <button @click="printSlip(selectedPayroll)" class="flex-1 text-sm border border-slate-200 rounded-lg py-2 hover:bg-slate-50 transition-colors text-slate-700">Print slip</button>
            <button @click="downloadPdf(selectedPayroll)" class="flex-1 text-sm border border-slate-200 rounded-lg py-2 hover:bg-slate-50 transition-colors text-slate-700">Download PDF</button>
            <button
              v-if="canManagePayroll"
              @click="openPayrollEditor(selectedPayroll)"
              :disabled="!payrollEditMode(selectedPayroll)"
              :title="payrollEditMode(selectedPayroll) ? '' : (selectedPayroll.status === 'paid' ? 'A paid slip cannot be changed.' : 'Reopen this slip for correction to change it.')"
              class="flex-1 text-sm border border-slate-200 rounded-lg py-2 hover:bg-slate-50 disabled:bg-slate-100 disabled:text-slate-400 disabled:cursor-not-allowed transition-colors text-slate-700"
            >
              Edit details
            </button>
            <button
              v-if="canManagePayroll && !selectedPayroll.salaryConfigured"
              @click="openSalarySetup(selectedPayroll)"
              :disabled="selectedPayroll.status === 'paid'"
              class="flex-1 border border-amber-300 bg-amber-50 text-amber-800 text-sm font-bold rounded-lg py-2 hover:bg-amber-100 disabled:bg-slate-100 disabled:text-slate-400 disabled:cursor-not-allowed transition-colors"
            >
              Set salary
            </button>
            <!-- Only before a slip exists. "Regenerate" asked the API to issue a
                 second slip for the month, which it always refuses; an issued
                 slip is corrected by reopening and editing it instead. -->
            <button
              v-if="canManagePayroll && selectedPayroll.salaryConfigured && !selectedPayroll.slipId"
              @click="generateSlip(selectedPayroll, true)"
              :disabled="selectedPayroll.status === 'paid'"
              class="flex-1 bg-slate-950 text-white text-sm font-bold rounded-lg py-2 hover:bg-black disabled:bg-slate-300 disabled:cursor-not-allowed transition-colors"
            >
              Generate slip
            </button>
            <button
              v-if="canManagePayroll && selectedPayroll.status !== 'paid'"
              @click="openPaymentModal(selectedPayroll)"
              :disabled="selectedPayroll.status !== 'approved'"
              class="flex-1 bg-emerald-600 text-white text-sm font-bold rounded-lg py-2 hover:bg-emerald-700 disabled:bg-slate-300 disabled:cursor-not-allowed transition-colors"
            >
              Mark paid
            </button>
            <button
              v-if="canManagePayroll && selectedPayroll.status === 'approved'"
              @click="reopenPayroll(selectedPayroll)"
              class="flex-1 text-sm border border-slate-200 rounded-lg py-2 hover:bg-slate-50 transition-colors text-slate-700"
            >
              Reopen
            </button>
          </div>
        </div>
      </div>

      <div v-if="paymentPayroll && paymentDraft" class="fixed inset-0 z-50 bg-black/40 backdrop-blur-[2px] flex items-center justify-center px-4 py-4 overflow-y-auto" @click.self="paymentPayroll = null">
        <div class="bg-white rounded-xl p-6 w-full max-w-lg border border-slate-200 shadow-2xl max-h-[calc(100vh-2rem)] overflow-y-auto modal-scroll-hidden">
          <div class="flex items-start justify-between gap-4 mb-5">
            <div>
              <div class="text-lg font-extrabold text-slate-900">Mark payroll paid</div>
              <div class="text-sm text-slate-600 mt-1">{{ paymentPayroll.employee.name }} - {{ paymentPayroll.month }}</div>
            </div>
            <button @click="paymentPayroll = null" class="text-slate-500 hover:text-slate-900 text-xl leading-none">x</button>
          </div>

          <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 mb-4">
            <div class="text-[11px] uppercase tracking-wide text-slate-500 font-bold">Net payable</div>
            <div class="text-2xl font-extrabold text-emerald-600 mt-1">{{ formatCurrency(paymentPayroll.netPay) }}</div>
            <div class="text-xs text-slate-600 mt-1">A slip will be generated first if it does not already exist.</div>
          </div>

          <div class="space-y-3">
            <label class="block">
              <span class="mb-1 block text-xs font-bold text-slate-600">Payment method</span>
              <select v-model="paymentDraft.method" class="w-full text-sm border border-slate-200 rounded-lg px-3 py-2 text-slate-700">
                <option>Bank transfer</option>
                <option>Cash</option>
                <option>Cheque</option>
                <option>JazzCash</option>
                <option>EasyPaisa</option>
              </select>
            </label>
            <label class="block">
              <span class="mb-1 block text-xs font-bold text-slate-600">Reference / transaction no.</span>
              <input v-model="paymentDraft.reference" type="text" class="w-full text-sm border border-slate-200 rounded-lg px-3 py-2 text-slate-700" placeholder="Bank ref, cheque no, or transaction id" />
            </label>
            <label class="block">
              <span class="mb-1 block text-xs font-bold text-slate-600">Paid date</span>
              <input v-model="paymentDraft.paidDate" type="date" class="w-full cursor-pointer text-sm border border-slate-200 rounded-lg px-3 py-2 text-slate-700" @click="openDatePicker" @focus="openDatePicker" />
            </label>
            <label class="block">
              <span class="mb-1 block text-xs font-bold text-slate-600">Payment notes</span>
              <textarea v-model="paymentDraft.notes" rows="2" class="w-full resize-none text-sm border border-slate-200 rounded-lg px-3 py-2 text-slate-700" placeholder="Optional payment remarks"></textarea>
            </label>
          </div>

          <div class="mt-5 flex gap-2">
            <button @click="paymentPayroll = null" class="flex-1 text-sm border border-slate-200 rounded-lg py-2 hover:bg-slate-50 transition-colors text-slate-700">Cancel</button>
            <button @click="confirmPaid" class="flex-1 bg-emerald-600 text-white text-sm font-bold rounded-lg py-2 hover:bg-emerald-700 transition-colors">Confirm paid</button>
          </div>
        </div>
      </div>

      <div v-if="salarySetupRow && salarySetupDraft" class="fixed inset-0 z-50 bg-black/40 backdrop-blur-[2px] flex items-center justify-center px-4 py-4 overflow-y-auto" @click.self="salarySetupRow = null">
        <div class="bg-white rounded-xl p-6 w-full max-w-lg border border-slate-200 shadow-2xl max-h-[calc(100vh-2rem)] overflow-y-auto modal-scroll-hidden">
          <div class="flex items-start justify-between gap-4 mb-5">
            <div>
              <div class="text-lg font-extrabold text-slate-900">Record salary</div>
              <div class="text-sm text-slate-600 mt-1">{{ salarySetupRow.employee.name }} · This creates an official salary record before payroll is generated.</div>
            </div>
            <button @click="salarySetupRow = null" class="text-slate-500 hover:text-slate-900 text-xl leading-none" aria-label="Close">×</button>
          </div>

          <div class="rounded-lg border border-sky-100 bg-sky-50 px-3 py-2 text-xs leading-relaxed text-sky-800 mb-4">
            Salary history is immutable. A later raise should be recorded as a new rate with its own effective date.
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <label class="block sm:col-span-2">
              <span class="mb-1 block text-xs font-bold text-slate-600">Base salary</span>
              <input v-model.number="salarySetupDraft.baseSalary" type="number" min="0" class="w-full text-sm border border-slate-200 rounded-lg px-3 py-2 text-slate-700" :placeholder="`${currencyCode()} amount`" />
            </label>
            <label class="block">
              <span class="mb-1 block text-xs font-bold text-slate-600">House allowance</span>
              <input v-model.number="salarySetupDraft.houseAllowance" type="number" min="0" class="w-full text-sm border border-slate-200 rounded-lg px-3 py-2 text-slate-700" :placeholder="`${currencyCode()} amount`" />
            </label>
            <label class="block">
              <span class="mb-1 block text-xs font-bold text-slate-600">Medical allowance</span>
              <input v-model.number="salarySetupDraft.medicalAllowance" type="number" min="0" class="w-full text-sm border border-slate-200 rounded-lg px-3 py-2 text-slate-700" :placeholder="`${currencyCode()} amount`" />
            </label>
            <label class="block">
              <span class="mb-1 block text-xs font-bold text-slate-600">Effective from</span>
              <input v-model="salarySetupDraft.effectiveFrom" type="date" class="w-full cursor-pointer text-sm border border-slate-200 rounded-lg px-3 py-2 text-slate-700" @click="openDatePicker" @focus="openDatePicker" />
            </label>
            <label class="block">
              <span class="mb-1 block text-xs font-bold text-slate-600">Reason</span>
              <input v-model="salarySetupDraft.reason" type="text" maxlength="255" class="w-full text-sm border border-slate-200 rounded-lg px-3 py-2 text-slate-700" placeholder="e.g. Initial salary record" />
            </label>
          </div>

          <div v-if="salarySetupError" class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
            {{ salarySetupError }}
          </div>

          <div class="mt-5 flex gap-2">
            <button @click="salarySetupRow = null" :disabled="savingSalary" class="flex-1 text-sm border border-slate-200 rounded-lg py-2 hover:bg-slate-50 disabled:opacity-60 transition-colors text-slate-700">Cancel</button>
            <button @click="saveSalarySetup" :disabled="savingSalary" class="flex-1 bg-slate-950 text-white text-sm font-bold rounded-lg py-2 hover:bg-black disabled:bg-slate-300 disabled:cursor-not-allowed transition-colors">
              {{ savingSalary ? 'Saving...' : 'Save salary' }}
            </button>
          </div>
        </div>
      </div>

      <div v-if="editingPayroll && payrollDraft" class="fixed inset-0 z-50 bg-black/40 backdrop-blur-[2px] flex items-center justify-center px-4 py-4 overflow-y-auto" @click.self="editingPayroll = null">
        <div class="bg-white rounded-xl p-6 w-full max-w-2xl border border-slate-200 shadow-2xl max-h-[calc(100vh-2rem)] overflow-y-auto modal-scroll-hidden">
          <div class="flex items-start justify-between gap-4 mb-5">
            <div>
              <div class="text-lg font-extrabold text-slate-900">Edit payroll details</div>
              <div class="text-sm text-slate-600 mt-1">{{ editingPayroll.employee.name }} · {{ editingPayroll.month }}</div>
            </div>
            <button @click="editingPayroll = null" class="text-slate-500 hover:text-slate-900 text-xl leading-none">x</button>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="space-y-3">
              <div class="text-[11px] uppercase tracking-wide text-slate-500 font-bold">Earnings</div>
              <label class="block">
                <span class="mb-1 block text-xs font-bold text-slate-600">Base salary</span>
                <input v-model.number="payrollDraft.baseSalary" type="number" min="0" class="w-full text-sm border border-slate-200 rounded-lg px-3 py-2 text-slate-700" :placeholder="`${currencyCode()} amount`" />
              </label>
              <label class="block">
                <span class="mb-1 block text-xs font-bold text-slate-600">House allowance</span>
                <input v-model.number="payrollDraft.houseAllowance" type="number" min="0" class="w-full text-sm border border-slate-200 rounded-lg px-3 py-2 text-slate-700" :placeholder="`${currencyCode()} amount`" />
              </label>
              <label class="block">
                <span class="mb-1 block text-xs font-bold text-slate-600">Medical allowance</span>
                <input v-model.number="payrollDraft.medicalAllowance" type="number" min="0" class="w-full text-sm border border-slate-200 rounded-lg px-3 py-2 text-slate-700" :placeholder="`${currencyCode()} amount`" />
              </label>
              <label class="block">
                <span class="mb-1 block text-xs font-bold text-slate-600">Bonus / adjustment</span>
                <input v-model.number="payrollDraft.bonus" type="number" min="0" class="w-full text-sm border border-slate-200 rounded-lg px-3 py-2 text-slate-700" :placeholder="`${currencyCode()} amount`" />
              </label>
            </div>
            <div class="space-y-3">
              <div class="text-[11px] uppercase tracking-wide text-slate-500 font-bold">Deductions</div>
              <label class="block">
                <span class="mb-1 block text-xs font-bold text-slate-600">Tax deduction</span>
                <input v-model.number="payrollDraft.taxDeduction" type="number" min="0" class="w-full text-sm border border-slate-200 rounded-lg px-3 py-2 text-slate-700" :placeholder="`${currencyCode()} amount`" />
              </label>
              <div class="rounded-lg border border-teal-100 bg-teal-50 px-3 py-2 text-xs leading-relaxed text-teal-800">
                <span class="block font-bold">Attendance deduction</span>
                Calculated by the server from explicit absent and half-day attendance records when this payroll is generated.
              </div>
              <label class="block">
                <span class="mb-1 block text-xs font-bold text-slate-600">Advance deduction</span>
                <input v-model.number="payrollDraft.advanceDeduction" type="number" min="0" class="w-full text-sm border border-slate-200 rounded-lg px-3 py-2 text-slate-700" :placeholder="`${currencyCode()} amount`" />
              </label>
              <label class="block">
                <span class="mb-1 block text-xs font-bold text-slate-600">Payroll notes</span>
                <textarea v-model="payrollDraft.notes" rows="2" class="w-full resize-none text-sm border border-slate-200 rounded-lg px-3 py-2 text-slate-700" placeholder="Optional notes"></textarea>
              </label>
            </div>
          </div>

          <div class="mt-4 grid grid-cols-3 gap-3">
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
              <div class="text-[11px] uppercase tracking-wide text-slate-500 font-bold">Allowances</div>
              <div class="text-sm font-extrabold text-slate-900 mt-1">{{ formatCurrency(draftTotals.allowances) }}</div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
              <div class="text-[11px] uppercase tracking-wide text-slate-500 font-bold">Deductions</div>
              <div class="text-sm font-extrabold text-rose-600 mt-1">{{ formatCurrency(draftTotals.deductions) }}</div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
              <div class="text-[11px] uppercase tracking-wide text-slate-500 font-bold">Net pay</div>
              <div class="text-sm font-extrabold text-emerald-600 mt-1">{{ formatCurrency(draftTotals.netPay) }}</div>
            </div>
          </div>

          <div v-if="payrollError" class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
            {{ payrollError }}
          </div>

          <div class="mt-5 flex gap-2">
            <button @click="editingPayroll = null" class="flex-1 text-sm border border-slate-200 rounded-lg py-2 hover:bg-slate-50 transition-colors text-slate-700">Cancel</button>
            <button @click="savePayrollDetails" :disabled="savingPayroll" class="flex-1 bg-slate-950 text-white text-sm font-bold rounded-lg py-2 hover:bg-black transition-colors disabled:opacity-60">
              {{ savingPayroll ? 'Saving…' : payrollEditMode(editingPayroll) === 'generate' ? 'Generate slip' : 'Save correction' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>

  <div v-else class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
    <div class="text-lg font-semibold text-slate-900">Payroll is not available for this role</div>
  </div>
</template>
