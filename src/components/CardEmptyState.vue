<script setup>
defineProps({
  title: {
    type: String,
    required: true
  },
  description: {
    type: String,
    default: ''
  },
  variant: {
    type: String,
    default: 'inbox',
    validator: value => ['inbox', 'calendar', 'people', 'projects', 'review'].includes(value)
  },
  compact: Boolean
})
</script>

<template>
  <div class="card-empty-state" :class="{ 'card-empty-state--compact': compact }">
    <span class="card-empty-icon" aria-hidden="true">
      <svg v-if="variant === 'calendar'" viewBox="0 0 40 40" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
        <rect x="7" y="9" width="26" height="24" rx="4"/>
        <path d="M13 6v6M27 6v6M7 16h26M13 22h5M13 27h10"/>
      </svg>
      <svg v-else-if="variant === 'people'" viewBox="0 0 40 40" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
        <circle cx="20" cy="14" r="5"/>
        <path d="M10 33c1.3-6 5.1-9 10-9s8.7 3 10 9M7 19a4 4 0 0 0 0-8M33 19a4 4 0 0 1 0-8"/>
      </svg>
      <svg v-else-if="variant === 'projects'" viewBox="0 0 40 40" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
        <path d="M6 13a3 3 0 0 1 3-3h8l3 4h11a3 3 0 0 1 3 3v13a3 3 0 0 1-3 3H9a3 3 0 0 1-3-3V13Z"/>
        <path d="M13 23h14M20 19v8"/>
      </svg>
      <svg v-else-if="variant === 'review'" viewBox="0 0 40 40" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
        <path d="M10 7h15l6 6v18a3 3 0 0 1-3 3H10a3 3 0 0 1-3-3V10a3 3 0 0 1 3-3Z"/>
        <path d="M25 7v7h7M13 20l3 3 7-7M13 29h14"/>
      </svg>
      <svg v-else viewBox="0 0 40 40" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
        <path d="M8 14h24l-2 16a3 3 0 0 1-3 3H13a3 3 0 0 1-3-3L8 14Z"/>
        <path d="M14 14V11a6 6 0 0 1 12 0v3M15 23h10"/>
      </svg>
    </span>
    <div class="card-empty-title">{{ title }}</div>
    <div v-if="description" class="card-empty-copy">{{ description }}</div>
  </div>
</template>
