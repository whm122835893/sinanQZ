<script setup>
import { computed } from 'vue'

// ============================================================
// 指标卡片：渐变背景 + 白色文字 + 环比趋势（Soybean Admin 风格）
// 用法：<StatCard icon="Coin" label="今日 GMV" :value="123" unit="元" :trend="5.2" tone="primary" />
// ============================================================

const props = defineProps({
  icon: { type: String, default: 'TrendCharts' },   // Element Plus 图标名
  label: { type: String, required: true },
  value: { type: [String, Number], default: 0 },
  unit: { type: String, default: '' },
  trend: { type: Number, default: null },            // 环比百分比，null 不显示
  tone: { type: String, default: 'primary' }         // primary/gold/blue/green
})

const trendText = computed(() => {
  if (props.trend === null || props.trend === undefined) return ''
  return `${props.trend > 0 ? '+' : ''}${props.trend}%`
})
</script>

<template>
  <div class="stat-card" :class="`is-${tone}`">
    <div class="stat-card__icon">
      <el-icon :size="26"><component :is="icon" /></el-icon>
    </div>
    <div class="stat-card__body">
      <div class="stat-card__label">{{ label }}</div>
      <div class="stat-card__value">
        {{ value }}<span v-if="unit" class="stat-card__unit">{{ unit }}</span>
        <span v-if="trend !== null" class="stat-card__trend" :class="trend > 0 ? 'is-up' : 'is-down'">
          <el-icon :size="12"><CaretTop v-if="trend > 0" /><CaretBottom v-else /></el-icon>
          {{ trendText }}
        </span>
      </div>
    </div>
  </div>
</template>

<style scoped lang="scss">
.stat-card {
  display: flex;
  align-items: center;
  gap: 14px;
  border-radius: 8px;
  padding: 20px;
  color: #fff;
  box-shadow: 0 2px 12px rgba(0, 0, 0, 0.05);

  &.is-primary {
    background: linear-gradient(135deg, #c00000 0%, #d03333 100%);
    .stat-card__icon { background: rgba(255,255,255,0.18); }
  }
  &.is-gold {
    background: linear-gradient(135deg, #b08d55 0%, #d4a574 100%);
    .stat-card__icon { background: rgba(255,255,255,0.2); }
  }
  &.is-blue {
    background: linear-gradient(135deg, #1989fa 0%, #4aa7f8 100%);
    .stat-card__icon { background: rgba(255,255,255,0.2); }
  }
  &.is-green {
    background: linear-gradient(135deg, #07a950 0%, #07c160 100%);
    .stat-card__icon { background: rgba(255,255,255,0.2); }
  }
}

.stat-card__icon {
  width: 52px;
  height: 52px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.stat-card__label {
  font-size: 12px;
  opacity: 0.85;
}

.stat-card__value {
  font-size: 24px;
  font-weight: 700;
  font-family: 'DIN Alternate', 'DIN Condensed', 'Helvetica Neue', Arial, sans-serif;
  margin-top: 4px;
  display: flex;
  align-items: baseline;
  gap: 6px;
}

.stat-card__unit {
  font-size: 12px;
  font-weight: 400;
  opacity: 0.8;
}

.stat-card__trend {
  font-size: 12px;
  font-weight: 500;
  display: inline-flex;
  align-items: center;
  gap: 1px;

  &.is-up { color: #eafff2; }
  &.is-down { color: #ffecec; }
}
</style>
