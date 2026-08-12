<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useStore } from '../store/index.js'
// A static display map (labels and colour classes), not data — this stays.
import { statusMeta } from '../config/ticketStatus.js'
import TicketTimer from '../components/TicketTimer.vue'
import { projectService } from '../services/projectService.js'
import { ticketService } from '../services/ticketService.js'
import { employeeService } from '../services/employeeService.js'
import { ApiError } from '../services/apiClient.js'

const route = useRoute()
const router = useRouter()
const {
  state,
  ticketSeconds,
  formatDuration,
  formatCurrency,
  currencyCode,
  formatDate,
  formatDateTime,
  projectSecondsToday,
  can,
  canSignOffTickets,
  pushNotification,
  logActivity,
  setProjects,
  setTickets,
  setEmployees,
  upsertProject,
  upsertTicket
} = useStore()

const isLoading = ref(true)
const loadError = ref('')
const isSavingDelivery = ref(false)

const projectSlug = computed(() => String(route.params.projectSlug || ''))

/**
 * The project (with its modules and members) plus every ticket in it.
 *
 * Tickets are fetched for the whole project rather than per module: the kanban
 * board switches modules without a route change, and refetching on each switch
 * would flash an empty board between clicks.
 */
async function loadData() {
  if (!projectSlug.value) return

  // Only blank the page when there is nothing to show. Clicking through from
  // the projects list, the store already holds this project, and swapping it
  // for a spinner to re-fetch the same record looks like a step backwards.
  isLoading.value = !state.projects.some(item => item.slug === projectSlug.value)
  loadError.value = ''

  try {
    // The roster does not depend on the project, so it rides along with it
    // rather than waiting for the tickets to come back first. Only the tickets
    // genuinely have to wait, since the endpoint filters by project id and the
    // id is not known until the project has been fetched.
    //
    // Non-fatal on its own: an employee holds no Employees permission, and the
    // board still renders without the roster — only assignee pickers are
    // affected, which fall back to module members.
    const [project, employees] = await Promise.allSettled([
      projectService.find(projectSlug.value),
      employeeService.list()
    ])

    if (employees.status === 'fulfilled') {
      setEmployees(employees.value.data)
    }

    if (project.status === 'rejected') {
      throw project.reason
    }

    // Replaces the whole collection: this view is reachable directly by URL,
    // so it cannot assume Projects.vue has already populated the store.
    setProjects([project.value])

    const tickets = await ticketService.list({ project_id: project.value.id })
    setTickets(tickets.data)
  } catch (error) {
    loadError.value = error instanceof ApiError
      ? error.message
      : 'Could not load this project. Please check your connection and try again.'
  } finally {
    isLoading.value = false
  }
}

onMounted(loadData)

// The slug changes when navigating between project categories without
// unmounting the view, so the data has to follow it.
watch(projectSlug, (next, previous) => {
  if (next && next !== previous) loadData()
})
const routeModuleSlug = computed(() => route.params.moduleSlug ? String(route.params.moduleSlug) : '')
const project = computed(() => state.projects.find(p => p.slug === projectSlug.value))
const projectId = computed(() => project.value?.id ?? null)
// HR holds no Projects row, so it cannot view this module at all.
const isHR = computed(() => !can('Projects', 'view'))
// Timers and ticket status belong to the person doing the work: the 'Tickets'
// module scoped to 'team' is exactly the employee role today.
const isEmployee = computed(() => state.currentUser.role === 'employee')
const canEditTicketFields = computed(() => can('Tickets', 'create'))
// Permission-based, not role-based. These were `&& isEmployee`, which left QA
// and interns unable to move their own work or run a timer — both work the
// board, and the API gates on permission rather than on which role you are.
const canUpdateTicketStatus = computed(() => can('Tickets', 'create'))
const canUseTicketTimer = computed(() => can('Tickets', 'create'))

// 'done' is a sign-off, so the board only offers it to someone the API would
// accept it from — otherwise the move comes straight back as a 403.
const canSignOff = computed(() => canSignOffTickets())
const availableStatuses = computed(() =>
  canSignOff.value ? columns : columns.filter(status => status !== 'done')
)
const canManageProjectMembers = computed(() => can('Projects', 'approve', project.value))
const canViewModuleFinance = computed(() => can('Finance', 'finance'))
const assignableEmployees = computed(() => state.employees.filter(e => e.role === 'employee'))
const availableProjectEmployees = computed(() => {
  const teamIds = project.value?.teamMembers || []
  return assignableEmployees.value.filter(employee => !teamIds.includes(employee.id))
})

const columns = ['todo', 'in_progress', 'review', 'done']
const draggingTicketId = ref(null)
const activeDropColumn = ref(null)
// Held as an id, not as the object. upsertTicket() replaces the entry in
// state.tickets rather than mutating it, so a stored reference goes stale the
// moment anything writes to the ticket — which is why a posted comment only
// appeared after a page refresh. Resolving through the store on every read
// means the modal always shows current data, whatever caused the change.
const selectedTicketId = ref(null)
const selectedTicket = computed(() =>
  selectedTicketId.value == null
    ? null
    : state.tickets.find(ticket => ticket.id === selectedTicketId.value) ?? null
)
const ticketDraft = ref(null)
const newComment = ref('')
const newAttachmentName = ref('')
const showAddEmployee = ref(false)
const showAddModuleEmployee = ref(false)
const selectedEmployeeId = ref(null)
const selectedModuleEmployeeId = ref(null)
const showAddModule = ref(false)
const editingModule = ref(null)
const ticketError = ref('')
const moduleError = ref('')
// In-flight guard, so a double-click cannot create the module twice.
const savingModule = ref(false)
const moduleEditError = ref('')
const moduleEditForm = ref({
  name: '',
  description: '',
  budget: '',
  commissionType: 'percent',
  commissionValue: ''
})
const newModuleForm = ref({
  name: '',
  description: '',
  budget: '',
  commissionType: 'percent',
  commissionValue: ''
})
const newTicketForm = ref({
  title: '',
  description: '',
  priority: 'medium',
  assigneeId: assignableEmployees.value[0]?.id ?? null,
  deadline: ''
})

function slugify(value) {
  return value
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
}

function uniqueModuleSlug(name) {
  const base = slugify(name) || `module-${Date.now()}`
  const existing = new Set(projectModules.value.map(module => module.slug))
  if (!existing.has(base)) return base
  let index = 2
  while (existing.has(`${base}-${index}`)) index += 1
  return `${base}-${index}`
}

const projectModules = computed(() => project.value?.modules || [])
// Guard the missing-project case explicitly: can() with a null resource is a
// page-level check and would return true here.
const isProjectMember = computed(() => !!project.value && can('Projects', 'view', project.value))
const visibleModules = computed(() => {
  if (!isProjectMember.value) return []
  // Anyone who can approve the project (owner/super_admin) sees every module;
  // everyone else only the modules they are assigned to.
  if (can('Projects', 'approve', project.value)) return projectModules.value
  return projectModules.value.filter(module => can('Projects', 'view', module))
})
const filteredVisibleModules = computed(() =>
  visibleModules.value.filter(module => {
    if (kanbanTicketFilter.value === 'open') return moduleOpenTickets(module.id) > 0
    if (kanbanTicketFilter.value === 'completed') return moduleCompletedTickets(module.id) > 0
    if (kanbanTicketFilter.value === 'overdue') return moduleOverdueTickets(module.id) > 0
    return true
  })
)
const activeModule = computed(() => {
  if (!routeModuleSlug.value) return null
  return visibleModules.value.find(module => module.slug === routeModuleSlug.value) || null
})
const isModuleBoard = computed(() => Boolean(activeModule.value))
const isModuleCompleted = computed(() => activeModule.value?.status === 'completed')
const kanbanTicketFilter = computed(() => {
  const value = String(route.query.tickets || 'all')
  // 'review' is here because QA and the intern dashboard link to it — a tile
  // that opened an unfiltered board would be a link that quietly lied.
  return ['all', 'open', 'completed', 'overdue', 'review'].includes(value) ? value : 'all'
})
const kanbanTicketFilterModel = computed({
  get: () => kanbanTicketFilter.value,
  set: value => updateKanbanTicketFilter(value)
})
const kanbanTicketFilterLabel = computed(() => {
  const labels = {
    all: 'All tickets',
    open: 'Open tickets',
    completed: 'Completed tickets',
    overdue: 'Overdue tickets',
    review: 'In review'
  }
  return labels[kanbanTicketFilter.value] || labels.all
})
const moduleListTitle = computed(() =>
  kanbanTicketFilter.value === 'all' ? 'Modules:' : `${kanbanTicketFilterLabel.value}:`
)
const moduleListSubtitle = computed(() => {
  if (kanbanTicketFilter.value === 'open') return `${filteredVisibleModules.value.length} modules contain open tickets in ${project.value?.name || 'this project category'}.`
  if (kanbanTicketFilter.value === 'completed') return `${filteredVisibleModules.value.length} modules contain completed tasks in ${project.value?.name || 'this project category'}.`
  if (kanbanTicketFilter.value === 'overdue') return `${filteredVisibleModules.value.length} modules contain overdue tickets in ${project.value?.name || 'this project category'}.`
  return 'Open one module to manage its tickets.'
})
const canAccessActiveModule = computed(() => {
  if (!activeModule.value) return false
  // Project owners reach every module; members reach the ones they are on.
  return can('Projects', 'approve', project.value) || can('Projects', 'view', activeModule.value)
})
const canCreateTickets = computed(() => !isModuleCompleted.value && canAccessActiveModule.value && can('Tickets', 'create'))
/**
 * The squad on the active module, for the assignee picker.
 *
 * Taken from the module payload, which already embeds its members with names.
 * Resolving teamMembers through state.employees instead meant this was empty
 * for anyone without the Employees permission — a team lead has no such row, so
 * the picker offered nobody and they could not hand work to their own squad.
 * The roster is kept as a fallback for payloads that predate `members`.
 */
const moduleAssignedEmployees = computed(() => {
  const embedded = activeModule.value?.members
  if (Array.isArray(embedded) && embedded.length > 0) return embedded

  const ids = activeModule.value?.teamMembers || []
  return ids.map(id => state.employees.find(employee => employee.id === id)).filter(Boolean)
})
const availableModuleEmployees = computed(() => {
  const moduleIds = activeModule.value?.teamMembers || []
  const projectIds = project.value?.teamMembers || []
  return state.employees.filter(employee => employee.role === 'employee' && projectIds.includes(employee.id) && !moduleIds.includes(employee.id))
})

function ticketsIn(status) {
  if (!activeModule.value || !canAccessActiveModule.value) return []
  return state.tickets.filter(t => t.projectId === projectId.value && ticketModuleId(t) === activeModule.value.id && t.status === status && matchesKanbanTicketFilter(t))
}

function matchesKanbanTicketFilter(ticket) {
  if (kanbanTicketFilter.value === 'open') return ticket.status !== 'done'
  if (kanbanTicketFilter.value === 'completed') return ticket.status === 'done'
  if (kanbanTicketFilter.value === 'overdue') return isTicketOverdue(ticket)
  if (kanbanTicketFilter.value === 'review') return ticket.status === 'review'
  return true
}

function updateKanbanTicketFilter(value) {
  const query = { ...route.query }
  if (value === 'all') delete query.tickets
  else query.tickets = value
  if (!activeModule.value) {
    router.replace({
      name: 'project-detail',
      params: { projectSlug: project.value?.slug || projectSlug.value },
      query
    })
    return
  }
  router.replace({
    name: 'project-module-detail',
    params: {
      projectSlug: project.value?.slug || projectSlug.value,
      moduleSlug: activeModule.value.slug
    },
    query
  })
}

function ticketModuleId(ticket) {
  return ticket.moduleId || projectModules.value[0]?.id || null
}

function moduleTickets(moduleId) {
  const module = projectModules.value.find(item => item.id === moduleId)
  // Employees only see tickets for modules they are assigned to.
  if (!can('Projects', 'approve', project.value) && !can('Projects', 'view', module)) return []
  return state.tickets.filter(ticket => ticket.projectId === projectId.value && ticketModuleId(ticket) === moduleId)
}

function moduleOpenTickets(moduleId) {
  return moduleTickets(moduleId).filter(ticket => ticket.status !== 'done').length
}

function moduleCompletedTickets(moduleId) {
  return moduleTickets(moduleId).filter(ticket => ticket.status === 'done').length
}

function moduleOverdueTickets(moduleId) {
  return moduleTickets(moduleId).filter(isTicketOverdue).length
}

function selectModule(moduleId) {
  const module = projectModules.value.find(item => item.id === moduleId)
  if (!project.value || !module) return
  const query = {}
  if (['open', 'completed', 'overdue'].includes(kanbanTicketFilter.value)) query.tickets = kanbanTicketFilter.value
  router.push({ name: 'project-module-detail', params: { projectSlug: project.value.slug, moduleSlug: module.slug }, query })
}

function goToModules() {
  if (!project.value) return
  router.push({ name: 'project-detail', params: { projectSlug: project.value.slug } })
}

function moduleRoute(moduleId) {
  const module = projectModules.value.find(item => item.id === moduleId)
  if (!project.value || !module) return { name: 'projects' }
  return { name: 'project-module-detail', params: { projectSlug: project.value.slug, moduleSlug: module.slug } }
}

function activeModuleRoute() {
  return activeModule.value ? moduleRoute(activeModule.value.id) : project.value ? { name: 'project-detail', params: { projectSlug: project.value.slug } } : { name: 'projects' }
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

const overdueTickets = computed(() => state.tickets.filter(ticket => ticket.projectId === projectId.value && isTicketOverdue(ticket)))
const activeModuleOverdueTickets = computed(() => activeModule.value ? moduleOverdueTickets(activeModule.value.id) : 0)

function canManageModuleDelivery(module = activeModule.value) {
  if (!project.value || !module) return false
  return can('Projects', 'approve', project.value)
}

async function applyModuleDelivered(module, delivered) {
  if (!canManageModuleDelivery(module) || isSavingDelivery.value) return

  isSavingDelivery.value = true

  // Optimistic: the button sits on a card that also shows the delivered badge
  // and hides the add-ticket form, so a round-trip delay reads as a dead click.
  const previousStatus = module.status
  const previousDeliveredAt = module.deliveredAt
  module.status = delivered ? 'completed' : 'active'
  module.deliveredAt = delivered ? todayKey() : ''
  if (delivered) showAdd.value = false

  try {
    const updated = await projectService.setModuleDelivered(module.id, delivered)
    // Take the server's values rather than keeping the guess — delivered_at is
    // stamped there, and its timezone is the company's, not the browser's.
    module.status = updated.status
    module.deliveredAt = updated.deliveredAt || ''
  } catch (error) {
    module.status = previousStatus
    module.deliveredAt = previousDeliveredAt
    pushNotification({
      title: 'Could not update delivery',
      message: error instanceof ApiError ? error.message : 'The change was not saved.',
      type: 'project',
      userIds: [state.currentUser.id],
      route: activeModuleRoute()
    })
    isSavingDelivery.value = false
    return
  }

  isSavingDelivery.value = false

  logActivity({
    module: 'project',
    action: delivered ? 'module delivered' : 'module reopened',
    details: `${state.currentUser.name} ${delivered ? 'marked' : 'reopened'} ${project.value?.name || 'Project'} / ${module.name}${delivered ? ' as delivered' : ''}.`,
    targetId: module.id,
    targetType: 'module'
  })
}

function assignee(id) {
  return state.employees.find(e => e.id === id)?.name ?? '—'
}

/**
 * Prefer the name the API embeds on the ticket over a roster lookup.
 *
 * state.employees is populated from /employees, which an ordinary employee is
 * forbidden to call — so for them that lookup always missed and every card read
 * "—". The ticket payload has carried `assignee` all along and now carries
 * `assignedBy` too; both are eager-loaded, so this costs no extra request.
 *
 * The roster lookup stays as a fallback for tickets cached from before the
 * embedded fields existed.
 */
function ticketAssigneeName(ticket) {
  return ticket.assignee?.name ?? assignee(ticket.assigneeId)
}

/**
 * Whether this ticket is assigned to whoever is looking at it.
 *
 * Compared against employeeId, not currentUser.id — tickets reference the
 * employees table while currentUser.id is the user id, and the two only line up
 * by coincidence.
 */
/** Whether the viewer is the person who raised this ticket. */
function isOwnAssignment(ticket) {
  return state.currentUser.employeeId != null
    && ticket?.assignedById === state.currentUser.employeeId
}

function isOwnTicket(ticket) {
  return state.currentUser.employeeId != null
    && ticket.assigneeId === state.currentUser.employeeId
}

function ticketAssignedByName(ticket) {
  return ticket.assignedBy?.name ?? assignee(ticket.assignedById)
}

async function openTicket(ticket) {
  selectedTicketId.value = ticket.id
  ticketDraft.value = {
    title: ticket.title,
    description: ticket.description || '',
    status: ticket.status,
    priority: ticket.priority,
    assigneeId: ticket.assigneeId,
    deadline: ticket.deadline || ''
  }
  newComment.value = ''
  ticketError.value = ''

  // The list endpoint omits comments and time logs — they are detail-only, and
  // shipping every thread with every board load would be wasteful. Fetched
  // here so the modal shows them; the modal is already open by now, so a slow
  // response fills in rather than blocking.
  try {
    const full = await ticketService.find(ticket.id)
    // No re-pointing needed: selectedTicket resolves through the store.
    upsertTicket(full)
  } catch {
    /* comments simply stay empty; the rest of the modal is already usable */
  }
}

function closeTicket() {
  selectedTicketId.value = null
  ticketDraft.value = null
  newComment.value = ''
  newAttachmentName.value = ''
  if (route.query.ticket) {
    const query = { ...route.query }
    delete query.ticket
    router.replace({
      name: 'project-module-detail',
      params: {
        projectSlug: project.value?.slug || projectSlug.value,
        moduleSlug: activeModule.value?.slug || routeModuleSlug.value
      },
      query
    })
  }
}

watch(
  [() => route.query.ticket, activeModule],
  ([ticketId]) => {
    if (!ticketId || !activeModule.value || !canAccessActiveModule.value) return
    const ticket = state.tickets.find(item =>
      item.id === Number(ticketId)
      && item.projectId === projectId.value
      && ticketModuleId(item) === activeModule.value.id
    )
    if (ticket && selectedTicket.value?.id !== ticket.id) {
      openTicket(ticket)
    }
  },
  { immediate: true }
)

function dueDateLabel(deadline) {
  if (!deadline) return 'No due date'
  return formatDate(deadline, { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' })
}

function ticketProjectName(ticket) {
  return project.value?.name || state.projects.find(p => p.id === ticket.projectId)?.name || '—'
}

function ticketLoggedTime(ticket) {
  return formatDuration(ticketSeconds(ticket))
}

function ticketModuleName(ticket) {
  const moduleId = ticketModuleId(ticket)
  return projectModules.value.find(module => module.id === moduleId)?.name || 'No module'
}

function ticketComments(ticket) {
  return ticket.comments || []
}

/**
 * Takes the comment, not just its id: the API sends `authorName` alongside
 * (the author relation is eager-loaded on the detail endpoint). Resolving the
 * id through state.employees instead showed "—" for everyone without the
 * Employees permission, since that roster is empty for them.
 */
function commentAuthorName(comment) {
  return comment.authorName ?? assignee(comment.authorId)
}

function ticketActivity(ticket) {
  const comments = ticketComments(ticket).map(comment => ({
    id: `comment-${comment.id}`,
    title: `Comment by ${commentAuthorName(comment)}`,
    detail: comment.text,
    at: comment.createdAt
  }))
  const logs = (ticket.timeLogs || []).map((log, index) => ({
    id: `time-${index}`,
    title: log.end ? 'Time logged' : 'Timer started',
    detail: log.end
      ? `${new Date(log.start).toLocaleString()} to ${new Date(log.end).toLocaleString()}`
      : new Date(log.start).toLocaleString(),
    at: log.end || log.start
  }))
  const attachments = (ticket.attachments || []).map(attachment => ({
    id: `attachment-${attachment.id}`,
    title: `Attachment added by ${attachment.uploadedBy}`,
    detail: attachment.name,
    at: attachment.uploadedAt
  }))
  return [...comments, ...logs, ...attachments].sort((a, b) => new Date(b.at).getTime() - new Date(a.at).getTime())
}

function addTicketAttachment() {
  if (!selectedTicket.value || !newAttachmentName.value.trim()) return
  if (!selectedTicket.value.attachments) selectedTicket.value.attachments = []
  const attachmentName = newAttachmentName.value.trim()
  selectedTicket.value.attachments.unshift({
    id: Date.now(),
    name: attachmentName,
    uploadedBy: state.currentUser.name,
    uploadedAt: new Date().toISOString()
  })
  logActivity({
    module: 'ticket',
    action: 'attachment added',
    details: `${state.currentUser.name} attached ${attachmentName} to "${selectedTicket.value.title}".`,
    targetId: selectedTicket.value.id,
    targetType: 'ticket'
  })
  newAttachmentName.value = ''
}

async function addComment() {
  if (!selectedTicket.value || !newComment.value.trim()) return

  try {
    // Returns the ticket with its full comment thread, so the author name and
    // server timestamp are the real ones rather than optimistic guesses.
    upsertTicket(await ticketService.comment(selectedTicket.value.id, newComment.value.trim()))
  } catch (error) {
    ticketError.value = error instanceof ApiError
      ? error.message
      : 'Could not post the comment. Please try again.'
    return
  }

  const recipients = [selectedTicket.value.assigneeId, selectedTicket.value.assignedById, project.value?.managerId]
    .filter(id => id && id !== state.currentUser.id)
  pushNotification({
    title: 'New ticket comment',
    message: `${state.currentUser.name} commented on "${selectedTicket.value.title}".`,
    type: 'ticket',
    userIds: [...new Set(recipients)],
    route: activeModuleRoute()
  })
  logActivity({
    module: 'ticket',
    action: 'comment posted',
    details: `${state.currentUser.name} commented on "${selectedTicket.value.title}".`,
    targetId: selectedTicket.value.id,
    targetType: 'ticket'
  })
  newComment.value = ''
}

function updateSelectedTicketStatus(status) {
  if (!canUpdateTicketStatus.value || !ticketDraft.value) return
  ticketDraft.value.status = status
}

async function saveTicketChanges() {
  if (!canEditTicketFields.value || !selectedTicket.value || !ticketDraft.value) return

  // Only checked when the roster is actually available. moduleAssignedEmployees
  // resolves module member ids through state.employees, which is empty for
  // anyone without the Employees permission — so this rejected every save, and
  // rejected it silently, because it returned without setting ticketError. The
  // server enforces the same rule regardless of what the client can see.
  const roster = moduleAssignedEmployees.value
  if (roster.length > 0 && !roster.some(employee => employee.id === ticketDraft.value.assigneeId)) {
    ticketError.value = 'Choose an assignee who is assigned to this module.'
    return
  }

  ticketError.value = ''

  const changes = {
    title: ticketDraft.value.title.trim() || selectedTicket.value.title,
    description: ticketDraft.value.description.trim(),
    priority: ticketDraft.value.priority,
    assigneeId: ticketDraft.value.assigneeId,
    deadline: ticketDraft.value.deadline || null
  }
  if (canUpdateTicketStatus.value) {
    changes.status = ticketDraft.value.status
  }

  try {
    // The API silently drops assigneeId for anyone without supervisory scope,
    // so the saved record — not the draft — is what the store must reflect.
    upsertTicket(await ticketService.update(selectedTicket.value.id, changes))
  } catch (error) {
    ticketError.value = error instanceof ApiError
      ? error.message
      : 'Could not save the ticket. Please try again.'
    return
  }

  pushNotification({
    title: 'Ticket updated',
    message: `${state.currentUser.name} updated "${selectedTicket.value.title}".`,
    type: 'ticket',
    userIds: [...new Set([selectedTicket.value.assigneeId, selectedTicket.value.assignedById, project.value?.managerId].filter(id => id && id !== state.currentUser.id))],
    route: activeModuleRoute()
  })
  logActivity({
    module: 'ticket',
    action: 'ticket updated',
    details: `${state.currentUser.name} updated "${selectedTicket.value.title}".`,
    targetId: selectedTicket.value.id,
    targetType: 'ticket'
  })
  closeTicket()
}

function beginDrag(ticketId) {
  if (!canUpdateTicketStatus.value) return
  draggingTicketId.value = ticketId
}

function endDrag() {
  draggingTicketId.value = null
  activeDropColumn.value = null
}

function highlightColumn(column) {
  if (!canUpdateTicketStatus.value) return
  activeDropColumn.value = column
}

async function moveTicket(ticketId, status) {
  if (!canUpdateTicketStatus.value) return

  // Refused here rather than optimistically applied and rolled back: dropping
  // a card into Done only to watch it jump out again reads as a broken board,
  // and the API refuses it for anyone who is not a reviewer.
  if (status === 'done' && !canSignOff.value) {
    ticketError.value = 'Only a reviewer can mark work done. Move it to review instead.'
    return
  }

  const ticket = state.tickets.find(t => t.id === ticketId)
  if (!ticket) return

  // Optimistic: a drag that waits for the round trip snaps back under the
  // cursor, which reads as a failed drop. Reverted below if the save fails.
  const previousStatus = ticket.status
  ticket.status = status

  try {
    upsertTicket(await ticketService.update(ticketId, { status }))
  } catch (error) {
    ticket.status = previousStatus
    pushNotification({
      title: 'Could not move ticket',
      message: error instanceof ApiError ? error.message : 'The change was not saved.',
      type: 'ticket',
      userIds: [state.currentUser.id],
      route: activeModuleRoute()
    })
    return
  }

  pushNotification({
    title: 'Ticket status changed',
    message: `"${ticket.title}" moved to ${statusMeta[status].label}.`,
    type: 'ticket',
    userIds: [...new Set([ticket.assigneeId, ticket.assignedById, project.value?.managerId].filter(id => id && id !== state.currentUser.id))],
    route: activeModuleRoute()
  })
  logActivity({
    module: 'ticket',
    action: 'status changed',
    details: `"${ticket.title}" moved to ${statusMeta[status].label}.`,
    targetId: ticket.id,
    targetType: 'ticket'
  })
}

function dropTicket(status) {
  if (!canUpdateTicketStatus.value) return
  if (!draggingTicketId.value) return
  moveTicket(draggingTicketId.value, status)
  endDrag()
}

function getDevelopersInProject(projectId) {
  const currentProject = state.projects.find(p => p.id === projectId)
  return currentProject?.teamMembers?.map(id => state.employees.find(e => e.id === id)).filter(Boolean) || []
}

function teamPreview(projectId) {
  return getDevelopersInProject(projectId).slice(0, 4)
}

function openAddEmployee() {
  selectedEmployeeId.value = availableProjectEmployees.value[0]?.id ?? null
  showAddEmployee.value = true
}

function openAddModuleEmployee() {
  if (!activeModule.value) return
  selectedModuleEmployeeId.value = availableModuleEmployees.value[0]?.id ?? null
  showAddModuleEmployee.value = true
}

function addEmployeeToProject() {
  if (!canManageProjectMembers.value || !project.value || !selectedEmployeeId.value) return
  if (!project.value.teamMembers) project.value.teamMembers = []
  if (!project.value.teamMembers.includes(selectedEmployeeId.value)) {
    project.value.teamMembers.push(selectedEmployeeId.value)
    pushNotification({
      title: 'Added to project',
      message: `You were added to ${project.value.name}.`,
      type: 'project',
      userIds: [selectedEmployeeId.value],
    route: { name: 'project-detail', params: { projectSlug: project.value.slug } }
    })
    logActivity({
      module: 'project',
      action: 'employee added',
      details: `${assignee(selectedEmployeeId.value)} was added to ${project.value.name}.`,
      targetId: project.value.id,
      targetType: 'project'
    })
  }
  showAddEmployee.value = false
  selectedEmployeeId.value = null
}

function addEmployeeToModule() {
  if (!canManageProjectMembers.value || !activeModule.value || !selectedModuleEmployeeId.value) return
  if (!activeModule.value.teamMembers) activeModule.value.teamMembers = []
  if (!activeModule.value.teamMembers.includes(selectedModuleEmployeeId.value)) {
    activeModule.value.teamMembers.push(selectedModuleEmployeeId.value)
    pushNotification({
      title: 'Added to module',
      message: `You were added to ${activeModule.value.name}.`,
      type: 'project',
      userIds: [selectedModuleEmployeeId.value],
      route: activeModuleRoute()
    })
    logActivity({
      module: 'project',
      action: 'employee added to module',
      details: `${assignee(selectedModuleEmployeeId.value)} was added to ${project.value.name} / ${activeModule.value.name}.`,
      targetId: activeModule.value.id,
      targetType: 'module'
    })
  }
  showAddModuleEmployee.value = false
  selectedModuleEmployeeId.value = null
}

function canManageModules() {
  if (!project.value) return false
  return can('Projects', 'approve', project.value)
}

function openAddModule() {
  moduleError.value = ''
  newModuleForm.value = {
    name: '',
    description: '',
    budget: '',
    commissionType: 'percent',
    commissionValue: ''
  }
  showAddModule.value = true
}

async function addModule() {
  moduleError.value = ''
  if (!canManageModules() || !project.value || savingModule.value) return

  const name = newModuleForm.value.name.trim()
  if (!name) {
    moduleError.value = 'Please enter a module name.'
    return
  }

  const clash = (project.value.modules || []).find(m => (m.name || '').trim().toLowerCase() === name.toLowerCase())
  if (clash) {
    moduleError.value = `This section already has a module named "${clash.name}".`
    return
  }

  savingModule.value = true

  // Saved through the API rather than pushed into local state with a
  // `Date.now()` id, which is what this used to do — the module looked created,
  // logged an activity, and was gone on the next refresh.
  let module
  try {
    module = await projectService.createModule(project.value.slug, {
      name,
      description: newModuleForm.value.description.trim() || null,
      budget: Number(newModuleForm.value.budget || 0),
      commissionType: newModuleForm.value.commissionType,
      commissionValue: Number(newModuleForm.value.commissionValue || 0)
    })
  } catch (error) {
    moduleError.value = error instanceof ApiError
      ? error.message
      : 'Could not create the module. Please try again.'
    return
  } finally {
    savingModule.value = false
  }

  if (!project.value.modules) project.value.modules = []
  project.value.modules.push(module)

  logActivity({
    module: 'project',
    action: 'module created',
    details: `${state.currentUser.name} created module "${module.name}" in ${project.value.name}.`,
    targetId: project.value.id,
    targetType: 'project'
  })

  showAddModule.value = false
  selectModule(module.id)
}

function openEditModule(module) {
  if (!canManageModules() || !module) return
  moduleEditError.value = ''
  editingModule.value = module
  moduleEditForm.value = {
    name: module.name || '',
    description: module.description || '',
    budget: module.budget ?? '',
    commissionType: module.commission?.type || 'percent',
    commissionValue: module.commission?.value ?? ''
  }
}

async function saveModuleEdit() {
  moduleEditError.value = ''
  if (!canManageModules() || !project.value || !editingModule.value) return
  const editedName = moduleEditForm.value.name.trim()
  if (!editedName) {
    moduleEditError.value = 'Please enter a module name.'
    return
  }
  const nameClash = (project.value.modules || []).find(m =>
    m !== editingModule.value && (m.name || '').trim().toLowerCase() === editedName.toLowerCase()
  )
  if (nameClash) {
    moduleEditError.value = `This section already has a module named "${nameClash.name}".`
    return
  }
  if (Number(moduleEditForm.value.budget || 0) < 0) {
    moduleEditError.value = 'Budget cannot be negative.'
    return
  }
  const module = editingModule.value
  const before = {
    name: module.name || '',
    description: module.description || '',
    budget: Number(module.budget || 0),
    commissionType: module.commission?.type || 'percent',
    commissionValue: Number(module.commission?.value || 0)
  }
  const next = {
    name: moduleEditForm.value.name.trim(),
    description: moduleEditForm.value.description.trim(),
    budget: Number(moduleEditForm.value.budget || 0),
    commissionType: moduleEditForm.value.commissionType,
    commissionValue: Number(moduleEditForm.value.commissionValue || 0)
  }
  const changes = []
  if (before.name !== next.name) changes.push(`name from "${before.name}" to "${next.name}"`)
  if (before.description !== next.description) changes.push('description')
  if (before.budget !== next.budget) changes.push(`budget from ${formatCurrency(before.budget)} to ${formatCurrency(next.budget)}`)
  if (before.commissionType !== next.commissionType || before.commissionValue !== next.commissionValue) {
    changes.push(`commission from ${before.commissionValue}${before.commissionType === 'percent' ? '%' : ' fixed'} to ${next.commissionValue}${next.commissionType === 'percent' ? '%' : ' fixed'}`)
  }
  if (changes.length === 0) {
    editingModule.value = null
    return
  }

  // Saved first, then reflected — this used to mutate the module in place and
  // never call the API, so an edit looked applied and reverted on refresh.
  let saved
  try {
    saved = await projectService.updateModule(module.id, {
      name: next.name,
      description: next.description || null,
      budget: next.budget,
      commissionType: next.commissionType,
      commissionValue: next.commissionValue
    })
  } catch (error) {
    moduleEditError.value = error instanceof ApiError
      ? error.message
      : 'Could not save the module. Please try again.'
    return
  }

  Object.assign(module, saved)

  const details = `${state.currentUser.name} updated module "${module.name}" in ${project.value.name}: ${changes.join(', ')}.`
  logActivity({
    module: 'project',
    action: before.budget !== next.budget ? 'module budget updated' : 'module updated',
    details,
    targetId: module.id,
    targetType: 'module'
  })
  pushNotification({
    title: 'Module updated',
    message: details,
    type: 'project',
    roles: ['super_admin'],
    route: moduleRoute(module.id)
  })
  editingModule.value = null
}

const priorityTone = {
  low: 'text-slate-500',
  medium: 'text-amber',
  high: 'text-coral'
}

const showAdd = ref(false)
const newTitle = ref('')
function openAddTicket() {
  ticketError.value = ''
  newTicketForm.value.assigneeId = moduleAssignedEmployees.value[0]?.id ?? null
  showAdd.value = true
}

async function addTicket() {
  ticketError.value = ''
  if (!canCreateTickets.value) return
  if (!activeModule.value) {
    ticketError.value = 'Open a module before creating a ticket.'
    return
  }
  if (!newTicketForm.value.title.trim()) {
    ticketError.value = 'Please enter a ticket title.'
    return
  }
  if (!newTicketForm.value.assigneeId) {
    ticketError.value = 'Please choose an assignee.'
    return
  }
  // Non-obvious rule: the assignee must be on this module's team.
  if (!moduleAssignedEmployees.value.some(employee => employee.id === newTicketForm.value.assigneeId)) {
    ticketError.value = 'That person is not assigned to this module.'
    return
  }
  let ticket
  try {
    // The id and projectId come from the server: projectId is derived from the
    // chosen module there, so it cannot be pointed at another project, and
    // Date.now() ids collided whenever two tickets were created in the same
    // millisecond.
    ticket = await ticketService.create({
      moduleId: activeModule.value.id,
      title: newTicketForm.value.title.trim(),
      description: newTicketForm.value.description.trim(),
      priority: newTicketForm.value.priority,
      assigneeId: newTicketForm.value.assigneeId,
      deadline: newTicketForm.value.deadline || null
    })
    upsertTicket(ticket)
  } catch (error) {
    ticketError.value = error instanceof ApiError
      ? (error.fieldError('title') || error.message)
      : 'Could not create the ticket. Please try again.'
    return
  }

  pushNotification({
    title: 'New ticket assigned',
    message: `"${ticket.title}" was created in ${activeModule.value.name}.`,
    type: 'ticket',
    userIds: [...new Set([ticket.assigneeId, ticket.assignedById, project.value?.managerId].filter(id => id && id !== state.currentUser.id))],
    route: activeModuleRoute()
  })
  logActivity({
    module: 'ticket',
    action: 'ticket created',
    details: `${state.currentUser.name} created "${ticket.title}" in ${project.value?.name || 'a project'} / ${activeModule.value.name}.`,
    targetId: ticket.id,
    targetType: 'ticket'
  })
  newTicketForm.value = {
    title: '',
      description: '',
    priority: 'medium',
    assigneeId: moduleAssignedEmployees.value[0]?.id ?? null,
      deadline: ''
  }
  showAdd.value = false
}
</script>

<template>
  <div v-if="project && !isHR" class="project-detail-page space-y-6">
    <div class="flex items-center justify-between gap-4">
      <div class="min-w-0">
        <div class="flex items-center gap-2 text-sm leading-tight">
          <button @click="router.push({ name: 'projects' })" class="font-medium text-slate-500 hover:text-emerald-700 transition-colors">Project Categories</button>
          <span class="text-slate-400">/</span>
          <span class="font-semibold text-slate-900 truncate">{{ project.name }}</span>
          <template v-if="activeModule">
            <span class="text-slate-400">/</span>
            <span class="font-semibold text-slate-900 truncate">{{ activeModule.name }}</span>
          </template>
        </div>
      </div>
      <div class="flex items-center gap-2 shrink-0">
        <button v-if="isModuleBoard" @click="goToModules" class="border border-slate-200 bg-white text-slate-700 text-sm font-medium rounded-lg px-4 py-2 hover:bg-slate-50 transition-colors">
          Back to modules
        </button>
        <button v-if="!isModuleBoard && canManageModules()" @click="openAddModule" class="border border-slate-200 bg-white text-slate-700 text-sm font-medium rounded-lg px-4 py-2 hover:bg-slate-50 transition-colors">
          + Add module
        </button>
        <button v-if="isModuleBoard && canManageProjectMembers && activeModule" @click="openAddModuleEmployee" class="border border-emerald-200 bg-emerald-50 text-emerald-700 text-sm font-medium rounded-lg px-4 py-2 hover:bg-emerald-100 transition-colors">
          Add to module
        </button>
        <button v-if="isModuleBoard && canManageModules() && activeModule" @click="openEditModule(activeModule)" class="border border-slate-200 bg-white text-slate-700 text-sm font-medium rounded-lg px-4 py-2 hover:bg-slate-50 transition-colors">
          Edit module
        </button>
        <button v-if="isModuleBoard && canCreateTickets && activeModule && moduleAssignedEmployees.length" @click="openAddTicket" class="bg-emerald-600 text-white text-sm font-medium rounded-lg px-4 py-2 hover:bg-emerald-700 transition-colors shadow-sm">
          + New ticket
        </button>
      </div>
    </div>

    <div v-if="isModuleBoard && isModuleCompleted" class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-700">
      This module was delivered{{ activeModule.deliveredAt ? ` on ${activeModule.deliveredAt}` : '' }}. New ticket creation is locked until the module is reopened.
    </div>

    <section v-if="!isModuleBoard" class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
      <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-3 mb-4">
        <div>
          <div class="text-sm font-bold text-slate-900">{{ moduleListTitle }}</div>
          <div class="text-xs text-slate-600 mt-1">{{ moduleListSubtitle }}</div>
        </div>
        <div class="text-xs font-semibold text-slate-500">
          {{ filteredVisibleModules.length }} visible modules / {{ filteredVisibleModules.reduce((total, module) => total + moduleTickets(module.id).length, 0) }} tickets
        </div>
      </div>

      <div v-if="kanbanTicketFilter !== 'all'" class="mb-4 flex items-center gap-2">
        <span class="company-hub-badge company-hub-badge-info">{{ kanbanTicketFilterLabel }}</span>
        <button @click="updateKanbanTicketFilter('all')" class="text-xs font-semibold text-slate-500 hover:text-slate-900">Clear filter</button>
      </div>

      <div v-if="filteredVisibleModules.length" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
        <div
          v-for="module in filteredVisibleModules"
          :key="module.id"
          role="button"
          tabindex="0"
          @click="selectModule(module.id)"
          @keydown.enter="selectModule(module.id)"
          @keydown.space.prevent="selectModule(module.id)"
          class="project-module-card project-module-card-idle text-left rounded-xl border p-4 transition-all"
        >
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <div class="text-sm font-extrabold text-slate-900 truncate">{{ module.name }}</div>
              <div class="text-xs text-slate-600 mt-1 line-clamp-2">{{ module.description || 'No module description added.' }}</div>
            </div>
            <span v-if="moduleOverdueTickets(module.id)" class="overdue-badge shrink-0">
              {{ moduleOverdueTickets(module.id) }} overdue
            </span>
            <span
              v-else-if="module.status === 'completed'"
              class="project-status-badge project-status-badge-delivered shrink-0"
            >
              Delivered
            </span>
          </div>
          <div class="mt-4 grid grid-cols-2 gap-2">
            <div class="module-stat-box rounded-lg border border-slate-200 bg-white/70 p-2">
              <div class="text-[10px] uppercase tracking-wide text-slate-500 font-bold">Open tickets</div>
              <!-- Open out of total. The footer used to carry the open count on
                   its own line under a tile showing the total, so a module with
                   no delivered work displayed the same number twice. -->
              <div class="text-lg font-extrabold text-slate-900">
                {{ moduleOpenTickets(module.id) }}<span class="text-sm font-bold text-slate-500"> / {{ moduleTickets(module.id).length }}</span>
              </div>
            </div>
            <div v-if="canViewModuleFinance" class="module-stat-box rounded-lg border border-slate-200 bg-white/70 p-2">
              <div class="text-[10px] uppercase tracking-wide text-slate-500 font-bold">Budget</div>
              <div class="text-lg font-extrabold text-slate-900">{{ formatCurrency(module.budget || 0) }}</div>
            </div>
            <div v-else class="module-stat-box rounded-lg border border-slate-200 bg-white/70 p-2">
              <div class="text-[10px] uppercase tracking-wide text-slate-500 font-bold">Assigned</div>
              <div class="text-lg font-extrabold text-slate-900">{{ module.teamMembers?.length || 0 }}</div>
            </div>
          </div>
          <!-- Collapsed to one row: the open-ticket count that used to sit on
               the left is now folded into the tile above, so this wrapper only
               ever held the actions. -->
          <div v-if="canManageModules() || canManageModuleDelivery(module)" class="mt-3 flex items-center justify-end gap-2">
            <button
              v-if="canManageModules()"
              @click.stop="openEditModule(module)"
              class="text-xs font-semibold rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-slate-700 hover:bg-slate-50 transition-colors"
            >
              Edit
            </button>
            <button
              v-if="canManageModuleDelivery(module)"
              :disabled="isSavingDelivery"
              @click.stop="applyModuleDelivered(module, module.status !== 'completed')"
              class="text-xs font-semibold rounded-lg px-3 py-1.5 transition-colors disabled:opacity-60 disabled:cursor-not-allowed"
              :class="module.status === 'completed' ? 'border border-slate-200 bg-white text-slate-700 hover:bg-slate-50' : 'bg-slate-950 text-white hover:bg-black'"
            >
              {{ module.status === 'completed' ? 'Reopen' : 'Mark delivered' }}
            </button>
          </div>
        </div>
      </div>

      <div v-else class="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-6 text-center">
        <div class="text-sm font-bold text-slate-900">{{ visibleModules.length ? 'No modules match this filter' : projectModules.length ? 'No modules assigned' : 'No modules yet' }}</div>
        <div class="text-sm text-slate-600 mt-1">
          {{ visibleModules.length ? 'Clear the current ticket filter to view all modules in this project category.' : projectModules.length ? 'You are added to this project category, but no module has been assigned to you yet.' : 'Add the first individual project/module, then create tickets inside it.' }}
        </div>
        <button v-if="visibleModules.length && kanbanTicketFilter !== 'all'" @click="updateKanbanTicketFilter('all')" class="mt-4 border border-slate-200 bg-white text-slate-700 text-sm font-medium rounded-lg px-4 py-2 hover:bg-slate-50 transition-colors">
          Clear filter
        </button>
        <button v-if="canManageModules()" @click="openAddModule" class="mt-4 bg-slate-950 text-white text-sm font-medium rounded-lg px-4 py-2 hover:bg-black transition-colors">
          + Add first module
        </button>
      </div>
    </section>

    <div v-if="isModuleBoard && activeModule" class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
      <div class="flex items-center gap-2">
        <select v-model="kanbanTicketFilterModel" class="table-filter-input w-full sm:w-[180px]">
          <option value="all">All tickets</option>
          <option value="open">Open tickets</option>
          <option value="completed">Completed tickets</option>
          <option value="review">In review</option>
          <option value="overdue">Overdue tickets</option>
        </select>
        <span v-if="kanbanTicketFilter !== 'all'" class="company-hub-badge company-hub-badge-info">{{ kanbanTicketFilterLabel }}</span>
      </div>
      <div class="flex items-center justify-end gap-2">
        <span
          class="project-status-badge"
          :class="isModuleCompleted ? 'project-status-badge-delivered' : 'project-status-badge-active'"
        >
          {{ isModuleCompleted ? 'Delivered' : 'Active' }}
        </span>
        <span v-if="activeModuleOverdueTickets" class="overdue-badge">{{ activeModuleOverdueTickets }} overdue</span>
      </div>
    </div>

    <div v-else-if="routeModuleSlug && !activeModule" class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-800">
      <div class="font-bold text-amber-900">Module access required</div>
      <div class="mt-1">You are added to the project category, but you are not assigned to this module. Ask your manager or super admin to add you to the module before viewing its tickets.</div>
    </div>

    <div v-if="isModuleBoard && activeModule" class="overflow-x-auto pb-2">
      <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4 min-w-[1120px]">
        <div
          v-for="col in columns"
          :key="col"
          @dragover.prevent="highlightColumn(col)"
          @dragleave="activeDropColumn = null"
          @drop.prevent="dropTicket(col)"
          :class="activeDropColumn === col ? 'ring-2 ring-emerald-400 bg-emerald-50/40' : 'bg-slate-50'"
          class="rounded-xl p-3 border border-slate-200 flex flex-col h-[68vh] transition-all"
        >
          <div class="flex items-center justify-between px-1 mb-2.5 pb-2 border-b border-slate-200">
            <span class="text-xs font-medium text-slate-900">{{ statusMeta[col].label }}</span>
            <span class="text-[11px] text-slate-600">{{ ticketsIn(col).length }}</span>
          </div>
          <div class="modal-scroll-hidden flex-1 overflow-y-auto pr-1 space-y-2">
            <div
              v-for="t in ticketsIn(col)"
              :key="t.id"
              :draggable="canUpdateTicketStatus"
              @dragstart="beginDrag(t.id)"
              @dragend="endDrag"
              @click="openTicket(t)"
              :class="[
                draggingTicketId === t.id ? 'opacity-50 scale-[0.98]' : '',
                canUpdateTicketStatus ? 'cursor-grab active:cursor-grabbing' : 'cursor-pointer',
                isTicketOverdue(t) ? 'overdue-ticket-card' : 'bg-white border-slate-200'
              ]"
              class="border rounded-xl p-3.5 shadow-sm transition-all hover:border-emerald-300 hover:shadow-md hover:-translate-y-px"
            >
              <!-- No status pill: ticketsIn() filters each column on that exact
                   status, so a card can only ever be sitting under its own label
                   and the pill just repeated the column header. The overdue flag
                   takes the space instead — it is the one thing about a card that
                   its column cannot tell you. -->
              <div class="flex items-start justify-between gap-2 mb-2">
                <div class="min-w-0">
                  <!-- The identifier people quote in Teams and in conversation, so
                       it sits above the title rather than being buried in the modal. -->
                  <div v-if="t.reference" class="text-[10px] font-mono text-slate-500 mb-0.5 truncate">{{ t.reference }}</div>
                  <div class="text-sm text-slate-900 leading-snug font-semibold">{{ t.title }}</div>
                </div>
                <span v-if="isTicketOverdue(t)" class="overdue-badge shrink-0 text-[10px]">Overdue</span>
              </div>

              <div class="flex items-center gap-2 flex-wrap mb-2.5">
                <!-- The dot carries the priority colour so the word itself does
                     not have to shout. -->
                <span class="inline-flex items-center gap-1.5 text-[11px] font-semibold capitalize" :class="priorityTone[t.priority]">
                  <span class="w-1.5 h-1.5 rounded-full bg-current shrink-0"></span>{{ t.priority }}
                </span>
              </div>

              <div class="space-y-1 text-[11px] text-slate-500">
                <!-- Hidden when the ticket is your own: on a personal board every
                     card would repeat your own name. A lead or manager looking at
                     the same board still needs it, so it is not removed outright. -->
                <div v-if="!isOwnTicket(t)" class="truncate">
                  For <span class="text-slate-700 font-medium">{{ ticketAssigneeName(t) }}</span>
                </div>
                <div v-if="t.assignedById && !isOwnAssignment(t)" class="truncate">
                  Assigned by <span class="text-slate-700 font-medium">{{ ticketAssignedByName(t) }}</span>
                </div>
                <div class="truncate">
                  Due <span class="font-medium" :class="isTicketOverdue(t) ? 'overdue-date-text' : 'text-slate-700'">{{ dueDateLabel(t.deadline) }}</span>
                </div>
              </div>
              <div class="flex items-center justify-between gap-2 pt-2.5 mt-3 border-t border-slate-200">
                <span class="text-[10px] text-slate-600 uppercase tracking-wide font-semibold">Total logged</span>
                <TicketTimer :ticket="t" :readonly="!canUseTicketTimer" />
              </div>
            </div>
            <div v-if="ticketsIn(col).length === 0" class="text-[11px] text-slate-600 text-center py-4">No tickets</div>
          </div>
        </div>
      </div>
    </div>

    <Teleport to="body">
      <!-- Ticket detail modal -->
      <div v-if="selectedTicket" class="fixed inset-0 z-50 bg-black/40 backdrop-blur-[2px] flex items-center justify-center px-4 py-4 overflow-y-auto" @click.self="closeTicket">
        <div class="w-full max-w-2xl rounded-2xl bg-white border border-slate-200 shadow-2xl overflow-hidden max-h-[calc(100vh-2rem)] flex flex-col">
          <div class="flex items-start justify-between gap-4 p-5 border-b border-slate-200 shrink-0">
            <div class="min-w-0">
              <div class="text-[11px] uppercase tracking-[0.18em] text-slate-500 font-semibold">Ticket Details</div>
              <div class="text-2xl font-bold text-slate-900 mt-1 leading-tight">{{ selectedTicket.title }}</div>
              <div class="text-sm text-slate-600 mt-1">{{ ticketProjectName(selectedTicket) }} / {{ ticketModuleName(selectedTicket) }}</div>
            </div>
            <button @click="closeTicket" class="text-slate-500 hover:text-slate-900 text-xl leading-none">✕</button>
          </div>

          <div class="modal-scroll-hidden flex-1 overflow-y-auto p-5">
            <div v-if="isTicketOverdue(selectedTicket)" class="overdue-banner mb-4 rounded-xl px-4 py-3 text-sm font-semibold">
              This ticket is overdue. Its due date has passed and it is not marked done.
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 space-y-3">
                <div>
                  <label class="block">
                  <span class="text-[11px] uppercase tracking-[0.16em] text-slate-500 font-semibold block mb-2">Title</span>
                  <input v-model="ticketDraft.title" type="text" :disabled="!canEditTicketFields" class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 focus:outline-none focus:ring-1 focus:ring-emerald-500 disabled:bg-slate-100 disabled:text-slate-500" />
                  </label>
                </div>
                <div>
                  <label class="block">
                  <span class="text-[11px] uppercase tracking-[0.16em] text-slate-500 font-semibold block mb-2">Description</span>
                  <textarea v-model="ticketDraft.description" rows="3" :disabled="!canEditTicketFields" class="w-full resize-none rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 focus:outline-none focus:ring-1 focus:ring-emerald-500 disabled:bg-slate-100 disabled:text-slate-500" placeholder="Add ticket details, blockers, or acceptance notes..."></textarea>
                  </label>
                </div>
                <div class="flex items-center justify-between gap-3">
                  <span class="text-[11px] uppercase tracking-[0.16em] text-slate-500 font-semibold">Status</span>
                  <select
                    v-model="ticketDraft.status"
                    :disabled="!canUpdateTicketStatus"
                    class="text-[11px] font-medium capitalize px-2 py-1 rounded-full border border-slate-200 bg-white text-slate-700 focus:outline-none focus:ring-1 focus:ring-emerald-500 disabled:bg-slate-100 disabled:text-slate-500"
                  >
                    <!-- availableStatuses, not columns: 'done' is a sign-off and is
                         hidden from anyone the API would refuse it from. The current
                         status is kept so an already-done ticket still shows its own. -->
                    <option v-for="col in availableStatuses" :key="col" :value="col">{{ statusMeta[col].label }}</option>
                    <option v-if="!availableStatuses.includes(ticketDraft.status)" :value="ticketDraft.status">{{ statusMeta[ticketDraft.status].label }}</option>
                  </select>
                </div>
                <div class="flex items-center justify-between gap-3">
                  <span class="text-sm text-slate-600">Priority</span>
                  <select v-model="ticketDraft.priority" :disabled="!canEditTicketFields" class="text-sm font-medium capitalize rounded-lg border border-slate-200 bg-white px-2 py-1 focus:outline-none focus:ring-1 focus:ring-emerald-500 disabled:bg-slate-100 disabled:text-slate-500">
                    <option value="low">low</option>
                    <option value="medium">medium</option>
                    <option value="high">high</option>
                  </select>
                </div>
                <!-- The dropdown's options come from state.employees, which is empty
                     for anyone without the Employees permission — an employee holds
                     Tickets:create but no roster, so the control rendered with zero
                     options and showed an empty box instead of the name. It is only
                     used when there is something to choose from; otherwise the
                     current assignee is shown as text, read from the ticket itself.

                     The row is dropped entirely on your own ticket — it would only
                     ever say "you". -->
                <div v-if="canEditTicketFields || !isOwnTicket(selectedTicket)" class="flex items-center justify-between gap-3">
                  <span class="text-sm text-slate-600">Assignee</span>
                  <select v-if="canEditTicketFields && moduleAssignedEmployees.length > 0" v-model="ticketDraft.assigneeId" class="text-sm font-medium rounded-lg border border-slate-200 bg-white px-2 py-1 focus:outline-none focus:ring-1 focus:ring-emerald-500 max-w-[180px]">
                    <option v-for="emp in moduleAssignedEmployees" :key="emp.id" :value="emp.id">{{ emp.name }}</option>
                  </select>
                  <span v-else class="text-sm font-medium text-slate-900 max-w-[180px] truncate">{{ ticketAssigneeName(selectedTicket) }}</span>
                </div>
                <!-- Never editable, for anyone. This is `created_by` — a record of
                     who raised the ticket, not a setting. It was a dropdown that
                     could not work: saveTicketChanges never sent the field, and
                     UpdateTicketRequest would have rejected it anyway. Editing it
                     would mean rewriting the ticket's own history.

                     Hidden from whoever raised it: telling someone they assigned
                     the ticket they are looking at is not information. A manager
                     or lead reading somebody else's ticket still sees it. -->
                <div v-if="!isOwnAssignment(selectedTicket)" class="flex items-center justify-between gap-3">
                  <span class="text-sm text-slate-600">Reference</span>
                  <!-- Never editable. It is derived from the company, project and
                       module at creation and quoted elsewhere, so it must not drift. -->
                  <span class="text-sm font-mono font-medium text-slate-900 max-w-[180px] truncate">{{ selectedTicket.reference || '—' }}</span>
                </div>
                <div class="flex items-center justify-between gap-3">
                  <span class="text-sm text-slate-600">Assigned by</span>
                  <span class="text-sm font-medium text-slate-900 max-w-[180px] truncate">{{ ticketAssignedByName(selectedTicket) }}</span>
                </div>
                <div class="flex items-center justify-between gap-3">
                  <span class="text-sm text-slate-600">Due date</span>
                  <input v-model="ticketDraft.deadline" type="date" :disabled="!canEditTicketFields" class="text-sm font-medium rounded-lg border border-slate-200 bg-white px-2 py-1 focus:outline-none focus:ring-1 focus:ring-emerald-500 disabled:bg-slate-100 disabled:text-slate-500" />
                </div>
              </div>

              <div class="rounded-xl border border-slate-200 bg-white p-4 space-y-3">
                <div class="text-[11px] uppercase tracking-[0.16em] text-slate-500 font-semibold">Time</div>
                <div class="flex items-center justify-between gap-3">
                  <span class="text-sm text-slate-600">Total logged</span>
                  <span class="text-lg font-mono font-semibold text-emerald-600">{{ ticketLoggedTime(selectedTicket) }}</span>
                </div>
                <div class="flex items-center justify-between gap-3">
                  <span class="text-sm text-slate-600">Current status column</span>
                  <span class="text-sm font-medium text-slate-900 capitalize">{{ statusMeta[selectedTicket.status].label }}</span>
                </div>
                <div class="flex items-center justify-between gap-3">
                  <span class="text-sm text-slate-600">Project hours today</span>
                  <span class="text-sm font-mono font-medium text-slate-900">{{ formatDuration(projectSecondsToday(projectId)) }}</span>
                </div>
                <div class="pt-2 border-t border-slate-200">
                  <div class="text-[11px] uppercase tracking-[0.16em] text-slate-500 font-semibold mb-2">Timer</div>
                  <div class="flex items-center justify-between gap-3">
                    <div class="flex items-center gap-2 text-sm">
                      <span class="w-2 h-2 rounded-full" :class="state.activeTimer?.ticketId === selectedTicket.id ? 'bg-emerald-500 animate-pulse' : 'bg-slate-300'"></span>
                      <span class="text-slate-700">{{ state.activeTimer?.ticketId === selectedTicket.id ? 'Timer running' : 'Timer stopped' }}</span>
                    </div>
                    <TicketTimer :ticket="selectedTicket" :readonly="!canUseTicketTimer" />
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
                  <div class="text-xs text-slate-500">{{ (selectedTicket.attachments || []).length }} files</div>
                </div>
                <div class="space-y-2 max-h-32 overflow-y-auto modal-scroll-hidden">
                  <div v-if="!(selectedTicket.attachments || []).length" class="text-sm text-slate-500 italic">No attachments yet.</div>
                  <div v-for="attachment in selectedTicket.attachments || []" :key="attachment.id" class="flex items-center justify-between gap-3 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                    <div class="min-w-0">
                      <div class="truncate text-sm font-semibold text-slate-900">{{ attachment.name }}</div>
                      <div class="text-[11px] text-slate-500">{{ attachment.uploadedBy }} · {{ formatDate(attachment.uploadedAt) }}</div>
                    </div>
                    <span class="text-[11px] uppercase tracking-[0.14em] text-slate-500 font-semibold">File</span>
                  </div>
                </div>
                <div class="flex gap-2 mt-3">
                  <input v-model="newAttachmentName" type="text" class="min-w-0 flex-1 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 focus:outline-none focus:ring-1 focus:ring-emerald-500" placeholder="Attachment name or reference" />
                  <button @click="addTicketAttachment" class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 transition-colors">Add</button>
                </div>
              </div>

              <div class="rounded-xl border border-slate-200 bg-white p-4">
                <div class="flex items-center justify-between gap-3 mb-3">
                  <div>
                    <div class="text-[11px] uppercase tracking-[0.16em] text-slate-500 font-semibold">Activity Timeline</div>
                    <div class="text-sm text-slate-600 mt-1">Audit trail for work and updates.</div>
                  </div>
                  <div class="text-xs text-slate-500">{{ ticketActivity(selectedTicket).length }} events</div>
                </div>
                <div class="space-y-2 max-h-44 overflow-y-auto modal-scroll-hidden">
                  <div v-if="ticketActivity(selectedTicket).length === 0" class="text-sm text-slate-500 italic">No activity recorded yet.</div>
                  <div v-for="event in ticketActivity(selectedTicket)" :key="event.id" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
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
                  <div class="text-sm text-slate-600 mt-1">Add an update like you would on Jira or Zoho.</div>
                </div>
                <div class="text-xs text-slate-500">{{ ticketComments(selectedTicket).length }} comments</div>
              </div>

              <div class="space-y-3 max-h-56 overflow-y-auto pr-1">
                <div v-if="ticketComments(selectedTicket).length === 0" class="text-sm text-slate-500 italic">No comments yet. Add the first update below.</div>
                <div v-for="comment in ticketComments(selectedTicket)" :key="comment.id" class="rounded-xl border border-slate-200 bg-white p-3">
                  <div class="flex items-center justify-between gap-3 mb-2">
                    <div class="text-sm font-semibold text-slate-900">{{ commentAuthorName(comment) }}</div>
                    <div class="text-[11px] text-slate-500">{{ formatDateTime(comment.createdAt) }}</div>
                  </div>
                  <div class="text-sm text-slate-700 leading-relaxed">{{ comment.text }}</div>
                </div>
              </div>

              <div class="mt-4 rounded-xl border border-slate-200 bg-white p-3">
                <label class="block">
                <span class="text-[11px] uppercase tracking-[0.16em] text-slate-500 font-semibold block mb-2">Add comment</span>
                <textarea
                  v-model="newComment"
                  rows="3"
                  class="w-full resize-none rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-700 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                  placeholder="Write an update, mention blockers, or note next steps..."
                ></textarea>
                </label>
                <div class="flex justify-end mt-3">
                  <button @click="addComment" class="bg-emerald-600 text-white text-sm font-medium rounded-lg px-4 py-2 hover:bg-emerald-700 transition-colors">
                    Post comment
                  </button>
                </div>
              </div>

              <div class="flex items-center justify-end gap-2 mt-4 pt-4 border-t border-slate-200">
                <button @click="closeTicket" class="text-sm border border-slate-200 rounded-lg px-4 py-2 hover:bg-slate-50 transition-colors text-slate-700 font-medium">Cancel</button>
                <button v-if="canEditTicketFields" @click="saveTicketChanges" class="bg-emerald-600 text-white text-sm font-medium rounded-lg px-4 py-2 hover:bg-emerald-700 transition-colors">Save changes</button>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- New ticket modal -->
      <div v-if="showAdd" class="fixed inset-0 z-50 bg-black/40 backdrop-blur-[2px] flex items-center justify-center px-4 py-4 overflow-y-auto" @click.self="showAdd = false">
        <div class="bg-white rounded-xl p-6 w-full max-w-sm border border-slate-200 shadow-2xl max-h-[calc(100vh-2rem)] overflow-hidden">
          <div class="font-semibold text-[15px] text-slate-900 mb-1">New ticket</div>
          <div class="text-sm text-slate-600 mb-4">Creating inside {{ activeModule?.name }}.</div>
          <div class="space-y-3">
            <input v-model="newTicketForm.title" type="text" placeholder="Ticket title" class="w-full text-[13px] border border-slate-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-1 focus:ring-emerald-500 text-slate-700" />
            <!-- No reference input: the server derives it from the company name,
                 project and module (LPS-Internal-AS-1) and returns it on create. -->
            <textarea v-model="newTicketForm.description" rows="3" placeholder="Description" class="w-full resize-none text-[13px] border border-slate-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-1 focus:ring-emerald-500 text-slate-700"></textarea>
            <div class="grid grid-cols-2 gap-3">
              <select v-model="newTicketForm.priority" class="text-[13px] border border-slate-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-1 focus:ring-emerald-500 text-slate-700">
                <option value="low">Low priority</option>
                <option value="medium">Medium priority</option>
                <option value="high">High priority</option>
              </select>
              <input v-model="newTicketForm.deadline" type="date" class="text-[13px] border border-slate-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-1 focus:ring-emerald-500 text-slate-700" />
            </div>
            <select v-model="newTicketForm.assigneeId" class="w-full text-[13px] border border-slate-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-1 focus:ring-emerald-500 text-slate-700">
              <option v-for="emp in moduleAssignedEmployees" :key="emp.id" :value="emp.id">Assign to: {{ emp.name }}</option>
            </select>
            <!-- No "assigned by" picker: the server stamps created_by with whoever
                 is authenticated, and StoreTicketRequest does not accept the field.
                 Offering a choice here only implied one existed. -->
          </div>
          <div v-if="ticketError" class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-[12px] font-semibold text-rose-700">
            {{ ticketError }}
          </div>
          <div class="flex gap-2 mt-4">
            <button @click="showAdd = false" class="flex-1 text-[13px] border border-slate-200 rounded-lg py-2 hover:bg-slate-50 transition-colors text-slate-700 font-medium">Cancel</button>
            <button
              @click="addTicket"
              :disabled="!newTicketForm.title.trim() || !newTicketForm.assigneeId"
              class="flex-1 bg-emerald-600 text-white text-[13px] font-medium rounded-lg py-2 hover:bg-emerald-700 disabled:bg-slate-300 disabled:cursor-not-allowed transition-colors"
            >
              Create
            </button>
          </div>
        </div>
      </div>

      <!-- Add module modal -->
      <div v-if="showAddModule" class="fixed inset-0 z-50 bg-black/40 backdrop-blur-[2px] flex items-center justify-center px-4 py-4 overflow-y-auto" @click.self="showAddModule = false">
        <div class="bg-white rounded-xl p-6 w-full max-w-md border border-slate-200 shadow-2xl max-h-[calc(100vh-2rem)] overflow-y-auto">
          <div class="font-semibold text-[15px] text-slate-900 mb-1">Add module / project</div>
          <div class="text-sm text-slate-600 mb-4">Create an individual project under {{ project.name }}. Tickets will be tracked inside this module.</div>
          <div class="space-y-3">
            <input v-model="newModuleForm.name" type="text" placeholder="Project/module name, e.g. Website for Client X" class="w-full text-[13px] border border-slate-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-1 focus:ring-emerald-500 text-slate-700" />
            <textarea v-model="newModuleForm.description" rows="3" placeholder="Scope, client notes, or delivery details" class="w-full resize-none text-[13px] border border-slate-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-1 focus:ring-emerald-500 text-slate-700"></textarea>
            <input v-model="newModuleForm.budget" type="number" min="0" placeholder="Client budget for this module/project" class="w-full text-[13px] border border-slate-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-1 focus:ring-emerald-500 text-slate-700" />
            <div class="grid grid-cols-2 gap-2">
              <select v-model="newModuleForm.commissionType" class="w-full text-[13px] border border-slate-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-1 focus:ring-emerald-500 text-slate-700">
                <option value="percent">Commission %</option>
                <option value="fixed">Fixed commission</option>
              </select>
              <input v-model="newModuleForm.commissionValue" type="number" min="0" :placeholder="newModuleForm.commissionType === 'percent' ? 'Percentage' : 'Fixed amount'" class="w-full text-[13px] border border-slate-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-1 focus:ring-emerald-500 text-slate-700" />
            </div>
          </div>
          <div v-if="moduleError" class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-[12px] font-semibold text-rose-700">
            {{ moduleError }}
          </div>
          <div class="flex gap-2 mt-4">
            <button @click="showAddModule = false" class="flex-1 text-[13px] border border-slate-200 rounded-lg py-2 hover:bg-slate-50 transition-colors text-slate-700 font-medium">Cancel</button>
            <button
              @click="addModule"
              :disabled="!newModuleForm.name.trim()"
              class="flex-1 bg-emerald-600 text-white text-[13px] font-medium rounded-lg py-2 hover:bg-emerald-700 disabled:bg-slate-300 disabled:cursor-not-allowed transition-colors"
            >
              Create module
            </button>
          </div>
        </div>
      </div>

      <!-- Edit module modal -->
      <div v-if="editingModule" class="fixed inset-0 z-50 bg-black/40 backdrop-blur-[2px] flex items-center justify-center px-4 py-4 overflow-y-auto" @click.self="editingModule = null">
        <div class="bg-white rounded-xl p-6 w-full max-w-md border border-slate-200 shadow-2xl max-h-[calc(100vh-2rem)] overflow-y-auto">
          <div class="font-semibold text-[15px] text-slate-900 mb-1">Edit module</div>
          <div class="text-sm text-slate-600 mb-4">Update module details, budget, and commission setup.</div>
          <div class="space-y-3">
            <label class="block">
              <span class="mb-1 block text-xs font-bold text-slate-600">Module name</span>
              <input v-model="moduleEditForm.name" type="text" placeholder="Project/module name" class="w-full text-[13px] border border-slate-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-1 focus:ring-emerald-500 text-slate-700" />
            </label>
            <label class="block">
              <span class="mb-1 block text-xs font-bold text-slate-600">Description</span>
              <textarea v-model="moduleEditForm.description" rows="3" placeholder="Short module description" class="w-full resize-none text-[13px] border border-slate-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-1 focus:ring-emerald-500 text-slate-700"></textarea>
            </label>
            <label v-if="canViewModuleFinance" class="block">
              <span class="mb-1 block text-xs font-bold text-slate-600">Client budget</span>
              <input v-model="moduleEditForm.budget" type="number" min="0" :placeholder="`${currencyCode()} budget`" class="w-full text-[13px] border border-slate-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-1 focus:ring-emerald-500 text-slate-700" />
            </label>
            <div v-if="canViewModuleFinance" class="grid grid-cols-2 gap-2">
              <label class="block">
                <span class="mb-1 block text-xs font-bold text-slate-600">Commission type</span>
                <select v-model="moduleEditForm.commissionType" class="w-full text-[13px] border border-slate-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-1 focus:ring-emerald-500 text-slate-700">
                  <option value="percent">Percentage</option>
                  <option value="fixed">Fixed</option>
                </select>
              </label>
              <label class="block">
                <span class="mb-1 block text-xs font-bold text-slate-600">Commission value</span>
                <input v-model="moduleEditForm.commissionValue" type="number" min="0" placeholder="Value" class="w-full text-[13px] border border-slate-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-1 focus:ring-emerald-500 text-slate-700" />
              </label>
            </div>
          </div>
          <div v-if="moduleEditError" class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-[12px] font-semibold text-rose-700">
            {{ moduleEditError }}
          </div>
          <div class="flex gap-2 mt-5">
            <button @click="editingModule = null" class="flex-1 text-[13px] border border-slate-200 rounded-lg py-2 hover:bg-slate-50 transition-colors text-slate-700 font-medium">Cancel</button>
            <button @click="saveModuleEdit" class="flex-1 bg-slate-950 text-white text-[13px] font-semibold rounded-lg py-2 hover:bg-black transition-colors">
              Save changes
            </button>
          </div>
        </div>
      </div>

      <!-- Add employee to module modal -->
      <div v-if="showAddModuleEmployee && activeModule" class="fixed inset-0 z-50 bg-black/40 backdrop-blur-[2px] flex items-center justify-center px-4 py-4 overflow-y-auto" @click.self="showAddModuleEmployee = false">
        <div class="bg-white rounded-xl p-6 w-full max-w-md border border-slate-200 shadow-2xl max-h-[calc(100vh-2rem)] overflow-y-auto">
          <div class="font-semibold text-[15px] text-slate-900 mb-1">Add employee to module</div>
          <div class="text-sm text-slate-600 mb-4">Only employees assigned to {{ activeModule.name }} can see and access its tickets.</div>

          <div v-if="availableModuleEmployees.length" class="space-y-3 max-h-80 overflow-y-auto mb-4">
            <button
              v-for="employee in availableModuleEmployees"
              :key="employee.id"
              @click="selectedModuleEmployeeId = employee.id"
              :class="{
                'ring-2 ring-emerald-500 border-emerald-500': selectedModuleEmployeeId === employee.id,
                'border-slate-200 hover:border-slate-300': selectedModuleEmployeeId !== employee.id
              }"
              class="w-full flex items-center gap-3 p-3 rounded-lg border transition-all text-left"
            >
              <div class="w-8 h-8 rounded-full bg-emerald-100 text-emerald-700 flex items-center justify-center text-xs font-semibold flex-shrink-0">
                {{ employee.name.split(' ').map(name => name[0]).join('') }}
              </div>
              <div>
                <div class="text-sm font-medium text-slate-900">{{ employee.name }}</div>
                <div class="text-xs text-slate-600">{{ employee.department }}</div>
              </div>
            </button>
          </div>
          <div v-else class="rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600 mb-4">
            All project-level employees are already assigned to this module. Add someone to the project category first if needed.
          </div>

          <div class="flex gap-2">
            <button @click="showAddModuleEmployee = false" class="flex-1 text-[13px] border border-slate-200 rounded-lg py-2 hover:bg-slate-50 transition-colors text-slate-700 font-medium">Cancel</button>
            <button
              @click="addEmployeeToModule"
              :disabled="!selectedModuleEmployeeId"
              class="flex-1 bg-emerald-600 text-white text-[13px] font-medium rounded-lg py-2 hover:bg-emerald-700 disabled:bg-slate-300 disabled:cursor-not-allowed transition-colors"
            >
              Add to module
            </button>
          </div>
        </div>
      </div>

      <!-- Add employee modal -->
      <div v-if="showAddEmployee" class="fixed inset-0 z-50 bg-black/40 backdrop-blur-[2px] flex items-center justify-center px-4 py-4 overflow-y-auto" @click.self="showAddEmployee = false">
        <div class="bg-white rounded-xl p-6 w-full max-w-md border border-slate-200 shadow-2xl max-h-[calc(100vh-2rem)] overflow-y-auto">
          <div class="font-semibold text-[15px] text-slate-900 mb-1">Add employee to project</div>
          <div class="text-sm text-slate-600 mb-4">Select an employee to add to {{ project.name }}.</div>

          <div v-if="availableProjectEmployees.length" class="space-y-3 max-h-80 overflow-y-auto mb-4">
            <button
              v-for="employee in availableProjectEmployees"
              :key="employee.id"
              @click="selectedEmployeeId = employee.id"
              :class="{
                'ring-2 ring-emerald-500 border-emerald-500': selectedEmployeeId === employee.id,
                'border-slate-200 hover:border-slate-300': selectedEmployeeId !== employee.id
              }"
              class="w-full flex items-center gap-3 p-3 rounded-lg border transition-all text-left"
            >
              <div class="w-8 h-8 rounded-full bg-emerald-100 text-emerald-700 flex items-center justify-center text-xs font-semibold flex-shrink-0">
                {{ employee.name.split(' ').map(name => name[0]).join('') }}
              </div>
              <div>
                <div class="text-sm font-medium text-slate-900">{{ employee.name }}</div>
                <div class="text-xs text-slate-600">{{ employee.department }}</div>
              </div>
            </button>
          </div>
          <div v-else class="rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600 mb-4">
            All employees are already added to this project.
          </div>

          <div class="flex gap-2">
            <button @click="showAddEmployee = false" class="flex-1 text-[13px] border border-slate-200 rounded-lg py-2 hover:bg-slate-50 transition-colors text-slate-700 font-medium">Cancel</button>
            <button
              @click="addEmployeeToProject"
              :disabled="!selectedEmployeeId"
              class="flex-1 bg-emerald-600 text-white text-[13px] font-medium rounded-lg py-2 hover:bg-emerald-700 disabled:bg-slate-300 disabled:cursor-not-allowed transition-colors"
            >
              Add employee
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
  <div v-else-if="isHR" class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
    <div class="text-lg font-semibold text-slate-900">Tickets are not available for HR</div>
    <div class="text-sm text-slate-600 mt-1">Use the HR dashboard for attendance, claims, leaves, and payroll requests.</div>
  </div>

  <!-- States that did not exist with mock data, where `project` was always
       present synchronously. -->
  <div v-else-if="isLoading" class="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-600 shadow-sm">
    Loading project…
  </div>

  <div v-else-if="loadError" class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800">
    <strong class="block mb-1">{{ loadError }}</strong>
    <button type="button" class="font-semibold underline" @click="loadData">Try again</button>
  </div>

  <!-- The API returns 404 both for a project that does not exist and one the
       user may not see, so this wording must cover both without implying the
       project is real. -->
  <div v-else class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
    <div class="text-lg font-semibold text-slate-900">Project not found</div>
    <div class="text-sm text-slate-600 mt-1">It may have been removed, or you may not have access to it.</div>
    <button
      type="button"
      class="mt-4 text-sm font-semibold text-emerald-700 hover:text-emerald-800"
      @click="router.push({ name: 'projects' })"
    >
      Back to project categories
    </button>
  </div>
</template>
