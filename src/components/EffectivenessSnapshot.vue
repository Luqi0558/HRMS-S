<script setup>
import { computed, onMounted, ref } from 'vue'
import CardEmptyState from './CardEmptyState.vue'
import { effectivenessService } from '../services/effectivenessService.js'
import { useStore } from '../store/index.js'

const { state } = useStore()
const props = defineProps({
  scope: {
    type: String,
    default: 'personal',
    validator: value => ['personal', 'organisation'].includes(value)
  }
})
function dateInputValue(date) {
  const timezoneOffset = date.getTimezoneOffset() * 60000
  return new Date(date.getTime() - timezoneOffset).toISOString().slice(0, 10)
}

const toDate = ref(dateInputValue(new Date()))
const fromDate = ref(dateInputValue(new Date(Date.now() - (29 * 24 * 60 * 60 * 1000))))
const isOrganisation = computed(() => props.scope === 'organisation')
const effectiveness = ref(null)
const history = ref([])
const isLoading = ref(true)
const loadError = ref('')
const cycles = ref([])
const cycleMessage = ref('')
const cycleError = ref('')
const actingCycleId = ref(null)
const canReviewCycles = computed(() => ['hr', 'super_admin'].includes(state.currentUser?.role))

async function loadEffectiveness() {
  isLoading.value = true
  loadError.value = ''
  try {
    const response = isOrganisation.value
      ? await effectivenessService.workforce({ from: fromDate.value, to: toDate.value })
      : await effectivenessService.mine({ from: fromDate.value, to: toDate.value })
    effectiveness.value = response.effectiveness
    const historyResponse = await effectivenessService.history(isOrganisation.value ? 'workforce' : 'personal')
    history.value = historyResponse.history || []
  } catch (error) {
    effectiveness.value = null
    history.value = []
    loadError.value = error.message || 'Effectiveness data could not be loaded.'
  } finally {
    isLoading.value = false
  }
}

onMounted(async () => {
  await loadEffectiveness()
  await loadCycles()
})

async function applyDateFilter() {
  if (!fromDate.value || !toDate.value || fromDate.value > toDate.value) {
    loadError.value = 'Choose a valid date range.'
    return
  }
  await loadEffectiveness()
}

const scoreStyle = computed(() => ({
  background: effectiveness.value?.score === null || effectiveness.value?.score === undefined
    ? 'conic-gradient(#94a3b8 0deg, #e5edf3 0deg)'
    : 'conic-gradient(#0f8b76 ' + (effectiveness.value.score * 3.6) + 'deg, #e5edf3 0deg)'
}))

function historyLabel(point) {
  return new Date(point.periodStart + 'T00:00:00').toLocaleDateString(undefined, { month: 'short' })
}

async function loadCycles() {
  if (!canReviewCycles.value) return
  try {
    cycles.value = (await effectivenessService.cycles()).cycles || []
  } catch {
    cycles.value = []
  }
}

async function reviewCycle(cycle) {
  actingCycleId.value = cycle.id
  cycleMessage.value = ''
  cycleError.value = ''
  try {
    await effectivenessService.reviewCycle(cycle.id)
    cycleMessage.value = 'Monthly evidence marked as reviewed.'
    await loadCycles()
  } catch (error) {
    cycleError.value = error.message || 'The period could not be reviewed.'
  } finally {
    actingCycleId.value = null
  }
}

async function finalizeCycle(cycle) {
  actingCycleId.value = cycle.id
  cycleMessage.value = ''
  cycleError.value = ''
  try {
    await effectivenessService.finalizeCycle(cycle.id)
    cycleMessage.value = 'Monthly snapshot finalized and added to history.'
    await Promise.all([loadCycles(), loadEffectiveness()])
  } catch (error) {
    cycleError.value = error.message || 'The period could not be finalized.'
  } finally {
    actingCycleId.value = null
  }
}
</script>

<template>
  <section class="orbit-card effectiveness-dashboard p-5">
    <div class="flex flex-col xl:flex-row xl:items-start justify-between gap-4">
      <div>
        <div class="flex flex-wrap items-center gap-2">
          <div class="text-lg font-bold text-slate-900">{{ isOrganisation ? 'Workforce effectiveness' : 'My work effectiveness' }}</div>
        </div>
      </div>
      <div class="effectiveness-date-filter">
        <label>From <input v-model="fromDate" type="date" aria-label="Effectiveness from date"></label>
        <label>To <input v-model="toDate" type="date" aria-label="Effectiveness to date"></label>
        <button @click="applyDateFilter" :disabled="isLoading" class="orbit-button orbit-button-primary text-xs px-3 py-2">{{ isLoading ? 'Applying...' : 'Apply' }}</button>
      </div>
    </div>

    <div v-if="loadError" class="effectiveness-filter-error mt-3">{{ loadError }}</div>

    <div class="grid grid-cols-1 xl:grid-cols-[250px_minmax(0,1fr)] gap-5 mt-5">
      <div class="effectiveness-score-panel">
        <div class="effectiveness-score-ring" :style="scoreStyle">
          <div class="effectiveness-score-inner">
            <strong>{{ isLoading ? '...' : (effectiveness?.score ?? '—') }}</strong>
            <span>/ 100</span>
          </div>
        </div>
        <div class="mt-4 text-center">
          <div class="text-sm font-extrabold text-slate-900">{{ effectiveness?.score === null || !effectiveness ? 'Awaiting evidence' : (isOrganisation ? 'Company score' : 'Your score') }}</div>
          <!-- Legacy preview caption removed after live calculation was connected.
          <div class="text-xs text-slate-500 mt-1">{{ effectivenessPreview.confidence }} · preview only</div>
        -->
        </div>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <div v-for="metric in effectiveness?.metrics || []" :key="metric.id" class="effectiveness-metric" :class="{ 'opacity-60': !metric.available }">
          <div class="flex items-start justify-between gap-3">
            <div>
              <div class="text-sm font-bold text-slate-900">{{ metric.label }}</div>
              <div class="text-xs text-slate-500 mt-1 leading-5">{{ metric.detail }}</div>
            </div>
            <div class="text-lg font-extrabold text-slate-900">{{ metric.score === null ? '—' : metric.score + '%' }}</div>
          </div>
          <div class="effectiveness-progress mt-3"><div :style="{ width: (metric.score || 0) + '%' }"></div></div>
        </div>
        <CardEmptyState
          v-if="!isLoading && !(effectiveness?.metrics || []).length"
          class="md:col-span-2"
          compact
          variant="review"
          title="No measurable records yet"
          description="Approved attendance and completed work in this period will create measurable evidence."
        />
      </div>
    </div>

    <div :class="isOrganisation ? 'grid grid-cols-1 xl:grid-cols-[minmax(0,1fr)_300px] gap-5 mt-5' : 'grid grid-cols-1 gap-5 mt-5'">
      <div class="effectiveness-trend-card">
        <div class="flex items-center justify-between gap-3">
          <div>
            <div class="text-sm font-bold text-slate-900">{{ isOrganisation ? 'Six-month company trend' : 'Six-month trend' }}</div>
          </div>
        </div>
        <div v-if="history.length" class="effectiveness-bars">
          <div v-for="point in history" :key="point.periodStart" class="effectiveness-bar-group">
            <div class="effectiveness-bar-value">{{ point.score ?? '—' }}</div>
            <div class="effectiveness-bar-track"><div class="effectiveness-bar" :style="{ height: (point.score || 0) + '%' }"></div></div>
            <div class="text-[11px] font-semibold text-slate-500">{{ historyLabel(point) }}</div>
          </div>
        </div>
        <CardEmptyState
          v-else
          class="effectiveness-empty-state"
          variant="review"
          title="No finalized snapshots yet"
          description="Monthly scores appear here after HR review and Super Admin finalization."
        />
      </div>

      <aside v-if="isOrganisation" class="effectiveness-evidence">
        <div class="text-sm font-bold text-slate-900">Department comparison</div>
        <div v-if="effectiveness?.departments?.length" class="mt-3">
          <div class="mt-2 space-y-2">
            <div v-for="department in effectiveness.departments.slice(0, 4)" :key="department.department" class="flex items-center justify-between gap-3 text-xs">
              <span class="font-semibold text-slate-700 truncate">{{ department.department }}</span>
              <span class="font-bold text-slate-900">{{ department.score }}%</span>
            </div>
          </div>
        </div>
        <div v-else class="text-sm text-slate-600 mt-3">No department evidence in this period.</div>
      </aside>
    </div>

    <section v-if="canReviewCycles" class="effectiveness-cycle-panel mt-5">
      <div class="flex flex-col md:flex-row md:items-center justify-between gap-3">
        <div>
          <div class="text-sm font-bold text-slate-900">Monthly snapshot review</div>
          <div class="text-xs text-slate-500 mt-1">HR reviews draft evidence; Super Admin finalizes the reviewed monthly record.</div>
        </div>
        <span class="text-xs font-semibold text-slate-500">{{ cycles.length }} recent period{{ cycles.length === 1 ? '' : 's' }}</span>
      </div>
      <div v-if="cycleMessage" class="effectiveness-cycle-message success mt-3">{{ cycleMessage }}</div>
      <div v-if="cycleError" class="effectiveness-cycle-message error mt-3">{{ cycleError }}</div>
      <CardEmptyState
        v-if="!cycles.length"
        class="mt-4"
        compact
        variant="review"
        title="No monthly snapshots waiting for review"
        description="Draft snapshots will appear here when there is evidence to review."
      />
      <div v-else class="mt-4 space-y-2">
        <div v-for="cycle in cycles" :key="cycle.id" class="effectiveness-cycle-row">
          <div>
            <div class="text-sm font-semibold text-slate-900">{{ new Date(cycle.periodStart + 'T00:00:00').toLocaleDateString(undefined, { month: 'long', year: 'numeric' }) }}</div>
            <div class="text-xs text-slate-500 mt-1">Policy {{ cycle.policyVersion }} · {{ cycle.status }}</div>
          </div>
          <button
            v-if="state.currentUser.role === 'hr' && cycle.status === 'draft'"
            @click="reviewCycle(cycle)"
            :disabled="actingCycleId === cycle.id"
            class="orbit-button orbit-button-secondary text-xs px-3 py-1.5"
          >{{ actingCycleId === cycle.id ? 'Reviewing...' : 'Mark reviewed' }}</button>
          <button
            v-else-if="state.currentUser.role === 'super_admin' && cycle.status === 'reviewed'"
            @click="finalizeCycle(cycle)"
            :disabled="actingCycleId === cycle.id"
            class="orbit-button orbit-button-primary text-xs px-3 py-1.5"
          >{{ actingCycleId === cycle.id ? 'Finalizing...' : 'Finalize' }}</button>
          <span v-else class="text-xs font-semibold text-slate-500 capitalize">{{ cycle.status }}</span>
        </div>
      </div>
    </section>
  </section>
</template>
