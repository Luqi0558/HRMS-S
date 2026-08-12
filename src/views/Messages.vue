<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useStore } from '../store/index.js'
import { messageService } from '../services/messageService.js'

const {
  state, can, formatDateTime, formatTime: formatCompanyTime,
  logActivity, pushNotification, setMessages, markMessageReadLocally,
  addOptimisticMessage, confirmOptimisticMessage, failOptimisticMessage, removeMessage,
  prependMessages,
  presenceFor
} = useStore()

// Who this user may write to, as the API resolves it. Not derived from
// state.employees: an employee cannot read the roster, and the contacts they
// may message are a much smaller set than "everyone".
const contacts = ref([])

const loadError = ref('')
const sending = ref(false)
// True until the first fetch settles. Without it the page renders its empty
// state while the request is in flight — "No conversations yet" is a claim,
// and it is the wrong one for the two seconds before the data arrives.
const loading = ref(true)
// Paging backwards through history. The API pages on a keyset (the oldest id
// held) rather than an offset, which would shift under us every time a new
// message arrived.
const hasMore = ref(false)
const oldestId = ref(0)
const loadingOlder = ref(false)
// The scroll container, so a new message can bring itself into view.
const threadRef = ref(null)
// The first message not yet read when a conversation was opened. Frozen at
// that moment so the "new messages" line does not jump as they are marked
// read a fraction of a second later.
const unreadMarkerId = ref(null)

/**
 * The inbox for the first paint. Anything that arrives afterwards comes
 * through the sync poll running in App.vue, so this is a one-off load rather
 * than something that needs refreshing.
 */
async function loadMessages() {
  try {
    const { messages, contacts: reachable, hasMore: more, oldestId: oldest } = await messageService.list()
    setMessages(messages)
    contacts.value = reachable
    hasMore.value = more
    oldestId.value = oldest
  } catch (error) {
    loadError.value = error?.message || 'Unable to load messages.'
  } finally {
    loading.value = false
  }
}

/** Fetches the page of history before what is already on screen. */
async function loadOlder() {
  if (loadingOlder.value || !hasMore.value) return

  loadingOlder.value = true
  const thread = threadRef.value
  const heightBefore = thread?.scrollHeight ?? 0

  try {
    const { messages, hasMore: more, oldestId: oldest } = await messageService.list(oldestId.value)
    prependMessages(messages)
    hasMore.value = more
    if (oldest) oldestId.value = oldest

    // Hold the reading position: without this, inserting older messages above
    // yanks the thread and the user loses their place.
    await nextTick()
    if (thread) thread.scrollTop = thread.scrollHeight - heightBefore
  } catch (error) {
    loadError.value = error?.message || 'Unable to load older messages.'
  } finally {
    loadingOlder.value = false
  }
}

/**
 * The audiences this user may broadcast to.
 *
 * allSettled-style: a failure leaves the list empty, which hides the broadcast
 * button rather than offering one that cannot work.
 */
async function loadAudiences() {
  try {
    broadcastOptions.value = await messageService.audiences()
  } catch {
    broadcastOptions.value = []
    return
  }

  // Default to the first audience the API offered rather than a hardcoded id,
  // which may not be one this user actually has.
  if (!broadcastOptions.value.some(option => option.id === broadcastAudience.value)) {
    broadcastAudience.value = broadcastOptions.value[0]?.id ?? ''
  }
}

onMounted(() => {
  loadMessages()
  loadAudiences()
})

const messageMode = ref('direct')
const selectedRecipientId = ref('')
const broadcastAudience = ref('employees')
const messageText = ref('')
const selectedConversationKey = ref('')

// Messages reference the employees table — senderId and recipientIds are
// employee ids — while currentUser.id is the user id. They coincide only when
// the two tables were filled in step.
const currentUserId = computed(() => state.currentUser.employeeId ?? state.currentUser.id)

// People the current user manages: their own projects' members, plus the
// members of any module they lead. Covers managers and team leads alike.
const myTeamIds = computed(() => {
  const ids = new Set()
  state.projects.forEach(project => {
    if (project.managerId === state.currentUser.id) {
      ;(project.teamMembers || []).forEach(id => ids.add(id))
      ;(project.modules || []).forEach(module => (module.teamMembers || []).forEach(id => ids.add(id)))
    }
    ;(project.modules || []).forEach(module => {
      if (module.leadId === state.currentUser.id) {
        ;(module.teamMembers || []).forEach(id => ids.add(id))
      }
    })
  })
  ids.delete(state.currentUser.id)
  return Array.from(ids)
})

// Who this user may DM, driven by their Messages scope rather than a chain of
// role checks. Previously any role that was not manager or employee fell
// through to "everyone" — a team lead could message the entire company,
// including super admin, despite holding scope 'owned'.
// Who this user may DM. The API resolves this — it knows the project and
// module membership that decides it, and it is the same list the send
// endpoint enforces, so the dropdown cannot offer someone the API will refuse.
//
// Previously computed here from state.projects, which this page never loads:
// the list was empty or wrong depending on what another page had fetched.
const directRecipients = computed(() => contacts.value)

// Broadcasting is company-wide messaging: the 'all' scope on Messages.
// Non-empty only for someone the API will accept a broadcast from, so the
// button appears exactly when it will work.
const canBroadcast = computed(() => broadcastOptions.value.length > 0)

/**
 * Audiences from the API, each with a live recipient count.
 *
 * Both halves of this used to be hardcoded here — which roles may broadcast,
 * and which audiences they may pick — and both drifted from the server the
 * moment the qa and intern roles were added. The API is now the only place
 * that knows, so a new role cannot be missed by this file.
 */
const broadcastOptions = ref([])

const relatedMessages = computed(() =>
  state.messages
    .filter(message => message.senderId === currentUserId.value || message.recipientIds.includes(currentUserId.value))
    .sort((a, b) => new Date(a.createdAt).getTime() - new Date(b.createdAt).getTime())
)

const conversations = computed(() => {
  const map = new Map()

  relatedMessages.value.forEach(message => {
    const key = conversationKey(message)
    const existing = map.get(key)
    const unread = message.recipientIds.includes(currentUserId.value) && !message.readBy?.includes(currentUserId.value)

    if (!existing) {
      map.set(key, {
        key,
        type: isBroadcastMessage(message) ? 'broadcast' : 'direct',
        title: conversationTitle(message),
        subtitle: conversationSubtitle(message),
        avatar: conversationAvatar(message),
        messages: [message],
        lastMessage: message,
        unread: unread ? 1 : 0
      })
      return
    }

    existing.messages.push(message)
    existing.lastMessage = message
    if (unread) existing.unread += 1
  })

  return Array.from(map.values()).sort((a, b) => new Date(b.lastMessage.createdAt).getTime() - new Date(a.lastMessage.createdAt).getTime())
})

const selectedConversation = computed(() => {
  const existing = conversations.value.find(conversation => conversation.key === selectedConversationKey.value)
  if (existing) return existing
  if (selectedConversationKey.value.startsWith('direct:')) return directConversationStub(selectedConversationKey.value)
  if (messageMode.value === 'broadcast') return null
  return conversations.value[0] || null
})

const selectedMessages = computed(() => selectedConversation.value?.messages || [])

/**
 * The thread as it is rendered: messages, with a date heading whenever the day
 * changes and a "new messages" line at the first thing unread when this
 * conversation was opened.
 */
const threadItems = computed(() => {
  const items = []
  let lastDay = null
  let markerPlaced = false

  selectedMessages.value.forEach(message => {
    const day = (message.createdAt || '').slice(0, 10)

    if (day && day !== lastDay) {
      items.push({ kind: 'day', key: `day-${day}`, label: dayLabel(message.createdAt) })
      lastDay = day
    }

    if (!markerPlaced && unreadMarkerId.value && message.id === unreadMarkerId.value) {
      items.push({ kind: 'unread', key: 'unread-marker' })
      markerPlaced = true
    }

    items.push({ kind: 'message', key: message.id, message })
  })

  return items
})

/** "Today" / "Yesterday" / "Monday" — a full date on every line is noise. */
function dayLabel(value) {
  if (!value) return ''

  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return ''

  const today = new Date()
  const startOf = d => new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime()
  const days = Math.round((startOf(today) - startOf(date)) / 86400000)

  if (days === 0) return 'Today'
  if (days === 1) return 'Yesterday'
  if (days > 1 && days < 7) return date.toLocaleDateString(undefined, { weekday: 'long' })

  return date.toLocaleDateString(undefined, {
    day: 'numeric',
    month: 'short',
    year: date.getFullYear() === today.getFullYear() ? undefined : 'numeric'
  })
}

const conversationList = computed(() => {
  const items = [...conversations.value]
  if (selectedConversation.value && !items.some(item => item.key === selectedConversation.value.key)) {
    items.unshift(selectedConversation.value)
  }
  return items
})

const unreadCount = computed(() => conversations.value.reduce((total, conversation) => total + conversation.unread, 0))

/**
 * Who a message typed in the open thread is addressed to.
 *
 * A broadcast has no single recipient, so this used to return null for one —
 * and sendMessage falls back to the compose dropdown, which defaults to the
 * first contact in the list. Replying to a company announcement therefore sent
 * the reply to whoever happened to be selected there, silently.
 *
 * A broadcast is answered privately to whoever sent it, which is what the key
 * carries: broadcast:<senderId>:<audience>. Null when you sent it yourself,
 * since there is no one person to answer.
 */
const activeRecipientId = computed(() => {
  const conversation = selectedConversation.value
  if (!conversation) return null

  if (conversation.type === 'direct') {
    return Number(conversation.key.replace('direct:', ''))
  }

  const senderId = Number(conversation.key.split(':')[1])
  return senderId && senderId !== currentUserId.value ? senderId : null
})

/** The name a broadcast reply will go to, for the hint under the composer. */
const broadcastReplyTo = computed(() => {
  const conversation = selectedConversation.value
  if (!conversation || conversation.type === 'direct' || !activeRecipientId.value) return null

  // The sender's name off the message itself, falling back to the contact
  // list. Resolving through contacts alone rendered "Unknown user" whenever the
  // broadcaster was not somebody this person may normally write to — which was
  // the visible half of a deeper problem: the reply was not deliverable either.
  const fromMessage = (conversation.messages || [])
    .find(message => message.senderId === activeRecipientId.value)?.senderName

  return fromMessage || employeeName(activeRecipientId.value)
})

watch(conversations, items => {
  if (!items.length) {
    if (!selectedConversationKey.value.startsWith('direct:')) selectedConversationKey.value = ''
    return
  }
  const selectedDirectId = selectedConversationKey.value.startsWith('direct:')
    ? Number(selectedConversationKey.value.replace('direct:', ''))
    : null
  const selectedDirectIsAllowed = selectedDirectId && directRecipients.value.some(employee => employee.id === selectedDirectId)
  if (!items.some(item => item.key === selectedConversationKey.value) && !selectedDirectIsAllowed && messageMode.value !== 'broadcast') {
    selectedConversationKey.value = items[0].key
  }
}, { immediate: true })

watch(directRecipients, recipients => {
  if (!selectedRecipientId.value && recipients[0]) selectedRecipientId.value = String(recipients[0].id)
}, { immediate: true })

// Opening a conversation reads it. Marked locally first so the badge responds
// immediately, then persisted — a failed write is corrected by the next poll
// rather than blocking the click.
watch(selectedConversation, conversation => {
  if (!conversation) return
  conversation.messages.forEach(message => {
    if (message.recipientIds.includes(currentUserId.value) && !message.readBy.includes(currentUserId.value)) {
      message.readBy.push(currentUserId.value)

      if (message.fromServer) {
        markMessageReadLocally(message.id)
        messageService.markRead(message.id).catch(() => {})
      }
    }
  })
}, { immediate: true })

/** A broadcast is defined by its audience, not by how many rows it fanned to. */
function isBroadcastMessage(message) {
  if (message.audience && message.audience !== 'Direct message') return true

  return (message.recipientIds || []).length > 1
}

/**
 * Which conversation a message belongs to.
 *
 * Decided by audience, not by counting recipients. A broadcast's optimistic
 * row has no recipients yet — the API resolves them — so counting produced the
 * key `direct:undefined` and filed a company-wide announcement under a
 * conversation with nobody.
 */
function conversationKey(message) {
  const isBroadcast = message.audience && message.audience !== 'Direct message'
  if (isBroadcast || (message.recipientIds || []).length > 1) {
    return `broadcast:${message.senderId}:${message.audience}`
  }

  const otherId = message.senderId === currentUserId.value
    ? (message.recipientIds || [])[0]
    : message.senderId

  return `direct:${otherId}`
}

function conversationTitle(message) {
  if (isBroadcastMessage(message)) return message.audience
  const otherId = message.senderId === currentUserId.value ? message.recipientIds[0] : message.senderId
  return employeeName(otherId)
}

function conversationSubtitle(message) {
  if (isBroadcastMessage(message)) return `${message.senderName} broadcast`
  const otherId = message.senderId === currentUserId.value ? message.recipientIds[0] : message.senderId
  const person = personFor(otherId)
  return person?.role ? person.role.replace('_', ' ') : 'Direct message'
}

function conversationAvatar(message) {
  return initials(conversationTitle(message))
}

/** The other person in a direct conversation. */
function conversationPartnerId(conversation) {
  if (!conversation?.key?.startsWith('direct:')) return null

  return Number(conversation.key.replace('direct:', ''))
}

const PRESENCE_DOTS = {
  active: 'bg-emerald-500',
  away: 'bg-amber-400',
  offline: 'bg-slate-300'
}

function presenceDotClass(employeeId) {
  if (!employeeId) return PRESENCE_DOTS.offline

  return PRESENCE_DOTS[presenceFor(employeeId).status] || PRESENCE_DOTS.offline
}

/** Hover text: the status, plus their note if they left one. */
function presenceLabel(employeeId) {
  if (!employeeId) return ''

  const { status, message } = presenceFor(employeeId)
  const label = { active: 'Active', away: 'Away', offline: 'Offline' }[status] || 'Offline'

  return message ? `${label} — ${message}` : label
}

/** A contact, falling back to the roster on pages that have it loaded. */
function personFor(id) {
  return contacts.value.find(person => person.id === Number(id))
    || state.employees.find(employee => employee.id === Number(id))
    || null
}

function directConversationStub(key) {
  const employeeId = Number(key.replace('direct:', ''))
  const employee = personFor(employeeId)
  if (!employee) return null
  return {
    key,
    type: 'direct',
    title: employee.name,
    subtitle: (employee.role || 'direct message').replace('_', ' '),
    avatar: initials(employee.name),
    messages: [],
    lastMessage: null,
    unread: 0
  }
}

/**
 * A person's name.
 *
 * Contacts first — that list is always loaded here, while state.employees is
 * only populated on pages allowed to read the roster. Messages carry
 * senderName themselves, so this is only needed for compose targets.
 */
function employeeName(id) {
  return personFor(id)?.name || 'Unknown user'
}

function initials(name) {
  return name.split(' ').map(part => part[0]).join('').slice(0, 2).toUpperCase()
}

// Date + time stamp for the conversation list.
function formatTime(value) {
  return formatDateTime(value)
}

function shortTime(value) {
  return formatCompanyTime(value)
}

/**
 * Deliberately empty: the API resolves a broadcast's audience to its roles
 * itself and ignores whatever ids the client sends. Deriving them here would
 * be a second, weaker answer to a question the server already decides — and
 * it needs the roster, which an employee cannot read.
 */
function recipientIdsForBroadcast() {
  return []
}

function audienceLabel() {
  const option = broadcastOptions.value.find(item => item.id === broadcastAudience.value)
  if (!option) return 'Broadcast'

  // The count is the safeguard: a wrong audience shows itself before sending,
  // rather than after nobody answers.
  return `${option.label} (${option.reaches} ${option.reaches === 1 ? 'person' : 'people'})`
}

function openDirectConversation(employeeId) {
  selectedConversationKey.value = `direct:${employeeId}`
  messageMode.value = 'direct'
  selectedRecipientId.value = String(employeeId)
}

/**
 * Builds the payload from what is on screen, then hands it to deliver().
 *
 * The split matters: deliver() takes an explicit payload, so a retry replays
 * exactly what was originally sent. Re-deriving it from component state was a
 * real defect — sending reset messageMode to 'direct' before awaiting, so
 * retrying a failed BROADCAST read the reset mode and delivered a company-wide
 * announcement as a private message to whoever was selected in the dropdown.
 */
async function sendMessage() {
  const body = messageText.value.trim()
  if (!body) return

  let payload

  if (messageMode.value === 'broadcast') {
    payload = {
      body,
      subject: 'Team announcement',
      // The id, not the label: the API resolves the audience to its roles
      // itself, which is also why recipientIds stays empty by design.
      audience: broadcastAudience.value,
      recipientIds: []
    }
  } else {
    // The open thread decides the recipient; the compose dropdown is only
    // consulted when no thread is open. Falling back to the dropdown while a
    // conversation was on screen is what sent broadcast replies to whoever it
    // happened to be defaulted to.
    const recipientId = selectedConversation.value
      ? activeRecipientId.value
      : Number(selectedRecipientId.value)

    if (!recipientId || recipientId === state.currentUser.id) return

    payload = {
      body,
      subject: 'Direct message',
      audience: 'Direct message',
      recipientIds: [recipientId]
    }
  }

  // Cleared immediately so the next message can be typed while this one is in
  // flight. The text survives on the optimistic row, so a failure is retryable.
  messageText.value = ''
  saveDraft('')

  await deliver(payload)
}

/**
 * Sends one payload and reconciles the optimistic row with the result.
 *
 * The payload is stored on the row so a retry replays it verbatim rather than
 * rebuilding it from whatever the UI happens to look like by then.
 */
async function deliver(payload) {
  const tempId = `pending:${Date.now()}:${Math.random().toString(36).slice(2, 8)}`

  // The button renders "Sending..." off this and it was never assigned, so the
  // state was unreachable and a double-tap could send twice.
  sending.value = true

  addOptimisticMessage({ tempId, ...payload })
  loadError.value = ''
  selectedConversationKey.value = conversationKey(state.messages[0])

  const wasBroadcast = payload.audience !== 'Direct message'
  // Reset only after the payload is safely captured on the row.
  messageMode.value = 'direct'

  try {
    const sent = await messageService.send(payload)
    confirmOptimisticMessage(tempId, sent)

    logActivity({
      module: 'messages',
      action: wasBroadcast ? 'broadcast sent' : 'direct message sent',
      details: `${state.currentUser.name} sent a message to ${wasBroadcast ? payload.audience : employeeName(payload.recipientIds[0])}.`
    })
  } catch (error) {
    // The text stays on screen marked failed rather than disappearing —
    // losing what someone typed is worse than showing it did not send.
    failOptimisticMessage(tempId)
    loadError.value = error?.message || 'Unable to send that message.'
  } finally {
    sending.value = false
  }
}

/**
 * Whether everyone it was sent to has read it.
 *
 * A broadcast to twelve people shows read only once all twelve have opened
 * it — a double tick that appeared after the first would misrepresent what
 * the sender actually knows.
 */
function messageIsRead(message) {
  const delivery = message.delivery
  if (!delivery || !delivery.total) return false

  return delivery.read >= delivery.total
}

/**
 * Keeps the newest message in view.
 *
 * Without this a message arriving while you are at the bottom renders below
 * the fold and simply is not seen — the single most jarring thing about the
 * thread before. Only auto-scrolls when you are already near the bottom: if
 * you have scrolled up to read history, yanking you back down is worse.
 */
const NEAR_BOTTOM = 120

function isNearBottom() {
  const el = threadRef.value
  if (!el) return true

  return el.scrollHeight - el.scrollTop - el.clientHeight < NEAR_BOTTOM
}

async function scrollToNewest(force = false) {
  const shouldStick = force || isNearBottom()
  await nextTick()

  const el = threadRef.value
  if (el && shouldStick) el.scrollTop = el.scrollHeight
}

// A new message in the open thread.
watch(() => selectedMessages.value.length, (now, before) => {
  if (now > (before ?? 0)) scrollToNewest()
})

// Opening a different conversation always starts at the newest, and freezes
// the "new messages" line where it was at that moment — the messages are
// marked read a fraction of a second later, so reading unread state after
// that would never show a divider at all.
watch(selectedConversationKey, () => {
  const firstUnread = selectedMessages.value.find(
    message => message.senderId !== currentUserId.value && !(message.readBy || []).includes(currentUserId.value)
  )
  unreadMarkerId.value = firstUnread?.id ?? null

  restoreDraft()
  scrollToNewest(true)
}, { immediate: true })

// Scroll to the bottom once the first load lands.
watch(loading, isLoading => {
  if (!isLoading) scrollToNewest(true)
})

/**
 * Drafts, kept per conversation.
 *
 * Typing half a message, navigating away and losing it is a small thing that
 * feels careless. Held in localStorage so it survives a refresh too.
 */
const DRAFT_PREFIX = 'orbit-draft:'

function draftKey() {
  return `${DRAFT_PREFIX}${state.currentUser.id}:${selectedConversationKey.value || 'none'}`
}

function restoreDraft() {
  try {
    messageText.value = window.localStorage.getItem(draftKey()) || ''
  } catch {
    messageText.value = ''
  }
}

function saveDraft(value) {
  try {
    if (value) window.localStorage.setItem(draftKey(), value)
    else window.localStorage.removeItem(draftKey())
  } catch {
    /* storage unavailable — the draft simply will not survive a reload */
  }
}

watch(messageText, value => saveDraft(value))

/**
 * Enter sends, Shift+Enter starts a new line.
 *
 * Reaching for the mouse after every sentence is what made this feel like a
 * form rather than a conversation.
 */
function onComposerKeydown(event) {
  if (event.key !== 'Enter' || event.shiftKey) return
  // Leave IME composition alone: in a language that composes characters,
  // Enter confirms the candidate rather than sending.
  if (event.isComposing) return

  event.preventDefault()
  sendMessage()
}

/** Resends a failed message with its ORIGINAL audience and recipients. */
async function retryMessage(message) {
  const payload = message.payload
  if (!payload) return

  removeMessage(message.id)
  await deliver(payload)
}

</script>

<template>
  <div class="space-y-4">
    <!-- The page title lives in the topbar; only the counts stay here. -->
    <div class="flex items-center justify-between gap-3">
      <div class="text-sm text-slate-600">Direct messages and admin broadcasts.</div>
      <div class="flex flex-wrap items-center gap-2 flex-shrink-0">
        <span class="rounded-full bg-white border border-slate-200 px-3 py-1.5 text-xs font-bold text-slate-700">{{ conversationList.length }} chats</span>
        <span v-if="unreadCount" class="rounded-full bg-emerald-100 px-3 py-1.5 text-xs font-bold text-emerald-700">{{ unreadCount }} unread</span>
      </div>
    </div>

    <!-- Sized to the viewport rather than a fixed height, so the compose bar
         always stays on screen. The chat column is a little wider now that the
         redundant panel header is gone — message previews truncate, so the
         extra width shows more of each one. -->
    <section class="grid grid-cols-1 xl:grid-cols-[380px_1fr] gap-4 h-[calc(100vh-10.5rem)] min-h-[420px]">
      <div class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm h-full min-h-0 flex flex-col">
        <!-- The "Chats" heading duplicated the page title above and cost the
             list ~52px of height; the count moved into the page header. -->
        <div class="px-4 py-3 border-b border-slate-200 flex-shrink-0">
          <label class="block">
          <span class="text-xs font-bold uppercase tracking-wide text-slate-500 block mb-1.5">Start direct chat</span>
          <select
            v-model="selectedRecipientId"
            @change="openDirectConversation(selectedRecipientId)"
            class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500"
          >
            <option v-for="employee in directRecipients" :key="employee.id" :value="employee.id">
              {{ employee.name }} - {{ employee.role }}
            </option>
          </select>
          </label>
        </div>

        <div v-if="canBroadcast" class="px-4 py-3 border-b border-slate-200 flex-shrink-0">
          <div class="grid grid-cols-[1fr_auto] gap-2">
            <select v-model="broadcastAudience" class="rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500">
              <option v-for="option in broadcastOptions" :key="option.id" :value="option.id">{{ option.label }} ({{ option.reaches }})</option>
            </select>
            <button @click="messageMode = 'broadcast'; selectedConversationKey = ''" class="rounded-lg bg-slate-950 px-3 py-2 text-sm font-bold text-white">
              Broadcast
            </button>
          </div>
        </div>

        <div class="flex-1 min-h-0 overflow-y-auto divide-y divide-slate-200">
          <button
            v-for="conversation in (loading ? [] : conversationList)"
            :key="conversation.key"
            @click="selectedConversationKey = conversation.key; messageMode = 'direct'"
            :class="selectedConversation?.key === conversation.key ? 'bg-slate-50/80' : 'hover:bg-slate-50/60'"
            class="w-full px-4 py-4 text-left transition-colors flex items-start gap-3"
          >
            <div class="relative shrink-0">
              <div class="w-10 h-10 rounded-2xl bg-slate-950 text-white flex items-center justify-center text-sm font-bold">
                {{ conversation.avatar }}
              </div>
              <!-- Only on direct chats: a broadcast has no single person whose
                   status would mean anything. -->
              <span
                v-if="conversation.type === 'direct'"
                class="absolute -bottom-0.5 -right-0.5 h-3.5 w-3.5 rounded-full border-2 border-white"
                :class="presenceDotClass(conversationPartnerId(conversation))"
                :title="presenceLabel(conversationPartnerId(conversation))"
              ></span>
            </div>
            <div class="min-w-0 flex-1">
              <div class="flex items-center gap-2">
                <div class="text-sm font-bold text-slate-900 truncate">{{ conversation.title }}</div>
                <span v-if="conversation.type === 'broadcast'" class="rounded-full bg-blue-100 px-2 py-0.5 text-[10px] font-bold text-blue-700">Broadcast</span>
              </div>
              <div class="text-xs text-slate-500 capitalize mt-1">{{ conversation.subtitle }}</div>
              <div class="text-xs text-slate-600 truncate mt-1">{{ conversation.lastMessage?.body || 'No messages yet' }}</div>
            </div>
            <div class="text-right shrink-0">
              <div class="text-[11px] text-slate-400 whitespace-nowrap">{{ conversation.lastMessage ? shortTime(conversation.lastMessage.createdAt) : 'New' }}</div>
              <div v-if="conversation.unread" class="mt-2 ml-auto w-fit rounded-full bg-emerald-600 px-2 py-0.5 text-[11px] font-bold text-white">{{ conversation.unread }}</div>
            </div>
          </button>
          <!-- Skeletons rather than a message: the list is unknown, not known
               to be empty. -->
          <div v-if="loading" class="px-4 py-4 space-y-3">
            <div v-for="n in 3" :key="n" class="flex items-center gap-3 animate-pulse">
              <div class="h-10 w-10 rounded-full bg-slate-200 shrink-0"></div>
              <div class="flex-1 space-y-2">
                <div class="h-3 w-1/2 rounded bg-slate-200"></div>
                <div class="h-3 w-3/4 rounded bg-slate-100"></div>
              </div>
            </div>
          </div>
          <div v-else-if="conversationList.length === 0" class="px-4 py-10 text-center text-sm text-slate-500">No conversations yet.</div>
        </div>
      </div>

      <div class="bg-white border border-slate-200 rounded-xl shadow-sm h-full min-h-0 flex flex-col overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-200 flex items-center justify-between gap-3 flex-shrink-0">
          <div v-if="messageMode === 'broadcast' && !selectedConversation" class="min-w-0">
            <div class="text-lg font-bold text-slate-900">New broadcast</div>
            <div class="text-xs text-slate-500 mt-1">To {{ audienceLabel() }}</div>
          </div>
          <div v-else-if="selectedConversation" class="min-w-0">
            <div class="flex items-center gap-2">
              <div class="text-lg font-bold text-slate-900 truncate">{{ selectedConversation.title }}</div>
              <span v-if="selectedConversation.type === 'broadcast'" class="rounded-full bg-blue-100 px-3 py-1 text-xs font-bold text-blue-700">Broadcast</span>
            </div>
            <div class="text-xs text-slate-500 mt-1 flex items-center gap-1.5">
              <template v-if="selectedConversation.type === 'direct'">
                <span class="h-2 w-2 rounded-full" :class="presenceDotClass(conversationPartnerId(selectedConversation))"></span>
                <span>{{ presenceLabel(conversationPartnerId(selectedConversation)) }}</span>
              </template>
              <span v-else class="capitalize">{{ selectedConversation.subtitle }}</span>
            </div>
          </div>
          <div v-else-if="loading" class="min-w-0 animate-pulse">
            <div class="h-5 w-40 rounded bg-slate-200"></div>
            <div class="mt-2 h-3 w-56 rounded bg-slate-100"></div>
          </div>
          <div v-else>
            <div class="text-lg font-bold text-slate-900">Select a chat</div>
            <div class="text-xs text-slate-500 mt-1">Choose a conversation or start a direct chat.</div>
          </div>
        </div>

        <div ref="threadRef" class="flex-1 min-h-0 overflow-y-auto p-5 space-y-4 bg-slate-50/40">
          <template v-if="!loading && threadItems.length">
            <!-- Older history, on demand. The 100 most recent load first, so a
                 conversation is not capped at whatever fits in one response. -->
            <div v-if="hasMore" class="flex justify-center pb-2">
              <button
                @click="loadOlder"
                :disabled="loadingOlder"
                class="rounded-full border border-slate-200 bg-white px-3 py-1 text-[11px] font-semibold text-slate-600 hover:bg-slate-50 disabled:opacity-60"
              >
                {{ loadingOlder ? 'Loading...' : 'Load older messages' }}
              </button>
            </div>

            <template v-for="item in threadItems" :key="item.key">
              <!-- Date heading, so a long thread reads as days rather than a
                   wall of identical timestamps. -->
              <div v-if="item.kind === 'day'" class="flex justify-center py-1">
                <span class="rounded-full bg-slate-200/70 px-3 py-1 text-[10px] font-bold uppercase tracking-wide text-slate-600">
                  {{ item.label }}
                </span>
              </div>

              <!-- Where you left off. -->
              <div v-else-if="item.kind === 'unread'" class="flex items-center gap-3 py-1">
                <div class="h-px flex-1 bg-rose-300"></div>
                <span class="text-[10px] font-bold uppercase tracking-wide text-rose-500">New messages</span>
                <div class="h-px flex-1 bg-rose-300"></div>
              </div>

              <div
                v-else
                class="flex"
                :class="item.message.senderId === currentUserId ? 'justify-end' : 'justify-start'"
              >
                <div
                  class="max-w-[78%] rounded-2xl px-4 py-3 shadow-sm"
                  :class="item.message.senderId === currentUserId ? 'bg-slate-950 text-white' : 'bg-white border border-slate-200 text-slate-800'"
                >
                  <div v-if="item.message.senderId !== currentUserId" class="text-[11px] font-bold mb-1 text-slate-500">{{ item.message.senderName }}</div>
                  <div class="text-sm leading-relaxed whitespace-pre-line">{{ item.message.body }}</div>
                  <div class="mt-2 flex items-center gap-1.5 text-[10px]" :class="item.message.senderId === currentUserId ? 'text-white/60' : 'text-slate-400'">
                    <span>{{ formatTime(item.message.createdAt) }}</span>
                  <!-- Ticks only on your own messages: whether you have read
                       something you received is not information you need. -->
                  <template v-if="item.message.senderId === currentUserId">
                    <span v-if="item.message.status === 'sending'" class="opacity-70" title="Sending">○</span>
                    <button
                      v-else-if="item.message.status === 'failed'"
                      @click="retryMessage(item.message)"
                      class="text-rose-300 hover:text-rose-200 font-bold"
                      title="Not sent — click to retry"
                    >! retry</button>
                    <span
                      v-else
                      :class="messageIsRead(item.message) ? 'text-sky-300' : 'opacity-70'"
                      :title="messageIsRead(item.message) ? 'Read' : 'Delivered'"
                    >{{ messageIsRead(item.message) ? '✓✓' : '✓' }}</span>
                  </template>
                  </div>
                </div>
              </div>
            </template>
          </template>
          <div v-else-if="loading" class="space-y-4">
            <div v-for="n in 3" :key="n" class="flex animate-pulse" :class="n % 2 ? 'justify-start' : 'justify-end'">
              <div class="h-14 w-1/2 rounded-2xl" :class="n % 2 ? 'bg-white border border-slate-200' : 'bg-slate-200'"></div>
            </div>
          </div>
          <div v-else class="h-full min-h-[360px] flex items-center justify-center text-sm text-slate-500">
            No messages in this conversation yet.
          </div>
        </div>

        <div class="border-t border-slate-200 p-4 flex-shrink-0">
          <div v-if="messageMode === 'broadcast' && canBroadcast" class="mb-3 text-xs font-bold text-blue-700">
            Broadcast will be sent to {{ audienceLabel() }}.
          </div>
          <div class="grid grid-cols-[1fr_auto] gap-3">
            <textarea
              @keydown="onComposerKeydown"
              v-model="messageText"
              placeholder="Type a message..."
              class="rounded-xl border border-slate-200 px-3 py-3 text-sm min-h-12 max-h-32 focus:outline-none focus:ring-1 focus:ring-emerald-500"
            ></textarea>
            <button @click="sendMessage" :disabled="sending" class="self-end rounded-xl bg-emerald-600 px-5 py-3 text-sm font-bold text-white hover:bg-emerald-700 transition-colors disabled:opacity-60 disabled:cursor-not-allowed">
              {{ sending ? 'Sending...' : 'Send' }}
            </button>
          </div>
          <!-- An announcement cannot be answered to everyone who received it, so
               the reply is a direct message to whoever sent it. Said out loud
               because the answer then appears in a different thread. -->
          <div v-if="broadcastReplyTo" class="px-1 pb-1 text-[11px] text-slate-500">
            Your reply goes privately to <span class="font-semibold text-slate-700">{{ broadcastReplyTo }}</span>.
          </div>
          <div v-if="loadError" class="mt-2 text-xs text-rose-600">{{ loadError }}</div>
        </div>
      </div>
    </section>
  </div>
</template>
