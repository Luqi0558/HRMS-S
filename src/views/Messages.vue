<script setup>
import { computed, ref, watch } from 'vue'
import { useStore } from '../store/index.js'

const { state, can, permissionRow, formatDateTime, formatTime: formatCompanyTime, logActivity, pushNotification } = useStore()

const messageMode = ref('direct')
const selectedRecipientId = ref('')
const broadcastAudience = ref('employees')
const messageText = ref('')
const selectedConversationKey = ref('')

const currentUserId = computed(() => state.currentUser.id)

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
const directRecipients = computed(() => {
  const scope = permissionRow('Messages')?.scope
  const others = state.employees.filter(employee => employee.id !== state.currentUser.id)

  if (scope === 'all') return others

  if (scope === 'owned') {
    // Their team, plus the people they escalate to.
    const teamIds = new Set(myTeamIds.value)
    return others.filter(employee => teamIds.has(employee.id) || employee.role === 'hr')
  }

  // scope 'self' (employees): the managers/leads they report to, plus HR.
  const contactIds = new Set()
  state.projects.forEach(project => {
    const onProject = (project.teamMembers || []).includes(state.currentUser.id)
    const onModule = (project.modules || []).some(module =>
      (module.teamMembers || []).includes(state.currentUser.id)
    )
    if (!onProject && !onModule) return
    if (project.managerId) contactIds.add(project.managerId)
    ;(project.modules || []).forEach(module => {
      if (module.leadId && (module.teamMembers || []).includes(state.currentUser.id)) {
        contactIds.add(module.leadId)
      }
    })
  })
  return others.filter(employee => contactIds.has(employee.id) || employee.role === 'hr')
})

// Broadcasting is company-wide messaging: the 'all' scope on Messages.
const canBroadcast = computed(() => can('Messages', 'create') && permissionRow('Messages')?.scope === 'all')

const broadcastOptions = computed(() => {
  if (state.currentUser.role === 'super_admin') {
    return [
      { id: 'all_company', label: 'All company' },
      { id: 'employees', label: 'All employees' },
      { id: 'managers', label: 'All managers' },
      { id: 'hr', label: 'HR team' }
    ]
  }
  if (state.currentUser.role === 'hr') {
    return [
      { id: 'employees', label: 'All employees' },
      { id: 'managers', label: 'All managers' }
    ]
  }
  return []
})

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
        type: message.recipientIds.length > 1 ? 'broadcast' : 'direct',
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

const conversationList = computed(() => {
  const items = [...conversations.value]
  if (selectedConversation.value && !items.some(item => item.key === selectedConversation.value.key)) {
    items.unshift(selectedConversation.value)
  }
  return items
})

const unreadCount = computed(() => conversations.value.reduce((total, conversation) => total + conversation.unread, 0))

const activeRecipientId = computed(() => {
  if (!selectedConversation.value || selectedConversation.value.type !== 'direct') return null
  return Number(selectedConversation.value.key.replace('direct:', ''))
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

watch(selectedConversation, conversation => {
  if (!conversation) return
  conversation.messages.forEach(message => {
    if (message.recipientIds.includes(currentUserId.value) && !message.readBy.includes(currentUserId.value)) {
      message.readBy.push(currentUserId.value)
    }
  })
}, { immediate: true })

function conversationKey(message) {
  if (message.recipientIds.length > 1) return `broadcast:${message.senderId}:${message.audience}`
  const otherId = message.senderId === currentUserId.value ? message.recipientIds[0] : message.senderId
  return `direct:${otherId}`
}

function conversationTitle(message) {
  if (message.recipientIds.length > 1) return message.audience
  const otherId = message.senderId === currentUserId.value ? message.recipientIds[0] : message.senderId
  return employeeName(otherId)
}

function conversationSubtitle(message) {
  if (message.recipientIds.length > 1) return `${message.senderName} broadcast`
  const otherId = message.senderId === currentUserId.value ? message.recipientIds[0] : message.senderId
  const employee = state.employees.find(item => item.id === Number(otherId))
  return employee?.role ? employee.role.replace('_', ' ') : 'Direct message'
}

function conversationAvatar(message) {
  return initials(conversationTitle(message))
}

function directConversationStub(key) {
  const employeeId = Number(key.replace('direct:', ''))
  const employee = state.employees.find(item => item.id === employeeId)
  if (!employee) return null
  return {
    key,
    type: 'direct',
    title: employee.name,
    subtitle: employee.role.replace('_', ' '),
    avatar: initials(employee.name),
    messages: [],
    lastMessage: null,
    unread: 0
  }
}

function employeeName(id) {
  if (id === 99) return 'Admin User'
  return state.employees.find(employee => employee.id === Number(id))?.name || 'Unknown user'
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

function recipientIdsForBroadcast() {
  if (broadcastAudience.value === 'all_company') {
    return state.employees
      .filter(employee => employee.status === 'active')
      .map(employee => employee.id)
      .filter(id => id !== state.currentUser.id)
  }
  if (broadcastAudience.value === 'employees') {
    return state.employees.filter(employee => employee.role === 'employee' && employee.status === 'active').map(employee => employee.id)
  }
  if (broadcastAudience.value === 'managers') {
    return state.employees.filter(employee => employee.role === 'manager' && employee.status === 'active').map(employee => employee.id)
  }
  if (broadcastAudience.value === 'hr') {
    return state.employees.filter(employee => employee.role === 'hr' && employee.status === 'active').map(employee => employee.id)
  }
  return []
}

function audienceLabel() {
  return broadcastOptions.value.find(option => option.id === broadcastAudience.value)?.label || 'Broadcast'
}

function openDirectConversation(employeeId) {
  selectedConversationKey.value = `direct:${employeeId}`
  messageMode.value = 'direct'
  selectedRecipientId.value = String(employeeId)
}

function sendMessage() {
  const body = messageText.value.trim()
  if (!body) return

  let recipientIds = []
  let audience = 'Direct message'
  let subject = 'Direct message'

  if (messageMode.value === 'broadcast') {
    recipientIds = recipientIdsForBroadcast()
    audience = audienceLabel()
    subject = 'Team announcement'
  } else {
    const recipientId = activeRecipientId.value || Number(selectedRecipientId.value)
    recipientIds = [recipientId].filter(Boolean)
  }

  recipientIds = [...new Set(recipientIds.filter(id => id !== state.currentUser.id))]
  if (!recipientIds.length) return

  const message = {
    id: Date.now() + Math.floor(Math.random() * 1000),
    senderId: state.currentUser.id,
    senderName: state.currentUser.name,
    senderRole: state.currentUser.role,
    recipientIds,
    audience,
    subject,
    body,
    createdAt: new Date().toISOString(),
    readBy: [state.currentUser.id]
  }

  state.messages.unshift(message)

  pushNotification({
    title: message.recipientIds.length > 1 ? audience : state.currentUser.name,
    message: body,
    type: 'messages',
    userIds: recipientIds,
    route: { name: 'messages' }
  })

  logActivity({
    module: 'messages',
    action: message.recipientIds.length > 1 ? 'broadcast sent' : 'direct message sent',
    details: `${state.currentUser.name} sent a message to ${message.recipientIds.length > 1 ? audience : employeeName(message.recipientIds[0])}.`
  })

  selectedConversationKey.value = conversationKey(message)
  messageMode.value = 'direct'
  messageText.value = ''
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
              <option v-for="option in broadcastOptions" :key="option.id" :value="option.id">{{ option.label }}</option>
            </select>
            <button @click="messageMode = 'broadcast'; selectedConversationKey = ''" class="rounded-lg bg-slate-950 px-3 py-2 text-sm font-bold text-white">
              Broadcast
            </button>
          </div>
        </div>

        <div class="flex-1 min-h-0 overflow-y-auto divide-y divide-slate-200">
          <button
            v-for="conversation in conversationList"
            :key="conversation.key"
            @click="selectedConversationKey = conversation.key; messageMode = 'direct'"
            :class="selectedConversation?.key === conversation.key ? 'bg-slate-50/80' : 'hover:bg-slate-50/60'"
            class="w-full px-4 py-4 text-left transition-colors flex items-start gap-3"
          >
            <div class="w-10 h-10 rounded-2xl bg-slate-950 text-white flex items-center justify-center text-sm font-bold shrink-0">
              {{ conversation.avatar }}
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
          <div v-if="conversationList.length === 0" class="px-4 py-10 text-center text-sm text-slate-500">No conversations yet.</div>
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
            <div class="text-xs text-slate-500 mt-1 capitalize">{{ selectedConversation.subtitle }}</div>
          </div>
          <div v-else>
            <div class="text-lg font-bold text-slate-900">Select a chat</div>
            <div class="text-xs text-slate-500 mt-1">Choose a conversation or start a direct chat.</div>
          </div>
        </div>

        <div class="flex-1 min-h-0 overflow-y-auto p-5 space-y-4 bg-slate-50/40">
          <template v-if="selectedMessages.length">
            <div
              v-for="message in selectedMessages"
              :key="message.id"
              class="flex"
              :class="message.senderId === currentUserId ? 'justify-end' : 'justify-start'"
            >
              <div
                class="max-w-[78%] rounded-2xl px-4 py-3 shadow-sm"
                :class="message.senderId === currentUserId ? 'bg-slate-950 text-white' : 'bg-white border border-slate-200 text-slate-800'"
              >
                <div v-if="message.senderId !== currentUserId" class="text-[11px] font-bold mb-1 text-slate-500">{{ message.senderName }}</div>
                <div class="text-sm leading-relaxed whitespace-pre-line">{{ message.body }}</div>
                <div class="mt-2 text-[10px]" :class="message.senderId === currentUserId ? 'text-white/60' : 'text-slate-400'">{{ formatTime(message.createdAt) }}</div>
              </div>
            </div>
          </template>
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
              v-model="messageText"
              placeholder="Type a message..."
              class="rounded-xl border border-slate-200 px-3 py-3 text-sm min-h-12 max-h-32 focus:outline-none focus:ring-1 focus:ring-emerald-500"
            ></textarea>
            <button @click="sendMessage" class="self-end rounded-xl bg-emerald-600 px-5 py-3 text-sm font-bold text-white hover:bg-emerald-700 transition-colors">
              Send
            </button>
          </div>
        </div>
      </div>
    </section>
  </div>
</template>
