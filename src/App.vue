<script setup>
import { onUnmounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import Sidebar from './components/Sidebar.vue'
import Topbar from './components/Topbar.vue'

const route = useRoute()
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
