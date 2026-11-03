<script setup>
import { computed, ref } from 'vue'
import { formatDate, money } from '@/utils/format.js'

// Revenue (bars) and number of orders (line with dots) per day or month, drawn as plain SVG: no chart library. `points` = [{ date, orders, revenue }].
// Hovering or focusing a bar shows its numbers below the chart; the whole chart has a text summary for screen readers.
const props = defineProps({
  points: { type: Array, default: () => [] },
  granularity: { type: String, default: 'day' },   // day | month
})

const W = 720
const H = 230
const PAD = { top: 14, right: 40, bottom: 28, left: 52 }
const inner = { w: W - PAD.left - PAD.right, h: H - PAD.top - PAD.bottom }

const niceMax = (v) => {
  if (v <= 0) return 1
  const p = 10 ** Math.floor(Math.log10(v))
  const n = v / p
  return (n <= 1 ? 1 : n <= 2 ? 2 : n <= 5 ? 5 : 10) * p
}
const maxRevenue = computed(() => niceMax(Math.max(0, ...props.points.map(p => p.revenue))))
const maxOrders = computed(() => Math.max(1, Math.ceil(Math.max(0, ...props.points.map(p => p.orders)) / 4) * 4))
const step = computed(() => inner.w / Math.max(1, props.points.length))
const barW = computed(() => Math.max(2, Math.min(34, step.value * 0.66)))

const bars = computed(() => props.points.map((p, i) => {
  const h = (p.revenue / maxRevenue.value) * inner.h
  const cx = PAD.left + step.value * i + step.value / 2
  return { ...p, i, cx, x: cx - barW.value / 2, y: PAD.top + inner.h - h, h, ly: PAD.top + inner.h - (p.orders / maxOrders.value) * inner.h }
}))
const line = computed(() => bars.value.map(b => `${b.cx.toFixed(1)},${b.ly.toFixed(1)}`).join(' '))
const gridlines = computed(() => [0, 0.25, 0.5, 0.75, 1].map(f => ({ y: PAD.top + inner.h - f * inner.h, revenue: maxRevenue.value * f, orders: Math.round(maxOrders.value * f) })))

// about seven labels on the x axis
const labelEvery = computed(() => Math.max(1, Math.ceil(props.points.length / 7)))
const label = (date) => {
  if (props.granularity === 'month') return formatDate(`${date}-01`).replace(/^\d+ /, '')
  return formatDate(date).replace(/ \d{4}$/, '')
}

const short = (v) => (v >= 1e6 ? `${+(v / 1e6).toFixed(1)}M` : v >= 1e3 ? `${+(v / 1e3).toFixed(1)}k` : String(Math.round(v)))
const hover = ref(null)
const totals = computed(() => ({ revenue: props.points.reduce((a, p) => a + p.revenue, 0), orders: props.points.reduce((a, p) => a + p.orders, 0) }))
const readout = computed(() => {
  const p = hover.value === null ? null : props.points[hover.value]
  return p ? `${props.granularity === 'month' ? label(p.date) : formatDate(p.date)}: ${money(p.revenue)} from ${p.orders} order${p.orders === 1 ? '' : 's'}` : ''
})
</script>

<template>
  <div class="chart" data-testid="revenue-chart">
    <svg :viewBox="`0 0 ${W} ${H}`" role="img" :aria-label="`Revenue and orders: ${money(totals.revenue)} from ${totals.orders} orders in ${points.length} ${granularity === 'month' ? 'months' : 'days'}`" preserveAspectRatio="xMidYMid meet">
      <g class="grid">
        <g v-for="g in gridlines" :key="g.y">
          <line :x1="PAD.left" :x2="W - PAD.right" :y1="g.y" :y2="g.y" />
          <text :x="PAD.left - 6" :y="g.y + 3" text-anchor="end">{{ short(g.revenue) }}</text>
          <text :x="W - PAD.right + 6" :y="g.y + 3" text-anchor="start" class="orders-axis">{{ g.orders }}</text>
        </g>
      </g>
      <g>
        <rect v-for="b in bars" :key="b.date" class="bar" :class="{ 'is-hover': hover === b.i }" :x="b.x" :y="b.revenue > 0 ? b.y : PAD.top + inner.h - 1" :width="barW" :height="b.revenue > 0 ? Math.max(b.h, 2) : 1" rx="2"
          tabindex="0" :aria-label="`${b.date}: ${money(b.revenue)}, ${b.orders} orders`" @mouseenter="hover = b.i" @mouseleave="hover = null" @focus="hover = b.i" @blur="hover = null">
          <title>{{ b.date }}: {{ money(b.revenue) }}, {{ b.orders }} order(s)</title>
        </rect>
      </g>
      <polyline v-if="points.length > 1" class="orders-line" :points="line" />
      <circle v-for="b in bars" :key="`c${b.date}`" class="orders-dot" :class="{ 'is-hover': hover === b.i }" :cx="b.cx" :cy="b.ly" :r="hover === b.i ? 4 : 2.5" />
      <g class="xlabels">
        <text v-for="b in bars" v-show="b.i % labelEvery === 0" :key="`x${b.date}`" :x="b.cx" :y="H - 8" text-anchor="middle">{{ label(b.date) }}</text>
      </g>
    </svg>
    <div class="chart__legend">
      <span><i class="sw sw--bar" /> Revenue</span>
      <span><i class="sw sw--line" /> Orders</span>
      <strong class="chart__readout" aria-live="polite">{{ readout }}</strong>
    </div>
  </div>
</template>

<style scoped>
.chart { width: 100%; }
svg { width: 100%; height: auto; display: block; }
.grid line { stroke: var(--border); stroke-dasharray: 3 3; }
.grid text, .xlabels text { font-size: 10px; fill: var(--text-3); }
.grid .orders-axis { fill: var(--accent); }
.bar { fill: var(--primary); opacity: .82; transition: opacity .12s; outline: none; }
.bar:hover, .bar.is-hover, .bar:focus { opacity: 1; fill: var(--primary-dark); }
.orders-line { fill: none; stroke: var(--accent); stroke-width: 2; stroke-linejoin: round; }
.orders-dot { fill: #fff; stroke: var(--accent); stroke-width: 2; transition: r .1s; }
.chart__legend { display: flex; gap: 14px; flex-wrap: wrap; align-items: center; font-size: 12px; color: var(--text-2); padding: 2px 4px 0; min-height: 22px; }
.sw { display: inline-block; vertical-align: middle; margin-right: 4px; }
.sw--bar { width: 10px; height: 10px; background: var(--primary); border-radius: 2px; }
.sw--line { width: 14px; height: 0; border-top: 2px solid var(--accent); }
.chart__readout { color: var(--text); margin-left: auto; font-weight: 500; }
</style>
