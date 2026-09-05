<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { getDashboard } from '@/api'
import StatCard from '@/components/StatCard.vue'
import EChart from '@/components/EChart.vue'
import { fmtMoney, fmtNumber } from '@/utils/format'

const router = useRouter()
const loading = ref(true)
const data = ref(null)

const pct = (today, yesterday) =>
  yesterday ? Number((((today - yesterday) / yesterday) * 100).toFixed(1)) : null

const stat = computed(() => data.value ? {
  gmvTrend: pct(data.value.todayGmv, data.value.yesterdayGmv),
  orderTrend: pct(data.value.todayOrders, data.value.yesterdayOrders),
  userTrend: pct(data.value.todayNewUsers, data.value.yesterdayNewUsers)
} : {})

const todos = computed(() => data.value ? [
  { label: '实名待审核', value: data.value.pendingRealname, to: '/user/realname', tone: 'gold' },
  { label: '退款待审批', value: data.value.pendingRefunds, to: '/order/refunds', tone: 'primary' },
  { label: '转赠待处理', value: data.value.pendingTransfers, to: '/transfer', tone: 'blue' },
  { label: '异常订单', value: data.value.abnormalOrders, to: '/order', tone: 'green' }
] : [])

// ---- ECharts 配置 ----
const gmvOption = computed(() => ({
  tooltip: { trigger: 'axis' },
  grid: { left: 50, right: 16, top: 20, bottom: 24 },
  xAxis: {
    type: 'category',
    data: data.value?.trend.map((t) => t.date) || [],
    axisLine: { lineStyle: { color: '#ddd' } },
    axisLabel: { color: '#999' }
  },
  yAxis: { type: 'value', splitLine: { lineStyle: { color: '#f2f3f5' } }, axisLabel: { color: '#999' } },
  series: [{
    name: 'GMV',
    type: 'line',
    smooth: true,
    data: data.value?.trend.map((t) => t.gmv) || [],
    symbolSize: 6,
    lineStyle: { width: 2.5, color: '#C00000' },
    itemStyle: { color: '#C00000' },
    areaStyle: {
      color: {
        type: 'linear', x: 0, y: 0, x2: 0, y2: 1,
        colorStops: [
          { offset: 0, color: 'rgba(192,0,0,0.22)' },
          { offset: 1, color: 'rgba(192,0,0,0.02)' }
        ]
      }
    }
  }]
}))

const categoryOption = computed(() => ({
  tooltip: { trigger: 'item', formatter: '{b}: {d}%' },
  legend: { bottom: 0, icon: 'circle', itemWidth: 8, itemHeight: 8, textStyle: { color: '#666', fontSize: 11 } },
  series: [{
    type: 'pie',
    radius: ['48%', '72%'],
    center: ['50%', '44%'],
    avoidLabelOverlap: true,
    label: { show: false },
    data: (data.value?.categoryShare || []).map((s, i) => ({
      ...s,
      itemStyle: { color: ['#C00000', '#D4A574', '#1989fa', '#07c160', '#ff976a'][i % 5] }
    }))
  }]
}))

const orderOption = computed(() => ({
  tooltip: { trigger: 'axis' },
  grid: { left: 40, right: 16, top: 20, bottom: 24 },
  xAxis: {
    type: 'category',
    data: data.value?.trend.map((t) => t.date) || [],
    axisLine: { lineStyle: { color: '#ddd' } },
    axisLabel: { color: '#999' }
  },
  yAxis: { type: 'value', splitLine: { lineStyle: { color: '#f2f3f5' } }, axisLabel: { color: '#999' } },
  series: [{
    name: '订单量',
    type: 'bar',
    barWidth: 16,
    data: data.value?.trend.map((t) => t.orders) || [],
    itemStyle: {
      borderRadius: [4, 4, 0, 0],
      color: { type: 'linear', x: 0, y: 0, x2: 0, y2: 1, colorStops: [
        { offset: 0, color: '#D4A574' }, { offset: 1, color: 'rgba(212,165,116,0.45)' }
      ] }
    }
  }]
}))

onMounted(async () => {
  const res = await getDashboard()
  data.value = res.data
  loading.value = false
})
</script>

<template>
  <div class="adm-page dash">
    <template v-if="loading">
      <el-skeleton :rows="6" animated style="padding: 20px" />
    </template>
    <template v-else-if="data">
      <!-- 核心指标 -->
      <div class="adm-grid">
        <StatCard icon="Coin" label="今日 GMV" :value="fmtMoney(data.todayGmv)" unit="元" :trend="stat.gmvTrend" tone="primary" />
        <StatCard icon="List" label="今日订单" :value="fmtNumber(data.todayOrders)" unit="单" :trend="stat.orderTrend" tone="gold" />
        <StatCard icon="User" label="今日新增用户" :value="fmtNumber(data.todayNewUsers)" unit="人" :trend="stat.userTrend" tone="blue" />
        <StatCard icon="UserFilled" label="累计用户" :value="fmtNumber(data.totalUsers)" unit="人" tone="green" />
      </div>

      <!-- 待办事项 -->
      <div class="adm-card">
        <div class="adm-card__title">待办事项</div>
        <div class="dash__todo-grid">
          <div
            v-for="t in todos"
            :key="t.label"
            class="dash__todo"
            :class="`is-${t.tone}`"
            @click="router.push(t.to)"
          >
            <div class="dash__todo-num">{{ t.value }}</div>
            <div class="dash__todo-label">{{ t.label }}<el-icon class="dash__todo-arrow"><ArrowRight /></el-icon></div>
          </div>
        </div>
      </div>

      <div class="dash__split">
        <div class="adm-card">
          <div class="adm-card__title">近 7 日 GMV 趋势（元）</div>
          <EChart :option="gmvOption" :height="280" />
        </div>
        <div class="adm-card">
          <div class="adm-card__title">藏品分类销售占比</div>
          <EChart :option="categoryOption" :height="280" />
        </div>
      </div>

      <div class="dash__split">
        <div class="adm-card">
          <div class="adm-card__title">近 7 日订单量</div>
          <EChart :option="orderOption" :height="280" />
        </div>
        <div class="adm-card">
          <div class="adm-card__title">热销榜 TOP5</div>
          <div class="dash__rank-list">
            <div v-for="(t, i) in data.topCollectibles" :key="t.name" class="dash__rank-item">
              <div class="dash__rank-no" :class="{ 'is-top': i < 3 }">{{ i + 1 }}</div>
              <img class="adm-thumb" :src="t.cover" :alt="t.name" />
              <div class="adm-cell__body">
                <div class="adm-cell__title">{{ t.name }}</div>
                <div class="adm-cell__desc">已售 {{ fmtNumber(t.sold) }} 份</div>
              </div>
              <div class="dash__rank-amount price">¥{{ fmtNumber(t.amount) }}</div>
            </div>
          </div>
        </div>
      </div>

      <!-- 平台累计 -->
      <div class="adm-card">
        <div class="adm-card__title">平台累计</div>
        <div class="dash__total">
          <div class="dash__total-item">
            <div class="dash__total-value price">¥{{ fmtNumber(data.totalGmv) }}</div>
            <div class="dash__total-label">累计 GMV</div>
          </div>
          <div class="dash__total-item">
            <div class="dash__total-value price">{{ fmtNumber(data.totalUsers) }}</div>
            <div class="dash__total-label">累计用户</div>
          </div>
          <div class="dash__total-item">
            <div class="dash__total-value price">{{ fmtNumber(data.trend.reduce((s, t) => s + t.orders, 0)) }}</div>
            <div class="dash__total-label">近 7 日订单</div>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped lang="scss">
.dash__todo-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 12px;

  @media (max-width: 768px) {
    grid-template-columns: repeat(2, 1fr);
  }
}

.dash__todo {
  border-radius: 8px;
  padding: 16px;
  text-align: center;
  cursor: pointer;
  transition: transform 0.15s ease;

  &:hover { transform: translateY(-2px); }

  &.is-primary { background: rgba(192, 0, 0, 0.06); color: $color-primary; }
  &.is-gold { background: rgba(212, 165, 116, 0.14); color: $color-gold-dark; }
  &.is-blue { background: rgba(25, 137, 250, 0.07); color: var(--color-blue); }
  &.is-green { background: rgba(7, 193, 96, 0.07); color: var(--color-success); }
}

.dash__todo-num {
  font-size: 26px;
  font-weight: 700;
  font-family: 'DIN Alternate', 'DIN Condensed', Arial, sans-serif;
}

.dash__todo-label {
  font-size: 12px;
  margin-top: 4px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 2px;
}

.dash__todo-arrow { font-size: 11px; }

.dash__split {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 14px;

  @media (max-width: 992px) {
    grid-template-columns: 1fr;
  }
}

.dash__rank-list { display: flex; flex-direction: column; }

.dash__rank-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 9px 0;
  border-bottom: 1px solid $color-border;

  &:last-child { border-bottom: none; }
}

.dash__rank-no {
  width: 22px;
  height: 22px;
  border-radius: 6px;
  background: $color-surface;
  color: $color-text-tertiary;
  font-size: 11px;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;

  &.is-top {
    background: var(--color-primary-bg);
    color: $color-primary;
  }
}

.dash__rank-amount {
  font-size: 14px;
  margin-left: auto;
}

.dash__total {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 10px;
  text-align: center;
}

.dash__total-value { font-size: 22px; }
.dash__total-label {
  font-size: 12px;
  color: $color-text-tertiary;
  margin-top: 4px;
}
</style>
