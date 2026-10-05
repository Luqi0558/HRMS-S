<script setup>
import { useDashboard } from '../composables/useDashboard.js'

// Camera check-in, shared by the Employee, Manager, and HR dashboards.
// It reads the SAME composable state those dashboards call openCamera() on —
// previously the modal lived in Dashboard.vue with its own separate refs, so
// opening it from a role component set one ref while the modal watched another.
const {
  videoRef,
  showCameraModal,
  dashboardWorkMode,
  cameraError,
  cameraLoading,
  closeCamera,
  checkingIn,
  dashboardCheckInWithPhoto
} = useDashboard()
</script>

<template>
  <Teleport to="body">
    <div
      v-if="showCameraModal"
      class="orbit-modal-backdrop fixed inset-0 z-50 flex items-center justify-center px-4 py-4 overflow-y-auto"
      @click.self="closeCamera"
    >
      <div class="orbit-modal w-full max-w-lg overflow-hidden">
        <div class="p-4 border-b border-slate-200 flex items-center justify-between gap-3">
          <div>
            <div class="text-sm font-semibold text-slate-900">Camera check-in</div>
            <div class="text-xs text-slate-600">Take a photo before marking attendance</div>
          </div>
          <button @click="closeCamera" class="text-slate-500 hover:text-slate-900 text-lg leading-none">x</button>
        </div>
        <div class="p-4 space-y-3">
          <div>
            <div class="text-xs font-bold uppercase tracking-wide text-slate-500 mb-2">Where are you working today?</div>
            <div class="grid grid-cols-2 gap-2 rounded-xl bg-slate-50 border border-slate-200 p-1">
              <button
                type="button"
                :aria-pressed="dashboardWorkMode === 'office'"
                @click="dashboardWorkMode = 'office'"
                class="rounded-lg px-3 py-2 text-sm font-bold transition-colors"
                :class="dashboardWorkMode === 'office' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-800'"
              >
                Office
              </button>
              <button
                type="button"
                :aria-pressed="dashboardWorkMode === 'remote'"
                @click="dashboardWorkMode = 'remote'"
                class="rounded-lg px-3 py-2 text-sm font-bold transition-colors"
                :class="dashboardWorkMode === 'remote' ? 'bg-emerald-600 text-white shadow-sm' : 'text-slate-500 hover:text-slate-800'"
              >
                WFH
              </button>
            </div>
            <div class="mt-2 text-xs text-slate-600">This records today’s work location. It does not use leave balance or change payroll.</div>
          </div>
          <div class="rounded-xl overflow-hidden bg-slate-100 border border-slate-200 aspect-[4/3] flex items-center justify-center relative">
            <video ref="videoRef" autoplay playsinline class="w-full h-full object-cover"></video>
            <div v-if="cameraLoading" class="absolute text-sm text-slate-600">Starting camera...</div>
          </div>
          <div v-if="cameraError" class="text-sm text-rose-600 bg-rose-50 border border-rose-200 rounded-lg px-3 py-2">{{ cameraError }}</div>
          <div class="flex items-center justify-end gap-2">
            <button @click="closeCamera" class="orbit-button orbit-button-secondary text-sm font-medium px-4 py-2">Cancel</button>
            <button @click="dashboardCheckInWithPhoto" :disabled="checkingIn" class="orbit-button orbit-button-primary text-sm font-medium px-4 py-2">{{ checkingIn ? 'Checking in...' : 'Capture &amp; check in' }}</button>
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>
