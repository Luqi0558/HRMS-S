<script setup>
import { computed, ref, watch } from 'vue'
import TicketTimer from './TicketTimer.vue'
import { useStore } from '../store/index.js'
import { statusMeta } from '../data/mock.js'

// Ticket detail modal, shared by the Employee and Team Lead dashboards.
// Owns its own draft state; the parent only passes the ticket to open.
const props = defineProps({
  ticket: { type: Object, default: null }
})
const emit = defineEmits(['close'])

const {
  state,
  can,
  ticketSeconds,
  projectSecondsToday,
  formatDuration,
  formatDateTime,
  formatDate: formatCompanyDate,
  pushNotification,
  logActivity
} = useStore()

const dashboardTicket = computed(() => props.ticket)
const dashboardTicketDraft = ref(null)
const dashboardNewComment = ref('')
const dashboardNewAttachmentName = ref('')

const isEmployee = computed(() => state.currentUser.role === 'employee')

function employeeName(id) {
  return state.employees.find(employee => employee.id === id)?.name ?? '—'
}

// Rebuild the draft whenever a different ticket is opened.
watch(
  () => props.ticket,
  ticket => {
    if (!ticket) {
      dashboardTicketDraft.value = null
      return
    }
    dashboardTicketDraft.value = {
      title: ticket.title,
      description: ticket.description || '',
      status: ticket.status,
      priority: ticket.priority,
      assigneeId: ticket.assigneeId,
      assignedById: ticket.assignedById || null,
      deadline: ticket.deadline || ''
    }
    dashboardNewComment.value = ''
    dashboardNewAttachmentName.value = ''
  },
  { immediate: true }
)

function closeDashboardTicket() {
  emit('close')
}

function ticketProject(ticket) {
  return state.projects.find(project => project.id === ticket?.projectId)
}

function ticketModule(ticket) {
  const project = ticketProject(ticket)
  return project?.modules?.find(module => module.id === ticket?.moduleId) || project?.modules?.[0] || null
}

function ticketContextLabel(ticket) {
  const project = ticketProject(ticket)
  const module = ticketModule(ticket)
  return [project?.name, module?.name].filter(Boolean).join(' / ') || 'Project ticket'
}

function ticketLoggedTime(ticket) {
  return formatDuration(ticketSeconds(ticket))
}

const dashboardCanEditTicketFields = computed(() => can('Tickets', 'create'))
const dashboardCanUpdateTicketStatus = computed(() => can('Tickets', 'create') && isEmployee.value)
const dashboardCanUseTicketTimer = computed(() => can('Tickets', 'create') && isEmployee.value)

function dashboardModuleAssignedEmployees(ticket = dashboardTicket.value) {
  const module = ticketModule(ticket)
  const ids = module?.teamMembers || []
  return ids.map(id => state.employees.find(employee => employee.id === id)).filter(Boolean)
}

function dashboardAssignedByOptions(ticket = dashboardTicket.value) {
  const ids = new Set()
  const project = ticketProject(ticket)
  if (project?.managerId) ids.add(project.managerId)
  state.employees
    .filter(employee => employee.role === 'manager' || employee.role === 'super_admin')
    .forEach(employee => ids.add(employee.id))
  return Array.from(ids)
    .map(id => state.employees.find(employee => employee.id === id))
    .filter(Boolean)
}

function dashboardTicketComments(ticket) {
  return [...(ticket?.comments || [])].sort((a, b) => new Date(b.createdAt) - new Date(a.createdAt))
}

function dashboardCommentAuthorName(id) {
  return state.employees.find(employee => employee.id === id)?.name || state.currentUser.name
}

function dashboardTicketActivity(ticket) {
  const logs = (state.activityLogs || [])
    .filter(log => log.targetType === 'ticket' && log.targetId === ticket?.id)
    .map(log => ({
      id: `log-${log.id}`,
      title: log.action,
      detail: log.details,
      at: log.createdAt
    }))
  const timeLogs = (ticket?.timeLogs || []).map((log, index) => ({
    id: `time-${ticket.id}-${index}`,
    title: log.end ? 'Time logged' : 'Timer started',
    detail: log.end ? `Work session completed by ${employeeName(log.userId)}.` : `Timer is running for ${employeeName(log.userId)}.`,
    at: log.end || log.start
  }))
  return [...logs, ...timeLogs].sort((a, b) => new Date(b.at) - new Date(a.at))
}

function saveDashboardTicketChanges() {
  if (!dashboardCanEditTicketFields.value || !dashboardTicket.value || !dashboardTicketDraft.value) return
  const changes = {
    title: dashboardTicketDraft.value.title.trim() || dashboardTicket.value.title,
    description: dashboardTicketDraft.value.description.trim(),
    priority: dashboardTicketDraft.value.priority,
    assigneeId: dashboardTicketDraft.value.assigneeId,
    assignedById: dashboardTicketDraft.value.assignedById,
    deadline: dashboardTicketDraft.value.deadline || null
  }
  if (dashboardCanUpdateTicketStatus.value) changes.status = dashboardTicketDraft.value.status
  Object.assign(dashboardTicket.value, changes)
  pushNotification({
    title: 'Ticket updated',
    message: `${state.currentUser.name} updated "${dashboardTicket.value.title}".`,
    type: 'ticket',
    userIds: [...new Set([dashboardTicket.value.assigneeId, dashboardTicket.value.assignedById, ticketProject(dashboardTicket.value)?.managerId].filter(id => id && id !== state.currentUser.id))],
    route: moduleKanbanRouteForTicket(dashboardTicket.value, { ticket: dashboardTicket.value.id })
  })
  logActivity({
    module: 'ticket',
    action: 'ticket updated',
    details: `${state.currentUser.name} updated "${dashboardTicket.value.title}" from the dashboard.`,
    targetId: dashboardTicket.value.id,
    targetType: 'ticket'
  })
  closeDashboardTicket()
}

function addDashboardTicketComment() {
  if (!dashboardTicket.value || !dashboardNewComment.value.trim()) return
  if (!dashboardTicket.value.comments) dashboardTicket.value.comments = []
  dashboardTicket.value.comments.unshift({
    id: Date.now(),
    authorId: state.currentUser.id,
    text: dashboardNewComment.value.trim(),
    createdAt: new Date().toISOString()
  })
  pushNotification({
    title: 'New ticket comment',
    message: `${state.currentUser.name} commented on "${dashboardTicket.value.title}".`,
    type: 'ticket',
    userIds: [...new Set([dashboardTicket.value.assigneeId, dashboardTicket.value.assignedById, ticketProject(dashboardTicket.value)?.managerId].filter(id => id && id !== state.currentUser.id))],
    route: moduleKanbanRouteForTicket(dashboardTicket.value, { ticket: dashboardTicket.value.id })
  })
  logActivity({
    module: 'ticket',
    action: 'ticket comment added',
    details: `${state.currentUser.name} commented on "${dashboardTicket.value.title}" from the dashboard.`,
    targetId: dashboardTicket.value.id,
    targetType: 'ticket'
  })
  dashboardNewComment.value = ''
}

function addDashboardTicketAttachment() {
  if (!dashboardTicket.value || !dashboardNewAttachmentName.value.trim()) return
  if (!dashboardTicket.value.attachments) dashboardTicket.value.attachments = []
  const attachmentName = dashboardNewAttachmentName.value.trim()
  dashboardTicket.value.attachments.unshift({
    id: Date.now(),
    name: attachmentName,
    uploadedBy: state.currentUser.name,
    uploadedAt: new Date().toISOString()
  })
  logActivity({
    module: 'ticket',
    action: 'ticket attachment added',
    details: `${state.currentUser.name} attached ${attachmentName} to "${dashboardTicket.value.title}" from the dashboard.`,
    targetId: dashboardTicket.value.id,
    targetType: 'ticket'
  })
  dashboardNewAttachmentName.value = ''
}

</script>

<template>
  <Teleport to="body">
    <div v-if="dashboardTicket && dashboardTicketDraft" class="fixed inset-0 z-50 bg-black/40 backdrop-blur-[2px] flex items-center justify-center px-4 py-4 overflow-y-auto" @click.self="closeDashboardTicket">
      <div class="w-full max-w-2xl rounded-2xl bg-white border border-slate-200 shadow-2xl overflow-hidden max-h-[calc(100vh-2rem)] flex flex-col">
        <div class="flex items-start justify-between gap-4 p-5 border-b border-slate-200 shrink-0">
          <div class="min-w-0">
            <div class="text-[11px] uppercase tracking-[0.18em] text-slate-500 font-semibold">Ticket Details</div>
            <div class="text-2xl font-bold text-slate-900 mt-1 leading-tight">{{ dashboardTicket.title }}</div>
            <div class="text-sm text-slate-600 mt-1">{{ ticketContextLabel(dashboardTicket) }}</div>
          </div>
          <button @click="closeDashboardTicket" class="text-slate-400 hover:text-slate-900 text-xl leading-none">x</button>
        </div>

        <div class="modal-scroll-hidden flex-1 overflow-y-auto p-5">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 space-y-3">
              <div>
                <label class="block">
                <span class="text-[11px] uppercase tracking-[0.16em] text-slate-500 font-semibold block mb-2">Title</span>
                <input v-model="dashboardTicketDraft.title" type="text" :disabled="!dashboardCanEditTicketFields" class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 focus:outline-none focus:ring-1 focus:ring-emerald-500 disabled:bg-slate-100 disabled:text-slate-500" />
                </label>
              </div>
              <div>
                <label class="block">
                <span class="text-[11px] uppercase tracking-[0.16em] text-slate-500 font-semibold block mb-2">Description</span>
                <textarea v-model="dashboardTicketDraft.description" rows="3" :disabled="!dashboardCanEditTicketFields" class="w-full resize-none rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 focus:outline-none focus:ring-1 focus:ring-emerald-500 disabled:bg-slate-100 disabled:text-slate-500" placeholder="Add ticket details, blockers, or acceptance notes..."></textarea>
                </label>
              </div>
              <div class="flex items-center justify-between gap-3">
                <span class="text-[11px] uppercase tracking-[0.16em] text-slate-500 font-semibold">Status</span>
                <select v-model="dashboardTicketDraft.status" :disabled="!dashboardCanUpdateTicketStatus" class="text-[11px] font-medium capitalize px-2 py-1 rounded-full border border-slate-200 bg-white text-slate-700 focus:outline-none focus:ring-1 focus:ring-emerald-500 disabled:bg-slate-100 disabled:text-slate-500">
                  <option value="todo">To do</option>
                  <option value="in_progress">In progress</option>
                  <option value="review">Review</option>
                  <option value="done">Done</option>
                </select>
              </div>
              <div class="flex items-center justify-between gap-3">
                <span class="text-sm text-slate-600">Priority</span>
                <select v-model="dashboardTicketDraft.priority" :disabled="!dashboardCanEditTicketFields" class="text-sm font-medium capitalize rounded-lg border border-slate-200 bg-white px-2 py-1 focus:outline-none focus:ring-1 focus:ring-emerald-500 disabled:bg-slate-100 disabled:text-slate-500">
                  <option value="low">low</option>
                  <option value="medium">medium</option>
                  <option value="high">high</option>
                </select>
              </div>
              <div class="flex items-center justify-between gap-3">
                <span class="text-sm text-slate-600">Assignee</span>
                <select v-model="dashboardTicketDraft.assigneeId" :disabled="!dashboardCanEditTicketFields" class="text-sm font-medium rounded-lg border border-slate-200 bg-white px-2 py-1 focus:outline-none focus:ring-1 focus:ring-emerald-500 max-w-[180px] disabled:bg-slate-100 disabled:text-slate-500">
                  <option v-for="emp in dashboardModuleAssignedEmployees(dashboardTicket)" :key="emp.id" :value="emp.id">{{ emp.name }}</option>
                </select>
              </div>
              <div class="flex items-center justify-between gap-3">
                <span class="text-sm text-slate-600">Assigned by</span>
                <select v-model="dashboardTicketDraft.assignedById" :disabled="!dashboardCanEditTicketFields" class="text-sm font-medium rounded-lg border border-slate-200 bg-white px-2 py-1 focus:outline-none focus:ring-1 focus:ring-emerald-500 max-w-[180px] disabled:bg-slate-100 disabled:text-slate-500">
                  <option :value="null">Not specified</option>
                  <option v-for="emp in dashboardAssignedByOptions(dashboardTicket)" :key="emp.id" :value="emp.id">{{ emp.name }}</option>
                </select>
              </div>
              <div class="flex items-center justify-between gap-3">
                <span class="text-sm text-slate-600">Due date</span>
                <input v-model="dashboardTicketDraft.deadline" type="date" :disabled="!dashboardCanEditTicketFields" class="text-sm font-medium rounded-lg border border-slate-200 bg-white px-2 py-1 focus:outline-none focus:ring-1 focus:ring-emerald-500 disabled:bg-slate-100 disabled:text-slate-500" />
              </div>
            </div>

            <div class="rounded-xl border border-slate-200 bg-white p-4 space-y-3">
              <div class="text-[11px] uppercase tracking-[0.16em] text-slate-500 font-semibold">Time</div>
              <div class="flex items-center justify-between gap-3">
                <span class="text-sm text-slate-600">Total logged</span>
                <span class="text-lg font-mono font-semibold text-emerald-600">{{ ticketLoggedTime(dashboardTicket) }}</span>
              </div>
              <div class="flex items-center justify-between gap-3">
                <span class="text-sm text-slate-600">Current status column</span>
                <span class="text-sm font-medium text-slate-900 capitalize">{{ statusMeta[dashboardTicket.status].label }}</span>
              </div>
              <div class="flex items-center justify-between gap-3">
                <span class="text-sm text-slate-600">Project hours today</span>
                <span class="text-sm font-mono font-medium text-slate-900">{{ formatDuration(projectSecondsToday(dashboardTicket.projectId)) }}</span>
              </div>
              <div class="pt-2 border-t border-slate-200">
                <div class="text-[11px] uppercase tracking-[0.16em] text-slate-500 font-semibold mb-2">Timer</div>
                <div class="flex items-center justify-between gap-3">
                  <div class="flex items-center gap-2 text-sm">
                    <span class="w-2 h-2 rounded-full" :class="state.activeTimer?.ticketId === dashboardTicket.id ? 'bg-emerald-500 animate-pulse' : 'bg-slate-300'"></span>
                    <span class="text-slate-700">{{ state.activeTimer?.ticketId === dashboardTicket.id ? 'Timer running' : 'Timer stopped' }}</span>
                  </div>
                  <TicketTimer :ticket="dashboardTicket" :readonly="!dashboardCanUseTicketTimer" />
                </div>
              </div>
            </div>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
            <div class="rounded-xl border border-slate-200 bg-white p-4">
              <div class="flex items-center justify-between gap-3 mb-3">
                <div>
                  <div class="text-[11px] uppercase tracking-[0.16em] text-slate-500 font-semibold">Attachments</div>
                  <div class="text-sm text-slate-600 mt-1">Files linked with this ticket.</div>
                </div>
                <div class="text-xs text-slate-500">{{ (dashboardTicket.attachments || []).length }} files</div>
              </div>
              <div class="space-y-2 max-h-32 overflow-y-auto modal-scroll-hidden">
                <div v-if="!(dashboardTicket.attachments || []).length" class="text-sm text-slate-500 italic">No attachments yet.</div>
                <div v-for="attachment in dashboardTicket.attachments || []" :key="attachment.id" class="flex items-center justify-between gap-3 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                  <div class="min-w-0">
                    <div class="truncate text-sm font-semibold text-slate-900">{{ attachment.name }}</div>
                    <div class="text-[11px] text-slate-500">{{ attachment.uploadedBy }} - {{ formatCompanyDate(attachment.uploadedAt) }}</div>
                  </div>
                  <span class="text-[11px] uppercase tracking-[0.14em] text-slate-500 font-semibold">File</span>
                </div>
              </div>
              <div class="flex gap-2 mt-3">
                <input v-model="dashboardNewAttachmentName" type="text" class="min-w-0 flex-1 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 focus:outline-none focus:ring-1 focus:ring-emerald-500" placeholder="Attachment name or reference" />
                <button @click="addDashboardTicketAttachment" class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 transition-colors">Add</button>
              </div>
            </div>

            <div class="rounded-xl border border-slate-200 bg-white p-4">
              <div class="flex items-center justify-between gap-3 mb-3">
                <div>
                  <div class="text-[11px] uppercase tracking-[0.16em] text-slate-500 font-semibold">Activity Timeline</div>
                  <div class="text-sm text-slate-600 mt-1">Audit trail for work and updates.</div>
                </div>
                <div class="text-xs text-slate-500">{{ dashboardTicketActivity(dashboardTicket).length }} events</div>
              </div>
              <div class="space-y-2 max-h-44 overflow-y-auto modal-scroll-hidden">
                <div v-if="dashboardTicketActivity(dashboardTicket).length === 0" class="text-sm text-slate-500 italic">No activity recorded yet.</div>
                <div v-for="event in dashboardTicketActivity(dashboardTicket)" :key="event.id" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                  <div class="flex items-center justify-between gap-3">
                    <div class="text-sm font-semibold text-slate-900">{{ event.title }}</div>
                    <div class="text-[11px] text-slate-500">{{ formatDateTime(event.at) }}</div>
                  </div>
                  <div class="mt-1 text-sm text-slate-600 leading-relaxed">{{ event.detail }}</div>
                </div>
              </div>
            </div>
          </div>

          <div class="mt-5 px-0 pb-1 pt-0">
            <div class="flex items-center justify-between gap-3 py-4 border-t border-slate-200">
              <div>
                <div class="text-[11px] uppercase tracking-[0.16em] text-slate-500 font-semibold">Comments & Updates</div>
                <div class="text-sm text-slate-600 mt-1">Add an update like you would on the kanban board.</div>
              </div>
              <div class="text-xs text-slate-500">{{ dashboardTicketComments(dashboardTicket).length }} comments</div>
            </div>

            <div class="space-y-3 max-h-56 overflow-y-auto pr-1">
              <div v-if="dashboardTicketComments(dashboardTicket).length === 0" class="text-sm text-slate-500 italic">No comments yet. Add the first update below.</div>
              <div v-for="comment in dashboardTicketComments(dashboardTicket)" :key="comment.id" class="rounded-xl border border-slate-200 bg-white p-3">
                <div class="flex items-center justify-between gap-3 mb-2">
                  <div class="text-sm font-semibold text-slate-900">{{ dashboardCommentAuthorName(comment.authorId) }}</div>
                  <div class="text-[11px] text-slate-500">{{ formatDateTime(comment.createdAt) }}</div>
                </div>
                <div class="text-sm text-slate-700 leading-relaxed">{{ comment.text }}</div>
              </div>
            </div>

            <div class="mt-4 rounded-xl border border-slate-200 bg-white p-3">
              <label class="block">
              <span class="text-[11px] uppercase tracking-[0.16em] text-slate-500 font-semibold block mb-2">Add comment</span>
              <textarea v-model="dashboardNewComment" rows="3" class="w-full resize-none rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-700 focus:outline-none focus:ring-1 focus:ring-emerald-500" placeholder="Write an update, mention blockers, or note next steps..."></textarea>
              </label>
              <div class="flex justify-end mt-3">
                <button @click="addDashboardTicketComment" class="bg-emerald-600 text-white text-sm font-medium rounded-lg px-4 py-2 hover:bg-emerald-700 transition-colors">
                  Post comment
                </button>
              </div>
            </div>

            <div class="flex items-center justify-end gap-2 mt-4 pt-4 border-t border-slate-200">
              <button @click="closeDashboardTicket" class="text-sm border border-slate-200 rounded-lg px-4 py-2 hover:bg-slate-50 transition-colors text-slate-700 font-medium">Cancel</button>
              <button v-if="dashboardCanEditTicketFields" @click="saveDashboardTicketChanges" class="bg-emerald-600 text-white text-sm font-medium rounded-lg px-4 py-2 hover:bg-emerald-700 transition-colors">Save changes</button>
            </div>
          </div>
        </div>
      </div>
    </div>

  </Teleport>
</template>
