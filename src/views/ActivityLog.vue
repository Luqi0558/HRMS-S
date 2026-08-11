<script setup>
import { computed, ref, onMounted } from 'vue'
import { projectService } from '../services/projectService.js'
import { ticketService } from '../services/ticketService.js'
import { activityLogService } from '../services/activityLogService.js'
import { useRouter } from 'vue-router'
import { useStore } from '../store/index.js'
import { useTableControls } from '../composables/useTableControls.js'

const { state, can, formatDateTime,
  setProjects,
  setTickets,
  setActivityLogs
} = useStore()

const loadError = ref('')
// Only the modules that actually appear in the trail, so the filter cannot
// offer an option matching nothing.
const availableModules = ref([])
const router = useRouter()

const canAccess = computed(() => can('Activity Log', 'view'))
const moduleFilter = ref('all')
const actorFilter = ref('all')

const activityRows = computed(() => {
  const rows = state.activityLogs.filter(log => {
    const matchesModule = moduleFilter.value === 'all' || log.module === moduleFilter.value
    const matchesActor = actorFilter.value === 'all' || log.actorRole === actorFilter.value
    return matchesModule && matchesActor
  })
  return rows.sort((a, b) => new Date(b.createdAt).getTime() - new Date(a.createdAt).getTime())
})

const modules = computed(() => Array.from(new Set(state.activityLogs.map(log => log.module))).sort())
const actors = computed(() => Array.from(new Set(state.activityLogs.map(log => log.actorRole))).sort())
const activityTable = useTableControls(
  activityRows,
  log => `${log.actorName} ${log.actorRole} ${log.module} ${log.action} ${log.details}`,
  10
)

function formatTime(value) {
  return formatDateTime(value)
}

function targetRoute(log) {
  if (log.targetType === 'ticket') {
    const ticket = state.tickets.find(item => item.id === log.targetId)
    const project = ticket ? state.projects.find(item => item.id === ticket.projectId) : null
    const module = project?.modules?.find(item => item.id === ticket.moduleId)
    if (project && module) return { name: 'project-module-detail', params: { projectSlug: project.slug, moduleSlug: module.slug } }
    if (project) return { name: 'project-detail', params: { projectSlug: project.slug } }
  }
  if (log.targetType === 'module' && log.targetId) {
    const project = state.projects.find(item => (item.modules || []).some(module => module.id === log.targetId))
    const module = project?.modules?.find(item => item.id === log.targetId)
    if (project && module) return { name: 'project-module-detail', params: { projectSlug: project.slug, moduleSlug: module.slug } }
  }
  if (log.targetType === 'project' && log.targetId) {
    const project = state.projects.find(item => item.id === log.targetId)
    if (project) return { name: 'project-detail', params: { projectSlug: project.slug } }
  }
  if (log.module === 'leave') return { name: 'leave-requests' }
  if (log.module === 'medical') return { name: 'medical-claims' }
  if (log.module === 'salary') return { name: 'payroll' }
  if (log.module === 'employee') return { name: 'employees' }
  if (log.module === 'attendance') return { name: 'attendance' }
  if (log.module === 'finance') return { name: 'finance' }
  if (log.module === 'assets') return { name: 'assets' }
  if (log.module === 'messages') return { name: 'messages' }
  if (log.module === 'announcements' || log.module === 'policies') return { name: 'company-hub' }
  if (log.module === 'settings') return { name: 'settings' }
  return null
}

function openTarget(log) {
  const route = targetRoute(log)
  if (route) router.push(route)
}

function exportActivityCsv() {
  const rows = activityTable.filteredRows.value
  if (!rows.length) return
  const headers = ['createdAt', 'actorName', 'actorRole', 'module', 'action', 'details']
  const csv = [
    headers.join(','),
    ...rows.map(row => headers.map(header => `"${String(row[header] ?? '').replaceAll('"', '""')}"`).join(','))
  ].join('\n')
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = 'activity-log.csv'
  link.click()
  URL.revokeObjectURL(url)
}
/**
 * The log resolves project and ticket names to build its links, so it has
 * to load them rather than hoping another page already did.
 *
 * Non-fatal: roles reaching this page hold different permissions, and one 403
 * should not blank the whole view.
 */
onMounted(async () => {
  const [logs, projects, tickets] = await Promise.allSettled([
    activityLogService.list({ per_page: 200 }),
    projectService.list(),
    ticketService.list()
  ])

  if (logs.status === 'fulfilled') {
    setActivityLogs(logs.value.logs)
    availableModules.value = logs.value.modules
  } else {
    loadError.value = logs.reason?.message || 'Unable to load the activity log.'
  }

  // Projects and tickets resolve the links back to each logged record; a 403
  // on either leaves the entries readable, just without links.
  if (projects.status === 'fulfilled') setProjects(projects.value)
  if (tickets.status === 'fulfilled') setTickets(tickets.value.data)
})
</script>

<template>
  <div v-if="canAccess" class="space-y-6">
    <div class="flex flex-col lg:flex-row lg:items-end justify-between gap-4">
      <div>
        <div class="text-sm text-slate-600 mt-1">Audit trail of important actions across requests, tickets, finance, and settings.</div>
      </div>
      <button @click="exportActivityCsv" class="bg-slate-950 text-white text-sm font-medium rounded-lg px-4 py-2 hover:bg-black transition-colors">
        Export audit CSV
      </button>
    </div>

    <section class="grid grid-cols-1 md:grid-cols-3 gap-4">
      <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
        <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Total events</div>
        <div class="text-2xl font-extrabold text-slate-900 mt-2">{{ activityRows.length }}</div>
      </div>
      <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
        <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Modules</div>
        <div class="text-2xl font-extrabold text-slate-900 mt-2">{{ modules.length }}</div>
      </div>
      <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
        <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Recent actor</div>
        <div class="text-lg font-extrabold text-slate-900 mt-2">{{ activityRows[0]?.actorName || '-' }}</div>
      </div>
    </section>

    <section class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm">
      <div class="px-4 py-3 border-b border-slate-200">
        <div class="text-sm font-bold text-slate-900">Audit trail</div>
        <div class="text-xs text-slate-600 mt-1">Use filters to inspect who changed what and when.</div>
      </div>
      <div class="table-tools">
        <input v-model="activityTable.query.value" type="search" placeholder="Filter activity..." class="table-filter-input" />
        <select v-model="moduleFilter" class="table-filter-input max-w-[180px]">
          <option value="all">All modules</option>
          <option v-for="module in modules" :key="module" :value="module">{{ module }}</option>
        </select>
        <select v-model="actorFilter" class="table-filter-input max-w-[180px]">
          <option value="all">All actors</option>
          <option v-for="actor in actors" :key="actor" :value="actor">{{ actor }}</option>
        </select>
        <div class="table-count">{{ activityTable.filteredRows.value.length }} records</div>
      </div>
      <div class="table-scroll p-4" @scroll="activityTable.loadMoreRows">
        <div v-if="activityTable.filteredRows.value.length" class="relative space-y-3">
          <div class="absolute left-5 top-3 bottom-3 w-px bg-slate-200"></div>
          <article
            v-for="log in activityTable.visibleRows.value"
            :key="log.id"
            class="relative grid grid-cols-[42px_minmax(0,1fr)] gap-3"
          >
            <div class="relative z-10 h-10 w-10 rounded-full border border-slate-200 bg-white flex items-center justify-center text-xs font-extrabold text-slate-900 shadow-sm">
              {{ log.module.slice(0, 2).toUpperCase() }}
            </div>
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 hover:bg-white transition-colors">
              <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-3">
                <div class="min-w-0">
                  <div class="flex flex-wrap items-center gap-2">
                    <span class="company-hub-badge company-hub-badge-muted capitalize">{{ log.module }}</span>
                    <span class="company-hub-badge company-hub-badge-info capitalize">{{ log.actorRole }}</span>
                    <span class="text-[11px] font-bold text-slate-500">{{ formatTime(log.createdAt) }}</span>
                  </div>
                  <div class="text-sm font-extrabold text-slate-900 mt-3 capitalize">{{ log.action }}</div>
                  <div class="text-sm text-slate-600 mt-1">{{ log.details }}</div>
                  <div class="text-xs font-bold text-slate-900 mt-3">{{ log.actorName }}</div>
                </div>
                <button
                  v-if="targetRoute(log)"
                  @click="openTarget(log)"
                  class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-bold text-slate-700 hover:bg-white transition-colors"
                >
                  Open related page
                </button>
              </div>
            </div>
          </article>
        </div>
        <div v-else class="app-empty-state">
          <div class="app-empty-title">No activity found</div>
          <div class="app-empty-copy">Try changing the module, actor, or search filters.</div>
        </div>
      </div>
    </section>
  </div>

  <div v-else class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
    <div class="text-lg font-semibold text-slate-900">Activity log is not available for this role</div>
  </div>
</template>
