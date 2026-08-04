<script setup>
import { computed, onMounted, ref } from 'vue'
import { useStore } from '../store/index.js'
import { useTableControls } from '../composables/useTableControls.js'
import { payrollService } from '../services/payrollService.js'
import { employeeService } from '../services/employeeService.js'

const {
  state, can, formatCurrency, currencyCode, formatDate,
  formatDateTime: formatCompanyDateTime, logActivity, pushNotification,
  setSalarySlipRequests, upsertSalarySlipRequest, setEmployees
} = useStore()

const loadError = ref('')
const busyRowId = ref(null)
// Payroll figures are money — validate before they reach the record. Also
// carries API failures back to the reviewer.
const payrollError = ref('')

/**
 * Requests carry their issued slip, so one call covers both. Employees are
 * needed to name the rows; that fetch is non-fatal because an employee holds
 * no Employees permission but must still see their own payslip.
 */
async function loadPayroll() {
  const [requests, employees] = await Promise.allSettled([
    payrollService.requests(),
    employeeService.list()
  ])

  if (requests.status === 'fulfilled') setSalarySlipRequests(requests.value)
  else loadError.value = requests.reason?.message || 'Unable to load payroll.'

  if (employees.status === 'fulfilled') setEmployees(employees.value.data)
}

onMounted(loadPayroll)

const canAccess = computed(() => can('Payroll', 'view'))
// HR prepares payroll; only the final approver may release it for payment.
const canManagePayroll = computed(() => can('Payroll', 'create'))
const canApprovePayroll = computed(() => can('Payroll Approval', 'approve'))
const selectedMonth = ref('July 2026')
const selectedPeriod = ref({ from: '2026-07-01', to: '2026-07-31' })
const selectedPayroll = ref(null)
const editingPayroll = ref(null)
const payrollDraft = ref(null)
const paymentPayroll = ref(null)
const paymentDraft = ref(null)
const selectedPayrollIds = ref([])

const baseSalaryByRole = {
  employee: 95000,
  manager: 180000,
  hr: 130000
}

const statusFlow = ['requested', 'processing', 'generated', 'approved', 'paid']

function defaultPayrollDetails(employee) {
  const baseSalary = employee.salary || baseSalaryByRole[employee.role] || 90000
  return {
    baseSalary,
    houseAllowance: Math.round(baseSalary * 0.12),
    medicalAllowance: Math.round(baseSalary * 0.06),
    bonus: 0,
    taxDeduction: Math.round(baseSalary * 0.05),
    attendanceDeduction: Math.round(baseSalary * 0.03),
    advanceDeduction: 0,
    notes: ''
  }
}

function payrollValues(employee, request) {
  const details = {
    ...defaultPayrollDetails(employee),
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
      const values = payrollValues(employee, request)
      return {
        id: `${selectedMonth.value}-${employee.id}`,
        employee,
        month: selectedMonth.value,
        ...values,
        slipNo: request?.slipNo || '',
        generatedAt: request?.generatedAt || '',
        generatedById: request?.generatedById || null,
        paidAt: request?.paidAt || '',
        approvedAt: request?.approvedAt || '',
        approvedById: request?.approvedById || null,
        period: request?.period || { ...selectedPeriod.value },
        payment: request?.payment || null,
        history: request?.history || [],
        status: request?.status || 'draft',
        requested: Boolean(request),
        request
      }
    })
    .filter(row => {
      if (canManagePayroll.value) return true
      return row.employee.id === state.currentUser.id && ['generated', 'approved', 'paid'].includes(row.status)
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

const selectableRows = computed(() => payrollTable.visibleRows.value.filter(row => row.status !== 'paid'))

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
 * The local draft for a row that has no server-side request yet.
 *
 * Only the payroll editor uses this: it holds the reviewer's adjustments
 * until generateSlip() sends them as overrides, at which point the API
 * creates the real request and its id replaces this one. It is deliberately
 * not persisted — nothing here is a record until the slip is issued.
 */
function ensureSlipRequest(row) {
  let request = row.request
  if (!request) {
    request = {
      id: Date.now(),
      draft: true,
      employeeId: row.employee.id,
      month: row.month,
      status: 'requested',
      period: { ...selectedPeriod.value },
      history: [],
      details: {
        baseSalary: row.baseSalary,
        houseAllowance: row.houseAllowance,
        medicalAllowance: row.medicalAllowance,
        bonus: row.bonus,
        taxDeduction: row.taxDeduction,
        attendanceDeduction: row.attendanceDeduction,
        advanceDeduction: row.advanceDeduction,
        notes: row.notes
      }
    }
    state.salarySlipRequests.unshift(request)
    addPayrollHistory(request, 'Created', `${state.currentUser.name} created payroll record.`)
  }
  return request
}

function addPayrollHistory(request, action, detail) {
  if (!request.history) request.history = []
  request.history.unshift({
    id: Date.now() + Math.random(),
    action,
    detail,
    by: state.currentUser.name,
    at: new Date().toISOString()
  })
}

/**
 * Issues the slip for a row.
 *
 * The API issues it by approving the employee's request, so a row with no
 * request yet needs one raised first. Adjustments the reviewer made in the
 * editor ride along as overrides — they apply to this month's slip only and
 * leave the standing salary alone.
 */
async function generateSlip(row, openDetail = Boolean(selectedPayroll.value)) {
  if (!canManagePayroll.value || row.status === 'paid') return

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
        request = await payrollService.requestSlip(period)
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

    upsertSalarySlipRequest(await payrollService.decideRequest(request.id, 'approved', {
      baseSalary: row.baseSalary,
      houseAllowance: row.houseAllowance,
      medicalAllowance: row.medicalAllowance,
      bonus: row.bonus,
      taxDeduction: row.taxDeduction,
      attendanceDeduction: row.attendanceDeduction,
      advanceDeduction: row.advanceDeduction,
      notes: row.notes || undefined
    }))
  } catch (error) {
    payrollError.value = error?.message || 'Unable to generate that slip.'
    return
  } finally {
    busyRowId.value = null
  }

  addPayrollHistory(row.request || {}, 'Generated', `${state.currentUser.name} generated the payslip.`)
  logActivity({
    module: 'salary',
    action: 'payroll generated',
    details: `${employeeName(row.employee.id)} payroll for ${row.month} was generated.`,
    targetId: row.employee.id,
    targetType: 'employee'
  })

  const updatedRow = payrollRows.value.find(item => item.id === row.id) || row
  if (openDetail) selectedPayroll.value = updatedRow
}

async function approvePayroll(row) {
  if (!canApprovePayroll.value || !['processing', 'generated'].includes(row.status)) returnp exists.
  if (!row.slipId) {
    await generateSlip(row, false)
    row = payrollRows.value.find(item => item.id === row.id) || row
    if (!row.slipId) return
  }

  payrollError.value = ''
  busyRowId.value = row.id
  try {
    const slip = await payrollService.approveSlip(row.slipId)
    upsertSalarySlipRequest({ ...row.request, slip })
  } catch (error) {
    payrollError.value = error?.message || 'Unable to release that payroll.'
    return
  } finally {
    busyRowId.value = null
  }

  logActivity({
    module: 'salary',
    action: 'payroll approved',
    details: `${employeeName(row.employee.id)} payroll for ${row.month} was approved.`,
    targetId: row.employee.id,
    targetType: 'employee'
  })
  const updatedRow = payrollRows.value.find(item => item.id === row.id) || row
  if (selectedPayroll.value?.id === row.id) selectedPayroll.value = updatedRow
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

function downloadMockPdf(row) {
  const slip = ensureGeneratedForAction(row)
  const lines = [
    'Orbit HRMS - Salary Slip',
    `Slip No: ${slip.slipNo || 'Draft'}`,
    `Month: ${slip.month}`,
    `Period: ${slip.period.from} to ${slip.period.to}`,
    `Employee: ${slip.employee.name}`,
    `Email: ${slip.employee.email}`,
    `Department: ${slip.employee.department}`,
    `Generated By: ${generatedByName(slip)}`,
    `Generated At: ${formatDateTime(slip.generatedAt)}`,
    `Approved By: ${approvedByName(slip)}`,
    `Approved At: ${formatDateTime(slip.approvedAt)}`,
    `Paid Date: ${paidDateLabel(slip)}`,
    '',
    'Earnings',
    `Base Salary: ${formatCurrency(slip.baseSalary)}`,
    `House Allowance: ${formatCurrency(slip.houseAllowance)}`,
    `Medical Allowance: ${formatCurrency(slip.medicalAllowance)}`,
    `Bonus / Adjustment: ${formatCurrency(slip.bonus)}`,
    `Gross Pay: ${formatCurrency(slip.baseSalary + slip.allowances)}`,
    '',
    'Deductions',
    `Tax Deduction: ${formatCurrency(slip.taxDeduction)}`,
    `Attendance Deduction: ${formatCurrency(slip.attendanceDeduction)}`,
    `Advance Deduction: ${formatCurrency(slip.advanceDeduction)}`,
    `Total Deductions: ${formatCurrency(slip.deductions)}`,
    '',
    `Net Pay: ${formatCurrency(slip.netPay)}`
  ]
  const blob = new Blob([createSimplePdf(lines)], { type: 'application/pdf' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = `${slip.slipNo || `PAY-${slip.employee.id}`}.pdf`
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
  URL.revokeObjectURL(url)
}

function createSimplePdf(lines) {
  const escapedLines = lines.map(line => String(line).replace(/[()\\]/g, '\\$&'))
  const content = [
    'BT',
    '/F1 16 Tf',
    '50 790 Td',
    ...escapedLines.flatMap((line, index) => [
      index === 0 ? '' : '0 -18 Td',
      `(${line}) Tj`
    ]).filter(Boolean),
    'ET'
  ].join('\n')
  const objects = [
    '<< /Type /Catalog /Pages 2 0 R >>',
    '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
    '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
    '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    `<< /Length ${content.length} >>\nstream\n${content}\nendstream`
  ]
  let pdf = '%PDF-1.4\n'
  const offsets = [0]
  objects.forEach((object, index) => {
    offsets.push(pdf.length)
    pdf += `${index + 1} 0 obj\n${object}\nendobj\n`
  })
  const xrefOffset = pdf.length
  pdf += `xref\n0 ${objects.length + 1}\n0000000000 65535 f \n`
  offsets.slice(1).forEach(offset => {
    pdf += `${String(offset).padStart(10, '0')} 00000 n \n`
  })
  pdf += `trailer\n<< /Size ${objects.length + 1} /Root 1 0 R >>\nstartxref\n${xrefOffset}\n%%EOF`
  return pdf
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
  pushNotification({
    title: 'Salary paid',
    message: `${row.month} payroll has been marked paid.`,
    type: 'salary',
    userIds: [row.employee.id],
    route: { name: 'payroll' }
  })
  const updatedRow = payrollRows.value.find(item => item.id === row.id) || row
  selectedPayroll.value = updatedRow
  paymentPayroll.value = null
  paymentDraft.value = null
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

  addPayrollHistory(row.request || {}, 'Reopened', `${state.currentUser.name} reopened payroll for correction.`)
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
  if (!canManagePayroll.value || row.status === 'paid') return
  payrollError.value = ''
  editingPayroll.value = row
  payrollDraft.value = {
    baseSalary: row.baseSalary,
    houseAllowance: row.houseAllowance,
    medicalAllowance: row.medicalAllowance,
    bonus: row.bonus,
    taxDeduction: row.taxDeduction,
    attendanceDeduction: row.attendanceDeduction,
    advanceDeduction: row.advanceDeduction,
    notes: row.notes || ''
  }
}

const draftTotals = computed(() => {
  if (!payrollDraft.value) return { allowances: 0, deductions: 0, netPay: 0 }
  const allowances = Number(payrollDraft.value.houseAllowance || 0) + Number(payrollDraft.value.medicalAllowance || 0) + Number(payrollDraft.value.bonus || 0)
  const deductions = Number(payrollDraft.value.taxDeduction || 0) + Number(payrollDraft.value.attendanceDeduction || 0) + Number(payrollDraft.value.advanceDeduction || 0)
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
    ['attendanceDeduction', 'Attendance deduction'],
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
  const deductions = Number(draft.taxDeduction || 0) + Number(draft.attendanceDeduction || 0)
    + Number(draft.advanceDeduction || 0)
  if (deductions > earnings) return 'Total deductions cannot exceed total earnings.'
  return ''
}

function savePayrollDetails() {
  if (!editingPayroll.value || !payrollDraft.value) return
  payrollError.value = validatePayrollDraft(payrollDraft.value)
  if (payrollError.value) return
  const request = ensureSlipRequest(editingPayroll.value)
  request.status = request.status === 'approved' ? 'approved' : 'processing'
  request.details = {
    baseSalary: Number(payrollDraft.value.baseSalary || 0),
    houseAllowance: Number(payrollDraft.value.houseAllowance || 0),
    medicalAllowance: Number(payrollDraft.value.medicalAllowance || 0),
    bonus: Number(payrollDraft.value.bonus || 0),
    taxDeduction: Number(payrollDraft.value.taxDeduction || 0),
    attendanceDeduction: Number(payrollDraft.value.attendanceDeduction || 0),
    advanceDeduction: Number(payrollDraft.value.advanceDeduction || 0),
    notes: payrollDraft.value.notes || ''
  }
  request.period = { ...selectedPeriod.value }
  addPayrollHistory(request, 'Edited', `${state.currentUser.name} updated payroll details.`)
  logActivity({
    module: 'salary',
    action: 'payroll details updated',
    details: `${employeeName(editingPayroll.value.employee.id)} payroll details for ${editingPayroll.value.month} were updated.`,
    targetId: editingPayroll.value.employee.id,
    targetType: 'employee'
  })
  const updatedRow = payrollRows.value.find(row => row.id === editingPayroll.value.id)
  if (selectedPayroll.value?.id === editingPayroll.value.id && updatedRow) {
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
}

async function approveSelected() {
  if (!canApprovePayroll.value) return
  for (const row of selectedRows.value.filter(item => ['processing', 'generated'].includes(item.status))) {
    await approvePayroll(row)
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
      addPayrollHistory(row.request || {}, 'Paid', `${state.currentUser.name} marked payroll paid in bulk.`)
    } catch (error) {
      payrollError.value = `${employeeName(row.employee.id)}: ${error?.message || 'payment failed'}`
    } finally {
      busyRowId.value = null
    }
  }
}

async function moveRequestNext(request) {
  const row = payrollRows.value.find(item => item.employee.id === request.employeeId && item.month === request.month)

  if (!row) {
    payrollError.value = 'That request is not part of the selected month.'
    return
  }
  if (!row.slipId) {
    await generateSlip(row, false)
    return
  }

  if (row.status === 'generated') {
    await approvePayroll(row)
  }
}
</script>

<template>
  <div v-if="canAccess" class="space-y-6">
    <div class="flex flex-col xl:flex-row xl:items-end justify-between gap-4">
      <div>
        <div class="text-sm text-slate-600 mt-1">
          {{ canManagePayroll ? 'Manage monthly payroll, approvals, generated slips, and employee copy requests.' : 'View, print, and download your generated salary slips.' }}
        </div>
      </div>
      <div class="flex flex-wrap items-end gap-2">
        <label class="block">
          <span class="mb-1 block text-[11px] font-bold uppercase tracking-wide text-slate-500">Month</span>
          <select v-model="selectedMonth" class="table-filter-input max-w-[180px]">
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
      </div>
    </div>

    <section v-if="canManagePayroll" class="grid grid-cols-1 md:grid-cols-3 xl:grid-cols-6 gap-4">
      <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
        <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Employees</div>
        <div class="text-2xl font-extrabold text-slate-900 mt-2">{{ totals.employees }}</div>
      </div>
      <div v-if="loadError || (payrollError && !editingPayroll)" class="col-span-full rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
        {{ loadError || payrollError }}
      </div>
      <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
        <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Gross payroll</div>
        <div class="text-xl font-extrabold text-slate-900 mt-2">{{ formatCurrency(totals.gross) }}</div>
      </div>
      <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
        <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Deductions</div>
        <div class="text-xl font-extrabold text-rose-600 mt-2">{{ formatCurrency(totals.deductions) }}</div>
      </div>
      <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
        <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Net payable</div>
        <div class="text-xl font-extrabold text-emerald-600 mt-2">{{ formatCurrency(totals.net) }}</div>
      </div>
      <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
        <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Paid slips</div>
        <div class="text-2xl font-extrabold text-slate-900 mt-2">{{ totals.paid }}</div>
      </div>
      <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
        <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Requests</div>
        <div class="text-2xl font-extrabold text-slate-900 mt-2">{{ totals.pendingRequests }}</div>
      </div>
    </section>

    <section class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm">
      <div class="px-4 py-3 border-b border-slate-200">
        <div class="text-sm font-bold text-slate-900">Monthly Payroll Register</div>
        <div class="text-xs text-slate-600 mt-1">Generate slips, review deductions, and mark monthly payroll as paid.</div>
      </div>
      <div class="table-tools">
        <input v-model="payrollTable.query.value" type="search" placeholder="Search payroll..." class="table-filter-input" />
        <div v-if="canManagePayroll" class="flex flex-wrap items-center gap-2">
          <div class="table-count">{{ selectedRows.length }} selected</div>
          <button @click="generateSelected" :disabled="selectedRows.length === 0" class="text-xs bg-slate-950 text-white font-bold rounded-lg px-3 py-2 hover:bg-black disabled:bg-slate-300 disabled:cursor-not-allowed transition-colors">Generate selected</button>
          <button v-if="canApprovePayroll" @click="approveSelected" :disabled="selectedRows.length === 0" class="text-xs border border-slate-200 bg-white text-slate-700 font-bold rounded-lg px-3 py-2 hover:bg-slate-50 disabled:bg-slate-100 disabled:text-slate-400 disabled:cursor-not-allowed transition-colors">Approve selected</button>
          <button @click="markSelectedPaid" :disabled="selectedRows.length === 0" class="text-xs bg-emerald-600 text-white font-bold rounded-lg px-3 py-2 hover:bg-emerald-700 disabled:bg-slate-300 disabled:cursor-not-allowed transition-colors">Pay selected</button>
        </div>
        <div class="table-count">{{ payrollTable.filteredRows.value.length }} records</div>
      </div>
      <div class="table-scroll" @scroll="payrollTable.loadMoreRows">
        <table class="w-full text-sm">
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
                <input type="checkbox" :checked="selectedPayrollIds.includes(row.id)" :disabled="row.status === 'paid'" @change="togglePayrollSelection(row.id)" />
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
                    :disabled="row.status === 'paid'"
                    class="text-xs border border-slate-200 bg-white text-slate-700 font-bold rounded-lg px-3 py-2 hover:bg-slate-50 disabled:bg-slate-100 disabled:text-slate-400 disabled:cursor-not-allowed transition-colors"
                  >
                    Edit
                  </button>
                  <button
                    v-if="canManagePayroll && !row.slipId"
                    @click="generateSlip(row, false)"
                    :disabled="busyRowId === row.id"
                    class="text-xs bg-slate-950 text-white font-bold rounded-lg px-3 py-2 hover:bg-black disabled:bg-slate-300 disabled:cursor-not-allowed transition-colors"
                  >
                    {{ busyRowId === row.id ? 'Working...' : 'Generate' }}
                  </button>
                  <button
                    v-if="canApprovePayroll && ['processing', 'generated'].includes(row.status)"
                    @click="approvePayroll(row)"
                    :disabled="busyRowId === row.id"
                    class="text-xs border border-slate-200 bg-white text-slate-700 font-bold rounded-lg px-3 py-2 hover:bg-slate-50 disabled:opacity-60 disabled:cursor-not-allowed transition-colors"
                  >
                    Approve
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
          </tbody>
        </table>
      </div>
    </section>

    <section v-if="canManagePayroll" class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm">
      <div class="px-4 py-3 border-b border-slate-200">
        <div class="text-sm font-bold text-slate-900">Payroll Slip Requests</div>
        <div class="text-xs text-slate-600 mt-1">Employee requests for a generated payroll slip copy.</div>
      </div>
      <div class="table-scroll">
        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-slate-200 text-left bg-slate-50/80">
              <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Employee</th>
              <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Month</th>
              <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Status</th>
              <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Action</th>
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
                <button
                  @click="moveRequestNext(request)"
                  :disabled="request.status === 'paid'"
                  class="text-xs font-bold rounded-lg px-3 py-2 bg-emerald-600 text-white hover:bg-emerald-700 disabled:bg-slate-300 disabled:cursor-not-allowed transition-colors"
                >
                  Move next
                </button>
              </td>
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
            <button @click="downloadMockPdf(selectedPayroll)" class="flex-1 text-sm border border-slate-200 rounded-lg py-2 hover:bg-slate-50 transition-colors text-slate-700">Download PDF</button>
            <button
              v-if="canManagePayroll"
              @click="openPayrollEditor(selectedPayroll)"
              :disabled="selectedPayroll.status === 'paid'"
              class="flex-1 text-sm border border-slate-200 rounded-lg py-2 hover:bg-slate-50 disabled:bg-slate-100 disabled:text-slate-400 disabled:cursor-not-allowed transition-colors text-slate-700"
            >
              Edit details
            </button>
            <button
              v-if="canManagePayroll"
              @click="generateSlip(selectedPayroll, true)"
              :disabled="selectedPayroll.status === 'paid'"
              class="flex-1 bg-slate-950 text-white text-sm font-bold rounded-lg py-2 hover:bg-black disabled:bg-slate-300 disabled:cursor-not-allowed transition-colors"
            >
              {{ selectedPayroll.slipNo ? 'Regenerate slip' : 'Generate slip' }}
            </button>
            <button
              v-if="canApprovePayroll && ['processing', 'generated'].includes(selectedPayroll.status)"
              @click="approvePayroll(selectedPayroll)"
              class="flex-1 text-sm border border-slate-200 rounded-lg py-2 hover:bg-slate-50 transition-colors text-slate-700"
            >
              Approve
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
              <label class="block">
                <span class="mb-1 block text-xs font-bold text-slate-600">Attendance deduction</span>
                <input v-model.number="payrollDraft.attendanceDeduction" type="number" min="0" class="w-full text-sm border border-slate-200 rounded-lg px-3 py-2 text-slate-700" :placeholder="`${currencyCode()} amount`" />
              </label>
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
            <button @click="savePayrollDetails" class="flex-1 bg-slate-950 text-white text-sm font-bold rounded-lg py-2 hover:bg-black transition-colors">Save details</button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>

  <div v-else class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
    <div class="text-lg font-semibold text-slate-900">Payroll is not available for this role</div>
  </div>
</template>