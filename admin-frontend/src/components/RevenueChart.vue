<script setup>
import { computed, ref } from 'vue'
import { formatDate, money } from '@/utils/format.js'

// Revenue (a smooth filled area) and number of orders (a smooth line) per day or month, drawn as plain SVG: no chart library. `points` = [{ date, orders, revenue }].
// Left axis = revenue, right axis = orders. Hovering, touching or using the arrow keys on a day shows a band, dots and a bubble with its numbers;
// the whole chart has a text summary for screen readers.
const props = defineProps({
  points: { type: Array, default: () => [] },
  granularity: { type: String, default: 'day' },   // day | month
})

const W = 760
const H = 270
const PAD = { top: 16, right: 40, bottom: 28, left: 52 }
const inner = { w: W - PAD.left - PAD.right, h: H - PAD.top - PAD.bottom }

const niceMax = (v) => {
  if (v <= 0) return 1
  const p = 10 ** Math.floor(Math.log10(v))
  const n = v / p
  return (n <= 1 ? 1 : n <= 2 ? 2 : n <= 5 ? 5 : 10) * p
}
const maxRevenue = computed(() => niceMax(Math.max(0, ...props.points.map(p => p.revenue))))
const maxOrders = computed(() => Math.max(4, Math.ceil(Math.max(0, ...props.points.map(p => p.orders)) / 4) * 4))
const n = computed(() => props.points.length)
const hasData = computed(() => props.points.some(p => p.revenue > 0 || p.orders > 0))

const xOf = (i) => PAD.left + (n.value <= 1 ? inner.w / 2 : (inner.w * i) / (n.value - 1))
const base = PAD.top + inner.h
const rows = computed(() => props.points.map((p, i) => ({
  ...p, i, x: xOf(i),
  yRev: base - (p.revenue / maxRevenue.value) * inner.h,
  yOrd: base - (p.orders / maxOrders.value) * inner.h,
})))

// monotone cubic interpolation (no overshoot below zero or above the data): a smooth line through the points
const sign = (v) => (v < 0 ? -1 : 1)
function tangents(pts) {
  const m = pts.length
  const t = new Array(m).fill(0)
  if (m < 2) return t
  const d = (a, b) => (b.y - a.y) / (b.x - a.x || 1)
  if (m === 2) { t[0] = t[1] = d(pts[0], pts[1]); return t }
  for (let i = 1; i < m - 1; i++) {
    const s0 = d(pts[i - 1], pts[i])
    const s1 = d(pts[i], pts[i + 1])
    const h0 = pts[i].x - pts[i - 1].x
    const h1 = pts[i + 1].x - pts[i].x
    const p = (s0 * h1 + s1 * h0) / (h0 + h1)
    t[i] = (sign(s0) + sign(s1)) * Math.min(Math.abs(s0), Math.abs(s1), 0.5 * Math.abs(p)) || 0
  }
  t[0] = (3 * d(pts[0], pts[1]) - t[1]) / 2
  t[m - 1] = (3 * d(pts[m - 2], pts[m - 1]) - t[m - 2]) / 2
  return t
}
function smooth(pts) {
  if (!pts.length) return ''
  if (pts.length === 1) return `M${pts[0].x.toFixed(1)},${pts[0].y.toFixed(1)}`
  const t = tangents(pts)
  let d = `M${pts[0].x.toFixed(1)},${pts[0].y.toFixed(1)}`
  for (let i = 0; i < pts.length - 1; i++) {
    const a = pts[i]
    const b = pts[i + 1]
    const dx = (b.x - a.x) / 3
    d += ` C${(a.x + dx).toFixed(1)},${(a.y + t[i] * dx).toFixed(1)} ${(b.x - dx).toFixed(1)},${(b.y - t[i + 1] * dx).toFixed(1)} ${b.x.toFixed(1)},${b.y.toFixed(1)}`
  }
  return d
}
const revLine = computed(() => smooth(rows.value.map(r => ({ x: r.x, y: r.yRev }))))
const ordLine = computed(() => smooth(rows.value.map(r => ({ x: r.x, y: r.yOrd }))))
const area = computed(() => (n.value > 1 ? `${revLine.value} L${xOf(n.value - 1).toFixed(1)},${base} L${xOf(0).toFixed(1)},${base} Z` : ''))
const gridlines = computed(() => [0, 0.25, 0.5, 0.75, 1].map(f => ({ y: base - f * inner.h, revenue: maxRevenue.value * f, orders: Math.round(maxOrders.value * f) })))

// about seven labels on the x axis
const labelEvery = computed(() => Math.max(1, Math.ceil(n.value / 7)))
const label = (date) => {
  if (props.granularity === 'month') return formatDate(`${date}-01`).replace(/^\d+ /, '')
  return formatDate(date).replace(/,?\s*\d{4}$/, '')
}
const short = (v) => (v >= 1e6 ? `${+(v / 1e6).toFixed(1)}M` : v >= 1e3 ? `${+(v / 1e3).toFixed(1)}k` : String(Math.round(v)))

const hover = ref(null)
const svg = ref(null)
const totals = computed(() => ({ revenue: props.points.reduce((a, p) => a + p.revenue, 0), orders: props.points.reduce((a, p) => a + p.orders, 0) }))
const current = computed(() => (hover.value === null ? null : rows.value[hover.value] ?? null))
const when = (p) => (props.granularity === 'month' ? label(p.date) : formatDate(p.date))
const tipStyle = computed(() => {
  if (!current.value) return {}
  const left = (current.value.x / W) * 100
  const top = (Math.min(current.value.yRev, current.value.yOrd) / H) * 100
  return { left: `${left}%`, top: `${top}%`, transform: `translate(${left > 78 ? '-100%' : left < 22 ? '0' : '-50%'}, calc(-100% - 10px))` }
})

function pick(event) {
  const box = svg.value?.getBoundingClientRect()
  if (!box || !n.value) return
  const x = ((event.clientX - box.left) / box.width) * W
  hover.value = n.value === 1 ? 0 : Math.max(0, Math.min(n.value - 1, Math.round(((x - PAD.left) / inner.w) * (n.value - 1))))
}
function key(event) {
  if (!n.value) return
  if (event.key === 'ArrowRight') hover.value = Math.min(n.value - 1, (hover.value ?? -1) + 1)
  else if (event.key === 'ArrowLeft') hover.value = Math.max(0, (hover.value ?? n.value) - 1)
  else if (event.key === 'Escape') hover.value = null
  else return
  event.preventDefault()
}
</script>

<template>
  <div class="chart" data-testid="revenue-chart">
    <div class="chart__legend">
      <span><i class="sw sw--area" /> Sales</span>
      <span><i class="sw sw--line" /> Orders</span>
    </div>
    <div class="chart__stage">
      <svg ref="svg" :viewBox="`0 0 ${W} ${H}`" role="img" tabindex="0" :aria-label="`Sales and orders: ${money(totals.revenue)} from ${totals.orders} orders in ${n} ${granularity === 'month' ? 'months' : 'days'}. Use the arrow keys to read each ${granularity === 'month' ? 'month' : 'day'}.`" preserveAspectRatio="xMidYMid meet"
        @mousemove="pick" @mouseleave="hover = null" @touchstart.passive="pick($event.touches[0])" @touchmove.passive="pick($event.touches[0])" @keydown="key" @blur="hover = null">
        <defs>
          <linearGradient id="rc-fill" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" style="stop-color: var(--primary); stop-opacity: .28" />
            <stop offset="100%" style="stop-color: var(--primary); stop-opacity: 0" />
          </linearGradient>
        </defs>
        <g class="grid">
          <g v-for="g in gridlines" :key="g.y">
            <line :x1="PAD.left" :x2="W - PAD.right" :y1="g.y" :y2="g.y" />
            <text :x="PAD.left - 8" :y="g.y + 3" text-anchor="end">{{ short(g.revenue) }}</text>
            <text :x="W - PAD.right + 8" :y="g.y + 3" text-anchor="start" class="orders-axis">{{ g.orders }}</text>
          </g>
        </g>
        <rect v-if="current" class="band" :x="current.x - Math.max(10, inner.w / Math.max(n, 1) / 2)" :y="PAD.top" :width="Math.max(20, inner.w / Math.max(n, 1))" :height="inner.h" rx="6" />
        <path v-if="area" class="area" :d="area" fill="url(#rc-fill)" />
        <path v-if="n > 1" class="rev-line" :d="revLine" />
        <path v-if="n > 1" class="orders-line" :d="ordLine" />
        <template v-if="n === 1"><circle class="rev-dot" :cx="rows[0].x" :cy="rows[0].yRev" r="4" /><circle class="orders-dot" :cx="rows[0].x" :cy="rows[0].yOrd" r="4" /></template>
        <template v-if="current">
          <circle class="rev-dot" :cx="current.x" :cy="current.yRev" r="5" />
          <circle class="orders-dot" :cx="current.x" :cy="current.yOrd" r="4.5" />
        </template>
        <g class="xlabels">
          <text v-for="r in rows" v-show="r.i % labelEvery === 0" :key="`x${r.date}`" :x="r.x" :y="H - 8" text-anchor="middle">{{ label(r.date) }}</text>
        </g>
        <!-- one invisible target per day for the pointer (and for tests) -->
        <rect v-for="r in rows" :key="`h${r.date}`" class="hit" :x="r.x - (inner.w / Math.max(n, 1)) / 2" :y="PAD.top" :width="inner.w / Math.max(n, 1)" :height="inner.h" fill="transparent" :data-date="r.date" @mouseenter="hover = r.i">
          <title>{{ r.date }}: {{ money(r.revenue) }}, {{ r.orders }} order(s)</title>
        </rect>
      </svg>
      <div v-if="current" class="chart__tip" :style="tipStyle" role="status" aria-live="polite" data-testid="chart-tip">
        <strong>{{ money(current.revenue) }}</strong>
        <span>{{ current.orders }} order{{ current.orders === 1 ? '' : 's' }}</span>
        <small>{{ when(current) }}</small>
      </div>
      <p v-if="!hasData" class="chart__empty">No orders in this period yet.</p>
    </div>
  </div>
</template>

<style scoped>
.chart { width: 100%; }
.chart__stage { position: relative; }
svg { width: 100%; height: auto; display: block; outline: none; touch-action: pan-y; }
svg:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; border-radius: 8px; }
.grid line { stroke: var(--border-light); }
.grid text, .xlabels text { font-size: 10px; fill: var(--text-3); }
.grid .orders-axis { fill: var(--accent); }
.area { pointer-events: none; }
.rev-line { fill: none; stroke: var(--primary); stroke-width: 2.4; stroke-linecap: round; pointer-events: none; }
.orders-line { fill: none; stroke: var(--accent); stroke-width: 2; stroke-linecap: round; pointer-events: none; }
.rev-dot { fill: var(--surface); stroke: var(--primary); stroke-width: 2.4; pointer-events: none; }
.orders-dot { fill: var(--surface); stroke: var(--accent); stroke-width: 2; pointer-events: none; }
.band { fill: var(--primary-soft); opacity: .75; pointer-events: none; }
.chart__legend { display: flex; gap: 14px; flex-wrap: wrap; align-items: center; font-size: 12px; color: var(--text-2); justify-content: flex-end; margin: -2px 0 2px; }
.sw { display: inline-block; vertical-align: middle; margin-right: 5px; }
.sw--area { width: 10px; height: 10px; background: var(--primary); border-radius: 50%; }
.sw--line { width: 10px; height: 10px; background: var(--accent); border-radius: 50%; }
.chart__tip { position: absolute; pointer-events: none; background: var(--surface); border: 1px solid var(--border); box-shadow: var(--shadow-md); border-radius: 10px; padding: 5px 10px; display: grid; gap: 0; white-space: nowrap; z-index: 2; }
.chart__tip strong { font-size: 13px; font-weight: 600; }
.chart__tip span { font-size: 11.5px; color: var(--accent); font-weight: 500; }
.chart__tip small { font-size: 10.5px; color: var(--text-3); }
.chart__empty { position: absolute; inset: 0; display: grid; place-items: center; color: var(--text-3); font-size: 13px; pointer-events: none; }
</style>
