<script setup>
import { computed, ref, watch } from 'vue'
import CardEmptyState from './CardEmptyState.vue'

const props = defineProps({
  rows: {
    type: Array,
    required: true
    // each row: { id, name, department, checkIn, checkOut, status: 'present'|'late'|'active', hours }
  },
  // Dashboard cards can place the status controls beside their title while
  // retaining the search field and result count in the ledger toolbar.
  headerFilters: {
    type: Boolean,
    default: false
  }
})

const query = ref('')
const statusFilter = ref('all')
const visibleLimit = ref(10)

const statusOptions = [
  { value: 'all', label: 'All' },
  { value: 'present', label: 'On time' },
  { value: 'late', label: 'Late' },
  { value: 'active', label: 'Active' },
  { value: 'wfh', label: 'WFH' }
]

const filteredRows = computed(() => {
  const term = query.value.trim().toLowerCase()
  return props.rows.filter(row => {
    const matchesSearch = !term || `${row.name} ${row.department || ''} ${row.status} ${row.checkIn || ''} ${row.checkOut || ''}`.toLowerCase().includes(term)
    const matchesStatus = statusFilter.value === 'all'
      || row.status === statusFilter.value
      || (statusFilter.value === 'wfh' && (row.workMode === 'remote' || row.dayWorkMode === 'mixed'))
    return matchesSearch && matchesStatus
  })
})

const visibleRows = computed(() => filteredRows.value.slice(0, visibleLimit.value))
const hasMoreRows = computed(() => visibleLimit.value < filteredRows.value.length)
const emptyState = computed(() => {
  if (query.value.trim()) {
    return {
      title: 'No matching employees',
      description: 'Try another employee name or clear the search.'
    }
  }

  if (statusFilter.value === 'all') {
    return {
      title: 'No attendance records today',
      description: 'Today\'s check-ins will appear here as they are recorded.'
    }
  }

  const label = statusOptions.find(option => option.value === statusFilter.value)?.label?.toLowerCase() || 'matching'
  return {
    title: `No ${label} attendance records`,
    description: 'Choose All to return to the complete attendance ledger.'
  }
})

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

function currentClockTime() {
  const now = new Date()
  return `${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}`
}

function timeToMinutes(time) {
  if (!time || !/^\d{1,2}:\d{2}$/.test(time)) return null
  const [hours, minutes] = time.split(':').map(Number)
  return hours * 60 + minutes
}

// An open session is normal during the workday. It becomes an exception only
// after the expected end of the attendance window.
function hasCheckoutOverdue(row) {
  return Boolean(row.checkIn && !row.checkOut && timeToMinutes(currentClockTime()) >= 19 * 60)
}

function toneFor(row) {
  if (hasCheckoutOverdue(row)) return { text: 'text-coral', pill: 'punch-ledger-status-exception', label: 'No checkout' }
  if (row.status === 'late') return { text: 'text-amber', pill: 'punch-ledger-status-late', label: 'Late' }
  if (row.status === 'active') return { text: 'text-teal', pill: 'punch-ledger-status-active', label: 'Active' }
  return { text: 'text-teal', pill: 'punch-ledger-status-present', label: 'On time' }
}

function initials(name) {
  return String(name || '?').split(/\s+/).filter(Boolean).slice(0, 2).map(part => part[0]).join('').toUpperCase()
}

function checkoutLabel(row) {
  if (row.checkOut) return row.checkOut
  return hasCheckoutOverdue(row) ? 'Missing' : '—'
}

</script>

<template>
  <div class="punch-ledger" :class="{ 'punch-ledger--header-filters': headerFilters }">
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
      <div v-if="!headerFilters" class="punch-ledger-count">{{ filteredRows.length }} records</div>
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
          <span class="punch-ledger-avatar" aria-hidden="true">{{ initials(row.name) }}</span>
          <span class="punch-ledger-name">{{ row.name }}</span>
          <span class="punch-ledger-department">
            {{ row.department }}
            <template v-if="row.dayWorkMode === 'mixed'"> · Mixed location</template>
            <template v-else-if="row.workMode === 'remote'"> · WFH</template>
          </span>
        </div>
        <span class="punch-ledger-time">{{ row.checkIn || '-' }}</span>
        <span class="punch-ledger-time" :class="{ 'punch-ledger-missing': hasCheckoutOverdue(row) }">{{ checkoutLabel(row) }}</span>
        <span class="punch-ledger-hours" :class="toneFor(row).text">{{ row.hours }}</span>
        <span class="punch-ledger-status" :class="toneFor(row).pill"><span class="punch-ledger-status-dot"></span>{{ toneFor(row).label }}</span>
      </div>

      <CardEmptyState
        v-if="filteredRows.length === 0"
        class="punch-ledger-empty"
        compact
        variant="people"
        :title="emptyState.title"
        :description="emptyState.description"
      />
      <div v-else-if="hasMoreRows" class="punch-ledger-more">Scroll to load more</div>
    </div>
  </div>
</template>
