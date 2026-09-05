<script setup>
import { computed } from 'vue'

const props = defineProps({
  icon: { type: String, default: 'chart-trending-o' },
  label: { type: String, required: true },
  value: { type: [String, Number], required: true },
  unit: { type: String, default: '' },
  trend: { type: Number, default: null },   // 环比百分比
  tone: { type: String, default: 'primary' } // primary | gold | blue | green
})

const toneMap = {
  primary: { color: 'var(--color-primary)', bg: 'rgba(192,0,0,.08)' },
  gold:    { color: 'var(--color-gold)', bg: 'rgba(212,165,116,.14)' },
  blue:    { color: 'var(--color-blue)', bg: 'rgba(25,137,250,.08)' },
  green:   { color: 'var(--color-success)', bg: 'rgba(7,193,96,.08)' }
}

const tone = computed(() => toneMap[props.tone] || toneMap.primary)
const trendText = computed(() =>
  props.trend === null ? '' : `${props.trend >= 0 ? '+' : ''}${props.trend}% 环比昨日`
)
</script>

<template>
  <div class="stat">
    <div class="stat__icon" :style="{ background: tone.bg }">
      <van-icon :name="icon" size="19" :color="tone.color" />
    </div>
    <div class="stat__meta">
      <div class="stat__label">{{ label }}</div>
      <div class="stat__value">
        <span class="price">{{ value }}</span>
        <span v-if="unit" class="stat__unit">{{ unit }}</span>
      </div>
      <div v-if="trendText" class="stat__trend" :class="trend >= 0 ? 'trend-up' : 'trend-down'">
        {{ trendText }}
      </div>
    </div>
  </div>
</template>

<style scoped lang="scss">
.stat {
  background: $color-card;
  border-radius: $radius-lg;
  padding: 14px;
  display: flex;
  align-items: flex-start;
  gap: 10px;
  box-shadow: 0 1px 6px rgba(26, 26, 26, 0.04);
}

.stat__icon {
  width: 36px;
  height: 36px;
  border-radius: 10px;
  @include flex-center;
  flex-shrink: 0;
}

.stat__meta { min-width: 0; }

.stat__label {
  font-size: 12px;
  color: $color-text-secondary;
  @include ellipsis;
}

.stat__value {
  margin-top: 3px;
  display: flex;
  align-items: baseline;
  gap: 3px;

  .price { font-size: 20px; line-height: 1.1; }
}

.stat__unit {
  font-size: 11px;
  color: $color-text-tertiary;
}

.stat__trend {
  font-size: 11px;
  margin-top: 4px;
}
</style>
