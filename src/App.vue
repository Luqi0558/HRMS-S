<script setup>
import { onMounted, onUnmounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import Sidebar from './components/Sidebar.vue'
import Topbar from './components/Topbar.vue'
import { useStore } from './store/index.js'
import { authService } from './services/authService.js'
import { createSyncClient } from './services/syncService.js'
import { createRealtimeClient } from './services/realtimeService.js'
import { presenceService, createIdleWatcher } from './services/presenceService.js'

const route = useRoute()
const { state, isAuthenticated, setRolePermissions, applySync, applyPresence, setChosenPresence } = useStore()

/**
 * The live feed for the whole session.
 *
 * Mounted here rather than on the Messages page so notifications keep arriving
 * wherever the user is — the bell is in the top bar, not on one screen.
 *
 * Two layers. The poll is the delivery guarantee: stateless, self-healing, and
 * unaffected by a CPU spike killing a long-lived process. Pusher sits on top
 * as an accelerator, pinging the client to fetch immediately instead of
 * waiting up to three seconds.
 *
 * The poll never stops while the socket is up — it drops to a 30s heartbeat.
 * A silently dead socket is indistinguishable from a quiet office, so without
 * that heartbeat the failure this whole feature exists to fix would simply
 * reappear one layer up.
 */
const sync = createSyncClient({
  onData: applySync,
  // The POLL's health. Kept separate from the socket's: the poll is what
  // actually guarantees delivery, and a connected socket writing 'live' over
  // this field would hide a poll that had stopped working.
  onStatusChange: status => { state.syncStatus = status }
})

let realtime = null

/**
 * Marks the user away after five minutes idle, and available when they return.
 *
 * Deliberately does not touch an explicit choice: someone who set Busy stays
 * Busy while they read a long document. Auto-away only ever moves between "no
 * override" and "away", which is the only transition it can infer honestly.
 */
const idle = createIdleWatcher({
  onIdle: async () => {
    const chosen = state.presence.chosen[state.currentUser.employeeId]?.status
    if (chosen && chosen !== 'available') return

    try {
      await presenceService.set('away')
    } catch { /* presence is cosmetic; a failure is not worth surfacing */ }
  },
  onActive: async () => {
    const chosen = state.presence.chosen[state.currentUser.employeeId]?.status
    // Only clear what auto-away set. A manual Busy survives coming back.
    if (chosen && chosen !== 'away') return

    try {
      await presenceService.set(null)
    } catch { /* as above */ }
  }
})

function startFeed() {
  sync.start()

  realtime = createRealtimeClient({
    onPresence: applyPresence,
    // The employment record's id, not the user's — the channel is keyed on it.
    // Absent for a user with no employee record, who has no feed to subscribe
    // to; the poll covers them and returns an empty result.
    employeeId: state.currentUser.employeeId,
    // The ping carries no content — fetch the real thing from our own API.
    onPing: () => sync.poke(),
    // The accelerator's health, tracked separately so it can never mask the
    // poll's. Only a real channel subscription counts as live.
    onStatusChange: status => {
      state.socketStatus = status
      sync.setSocketLive(status === 'live')
    }
  })

  realtime.start()
  idle.start()

  // Chosen statuses for people who are offline right now — the presence
  // channel only reports who is currently connected.
  presenceService.list()
    .then(setChosenPresence)
    .catch(() => {})
}

function stopFeed() {
  idle.stop()
  sync.stop()
  if (realtime) realtime.stop()
  realtime = null
}

// Login fetches the permission grid, but a page refresh restores the session
// from localStorage without going through it. Without this the app would fall
// back to the seeded rows for the whole session after any reload.
onMounted(async () => {
  if (!isAuthenticated() || !authService.isAuthenticated()) return

  startFeed()

  try {
    setRolePermissions(await authService.permissions())
  } catch {
    // A 401 here is already handled by the client, which clears the session
    // and redirects. Anything else leaves the seeded grid in place.
  }
})

// Signing in and out mid-session starts and stops the feed without a reload.
// Watched through currentUser.id because isAuthenticated() is a plain function,
// not a ref — passing it directly would never re-evaluate.
watch(() => state.currentUser.id, id => {
  if (id) startFeed()
  else stopFeed()
})
const themeStorageKey = 'orbit-night-mode'
// The inline script in index.html already read this and set the pre-paint
// class; reading it again here just seeds Vue's own reactive copy.
const isNightMode = ref(window.localStorage.getItem(themeStorageKey) === 'true')

// Persist only. The <html> pre-paint class is managed by the watcher below,
// which also knows whether the current route renders light.
watch(isNightMode, value => {
  window.localStorage.setItem(themeStorageKey, String(value))
}, { immediate: true })

watch(
  [isNightMode, () => route.meta.blank, () => route.matched.length],
  ([value, isBlank, isResolved]) => {
    // Runs immediately at setup, when the route may not have resolved yet and
    // `isBlank` is still undefined. Treating that as "not blank" would re-add
    // the dark background on a night-mode reload of /login, undoing the skip
    // in index.html — so wait for the route before touching either class.
    if (!isResolved) return
    document.body.classList.toggle('night-mode', value && !isBlank)
    // Login/accept-invite render light regardless of theme, so clear the
    // pre-paint background there rather than leaving it dark behind the card.
    document.documentElement.classList.toggle('night-mode-preload', value && !isBlank)
  },
  { immediate: true }
)

onUnmounted(() => {
  stopFeed()
  document.body.classList.remove('night-mode')
  document.documentElement.classList.remove('night-mode-preload')
})
</script>

<template>
  <!-- Until the initial navigation resolves, `route.matched` is empty and
       `meta.blank` reads as undefined — rendering then would fall through to
       the dashboard shell and flash the sidebar over a hard-reloaded /login.
       Hold an empty div for that one frame instead of guessing a layout. -->
  <div v-if="!route.matched.length" class="min-h-screen"></div>
  <!-- Blank routes (login, accept-invite) scroll rather than clip: the login
       is a full-height split layout and would otherwise be cut off on short
       viewports. -->
  <div v-else-if="route.meta.blank" class="min-h-screen bg-white overflow-y-auto">
    <router-view />
  </div>
  <div v-else :class="{ 'night-mode': isNightMode }" class="app-shell h-screen flex overflow-hidden">
    <Sidebar />
    <div class="flex-1 min-w-0 flex flex-col min-h-0 overflow-hidden">
      <Topbar :is-night-mode="isNightMode" @toggle-night-mode="isNightMode = !isNightMode" />
      <main class="app-main flex-1 min-h-0 overflow-auto px-5 py-6">
        <div class="min-h-full">
          <router-view />
        </div>
      </main>
    </div>
  </div>
</template>
