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
// Derived from the data, not fixed at 7: a monthly view passes ~30 days and a
// hardcoded seventh drew every bar past the right edge of the plot.
const groupWidth = computed(() => plotWidth / Math.max(1, props.days.length))

// Wide enough to hold a duration inside it. The weekly view has ~99px per
// column and was using 26 of them, which is why "1h 26m" spilled out of the
// bar on both themes.
const barWidth = computed(() => Math.max(6, Math.min(52, groupWidth.value * 0.62)))

/**
 * Whether a duration printed inside a segment actually fits its width.
 *
 * "1h 26m" is roughly 33px at 10px type; a monthly column is 14px wide, so the
 * label belongs on the tooltip there rather than spilling across neighbours.
 */
const fitsInsideBar = computed(() => barWidth.value >= 40)

// A month is labelled by day number — "1 2 3 … 31" fits where "Mon / Aug 4"
// does not, and at ~14px in a 22px column every day gets its own label. An
// earlier attempt showed only every second or third, which left a bar you had
// worked on sitting under no date at all.
const isMonthly = computed(() => props.days.length > 14)
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

/**
 * Logged time, capped at the attendance it sits inside.
 *
 * A timer left running past check-out can exceed the day at the desk, and an
 * inner bar taller than its container reads as a rendering fault rather than
 * as the data problem it is. The tooltip still reports the real figure.
 */
function loggedWithin(day) {
  return day.attendanceSeconds > 0
    ? Math.min(day.loggedSeconds, day.attendanceSeconds)
    : day.loggedSeconds
}

/** A segment's pixel height, floored so a short one still reads as present. */
function segmentHeight(seconds) {
  if (seconds <= 0) return 0
  return Math.max(3, (seconds / maxSeconds.value) * plotHeight)
}

/** Time at the desk that did not land on a ticket — the upper segment. */
function unloggedDesk(day) {
  return Math.max(0, day.attendanceSeconds - loggedWithin(day))
}

function groupX(index) {
  return padding.left + index * groupWidth.value + groupWidth.value / 2
}

</script>

<template>
  <div class="timesheet-chart-card">
    <svg class="timesheet-chart-svg" :viewBox="`0 0 ${width} ${height}`" role="img" aria-label="Timesheet log chart">
      <g class="timesheet-grid">
        <g v-for="tick in ticks" :key="`tick-${tick}`">
          <line :x1="padding.left" :x2="width - padding.right" :y1="yFor(tick * 3600)" :y2="yFor(tick * 3600)" />
          <text :x="padding.left - 12" :y="yFor(tick * 3600) + 4" text-anchor="end">{{ tick }}h</text>
        </g>
      </g>

      <line class="timesheet-axis" :x1="padding.left" :x2="width - padding.right" :y1="padding.top + plotHeight" :y2="padding.top + plotHeight" />
      <line class="timesheet-axis" :x1="padding.left" :x2="padding.left" :y1="padding.top" :y2="padding.top + plotHeight" />

      <g v-for="(day, index) in days" :key="day.dateStr" class="timesheet-day" :class="{ 'is-weekend': day.isWeekend }">
        <!-- A band rather than a word, so a weekend is visible on the month
             view without taking the space its date needs. -->
        <rect
          v-if="day.isWeekend"
          class="timesheet-weekend-band"
          :x="groupX(index) - groupWidth / 2"
          :y="padding.top"
          :width="groupWidth"
          :height="plotHeight"
        />
        <line class="timesheet-day-divider" :x1="groupX(index) + groupWidth / 2 - 6" :x2="groupX(index) + groupWidth / 2 - 6" v-if="days.length <= 14" :y1="padding.top" :y2="padding.top + plotHeight" />

        <!-- A true stack: work logged at the bottom, the rest of the time at
             the desk above it, so the two segments sum to attendance rather
             than double-counting. Drawing logged ON TOP of an attendance bar
             instead made a day look like a single record whenever the two were
             close, which is what this replaced. -->
        <rect
          v-if="loggedWithin(day) > 0"
          class="timesheet-bar logged"
          :x="groupX(index) - barWidth / 2"
          :y="yFor(loggedWithin(day))"
          :width="barWidth"
          :height="segmentHeight(loggedWithin(day))"
        >
          <title>{{ day.day }} {{ day.date }} — on tickets {{ formatDuration(day.loggedSeconds) }}</title>
        </rect>

        <rect
          v-if="unloggedDesk(day) > 0"
          class="timesheet-bar attendance"
          :x="groupX(index) - barWidth / 2"
          :y="yFor(day.attendanceSeconds)"
          :width="barWidth"
          :height="segmentHeight(unloggedDesk(day))"
        >
          <title>{{ day.day }} {{ day.date }} — at desk, not on a ticket {{ formatDuration(unloggedDesk(day)) }}</title>
        </rect>

        <!-- Inside its own segment, which is what makes the split readable at a
             glance. Each is drawn only when its segment is tall enough to hold
             the text; the totals are always on the bar's tooltip. -->
        <text
          v-if="fitsInsideBar && segmentHeight(loggedWithin(day)) > 16"
          class="timesheet-value logged-inline"
          :x="groupX(index)"
          :y="yFor(loggedWithin(day) / 2) + 4"
          text-anchor="middle"
        >{{ formatDuration(day.loggedSeconds) }}</text>

        <text
          v-if="fitsInsideBar && segmentHeight(unloggedDesk(day)) > 16"
          class="timesheet-value attendance-inline"
          :x="groupX(index)"
          :y="yFor(loggedWithin(day) + unloggedDesk(day) / 2) + 4"
          text-anchor="middle"
        >{{ formatDuration(unloggedDesk(day)) }}</text>

        <!-- The day's total sits above the stack, since it is the sum of what
             is inside rather than a third quantity. -->
        <text
          v-if="!isMonthly && day.attendanceSeconds > 0"
          class="timesheet-value total-value"
          :x="groupX(index)"
          :y="Math.max(padding.top + 10, yFor(day.attendanceSeconds) - 8)"
          text-anchor="middle"
        >{{ formatDuration(day.attendanceSeconds) }}</text>

        <text class="timesheet-day-label" :x="groupX(index)" :y="height - 34" text-anchor="middle">
          {{ isMonthly ? day.dayOfMonth : day.day }}
        </text>
        <!-- Monthly shows the weekday initial under the number; the date is
             already the number. Weekly keeps the full date, and "OFF" only
             replaces it there — on a month it erased which Saturday this was. -->
        <text class="timesheet-date-label" :x="groupX(index)" :y="height - 18" text-anchor="middle">
          {{ isMonthly ? day.day.charAt(0) : (day.isWeekend ? 'OFF' : day.date) }}
        </text>
      </g>
    </svg>
  </div>
</template>
