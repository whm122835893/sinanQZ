<script setup>
import { computed } from 'vue'

// 轻量 SVG 图表（不引入 ECharts，视觉与 C 端主题一致）
const props = defineProps({
  type: { type: String, default: 'line' },          // line | bar | donut
  labels: { type: Array, default: () => [] },       // line/bar 横轴
  values: { type: Array, default: () => [] },        // line/bar 数值
  series: { type: Array, default: () => [] },        // donut: [{label, value}]
  color: { type: String, default: '#C00000' },
  height: { type: Number, default: 150 }
})

const uid = Math.random().toString(36).slice(2, 8)
const W = 320
const H = 150
const P = { l: 10, r: 10, t: 16, b: 22 }

const PALETTE = ['#C00000', '#D4A574', '#1989fa', '#07c160', '#7232dd', '#969799']

const maxVal = computed(() => {
  const m = Math.max(...(props.values.length ? props.values : [1]))
  return m <= 0 ? 1 : m * 1.12
})

const points = computed(() =>
  props.values.map((v, i) => {
    const n = props.values.length
    const x = P.l + (n > 1 ? (i * (W - P.l - P.r)) / (n - 1) : (W - P.l - P.r) / 2)
    const y = P.t + (1 - v / maxVal.value) * (H - P.t - P.b)
    return { x, y, v, label: props.labels[i] || '' }
  })
)

const linePath = computed(() => points.value.map((p, i) => `${i === 0 ? 'M' : 'L'}${p.x.toFixed(1)},${p.y.toFixed(1)}`).join(' '))
const areaPath = computed(() => {
  if (!points.value.length) return ''
  const first = points.value[0]
  const last = points.value[points.value.length - 1]
  return `${linePath.value} L${last.x.toFixed(1)},${H - P.b} L${first.x.toFixed(1)},${H - P.b} Z`
})

const barW = computed(() => {
  const n = props.values.length || 1
  return Math.min(26, ((W - P.l - P.r) / n) * 0.42)
})

const donutTotal = computed(() => props.series.reduce((s, i) => s + i.value, 0) || 1)
const C = 2 * Math.PI * 44

const donutSegs = computed(() => {
  let offset = 0
  return props.series.map((s, i) => {
    const len = (s.value / donutTotal.value) * C
    const seg = { ...s, len, offset, color: PALETTE[i % PALETTE.length] }
    offset += len
    return seg
  })
})
</script>

<template>
  <div class="chart">
    <!-- 折线图 -->
    <svg v-if="type === 'line'" :height="height" :viewBox="`0 0 ${W} ${H}`" preserveAspectRatio="none" class="chart__svg">
      <defs>
        <linearGradient :id="`grad-${uid}`" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" :stop-color="color" stop-opacity="0.18" />
          <stop offset="100%" :stop-color="color" stop-opacity="0" />
        </linearGradient>
      </defs>
      <path :d="areaPath" :fill="`url(#grad-${uid})`" />
      <path :d="linePath" fill="none" :stroke="color" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
      <g v-for="(p, i) in points" :key="i">
        <circle :cx="p.x" :cy="p.y" r="2.6" fill="#fff" :stroke="color" stroke-width="1.6" />
        <text v-if="i % 2 === 0 || points.length <= 7" :x="p.x" :y="H - 6" text-anchor="middle" font-size="9" fill="#999">
          {{ p.label }}
        </text>
        <text :x="p.x" :y="p.y - 8" text-anchor="middle" font-size="8.5" :fill="color" font-weight="600">
          {{ i === points.length - 1 ? p.v : '' }}
        </text>
      </g>
    </svg>

    <!-- 柱状图 -->
    <svg v-else-if="type === 'bar'" :height="height" :viewBox="`0 0 ${W} ${H}`" preserveAspectRatio="none" class="chart__svg">
      <g v-for="(v, i) in values" :key="i">
        <rect
          v-if="v > 0"
          :x="(P.l + (i * (W - P.l - P.r)) / values.length + ((W - P.l - P.r) / values.length - barW) / 2).toFixed(1)"
          :y="(P.t + (1 - v / maxVal) * (H - P.t - P.b)).toFixed(1)"
          :width="barW"
          :height="((v / maxVal) * (H - P.t - P.b)).toFixed(1)"
          rx="3"
          :fill="i === values.length - 1 ? color : '#F0D5D5'"
        />
        <text :x="P.l + (i * (W - P.l - P.r)) / values.length + (W - P.l - P.r) / values.length / 2" :y="H - 6" text-anchor="middle" font-size="9" fill="#999">
          {{ labels[i] }}
        </text>
      </g>
    </svg>

    <!-- 环形图 -->
    <div v-else-if="type === 'donut'" class="chart__donut">
      <svg width="132" height="132" viewBox="0 0 120 120">
        <g transform="rotate(-90 60 60)">
          <circle cx="60" cy="60" r="44" fill="none" stroke="#F2F3F5" stroke-width="15" />
          <circle
            v-for="s in donutSegs"
            :key="s.label"
            cx="60" cy="60" r="44" fill="none"
            :stroke="s.color"
            stroke-width="15"
            :stroke-dasharray="`${Math.max(0, s.len - 2)} ${C - Math.max(0, s.len - 2)}`"
            :stroke-dashoffset="-s.offset"
          />
        </g>
        <text x="60" y="57" text-anchor="middle" font-size="16" font-weight="700" fill="#333">{{ donutTotal }}%</text>
        <text x="60" y="72" text-anchor="middle" font-size="9" fill="#999">分类占比</text>
      </svg>
      <div class="chart__legend">
        <div v-for="s in donutSegs" :key="s.label" class="chart__legend-item">
          <i :style="{ background: s.color }" />
          <span class="l">{{ s.label }}</span>
          <span class="v">{{ s.value }}%</span>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped lang="scss">
.chart__svg {
  width: 100%;
  display: block;
}

.chart__donut {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 18px;
  flex-wrap: wrap;
}

.chart__legend {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.chart__legend-item {
  display: flex;
  align-items: center;
  gap: 7px;
  font-size: 12px;

  i { width: 9px; height: 9px; border-radius: 3px; }
  .l { color: $color-text-secondary; width: 42px; }
  .v { color: $color-text-primary; font-weight: 600; }
}
</style>
