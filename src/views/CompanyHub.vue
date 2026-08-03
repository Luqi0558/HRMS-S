<script setup>
import { computed, ref } from 'vue'
import { useStore } from '../store/index.js'
import { useTableControls } from '../composables/useTableControls.js'

const { state, can, formatDate: formatCompanyDate, logActivity, pushNotification } = useStore()

const canManage = computed(() => can('Company Hub', 'create'))
const activeTab = ref('announcements')
const showAnnouncementForm = ref(false)
const showPolicyForm = ref(false)
const announcementError = ref('')
const policyError = ref('')

function openAnnouncementForm() {
  announcementError.value = ''
  showAnnouncementForm.value = true
}

function openPolicyForm() {
  policyError.value = ''
  showPolicyForm.value = true
}
const selectedItem = ref(null)
const selectedItemType = ref('')
const audienceFilter = ref('all')
const priorityFilter = ref('all')
const categoryFilter = ref('all')
const announcementForm = ref({ title: '', audience: 'All company', priority: 'normal', body: '' })
const policyForm = ref({ title: '', category: 'HR', version: '1.0', summary: '', acknowledgmentRequired: true })

const filteredAnnouncements = computed(() =>
  state.announcements.filter(item => {
    const matchesAudience = audienceFilter.value === 'all' || item.audience === audienceFilter.value
    const matchesPriority = priorityFilter.value === 'all' || item.priority === priorityFilter.value
    return matchesAudience && matchesPriority
  })
)

const filteredPolicies = computed(() =>
  state.policies.filter(policy => categoryFilter.value === 'all' || policy.category === categoryFilter.value)
)

const announcementAudiences = computed(() => Array.from(new Set(state.announcements.map(item => item.audience))).sort())
const announcementPriorities = computed(() => Array.from(new Set(state.announcements.map(item => item.priority))).sort())
const policyCategories = computed(() => Array.from(new Set(state.policies.map(policy => policy.category))).sort())

const announcementTable = useTableControls(
  filteredAnnouncements,
  item => `${item.title} ${item.audience} ${item.priority} ${item.body}`,
  10
)

const policyTable = useTableControls(
  filteredPolicies,
  item => `${item.title} ${item.category} ${item.version} ${item.summary}`,
  10
)

function authorName(id) {
  return state.employees.find(employee => employee.id === Number(id))?.name || 'System'
}

function formatDate(value) {
  return formatCompanyDate(value, { month: 'short', day: 'numeric', year: 'numeric' })
}

function openItem(item, type) {
  selectedItem.value = item
  selectedItemType.value = type
}

function closeItem() {
  selectedItem.value = null
  selectedItemType.value = ''
}

function publishAnnouncement() {
  announcementError.value = ''
  if (!announcementForm.value.title.trim()) {
    announcementError.value = 'Please enter an announcement title.'
    return
  }
  if (!announcementForm.value.body.trim()) {
    announcementError.value = 'Please write the announcement body.'
    return
  }
  state.announcements.unshift({
    id: Date.now(),
    ...announcementForm.value,
    authorId: state.currentUser.id,
    publishedAt: new Date().toISOString()
  })
  logActivity({ module: 'announcements', action: 'announcement published', details: `${announcementForm.value.title} was published.` })
  pushNotification({
    title: 'New announcement',
    message: announcementForm.value.title,
    type: 'announcement',
    roles: ['employee', 'manager', 'hr', 'super_admin'],
    route: { name: 'company-hub' }
  })
  announcementForm.value = { title: '', audience: 'All company', priority: 'normal', body: '' }
  showAnnouncementForm.value = false
}

function savePolicy() {
  policyError.value = ''
  if (!policyForm.value.title.trim()) {
    policyError.value = 'Please enter a policy title.'
    return
  }
  if (!policyForm.value.summary.trim()) {
    policyError.value = 'Please write a short policy summary.'
    return
  }
  state.policies.unshift({
    id: Date.now(),
    ...policyForm.value,
    ownerId: state.currentUser.id,
    updatedAt: new Date().toISOString().slice(0, 10)
  })
  logActivity({ module: 'policies', action: 'policy added', details: `${policyForm.value.title} was added.` })
  pushNotification({
    title: 'Policy updated',
    message: policyForm.value.title,
    type: 'policy',
    roles: ['employee', 'manager', 'hr', 'super_admin'],
    route: { name: 'company-hub' }
  })
  policyForm.value = { title: '', category: 'HR', version: '1.0', summary: '', acknowledgmentRequired: true }
  showPolicyForm.value = false
}
</script>

<template>
  <div class="space-y-6">
    <div class="flex flex-col lg:flex-row lg:items-end justify-between gap-4">
      <div>
        <div class="text-sm text-slate-600 mt-1">Announcements, policy updates, and company-wide notices.</div>
      </div>
      <div class="flex flex-wrap gap-2">
        <button
          v-for="tab in [{ id: 'announcements', label: 'Announcements' }, { id: 'policies', label: 'Policies' }]"
          :key="tab.id"
          @click="activeTab = tab.id"
          :class="activeTab === tab.id ? 'bg-slate-950 text-white' : 'bg-white text-slate-700 border border-slate-200 hover:bg-slate-50'"
          class="text-sm font-medium rounded-lg px-4 py-2 transition-colors"
        >
          {{ tab.label }}
        </button>
      </div>
    </div>

    <section v-if="activeTab === 'announcements'" class="space-y-4">
      <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
        <div class="flex flex-col xl:flex-row xl:items-center justify-between gap-3">
          <div class="grid grid-cols-1 md:grid-cols-3 gap-3 flex-1">
            <input v-model="announcementTable.query.value" type="search" placeholder="Search announcements..." class="table-filter-input" />
            <select v-model="audienceFilter" class="table-filter-input">
              <option value="all">All audiences</option>
              <option v-for="audience in announcementAudiences" :key="audience" :value="audience">{{ audience }}</option>
            </select>
            <select v-model="priorityFilter" class="table-filter-input">
              <option value="all">All priorities</option>
              <option v-for="priority in announcementPriorities" :key="priority" :value="priority">{{ priority }}</option>
            </select>
          </div>
          <button v-if="canManage" @click="openAnnouncementForm()" class="bg-emerald-600 text-white text-sm font-medium rounded-lg px-4 py-2 hover:bg-emerald-700 transition-colors">
            New announcement
          </button>
        </div>
        <div class="text-xs text-slate-500 mt-3">{{ announcementTable.filteredRows.value.length }} announcements found</div>
      </div>

      <div class="table-scroll pr-1" @scroll="announcementTable.loadMoreRows">
        <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
          <article
            v-for="item in announcementTable.visibleRows.value"
            :key="item.id"
            class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm hover:border-emerald-200 hover:shadow-md transition-all cursor-pointer"
            @click="openItem(item, 'announcement')"
          >
            <div class="flex items-start justify-between gap-4">
              <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2 mb-3">
                  <span class="company-hub-badge company-hub-badge-neutral capitalize">{{ item.priority }}</span>
                  <span class="company-hub-badge company-hub-badge-success">{{ item.audience }}</span>
                </div>
                <h3 class="text-lg font-extrabold text-slate-900 leading-tight">{{ item.title }}</h3>
                <p class="text-sm text-slate-600 mt-2 line-clamp-3">{{ item.body }}</p>
              </div>
            </div>
            <div class="mt-5 flex items-center justify-between gap-3 border-t border-slate-200 pt-4">
              <div>
                <div class="text-xs font-bold text-slate-900">{{ authorName(item.authorId) }}</div>
                <div class="text-[11px] text-slate-500">Published by {{ authorName(item.authorId) }}</div>
              </div>
              <div class="text-xs font-semibold text-slate-500">{{ formatDate(item.publishedAt) }}</div>
            </div>
          </article>
        </div>
        <div v-if="announcementTable.filteredRows.value.length === 0" class="app-empty-state">
          <div class="app-empty-title">No announcements found</div>
          <div class="app-empty-copy">Try another audience, priority, or search term.</div>
        </div>
      </div>
    </section>

    <section v-if="activeTab === 'policies'" class="space-y-4">
      <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
        <div class="flex flex-col xl:flex-row xl:items-center justify-between gap-3">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-3 flex-1">
            <input v-model="policyTable.query.value" type="search" placeholder="Search policies..." class="table-filter-input" />
            <select v-model="categoryFilter" class="table-filter-input">
              <option value="all">All categories</option>
              <option v-for="category in policyCategories" :key="category" :value="category">{{ category }}</option>
            </select>
          </div>
          <button v-if="canManage" @click="openPolicyForm()" class="bg-emerald-600 text-white text-sm font-medium rounded-lg px-4 py-2 hover:bg-emerald-700 transition-colors">
            Add policy
          </button>
        </div>
        <div class="text-xs text-slate-500 mt-3">{{ policyTable.filteredRows.value.length }} policies found</div>
      </div>

      <div class="table-scroll pr-1" @scroll="policyTable.loadMoreRows">
        <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
          <article
            v-for="policy in policyTable.visibleRows.value"
            :key="policy.id"
            class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm hover:border-emerald-200 hover:shadow-md transition-all cursor-pointer"
            @click="openItem(policy, 'policy')"
          >
            <div class="flex items-start justify-between gap-4">
              <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2 mb-3">
                  <span class="company-hub-badge company-hub-badge-neutral">{{ policy.category }}</span>
                  <span class="company-hub-badge company-hub-badge-info">v{{ policy.version }}</span>
                  <span class="company-hub-badge" :class="policy.acknowledgmentRequired ? 'company-hub-badge-warning' : 'company-hub-badge-muted'">
                    {{ policy.acknowledgmentRequired ? 'Ack required' : 'Optional ack' }}
                  </span>
                </div>
                <h3 class="text-lg font-extrabold text-slate-900 leading-tight">{{ policy.title }}</h3>
                <p class="text-sm text-slate-600 mt-2 line-clamp-3">{{ policy.summary }}</p>
              </div>
            </div>
            <div class="mt-5 flex items-center justify-between gap-3 border-t border-slate-200 pt-4">
              <div>
                <div class="text-xs font-bold text-slate-900">{{ authorName(policy.ownerId) }}</div>
                <div class="text-[11px] text-slate-500">Policy owner</div>
              </div>
              <div class="text-xs font-semibold text-slate-500">Updated {{ formatDate(policy.updatedAt) }}</div>
            </div>
          </article>
        </div>
        <div v-if="policyTable.filteredRows.value.length === 0" class="app-empty-state">
          <div class="app-empty-title">No policies found</div>
          <div class="app-empty-copy">Try another category or search term.</div>
        </div>
      </div>
    </section>

    <Teleport to="body">
      <div v-if="selectedItem" class="fixed inset-0 z-50 bg-black/40 backdrop-blur-[2px] flex items-center justify-center px-4 py-4 overflow-y-auto" @click.self="closeItem">
        <div class="bg-white rounded-2xl p-6 w-full max-w-2xl border border-slate-200 shadow-2xl max-h-[calc(100vh-2rem)] overflow-hidden flex flex-col">
          <div class="flex items-start justify-between gap-4 border-b border-slate-200 pb-4">
            <div class="min-w-0">
              <div class="text-[11px] uppercase tracking-[0.16em] text-slate-500 font-bold">
                {{ selectedItemType === 'announcement' ? 'Announcement' : 'Policy' }}
              </div>
              <div class="text-2xl font-extrabold text-slate-900 mt-1 leading-tight">{{ selectedItem.title }}</div>
              <div class="flex flex-wrap items-center gap-2 mt-3">
                <span v-if="selectedItemType === 'announcement'" class="company-hub-badge company-hub-badge-success text-xs">{{ selectedItem.audience }}</span>
                <span v-if="selectedItemType === 'announcement'" class="company-hub-badge company-hub-badge-neutral text-xs capitalize">{{ selectedItem.priority }}</span>
                <span v-if="selectedItemType === 'policy'" class="company-hub-badge company-hub-badge-neutral text-xs">{{ selectedItem.category }}</span>
                <span v-if="selectedItemType === 'policy'" class="company-hub-badge company-hub-badge-info text-xs">v{{ selectedItem.version }}</span>
                <span v-if="selectedItemType === 'policy'" class="company-hub-badge text-xs" :class="selectedItem.acknowledgmentRequired ? 'company-hub-badge-warning' : 'company-hub-badge-muted'">
                  {{ selectedItem.acknowledgmentRequired ? 'Acknowledgment required' : 'Acknowledgment optional' }}
                </span>
              </div>
            </div>
            <button @click="closeItem" class="text-slate-500 hover:text-slate-900 text-xl leading-none">x</button>
          </div>

          <div class="modal-scroll-hidden flex-1 overflow-y-auto py-5">
            <p class="text-sm leading-7 text-slate-700 whitespace-pre-line">
              {{ selectedItemType === 'announcement' ? selectedItem.body : selectedItem.summary }}
            </p>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 border-t border-slate-200 pt-4">
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
              <div class="text-[11px] uppercase tracking-wide text-slate-500 font-bold">{{ selectedItemType === 'announcement' ? 'Published by' : 'Policy owner' }}</div>
              <div class="text-sm font-extrabold text-slate-900 mt-1">{{ authorName(selectedItemType === 'announcement' ? selectedItem.authorId : selectedItem.ownerId) }}</div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
              <div class="text-[11px] uppercase tracking-wide text-slate-500 font-bold">{{ selectedItemType === 'announcement' ? 'Published' : 'Updated' }}</div>
              <div class="text-sm font-extrabold text-slate-900 mt-1">{{ formatDate(selectedItemType === 'announcement' ? selectedItem.publishedAt : selectedItem.updatedAt) }}</div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
              <div class="text-[11px] uppercase tracking-wide text-slate-500 font-bold">{{ selectedItemType === 'announcement' ? 'Audience' : 'Category' }}</div>
              <div class="text-sm font-extrabold text-slate-900 mt-1">{{ selectedItemType === 'announcement' ? selectedItem.audience : selectedItem.category }}</div>
            </div>
          </div>
        </div>
      </div>
    </Teleport>

    <Teleport to="body">
      <div v-if="showAnnouncementForm" class="fixed inset-0 z-50 bg-black/40 backdrop-blur-[2px] flex items-center justify-center px-4 py-4 overflow-y-auto" @click.self="showAnnouncementForm = false">
        <div class="bg-white rounded-xl p-6 w-full max-w-xl border border-slate-200 shadow-2xl">
          <div class="font-semibold text-[15px] text-slate-900 mb-1">New announcement</div>
          <div class="text-xs text-slate-500 mb-4">Send a company notice to the selected audience.</div>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <input v-model="announcementForm.title" placeholder="Title" class="md:col-span-2 rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500" />
            <select v-model="announcementForm.audience" class="rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500">
              <option>All company</option>
              <option>Employees and Managers</option>
              <option>HR only</option>
            </select>
            <select v-model="announcementForm.priority" class="rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500">
              <option value="normal">Normal</option>
              <option value="high">High</option>
              <option value="urgent">Urgent</option>
            </select>
            <textarea v-model="announcementForm.body" placeholder="Announcement details" class="md:col-span-2 rounded-lg border border-slate-200 px-3 py-2 text-sm min-h-28 focus:outline-none focus:ring-1 focus:ring-emerald-500"></textarea>
          </div>
          <div v-if="announcementError" class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
            {{ announcementError }}
          </div>
          <div class="flex gap-2 mt-5">
            <button @click="showAnnouncementForm = false" class="flex-1 text-sm border border-slate-200 rounded-lg py-2 hover:bg-slate-50 transition-colors text-slate-700">Cancel</button>
            <button @click="publishAnnouncement" class="flex-1 bg-emerald-600 text-white text-sm font-medium rounded-lg py-2 hover:bg-emerald-700 transition-colors">Publish</button>
          </div>
        </div>
      </div>
    </Teleport>

    <Teleport to="body">
      <div v-if="showPolicyForm" class="fixed inset-0 z-50 bg-black/40 backdrop-blur-[2px] flex items-center justify-center px-4 py-4 overflow-y-auto" @click.self="showPolicyForm = false">
        <div class="bg-white rounded-xl p-6 w-full max-w-xl border border-slate-200 shadow-2xl">
          <div class="font-semibold text-[15px] text-slate-900 mb-1">Add policy</div>
          <div class="text-xs text-slate-500 mb-4">Publish a policy record for employees to reference.</div>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <input v-model="policyForm.title" placeholder="Policy title" class="md:col-span-2 rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500" />
            <select v-model="policyForm.category" class="rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500">
              <option>HR</option>
              <option>IT</option>
              <option>Finance</option>
              <option>Security</option>
            </select>
            <input v-model="policyForm.version" placeholder="Version" class="rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500" />
            <textarea v-model="policyForm.summary" placeholder="Policy summary" class="md:col-span-2 rounded-lg border border-slate-200 px-3 py-2 text-sm min-h-28 focus:outline-none focus:ring-1 focus:ring-emerald-500"></textarea>
            <label class="md:col-span-2 flex items-center gap-2 text-sm font-medium text-slate-700">
              <input v-model="policyForm.acknowledgmentRequired" type="checkbox" class="rounded border-slate-300" />
              Acknowledgment required
            </label>
          </div>
          <div v-if="policyError" class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
            {{ policyError }}
          </div>
          <div class="flex gap-2 mt-5">
            <button @click="showPolicyForm = false" class="flex-1 text-sm border border-slate-200 rounded-lg py-2 hover:bg-slate-50 transition-colors text-slate-700">Cancel</button>
            <button @click="savePolicy" class="flex-1 bg-emerald-600 text-white text-sm font-medium rounded-lg py-2 hover:bg-emerald-700 transition-colors">Save policy</button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>
