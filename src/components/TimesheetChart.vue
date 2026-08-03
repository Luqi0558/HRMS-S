<script setup>
import { computed } from 'vue'

const props = defineProps({
  days: { type: Array, required: true },
  maxHours: { type: Number, required: true },
  formatDuration: { type: Function, required: true }
})

const width = 760
const height = 230
const padding = { top: 24, right: 20, bottom: 56, left: 48 }
const plotWidth = width - padding.left - padding.right
const plotHeight = height - padding.top - padding.bottom
const groupWidth = plotWidth / 7
const barWidth = 16
// Computed, not plain consts: maxHours grows past its 9h floor as soon as a
// day's attendance exceeds it (see weeklyTimesheetMaxHours). Reading props once
// at setup froze the scale at its initial value, so a running clock that
// crossed 9h kept the old axis and drew its bar past the top of the plot.
const maxSeconds = computed(() => Math.max(1, props.maxHours * 3600))

// Quarter steps, de-duplicated. Rounding each step up can collide on small
// ranges (maxHours 1 -> [1,1,1,1,0]), which stacked identical gridlines.
const ticks = computed(() => {
  const steps = [1, 0.75, 0.5, 0.25, 0].map(fraction => Math.ceil(props.maxHours * fraction))
  return [...new Set(steps)]
})

function yFor(seconds) {
  return padding.top + plotHeight - (Math.max(0, seconds) / maxSeconds.value) * plotHeight
}

function barHeight(seconds) {
  return Math.max(3, padding.top + plotHeight - yFor(seconds))
}

function groupX(index) {
  return padding.left + index * groupWidth + groupWidth / 2
}
</script>

<template>
  <div class="timesheet-chart-card">
    <svg class="timesheet-chart-svg" :viewBox="`0 0 ${width} ${height}`" role="img" aria-label="Weekly timesheet chart">
      <g class="timesheet-grid">
        <g v-for="tick in ticks" :key="`tick-${tick}`">
          <line :x1="padding.left" :x2="width - padding.right" :y1="yFor(tick * 3600)" :y2="yFor(tick * 3600)" />
          <text :x="padding.left - 12" :y="yFor(tick * 3600) + 4" text-anchor="end">{{ tick }}h</text>
        </g>
      </g>

      <line class="timesheet-axis" :x1="padding.left" :x2="width - padding.right" :y1="padding.top + plotHeight" :y2="padding.top + plotHeight" />
      <line class="timesheet-axis" :x1="padding.left" :x2="padding.left" :y1="padding.top" :y2="padding.top + plotHeight" />

      <g v-for="(day, index) in days" :key="day.dateStr" class="timesheet-day">
        <line class="timesheet-day-divider" :x1="groupX(index) + groupWidth / 2 - 6" :x2="groupX(index) + groupWidth / 2 - 6" :y1="padding.top" :y2="padding.top + plotHeight" />

        <rect
          class="timesheet-bar attendance"
          :x="groupX(index) - barWidth - 4"
          :y="yFor(day.attendanceSeconds)"
          :width="barWidth"
          :height="barHeight(day.attendanceSeconds)"
          rx="7"
        >
          <title>Attendance: {{ formatDuration(day.attendanceSeconds) }}</title>
        </rect>
        <rect
          class="timesheet-bar logged"
          :x="groupX(index) + 4"
          :y="yFor(day.loggedSeconds)"
          :width="barWidth"
          :height="barHeight(day.loggedSeconds)"
          rx="7"
        >
          <title>Work logged: {{ formatDuration(day.loggedSeconds) }}</title>
        </rect>

        <text v-if="day.attendanceSeconds" class="timesheet-value attendance-value" :x="groupX(index) - 12" :y="Math.max(padding.top + 12, yFor(day.attendanceSeconds) - 7)" text-anchor="middle">
          {{ formatDuration(day.attendanceSeconds) }}
        </text>
        <text v-if="day.loggedSeconds" class="timesheet-value logged-value" :x="groupX(index) + 20" :y="Math.max(padding.top + 26, yFor(day.loggedSeconds) - 7)" text-anchor="middle">
          {{ formatDuration(day.loggedSeconds) }}
        </text>

        <text class="timesheet-day-label" :x="groupX(index)" :y="height - 34" text-anchor="middle">{{ day.day }}</text>
        <text class="timesheet-date-label" :x="groupX(index)" :y="height - 18" text-anchor="middle">{{ day.isWeekend ? 'OFF' : day.date }}</text>
      </g>
    </svg>
  </div>
</template>
