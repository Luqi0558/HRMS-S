<script setup>
import { computed, ref, watch } from 'vue'

const props = defineProps({
  rows: {
    type: Array,
    required: true
    // each row: { id, name, department, checkIn, checkOut, status: 'present'|'late'|'active', hours }
  }
})

const query = ref('')
const statusFilter = ref('all')
const visibleLimit = ref(10)

const statusOptions = [
  { value: 'all', label: 'All' },
  { value: 'present', label: 'On time' },
  { value: 'late', label: 'Late' },
  { value: 'active', label: 'Active' }
]

const filteredRows = computed(() => {
  const term = query.value.trim().toLowerCase()
  return props.rows.filter(row => {
    const matchesSearch = !term || `${row.name} ${row.department || ''} ${row.status} ${row.checkIn || ''} ${row.checkOut || ''}`.toLowerCase().includes(term)
    const matchesStatus = statusFilter.value === 'all' || row.status === statusFilter.value
    return matchesSearch && matchesStatus
  })
})

const visibleRows = computed(() => filteredRows.value.slice(0, visibleLimit.value))
const hasMoreRows = computed(() => visibleLimit.value < filteredRows.value.length)

function loadMoreRows(event) {
  if (!hasMoreRows.value) return
  const target = event.target
  if (target.scrollTop + target.clientHeight >= target.scrollHeight - 20) {
    visibleLimit.value = Math.min(visibleLimit.value + 10, filteredRows.value.length)
  }
}

watch([query, statusFilter], () => {
  visibleLimit.value = 10
})

function toneFor(status) {
  if (status === 'late') return { bar: 'bg-coral', text: 'text-coral', pill: 'punch-ledger-status-late', label: 'Late' }
  if (status === 'active') return { bar: 'bg-amber', text: 'text-amber', pill: 'punch-ledger-status-active', label: 'Active' }
  return { bar: 'bg-teal', text: 'text-teal', pill: 'punch-ledger-status-present', label: 'On time' }
}

// crude visual mapping of a 9am-6pm workday onto a 0-100 bar
function barStyle(checkIn, checkOut) {
  if (!checkIn) return { left: '0%', right: '100%' }
  const toPct = (time) => {
    const [h, m] = time.split(':').map(Number)
    const minutes = h * 60 + m
    const start = 8 * 60 // 8:00 window start
    const end = 19 * 60 // 19:00 window end
    return Math.min(100, Math.max(0, ((minutes - start) / (end - start)) * 100))
  }
  const left = toPct(checkIn)
  const right = checkOut ? 100 - toPct(checkOut) : 4
  return { left: `${left}%`, right: `${right}%` }
}
</script>

<template>
  <div class="punch-ledger">
    <div class="punch-ledger-tools">
      <input v-model="query" type="search" placeholder="Filter ledger..." class="punch-ledger-search" />
      <div class="punch-ledger-filters">
        <button
          v-for="option in statusOptions"
          :key="option.value"
          type="button"
          class="punch-ledger-filter"
          :class="{ active: statusFilter === option.value }"
          @click="statusFilter = option.value"
        >
          {{ option.label }}
        </button>
      </div>
      <div class="punch-ledger-count">{{ filteredRows.length }} records</div>
    </div>

    <div class="punch-ledger-scroll" @scroll="loadMoreRows">
      <div v-if="filteredRows.length" class="punch-ledger-header">
        <span>Employee</span>
        <span>In</span>
        <span>Out</span>
        <span>Total</span>
        <span>Status</span>
      </div>

      <div v-for="row in visibleRows" :key="row.id || `${row.employeeId}-${row.checkIn}`" class="punch-ledger-row">
        <div class="punch-ledger-person">
          <span class="punch-ledger-name">{{ row.name }}</span>
          <span class="punch-ledger-department">{{ row.department }}</span>
        </div>
        <span class="punch-ledger-time">{{ row.checkIn || '-' }}</span>
        <span class="punch-ledger-time" :class="{ 'punch-ledger-missing': !row.checkOut }">{{ row.checkOut || 'Missing' }}</span>
        <span class="punch-ledger-hours" :class="toneFor(row.status).text">{{ row.hours }}</span>
        <span class="punch-ledger-status" :class="toneFor(row.status).pill">{{ toneFor(row.status).label }}</span>
        <div class="punch-ledger-track">
          <div
            class="punch-ledger-bar"
            :class="toneFor(row.status).bar"
            :style="barStyle(row.checkIn, row.checkOut)"
          ></div>
        </div>
      </div>

      <div v-if="filteredRows.length === 0" class="punch-ledger-empty">No punch records for this filter today.</div>
      <div v-else-if="hasMoreRows" class="punch-ledger-more">Scroll to load more</div>
    </div>
  </div>
</template>
