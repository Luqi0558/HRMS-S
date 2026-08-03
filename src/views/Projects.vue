<script setup>
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useStore } from '../store/index.js'

const route = useRoute()
const router = useRouter()
const {
  state,
  formatDuration,
  projectSecondsToday,
  can,
  scopedList,
  logActivity,
  roleLabel
} = useStore()

const showAddProject = ref(false)
const projectError = ref('')
const developerError = ref('')
const showAddDev = ref(false)
const selectedProjectId = ref(null)
// A set of ids rather than one: "Add to team" reads as a bulk action, and
// adding five people one modal at a time was five round trips.
const selectedDevelopers = ref([])
const developerSearch = ref('')
const projectContextFilter = ref('all')
const isModuleResultsView = computed(() => route.query.view === 'modules')
const moduleResultsFilterLabel = computed(() => {
  const labels = {
    open: 'Open Tickets',
    completed: 'Completed Tasks',
    overdue: 'Overdue Tickets',
    assigned: 'Assigned Modules',
    all: 'Modules'
  }
  return labels[projectContextFilter.value] || labels.all
})
const pageTitle = computed(() =>
  isModuleResultsView.value ? `${moduleResultsFilterLabel.value} by Module` : 'Project Categories'
)
const pageSubtitle = computed(() => {
  if (isModuleResultsView.value) {
    if (projectContextFilter.value === 'open') return `${visibleModuleResults.value.length} modules contain open tickets.`
    if (projectContextFilter.value === 'completed') return `${visibleModuleResults.value.length} modules contain completed tasks.`
    if (projectContextFilter.value === 'overdue') return `${visibleModuleResults.value.length} modules contain overdue tickets.`
    if (projectContextFilter.value === 'assigned') return `${visibleModuleResults.value.length} modules assigned to you.`
    return `${visibleModuleResults.value.length} modules available.`
  }
  // Previously listed example names ("Freelance, internal, ERP…") that were
  // hardcoded and drifted from whatever categories actually exist.
  return state.currentUser.role === 'super_admin' ? 'All project categories across the company' : 'Your assigned project sections'
})
const newProjectForm = ref({
  name: '',
  description: '',
  managerId: state.currentUser.role === 'manager' ? state.currentUser.id : null
})

const canCreateProject = computed(() => can('Projects', 'create'))
// HR has no Projects row at all, so it cannot view the module.
const isHR = computed(() => !can('Projects', 'view'))
const managers = computed(() => state.employees.filter(e => e.role === 'manager'))

function slugify(value) {
  return value
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
}

function uniqueProjectSlug(name) {
  const base = slugify(name) || `project-${Date.now()}`
  const existing = new Set(state.projects.map(project => project.slug))
  if (!existing.has(base)) return base
  let index = 2
  while (existing.has(`${base}-${index}`)) index += 1
  return `${base}-${index}`
}

// Scope comes from the Projects row in state.rolePermissions:
// super_admin 'all', manager 'owned' (managerId), employee 'team' (teamMembers).
const baseVisibleProjects = computed(() => scopedList('Projects', state.projects))

const visibleProjects = computed(() =>
  baseVisibleProjects.value.filter(project => {
    if (projectContextFilter.value === 'open') return openCount(project.id) > 0
    if (projectContextFilter.value === 'completed') return state.tickets.some(ticket => ticket.projectId === project.id && ticket.status === 'done')
    if (projectContextFilter.value === 'overdue') return overdueCount(project.id) > 0
    if (projectContextFilter.value === 'assigned') return (project.teamMembers || []).includes(state.currentUser.id) || project.managerId === state.currentUser.id
    return true
  })
)

const visibleModuleResults = computed(() =>
  baseVisibleProjects.value
    .flatMap(project =>
      (project.modules || [])
        .filter(module => canSeeModule(project, module))
        .filter(module => moduleMatchesContext(project, module))
        .map(module => ({ project, module }))
    )
)

watch(
  () => route.query,
  query => {
    projectContextFilter.value = query.tickets || query.filter || 'all'
  },
  { immediate: true }
)

function ticketCount(id) {
  return state.tickets.filter(t => t.projectId === id).length
}

function ticketModuleId(project, ticket) {
  return ticket.moduleId || project.modules?.[0]?.id || null
}

function moduleTickets(project, module) {
  return state.tickets.filter(ticket => ticket.projectId === project.id && ticketModuleId(project, ticket) === module.id)
}

function moduleOpenCount(project, module) {
  return moduleTickets(project, module).filter(ticket => ticket.status !== 'done').length
}

function moduleCompletedCount(project, module) {
  return moduleTickets(project, module).filter(ticket => ticket.status === 'done').length
}

function moduleOverdueCount(project, module) {
  return moduleTickets(project, module).filter(isTicketOverdue).length
}

function canSeeModule(project, module) {
  // Managers are scoped to the parent project they own; employees to the
  // module they are a team member of.
  return can('Projects', 'view', project) || can('Projects', 'view', module)
}

function moduleMatchesContext(project, module) {
  if (projectContextFilter.value === 'open') return moduleOpenCount(project, module) > 0
  if (projectContextFilter.value === 'completed') return moduleCompletedCount(project, module) > 0
  if (projectContextFilter.value === 'overdue') return moduleOverdueCount(project, module) > 0
  if (projectContextFilter.value === 'assigned') return canSeeModule(project, module)
  return true
}

function moduleCount(project) {
  return project.modules?.length || 0
}

function openCount(id) {
  return state.tickets.filter(t => t.projectId === id && t.status !== 'done').length
}

function todayKey() {
  const date = new Date()
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

function isTicketOverdue(ticket) {
  return Boolean(ticket.deadline && ticket.deadline < todayKey() && ticket.status !== 'done')
}

function overdueCount(projectId) {
  return state.tickets.filter(ticket => ticket.projectId === projectId && isTicketOverdue(ticket)).length
}

function openModuleResult(project, module) {
  const query = {}
  if (['open', 'completed', 'overdue'].includes(projectContextFilter.value)) {
    query.tickets = projectContextFilter.value
  }
  router.push({
    name: 'project-module-detail',
    params: { projectSlug: project.slug, moduleSlug: module.slug },
    query
  })
}

function getDevelopersInProject(projectId) {
  const project = state.projects.find(p => p.id === projectId)
  return project?.teamMembers?.map(id => state.employees.find(e => e.id === id)).filter(Boolean) || []
}

function getAvailableDevelopers(projectId) {
  const project = state.projects.find(p => p.id === projectId)
  const teamIds = project?.teamMembers || []
  // Any staff member can join a project team, not just role === 'employee' —
  // leads and managers do delivery work too. The project's own manager is
  // excluded because managerId already grants them access (see the 'owned'
  // scope in the store), so listing them here would be a redundant second seat.
  return state.employees.filter(e =>
    !teamIds.includes(e.id) && e.id !== project?.managerId
  )
}

// Name/designation search over the available pool. Without it the list was an
// unbounded scroll — fine for a handful of employees, unusable at fifty.
const filteredDevelopers = computed(() => {
  const pool = getAvailableDevelopers(selectedProjectId.value)
  const term = developerSearch.value.trim().toLowerCase()
  if (!term) return pool
  return pool.filter(dev =>
    `${dev.name} ${dev.department || ''} ${dev.email || ''} ${roleLabel(dev.role)}`.toLowerCase().includes(term)
  )
})

function toggleDeveloper(id) {
  developerError.value = ''
  const index = selectedDevelopers.value.indexOf(id)
  if (index === -1) selectedDevelopers.value.push(id)
  else selectedDevelopers.value.splice(index, 1)
}

function openAddDeveloper(projectId) {
  selectedProjectId.value = projectId
  selectedDevelopers.value = []
  developerSearch.value = ''
  developerError.value = ''
  showAddDev.value = true
}

function addDeveloperToProject() {
  developerError.value = ''
  if (!selectedProjectId.value) {
    developerError.value = 'Please choose a project section.'
    return
  }
  if (!selectedDevelopers.value.length) {
    developerError.value = 'Please choose at least one employee to add.'
    return
  }
  const project = state.projects.find(p => p.id === selectedProjectId.value)
  if (!project) {
    developerError.value = 'That project section no longer exists.'
    return
  }
  if (!project.teamMembers) project.teamMembers = []

  // Skip anyone added to the section since this modal opened rather than
  // failing the whole batch. A silent no-op used to look like a successful add.
  const toAdd = selectedDevelopers.value.filter(id => !project.teamMembers.includes(id))
  if (!toAdd.length) {
    developerError.value = 'Everyone selected is already on this section.'
    return
  }
  project.teamMembers.push(...toAdd)

  showAddDev.value = false
  selectedProjectId.value = null
  selectedDevelopers.value = []
  developerSearch.value = ''
}


function canManageProject(projectId) {
  const project = state.projects.find(p => p.id === projectId)
  return !!project && can('Projects', 'approve', project)
}

function openAddProject() {
  projectError.value = ''
  newProjectForm.value = {
    name: '',
    description: '',
    managerId: state.currentUser.role === 'manager' ? state.currentUser.id : managers.value[0]?.id ?? null
  }
  showAddProject.value = true
}

function validateProject() {
  const name = newProjectForm.value.name.trim()
  if (!name) return 'Please enter a section name.'
  const clash = state.projects.find(project => project.name.trim().toLowerCase() === name.toLowerCase())
  if (clash) return `A section named "${clash.name}" already exists.`
  if (!newProjectForm.value.managerId) return 'Please choose a manager for this section.'
  return ''
}

function addProject() {
  if (!canCreateProject.value) return
  projectError.value = validateProject()
  if (projectError.value) return
  const project = {
    id: Date.now(),
    name: newProjectForm.value.name.trim(),
    slug: uniqueProjectSlug(newProjectForm.value.name.trim()),
    description: newProjectForm.value.description.trim(),
    managerId: newProjectForm.value.managerId,
    teamMembers: [],
    status: 'active',
    deliveredAt: '',
    modules: []
  }
  state.projects.push(project)
  logActivity({
    module: 'project',
    action: 'project created',
    details: `${state.currentUser.name} created ${project.name}.`,
    targetId: project.id,
    targetType: 'project'
  })
  showAddProject.value = false
}

</script>

<template>
  <div v-if="!isHR" class="space-y-6">
    <div class="flex items-start justify-between gap-4">
      <div>
        <div class="text-sm text-slate-600 mt-1">
          {{ pageSubtitle }}
        </div>
      </div>
      <button
        v-if="canCreateProject"
        @click="openAddProject"
        class="bg-emerald-600 text-white text-sm font-medium rounded-lg px-4 py-2 hover:bg-emerald-700 transition-colors shadow-sm"
      >
        + Add category
      </button>
    </div>

    <div v-if="!isModuleResultsView && visibleProjects.length === 0" class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
      <strong>{{ baseVisibleProjects.length ? 'No project sections match this filter.' : 'No project sections assigned yet.' }}</strong>
      {{ baseVisibleProjects.length ? 'Change the filter to view other project categories.' : 'Contact your super admin to get started.' }}
    </div>

    <div v-if="isModuleResultsView && visibleModuleResults.length === 0" class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
      <strong>No modules match this filter.</strong>
      Change the filter or open project categories to view all modules.
    </div>

    <!-- Bare filter row rather than the bordered .table-tools card the
         table-based views use. Those pages sit directly above a table, where the
         bar reads as its header; here it floated above a card grid, attached to
         nothing, and cost a full-width panel of vertical space. -->
    <div class="flex flex-wrap items-center justify-between gap-3">
      <select v-model="projectContextFilter" class="table-filter-input max-w-[220px]">
        <option value="all">All project categories</option>
        <option value="assigned">Assigned to me</option>
        <option value="open">With open tickets</option>
        <option value="completed">With completed tickets</option>
        <option value="overdue">With overdue tickets</option>
      </select>
      <div class="table-count">
        {{ isModuleResultsView ? `${visibleModuleResults.length} modules` : `${visibleProjects.length} project categories` }}
      </div>
    </div>

    <div v-if="isModuleResultsView && visibleModuleResults.length > 0" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
      <button
        v-for="{ project, module } in visibleModuleResults"
        :key="`${project.id}-${module.id}`"
        @click="openModuleResult(project, module)"
        class="bg-white border border-slate-200 rounded-xl p-4 text-left hover:border-emerald-300 hover:shadow-md transition-all shadow-sm"
      >
        <div class="flex items-start justify-between gap-3">
          <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2 mb-2">
              <span class="inline-flex items-center rounded-full bg-emerald-100 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-emerald-700">
                Module
              </span>
              <span v-if="moduleOverdueCount(project, module)" class="overdue-badge">
                {{ moduleOverdueCount(project, module) }} overdue
              </span>
              <span class="text-[11px] text-slate-500">{{ project.name }}</span>
            </div>
            <div class="text-lg font-bold text-slate-900 leading-tight">{{ module.name }}</div>
            <div class="mt-2 text-sm text-slate-600 leading-relaxed line-clamp-2">
              {{ module.description || 'No module description added.' }}
            </div>
          </div>
          <span class="company-hub-badge" :class="module.status === 'completed' ? 'company-hub-badge-success' : 'company-hub-badge-muted'">
            {{ module.status === 'completed' ? 'Delivered' : 'Active' }}
          </span>
        </div>

        <div class="grid grid-cols-3 gap-2.5 mt-4">
          <div class="rounded-lg bg-slate-50 border border-slate-200 p-3">
            <div class="text-[11px] uppercase tracking-wide text-slate-500 font-semibold mb-1">Open</div>
            <div class="text-xl font-bold text-slate-900">{{ moduleOpenCount(project, module) }}</div>
          </div>
          <div class="rounded-lg bg-slate-50 border border-slate-200 p-3">
            <div class="text-[11px] uppercase tracking-wide text-slate-500 font-semibold mb-1">Done</div>
            <div class="text-xl font-bold text-slate-900">{{ moduleCompletedCount(project, module) }}</div>
          </div>
          <div class="rounded-lg bg-slate-50 border border-slate-200 p-3">
            <div class="text-[11px] uppercase tracking-wide text-slate-500 font-semibold mb-1">Total</div>
            <div class="text-xl font-bold text-slate-900">{{ moduleTickets(project, module).length }}</div>
          </div>
        </div>
      </button>
    </div>

    <div v-else-if="visibleProjects.length > 0" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
      <div
        v-for="p in visibleProjects"
        :key="p.id"
        class="bg-white border border-slate-200 rounded-xl overflow-hidden hover:border-emerald-300 hover:shadow-md transition-all shadow-sm"
      >
        <div class="p-4 space-y-4">
          <button
            @click="router.push({ name: 'project-detail', params: { projectSlug: p.slug } })"
            class="w-full text-left group"
          >
            <div class="flex items-start justify-between gap-3">
              <div class="min-w-0">
                <!-- Module and ticket counts live in the stat tiles below, so
                     they are not repeated here. The "Category" pill was dropped
                     too: every card in this view is a category, so the label
                     never varied. Only the overdue badge stays — it is the one
                     thing here that distinguishes one card from another. -->
                <div v-if="overdueCount(p.id)" class="flex items-center gap-2 mb-2">
                  <span class="overdue-badge">
                    {{ overdueCount(p.id) }} overdue
                  </span>
                </div>
                <div class="text-lg font-bold text-slate-900 leading-tight group-hover:text-emerald-700 transition-colors">{{ p.name }}</div>
                <!-- line-clamp rather than max-h: a pixel crop cut the second
                     line through the middle of the glyphs. -->
                <div v-if="p.description" class="mt-2 text-sm text-slate-600 leading-relaxed line-clamp-2">{{ p.description }}</div>
              </div>
              <div class="project-time-badge shrink-0 rounded-xl bg-slate-50 border border-slate-200 px-3 py-2 text-right min-w-[88px]">
                <div class="project-time-label text-[11px] uppercase tracking-[0.06em] text-slate-500 font-bold">Today</div>
                <div class="project-time-value text-base font-extrabold text-emerald-700">{{ formatDuration(projectSecondsToday(p.id)) }}</div>
              </div>
            </div>
          </button>

          <div class="grid grid-cols-2 gap-2.5">
            <div class="rounded-lg bg-slate-50 border border-slate-200 p-3">
              <div class="text-[11px] uppercase tracking-wide text-slate-500 font-semibold mb-1">Modules</div>
              <div class="text-xl font-bold text-slate-900">{{ moduleCount(p) }}</div>
            </div>
            <div class="rounded-lg bg-slate-50 border border-slate-200 p-3">
              <div class="text-[11px] uppercase tracking-wide text-slate-500 font-semibold mb-1">Open work</div>
              <!-- Open out of total: the meta row used to carry the ticket
                   total separately, which read as a repeat of this number. -->
              <div class="text-xl font-bold text-slate-900">
                {{ openCount(p.id) }}<span class="text-sm font-semibold text-slate-500"> / {{ ticketCount(p.id) }}</span>
              </div>
            </div>
          </div>

          <button
            v-if="canManageProject(p.id)"
            @click="openAddDeveloper(p.id)"
            class="w-full text-sm border border-emerald-200 bg-emerald-50 text-emerald-700 font-medium rounded-lg px-4 py-2 hover:bg-emerald-100 transition-colors"
          >
            Add employee
          </button>
        </div>
      </div>
    </div>

    <!-- Add project modal -->
    <Teleport to="body">
    <div v-if="showAddProject" class="fixed inset-0 z-50 bg-black/40 backdrop-blur-[2px] flex items-center justify-center px-4 py-4 overflow-y-auto" @click.self="showAddProject = false">
      <div class="bg-white rounded-xl p-6 w-full max-w-md shadow-2xl border border-slate-200 max-h-[calc(100vh-2rem)] overflow-y-auto">
        <div class="font-semibold text-[15px] text-slate-900 mb-1">Add project category</div>
        <div class="text-sm text-slate-600 mb-4">Create a parent section like Freelance, In-house, ERP, or Support. Individual projects are added as modules inside it.</div>
        <div class="space-y-3">
          <input v-model="newProjectForm.name" type="text" placeholder="Category name, e.g. Freelance" class="w-full text-[13px] border border-slate-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-1 focus:ring-emerald-500 text-slate-700" />
          <textarea v-model="newProjectForm.description" rows="3" placeholder="What type of work belongs in this section?" class="w-full resize-none text-[13px] border border-slate-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-1 focus:ring-emerald-500 text-slate-700"></textarea>
          <select
            v-if="state.currentUser.role === 'super_admin'"
            v-model="newProjectForm.managerId"
            class="w-full text-[13px] border border-slate-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-1 focus:ring-emerald-500 text-slate-700"
          >
            <option v-for="manager in managers" :key="manager.id" :value="manager.id">Manager: {{ manager.name }}</option>
          </select>
        </div>
        <div v-if="projectError" class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
          {{ projectError }}
        </div>
        <div class="flex gap-2 mt-4">
          <button @click="showAddProject = false" class="flex-1 text-sm border border-slate-200 rounded-lg py-2 hover:bg-slate-50 transition-colors text-slate-700 font-medium">
            Cancel
          </button>
          <button
            @click="addProject"
            :disabled="!newProjectForm.name.trim() || !newProjectForm.managerId"
            class="flex-1 text-sm bg-emerald-600 text-white rounded-lg py-2 hover:bg-emerald-700 disabled:bg-slate-300 disabled:cursor-not-allowed transition-colors font-medium"
          >
            Create category
          </button>
        </div>
      </div>
    </div>
    </Teleport>

    <!-- Add employee modal -->
    <Teleport to="body">
    <div v-if="showAddDev" class="fixed inset-0 z-50 bg-black/40 backdrop-blur-[2px] flex items-center justify-center px-4 py-4 overflow-y-auto" @click.self="showAddDev = false">
      <div class="bg-white rounded-xl p-6 w-full max-w-md shadow-2xl border border-slate-200 max-h-[calc(100vh-2rem)] overflow-y-auto">
        <div class="font-semibold text-[15px] text-slate-900 mb-1">Add employees to project</div>
        <div class="text-sm text-slate-600 mb-4">Select one or more employees to add to the team.</div>

        <input
          v-model="developerSearch"
          type="search"
          placeholder="Search by name, designation, or role"
          class="w-full mb-3 rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500"
        />

        <div class="space-y-2 max-h-72 overflow-y-auto mb-4">
          <button
            v-for="dev in filteredDevelopers"
            :key="dev.id"
            @click="toggleDeveloper(dev.id)"
            :class="{
              'ring-2 ring-emerald-500 border-emerald-500': selectedDevelopers.includes(dev.id),
              'border-slate-200 hover:border-slate-300': !selectedDevelopers.includes(dev.id)
            }"
            class="w-full flex items-center gap-3 p-3 rounded-lg border transition-all text-left"
          >
            <div class="w-8 h-8 rounded-full bg-emerald-100 text-emerald-700 flex items-center justify-center text-xs font-semibold flex-shrink-0">
              {{ dev.name.split(' ').map(n => n[0]).join('') }}
            </div>
            <div class="min-w-0 flex-1">
              <div class="text-sm font-medium text-slate-900 truncate">{{ dev.name }}</div>
              <div class="text-xs text-slate-600 truncate">
                {{ dev.department }}
                <!-- The pool is no longer employees-only, so flag anyone who
                     carries a wider role — a lead and an employee otherwise
                     look identical here. -->
                <span v-if="dev.role !== 'employee'" class="font-semibold text-slate-500">· {{ roleLabel(dev.role) }}</span>
              </div>
            </div>
            <!-- Explicit tick: a ring alone does not read as "multiple allowed". -->
            <span
              class="w-5 h-5 rounded-md border flex items-center justify-center flex-shrink-0 transition-colors"
              :class="selectedDevelopers.includes(dev.id) ? 'bg-emerald-600 border-emerald-600 text-white' : 'border-slate-300'"
            >
              <svg v-if="selectedDevelopers.includes(dev.id)" viewBox="0 0 20 20" fill="currentColor" class="w-3.5 h-3.5">
                <path fill-rule="evenodd" d="M16.7 5.3a1 1 0 0 1 0 1.4l-7.5 7.5a1 1 0 0 1-1.4 0L3.3 9.7a1 1 0 1 1 1.4-1.4l3.8 3.8 6.8-6.8a1 1 0 0 1 1.4 0Z" clip-rule="evenodd" />
              </svg>
            </span>
          </button>

          <div v-if="!filteredDevelopers.length" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-6 text-center text-sm text-slate-600">
            {{ developerSearch.trim() ? 'No employees match that search.' : 'Every available employee is already on this section.' }}
          </div>
        </div>

        <div v-if="developerError" class="mb-3 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
          {{ developerError }}
        </div>
        <div class="flex gap-2">
          <button @click="showAddDev = false" class="flex-1 text-sm border border-slate-200 rounded-lg py-2 hover:bg-slate-50 transition-colors text-slate-700 font-medium">
            Cancel
          </button>
          <button
            @click="addDeveloperToProject"
            :disabled="!selectedDevelopers.length"
            class="flex-1 text-sm bg-emerald-600 text-white rounded-lg py-2 hover:bg-emerald-700 disabled:bg-slate-300 disabled:cursor-not-allowed transition-colors font-medium"
          >
            Add to team<span v-if="selectedDevelopers.length"> ({{ selectedDevelopers.length }})</span>
          </button>
        </div>
      </div>
    </div>
    </Teleport>

  </div>
  <div v-else class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
    <div class="text-lg font-semibold text-slate-900">Projects are not available for HR</div>
    <div class="text-sm text-slate-600 mt-1">HR can manage attendance and employee requests from the dashboard and attendance pages.</div>
  </div>
</template>
