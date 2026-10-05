<script setup>
// Shared confirmation for actions that mutate shared state and cannot be undone.
// Deliberately NOT used for edits to unsaved form drafts — those are reversible
// by cancelling the form, and prompting there would be noise.
defineProps({
  open: { type: Boolean, default: false },
  title: { type: String, default: 'Are you sure?' },
  message: { type: String, default: '' },
  confirmLabel: { type: String, default: 'Confirm' },
  cancelLabel: { type: String, default: 'Cancel' },
  // 'danger' for removals/blocks, 'default' for reversible-but-notable actions.
  tone: { type: String, default: 'danger' }
})

const emit = defineEmits(['confirm', 'cancel'])
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      class="orbit-modal-backdrop fixed inset-0 z-[60] flex items-center justify-center px-4 py-4"
      role="dialog"
      aria-modal="true"
      :aria-label="title"
      @click.self="emit('cancel')"
      @keydown.esc="emit('cancel')"
    >
      <div class="orbit-modal p-6 w-full max-w-sm">
        <div class="flex items-start gap-3">
          <div
            class="w-10 h-10 rounded-full flex items-center justify-center flex-shrink-0"
            :class="tone === 'danger' ? 'bg-rose-100 text-rose-600' : 'bg-amber-100 text-amber-700'"
          >
            <svg viewBox="0 0 24 24" class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
              <path d="M12 9v4" /><path d="M12 17h.01" />
              <path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z" />
            </svg>
          </div>
          <div class="min-w-0">
            <div class="text-base font-bold text-slate-900">{{ title }}</div>
            <div v-if="message" class="text-sm text-slate-600 mt-1 leading-relaxed">{{ message }}</div>
          </div>
        </div>

        <div class="flex gap-2 mt-5">
          <button
            @click="emit('cancel')"
            class="orbit-button orbit-button-secondary flex-1 text-sm py-2 font-medium"
          >
            {{ cancelLabel }}
          </button>
          <button
            @click="emit('confirm')"
            class="orbit-button flex-1 text-sm font-bold py-2"
            :class="tone === 'danger' ? 'orbit-button-danger' : 'orbit-button-primary'"
          >
            {{ confirmLabel }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
