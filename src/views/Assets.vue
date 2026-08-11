<script setup>
import { computed, ref, onMounted } from 'vue'
import { employeeService } from '../services/employeeService.js'
import { assetService } from '../services/assetService.js'
import { useStore } from '../store/index.js'
import { useTableControls } from '../composables/useTableControls.js'

const { state, can, logActivity, pushNotification,
  setEmployees,
  setAssets
} = useStore()

const canAccess = computed(() => can('Assets', 'view'))
const canManage = computed(() => can('Assets', 'create'))
const showForm = ref(false)
const saving = ref(false)
const selectedAsset = ref(null)
const assetError = ref('')
const form = ref({
  name: '',
  category: 'Laptop',
  serialNumber: '',
  assignedTo: '',
  condition: 'Good',
  status: 'available',
  issuedAt: '',
  returnDue: '',
  notes: ''
})

const visibleAssets = computed(() => {
  if (canAccess.value) return state.assets
  return []
})

const assetTable = useTableControls(
  visibleAssets,
  asset => `${asset.name} ${asset.category} ${asset.serialNumber} ${employeeName(asset.assignedTo)} ${asset.status}`,
  10
)

const totals = computed(() => ({
  total: visibleAssets.value.length,
  assigned: visibleAssets.value.filter(asset => asset.status === 'assigned').length,
  available: visibleAssets.value.filter(asset => asset.status === 'available').length,
  maintenance: visibleAssets.value.filter(asset => asset.status === 'maintenance').length
}))

function employeeName(id) {
  return state.employees.find(employee => employee.id === Number(id))?.name || 'Unassigned'
}

function openForm(asset = null) {
  assetError.value = ''
  selectedAsset.value = asset
  form.value = asset
    ? { ...asset, assignedTo: asset.assignedTo || '' }
    : {
        name: '',
        category: 'Laptop',
        serialNumber: '',
        assignedTo: '',
        condition: 'Good',
        status: 'available',
        issuedAt: '',
        returnDue: '',
        notes: ''
      }
  showForm.value = true
}

// Serial numbers identify physical hardware, so a duplicate means two records
// for one device — the assignment history then becomes unreliable.
function validateAsset() {
  const name = form.value.name.trim()
  const serial = form.value.serialNumber.trim()
  if (!name) return 'Please enter the asset name.'
  if (!serial) return 'Please enter the serial number.'
  const normalised = serial.toLowerCase()
  const clash = state.assets.find(asset =>
    // Exclude the record being edited, or saving it unchanged would clash with itself.
    asset !== selectedAsset.value
    && (asset.serialNumber || '').trim().toLowerCase() === normalised
  )
  if (clash) return `Serial number ${serial} is already recorded for ${clash.name}.`
  return ''
}

async function saveAsset() {
  assetError.value = validateAsset()
  if (assetError.value) return

  const payload = {
    ...form.value,
    assignedTo: form.value.assignedTo ? Number(form.value.assignedTo) : null
  }

  // Assignment is its own action, not a field on the asset: the API records a
  // handover so the register keeps who held what, which an overwritten column
  // could never do.
  saving.value = true
  try {
    const saved = selectedAsset.value
      ? await assetService.update(selectedAsset.value.id, payload)
      : await assetService.create(payload)

    if (payload.assignedTo) {
      await assetService.assign(saved.id, {
        employeeId: payload.assignedTo,
        returnDue: payload.returnDue || undefined,
        condition: payload.condition
      })
    }

    await loadAssets()
  } catch (error) {
    assetError.value = error?.message || 'Unable to save that asset.'
    return
  } finally {
    saving.value = false
  }

  logActivity({
    module: 'assets',
    action: selectedAsset.value ? 'asset updated' : 'asset added',
    details: `${payload.name} was ${selectedAsset.value ? 'updated' : 'added to inventory'}.`
  })

  if (payload.assignedTo) {
    pushNotification({
      title: 'Asset assigned',
      message: `${payload.name} has been assigned to you.`,
      type: 'assets',
      userIds: [payload.assignedTo],
      route: { name: 'assets' }
    })
  }
  showForm.value = false
}

async function markReturned(asset) {
  const previousEmployee = asset.assignedTo

  assetError.value = ''
  try {
    // The API closes the open assignment, so the history keeps who had it.
    await assetService.returnAsset(asset.id, { condition: asset.condition })
    await loadAssets()
  } catch (error) {
    assetError.value = error?.message || 'Unable to record that return.'
    return
  }

  logActivity({ module: 'assets', action: 'asset returned', details: `${asset.name} was returned to inventory.` })
  if (previousEmployee) {
    pushNotification({
      title: 'Asset return recorded',
      message: `${asset.name} has been marked returned.`,
      type: 'assets',
      userIds: [previousEmployee],
      route: { name: 'assets' }
    })
  }
}
/**
 * Assets are assigned to people, so the roster has to be real. The assets
 * themselves are still local until their own API lands.
 *
 * Non-fatal: roles reaching this page hold different permissions, and one 403
 * should not blank the whole view.
 */
async function loadAssets() {
  const [assets, employees] = await Promise.allSettled([
    assetService.list(),
    employeeService.list()
  ])

  if (assets.status === 'fulfilled') setAssets(assets.value)
  else assetError.value = assets.reason?.message || 'Unable to load assets.'

  // Non-fatal: only the assignee names fall back to blank.
  if (employees.status === 'fulfilled') setEmployees(employees.value.data)
}

onMounted(loadAssets)
</script>

<template>
  <div v-if="canAccess" class="space-y-6">
    <div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
      <div>
        <div class="text-sm text-slate-600 mt-1">Track laptops, accessories, and assigned company equipment.</div>
      </div>
      <button v-if="canManage" @click="openForm()" class="bg-emerald-600 text-white text-sm font-medium rounded-lg px-4 py-2 hover:bg-emerald-700 transition-colors">
        Add asset
      </button>
    </div>

    <section class="grid grid-cols-2 lg:grid-cols-4 gap-4">
      <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
        <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Total assets</div>
        <div class="text-2xl font-extrabold text-slate-900 mt-2">{{ totals.total }}</div>
      </div>
      <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
        <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Assigned</div>
        <div class="text-2xl font-extrabold text-slate-900 mt-2">{{ totals.assigned }}</div>
      </div>
      <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
        <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Available</div>
        <div class="text-2xl font-extrabold text-slate-900 mt-2">{{ totals.available }}</div>
      </div>
      <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
        <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Maintenance</div>
        <div class="text-2xl font-extrabold text-slate-900 mt-2">{{ totals.maintenance }}</div>
      </div>
    </section>

    <section class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm">
      <div class="table-tools">
        <input v-model="assetTable.query.value" type="search" placeholder="Filter assets..." class="table-filter-input" />
        <div class="table-count">{{ assetTable.filteredRows.value.length }} records</div>
      </div>
      <div class="table-scroll" @scroll="assetTable.loadMoreRows">
        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-slate-200 text-left bg-slate-50/80">
              <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Asset</th>
              <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Serial</th>
              <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Assigned to</th>
              <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Condition</th>
              <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Status</th>
              <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="asset in assetTable.visibleRows.value" :key="asset.id" class="border-b border-slate-200 last:border-0 hover:bg-slate-50/60 transition-colors">
              <td class="px-4 py-3">
                <div class="text-slate-900 font-bold">{{ asset.name }}</div>
                <div class="text-xs text-slate-500">{{ asset.category }}</div>
              </td>
              <td class="px-4 py-3 text-slate-700">{{ asset.serialNumber }}</td>
              <td class="px-4 py-3 text-slate-700">{{ employeeName(asset.assignedTo) }}</td>
              <td class="px-4 py-3 text-slate-700">{{ asset.condition }}</td>
              <td class="px-4 py-3">
                <span class="inline-flex rounded-full bg-slate-100 px-3 py-1 text-xs font-bold capitalize text-slate-700">{{ asset.status }}</span>
              </td>
              <td class="px-4 py-3">
                <div class="flex flex-wrap gap-2">
                  <button @click="openForm(asset)" class="text-sm border border-slate-200 bg-white text-slate-700 font-medium rounded-lg px-3 py-2 hover:bg-slate-50 transition-colors">
                    View
                  </button>
                  <button v-if="canManage && asset.status === 'assigned'" @click="markReturned(asset)" class="text-sm bg-emerald-600 text-white font-medium rounded-lg px-3 py-2 hover:bg-emerald-700 transition-colors">
                    Return
                  </button>
                </div>
              </td>
            </tr>
            <tr v-if="assetTable.filteredRows.value.length === 0">
              <td colspan="6" class="px-4 py-8 text-center text-sm text-slate-500">No assets found.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <Teleport to="body">
      <div v-if="showForm" class="fixed inset-0 z-50 bg-black/40 backdrop-blur-[2px] flex items-center justify-center px-4 py-4 overflow-y-auto" @click.self="showForm = false">
        <div class="bg-white rounded-xl p-6 w-full max-w-2xl border border-slate-200 shadow-2xl max-h-[calc(100vh-2rem)] overflow-y-auto">
          <div class="flex items-start justify-between gap-4 mb-4">
            <div>
              <div class="font-semibold text-[15px] text-slate-900">{{ selectedAsset ? 'Asset details' : 'Add asset' }}</div>
              <div class="text-xs text-slate-500 mt-1">Keep inventory assignment and return records traceable.</div>
            </div>
            <button @click="showForm = false" class="text-slate-500 hover:text-slate-900 text-xl leading-none">x</button>
          </div>
          <div v-if="selectedAsset" class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-5">
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
              <div class="text-[11px] uppercase tracking-wide text-slate-500 font-bold">Current assignee</div>
              <div class="text-sm font-extrabold text-slate-900 mt-1">{{ employeeName(form.assignedTo) }}</div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
              <div class="text-[11px] uppercase tracking-wide text-slate-500 font-bold">Current status</div>
              <div class="text-sm font-extrabold text-slate-900 mt-1 capitalize">{{ form.status }}</div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
              <div class="text-[11px] uppercase tracking-wide text-slate-500 font-bold">Serial number</div>
              <div class="text-sm font-extrabold text-slate-900 mt-1">{{ form.serialNumber || '-' }}</div>
            </div>
          </div>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div>
              <label class="block">
              <span class="text-xs font-bold uppercase tracking-wide text-slate-500 block mb-1.5">Asset name</span>
              <input v-model="form.name" :disabled="!canManage" placeholder="Asset name" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500" />
              </label>
            </div>
            <div>
              <label class="block">
              <span class="text-xs font-bold uppercase tracking-wide text-slate-500 block mb-1.5">Category</span>
              <select v-model="form.category" :disabled="!canManage" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500">
                <option>Laptop</option>
                <option>Monitor</option>
                <option>Accessory</option>
                <option>Mobile</option>
                <option>Other</option>
              </select>
              </label>
            </div>
            <div>
              <label class="block">
              <span class="text-xs font-bold uppercase tracking-wide text-slate-500 block mb-1.5">Serial number</span>
              <input v-model="form.serialNumber" :disabled="!canManage" placeholder="Serial number" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500" />
              </label>
            </div>
            <div>
              <label class="block">
              <span class="text-xs font-bold uppercase tracking-wide text-slate-500 block mb-1.5">Assigned employee</span>
              <select v-model="form.assignedTo" :disabled="!canManage" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500">
                <option value="">Unassigned</option>
                <option v-for="employee in state.employees" :key="employee.id" :value="employee.id">{{ employee.name }}</option>
              </select>
              </label>
            </div>
            <div>
              <label class="block">
              <span class="text-xs font-bold uppercase tracking-wide text-slate-500 block mb-1.5">Physical condition</span>
              <select v-model="form.condition" :disabled="!canManage" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500">
                <option>New</option>
                <option>Excellent</option>
                <option>Good</option>
                <option>Needs repair</option>
              </select>
              </label>
            </div>
            <div>
              <label class="block">
              <span class="text-xs font-bold uppercase tracking-wide text-slate-500 block mb-1.5">Inventory status</span>
              <select v-model="form.status" :disabled="!canManage" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500">
                <option value="available">Available</option>
                <option value="assigned">Assigned</option>
                <option value="maintenance">Maintenance</option>
                <option value="retired">Retired</option>
              </select>
              </label>
            </div>
            <div>
              <label class="block">
              <span class="text-xs font-bold uppercase tracking-wide text-slate-500 block mb-1.5">Issue date</span>
              <input v-model="form.issuedAt" :disabled="!canManage" type="date" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500" />
              </label>
            </div>
            <div>
              <label class="block">
              <span class="text-xs font-bold uppercase tracking-wide text-slate-500 block mb-1.5">Return due date</span>
              <input v-model="form.returnDue" :disabled="!canManage" type="date" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500" />
              </label>
            </div>
            <div class="md:col-span-2">
              <label class="block">
              <span class="text-xs font-bold uppercase tracking-wide text-slate-500 block mb-1.5">Asset notes</span>
              <textarea v-model="form.notes" :disabled="!canManage" placeholder="Notes" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm min-h-24 focus:outline-none focus:ring-1 focus:ring-emerald-500"></textarea>
              </label>
            </div>
          </div>
          <div v-if="assetError" class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
            {{ assetError }}
          </div>
          <div class="flex gap-2 mt-5">
            <button @click="showForm = false" class="flex-1 text-sm border border-slate-200 rounded-lg py-2 hover:bg-slate-50 transition-colors text-slate-700">Cancel</button>
            <button v-if="canManage" @click="saveAsset" class="flex-1 bg-emerald-600 text-white text-sm font-medium rounded-lg py-2 hover:bg-emerald-700 transition-colors">Save</button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>

  <div v-else class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
    <div class="text-lg font-semibold text-slate-900">Assets are not available for this role</div>
  </div>
</template>
