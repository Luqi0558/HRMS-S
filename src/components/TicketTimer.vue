<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useStore } from '../store/index.js'

const props = defineProps({
  ticket: { type: Object, required: true },
  readonly: { type: Boolean, default: false }
})
const { state, ticketSeconds, formatDuration, startTicketTimer, stopTicketTimer } = useStore()
const alertMessage = ref('')

// forces a re-render every second so a running timer's elapsed time updates live
const tick = ref(0)
let interval = null
onMounted(() => { interval = setInterval(() => { tick.value++ }, 1000) })
onUnmounted(() => clearInterval(interval))

const isRunning = computed(() => {
  tick.value
  return state.activeTimer?.ticketId === props.ticket.id
})

const elapsedLabel = computed(() => {
  tick.value
  return formatDuration(ticketSeconds(props.ticket))
})

async function toggle() {
  if (props.readonly) return
  if (isRunning.value) {
    const result = await stopTicketTimer(props.ticket.id)
    if (!result?.ok) alertMessage.value = result?.message || 'Unable to stop the timer. It is still running.'
  } else {
    const result = await startTicketTimer(props.ticket.id)
    if (!result?.ok) alertMessage.value = result?.message || 'Check in before starting a ticket timer.'
  }
}
</script>

<template>
  <div class="flex items-center gap-2">
    <span
      v-if="isRunning"
      class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold text-emerald-700"
    >
      <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
      Running
    </span>
    <span class="font-mono font-tabular text-[12px]" :class="isRunning ? 'text-emerald-600 font-semibold' : 'text-slate-600'">{{ elapsedLabel }}</span>
    <!-- .stop because this timer sits inside the kanban card, which opens the
         ticket modal on click. Without it the click reached both handlers and
         the modal swallowed the interaction, so the button looked dead. -->
    <button
      v-if="!readonly"
      @click.stop="toggle"
      class="w-6 h-6 rounded-md flex items-center justify-center flex-shrink-0 transition-colors"
      :class="isRunning ? 'bg-coral-bg text-coral hover:bg-coral hover:text-white' : 'bg-teal-bg text-teal hover:bg-teal hover:text-white'"
      :title="isRunning ? 'Stop timer' : 'Start timer'"
    >
      <svg v-if="!isRunning" viewBox="0 0 24 24" class="w-3 h-3" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
      <svg v-else viewBox="0 0 24 24" class="w-3 h-3" fill="currentColor"><rect x="6" y="6" width="12" height="12" rx="1.5"/></svg>
    </button>
  </div>

  <Teleport to="body">
    <div v-if="alertMessage" class="ticket-timer-alert-backdrop" @click.self="alertMessage = ''">
      <section
        class="ticket-timer-alert"
        role="alertdialog"
        aria-modal="true"
        aria-labelledby="ticket-timer-alert-title"
      >
        <div class="ticket-timer-alert-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
            <rect x="4" y="5" width="16" height="15" rx="2" />
            <path d="M8 3v4M16 3v4M4 10h16M12 13v3M10.5 14.5h3" />
          </svg>
        </div>
        <h2 id="ticket-timer-alert-title">Check-in required</h2>
        <p>{{ alertMessage }}</p>
        <button type="button" class="ticket-timer-alert-action" @click="alertMessage = ''">Got it</button>
      </section>
    </div>
  </Teleport>
</template>
