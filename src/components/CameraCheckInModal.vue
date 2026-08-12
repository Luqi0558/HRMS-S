<script setup>
import { useDashboard } from '../composables/useDashboard.js'

// Camera check-in, shared by the Employee, Manager, and HR dashboards.
// It reads the SAME composable state those dashboards call openCamera() on —
// previously the modal lived in Dashboard.vue with its own separate refs, so
// opening it from a role component set one ref while the modal watched another.
const {
  videoRef,
  showCameraModal,
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
      class="fixed inset-0 z-50 bg-black/40 backdrop-blur-[2px] flex items-center justify-center px-4 py-4 overflow-y-auto"
      @click.self="closeCamera"
    >
      <div class="w-full max-w-lg rounded-2xl bg-white shadow-2xl border border-slate-200 overflow-hidden">
        <div class="p-4 border-b border-slate-200 flex items-center justify-between gap-3">
          <div>
            <div class="text-sm font-semibold text-slate-900">Camera check-in</div>
            <div class="text-xs text-slate-600">Take a photo before marking attendance</div>
          </div>
          <button @click="closeCamera" class="text-slate-500 hover:text-slate-900 text-lg leading-none">x</button>
        </div>
        <div class="p-4 space-y-3">
          <div class="rounded-xl overflow-hidden bg-slate-100 border border-slate-200 aspect-[4/3] flex items-center justify-center relative">
            <video ref="videoRef" autoplay playsinline class="w-full h-full object-cover"></video>
            <div v-if="cameraLoading" class="absolute text-sm text-slate-600">Starting camera...</div>
          </div>
          <div v-if="cameraError" class="text-sm text-rose-600 bg-rose-50 border border-rose-200 rounded-lg px-3 py-2">{{ cameraError }}</div>
          <div class="flex items-center justify-end gap-2">
            <button @click="closeCamera" class="text-sm border border-slate-200 rounded-lg px-4 py-2 hover:bg-slate-50 transition-colors text-slate-700 font-medium">Cancel</button>
            <button @click="dashboardCheckInWithPhoto" :disabled="checkingIn" class="bg-emerald-600 text-white text-sm font-medium rounded-lg px-4 py-2 hover:bg-emerald-700 transition-colors disabled:opacity-60 disabled:cursor-not-allowed">{{ checkingIn ? 'Checking in...' : 'Capture &amp; check in' }}</button>
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>
