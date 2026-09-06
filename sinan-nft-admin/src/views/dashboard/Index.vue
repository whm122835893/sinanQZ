<script setup lang="ts">
/**
 * 数据大盘：核心指标卡 + GMV/订单/用户趋势图 + 待办事项
 */
import { computed, onMounted, ref } from 'vue'
import { User, Collection, Document, ShoppingCart, Bell, Warning } from '@element-plus/icons-vue'
import type { EChartsOption } from 'echarts'
import EChart from '@/components/EChart.vue'
import { fetchOverview, fetchTrend } from '@/api/dashboard'
import { money } from '@/utils/format'

const loading = ref(false)
const overview = ref<any>({})
const trend = ref<any[]>([])
const trendDays = ref(7)

async function loadAll() {
  loading.value = true
  try {
    const [ov, tr] = await Promise.all([fetchOverview(), fetchTrend({ days: trendDays.value })])
    overview.value = ov || {}
    trend.value = tr?.series || []
  } finally {
    loading.value = false
  }
}

onMounted(loadAll)

// 指标卡
const statCards = computed(() => {
  const u = overview.value.user || {}
  const c = overview.value.collectible || {}
  const o = overview.value.order || {}
  return [
    { label: '注册用户', value: u.total ?? 0, sub: `今日新增 ${u.today ?? 0} · 实名率 ${u.realnameRate ?? 0}%`, icon: 'User', color: '' },
    { label: 'GMV 总额（元）', value: money(o.gmvTotal), sub: `今日 ${money(o.gmvToday)} 元`, icon: 'ShoppingCart', color: 'green' },
    { label: '订单总数', value: o.total ?? 0, sub: `今日 ${o.today ?? 0} · 待支付 ${o.pending ?? 0}`, icon: 'Document', color: 'blue' },
    { label: '在售藏品', value: c.onsale ?? 0, sub: `共 ${c.total ?? 0} 款 · 盲盒 ${c.blindBox ?? 0} 款`, icon: 'Collection', color: 'orange' },
  ]
})

// 待办事项
const todos = computed(() => {
  const m = overview.value.market || {}
  const t = overview.value.todo || {}
  return [
    { label: '待审批退款', value: t.refundPending ?? 0, path: '/refund', color: '#b00000' },
    { label: '待处理工单', value: t.ticketOpen ?? 0, path: '/ticket', color: '#b26a00' },
    { label: '风控告警', value: t.riskAlert ?? 0, path: '/security/risk-alert', color: '#c62828' },
    { label: '寄售中挂单', value: m.listingSelling ?? 0, path: '/market', color: '#1565c0' },
    { label: '待确认转赠', value: m.transferPending ?? 0, path: '/transfer', color: '#6a1b9a' },
  ]
})

// 趋势图配置（GMV + 订单量 + 新增用户）
const trendOption = computed((): EChartsOption => ({
  tooltip: { trigger: 'axis' },
  legend: { data: ['GMV（元）', '订单量', '新增用户'], top: 0 },
  grid: { left: 8, right: 16, top: 36, bottom: 8, containLabel: true },
  xAxis: { type: 'category', data: trend.value.map((s: any) => s.date), boundaryGap: false },
  yAxis: [
    { type: 'value', name: '金额/数量', axisLine: { show: false } },
  ],
  series: [
    {
      name: 'GMV（元）',
      type: 'line',
      smooth: true,
      data: trend.value.map((s: any) => s.gmv),
      itemStyle: { color: '#b00000' },
      areaStyle: { color: 'rgba(176,0,0,0.08)' },
    },
    {
      name: '订单量',
      type: 'bar',
      barMaxWidth: 18,
      data: trend.value.map((s: any) => s.orders),
      itemStyle: { color: '#d56666' },
    },
    {
      name: '新增用户',
      type: 'line',
      smooth: true,
      data: trend.value.map((s: any) => s.newUser),
      itemStyle: { color: '#1565c0' },
    },
  ],
}))

function changeDays(days: number) {
  trendDays.value = days
  loadAll()
}
</script>

<template>
  <div class="page-container" v-loading="loading">
    <!-- 指标卡 -->
    <div class="stat-grid">
      <div v-for="card in statCards" :key="card.label" class="stat-card">
        <div class="stat-info">
          <div class="stat-label">{{ card.label }}</div>
          <div class="stat-value">{{ card.value }}</div>
          <div class="stat-sub">{{ card.sub }}</div>
        </div>
        <div class="stat-icon" :class="card.color">
          <el-icon :size="22"><component :is="card.icon" /></el-icon>
        </div>
      </div>
    </div>

    <!-- 趋势 -->
    <div class="chart-box">
      <div class="chart-title">
        平台趋势
        <el-radio-group :model-value="trendDays" size="small" style="margin-left: 12px" @change="changeDays">
          <el-radio-button :value="7">近 7 天</el-radio-button>
          <el-radio-button :value="30">近 30 天</el-radio-button>
        </el-radio-group>
      </div>
      <EChart :option="trendOption" height="340px" />
    </div>

    <!-- 待办 -->
    <div class="chart-box">
      <div class="chart-title">待办事项</div>
      <div class="todo-grid">
        <div v-for="todo in todos" :key="todo.label" class="todo-item" @click="$router.push(todo.path)">
          <div class="todo-num" :style="{ color: todo.color }">{{ todo.value }}</div>
          <div class="todo-label">{{ todo.label }}</div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped lang="scss">
.todo-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
  gap: 12px;
}

.todo-item {
  border: 1px solid var(--sn-border);
  border-radius: 8px;
  padding: 18px 16px;
  text-align: center;
  cursor: pointer;
  transition: all 0.15s ease;

  &:hover {
    border-color: var(--sn-red);
    background: var(--sn-red-bg);
    transform: translateY(-2px);
  }

  .todo-num {
    font-size: 28px;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
  }
  .todo-label {
    margin-top: 6px;
    font-size: 13px;
    color: var(--sn-text-secondary);
  }
}
</style>
