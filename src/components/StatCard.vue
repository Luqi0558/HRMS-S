<script setup>
defineProps({
  label: { type: String, required: true },
  value: { type: [String, Number], required: true },
  tone: { type: String, default: 'slate' } // slate | teal | amber | coral
})

const toneClass = {
  slate: 'text-slate-900',
  teal: 'text-teal',
  amber: 'text-amber',
  coral: 'text-coral'
}
</script>

<template>
  <div class="bg-white border border-slate-200 rounded-lg px-4 py-3 shadow-sm">
    <div class="text-xs text-slate-600 font-medium mb-1.5">{{ label }}</div>
    <div class="font-mono font-tabular text-[22px] font-medium" :class="toneClass[tone]">{{ value }}</div>
  </div>
</template>
