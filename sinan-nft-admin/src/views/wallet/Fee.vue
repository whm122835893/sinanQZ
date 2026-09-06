<script setup lang="ts">
/**
 * 手续费统计（寄售成交）
 *
 * - 数据源：WalletController::fee → { totalFee, totalDeal, trend[{date,count,fee}], top[{name,dealCount,feeTotal}] }
 * - 统计卡：平台手续费总额 / 寄售成交笔数 / 笔均手续费 / 近 30 日手续费
 * - 按日趋势：EChart 双轴图（手续费折线 + 成交笔数柱状）
 * - 按藏品明细：TOP10 横向柱状图 + 明细表格
 */
import { computed, onMounted, ref } from 'vue'
import type { EChartsOption } from 'echarts'
import EChart from '@/components/EChart.vue'
import { money } from '@/utils/format'
import { fetchFeeStats } from '@/api/wallet'

const loading = ref(false)
const stats = ref<any>({})

async function load() {
  loading.value = true
  try {
    stats.value = (await fetchFeeStats()) || {}
  } finally {
    loading.value = false
  }
}

onMounted(load)

const trend = computed(() => stats.value.trend || [])
const top = computed(() => stats.value.top || [])

/** 近 30 日手续费合计 / 成交笔数合计 */
const trendFeeTotal = computed(() => trend.value.reduce((sum: number, r: any) => sum + Number(r.fee || 0), 0))
const trendCountTotal = computed(() => trend.value.reduce((sum: number, r: any) => sum + Number(r.count || 0), 0))

/** 笔均手续费 = 手续费总额 ÷ 成交笔数 */
const avgFee = computed(() => {
  const deal = Number(stats.value.totalDeal) || 0
  return deal > 0 ? (Number(stats.value.totalFee) || 0) / deal : 0
})

const statCards = computed(() => [
  { label: '平台手续费总额（元）', value: money(stats.value.totalFee), sub: '寄售成交（sold）手续费沉淀', icon: 'Coin', color: '' },
  { label: '寄售成交笔数', value: stats.value.totalDeal ?? 0, sub: '状态为已售出的挂单合计', icon: 'ShoppingCart', color: 'blue' },
  { label: '笔均手续费（元）', value: money(avgFee.value), sub: '手续费总额 ÷ 成交笔数', icon: 'TrendCharts', color: 'green' },
  { label: '近 30 日手续费（元）', value: money(trendFeeTotal.value), sub: `近 30 日成交 ${trendCountTotal.value} 笔`, icon: 'DataAnalysis', color: 'orange' },
])

/** 近 30 日趋势：手续费折线（左轴）+ 成交笔数柱状（右轴） */
const trendOption = computed<EChartsOption>(() => ({
  tooltip: { trigger: 'axis' },
  legend: { data: ['手续费（元）', '成交笔数'], top: 0 },
  grid: { left: 8, right: 8, top: 36, bottom: 8, containLabel: true },
  xAxis: {
    type: 'category',
    data: trend.value.map((r: any) => r.date),
    boundaryGap: false,
  },
  yAxis: [
    { type: 'value', name: '手续费（元）', axisLine: { show: false } },
    { type: 'value', name: '成交笔数', axisLine: { show: false }, splitLine: { show: false } },
  ],
  series: [
    {
      name: '手续费（元）',
      type: 'line',
      smooth: true,
      data: trend.value.map((r: any) => r.fee),
      itemStyle: { color: '#b00000' },
      areaStyle: { color: 'rgba(176,0,0,0.08)' },
    },
    {
      name: '成交笔数',
      type: 'bar',
      yAxisIndex: 1,
      barMaxWidth: 18,
      data: trend.value.map((r: any) => r.count),
      itemStyle: { color: '#1565c0' },
    },
  ],
}))

/** 按藏品 TOP10：横向柱状图 */
const topOption = computed<EChartsOption>(() => ({
  tooltip: { trigger: 'axis', axisPointer: { type: 'shadow' } },
  grid: { left: 8, right: 32, top: 8, bottom: 8, containLabel: true },
  xAxis: { type: 'value', name: '手续费（元）' },
  yAxis: {
    type: 'category',
    inverse: true,
    data: top.value.map((r: any) => r.name || '未关联藏品'),
  },
  series: [
    {
      name: '手续费（元）',
      type: 'bar',
      barMaxWidth: 18,
      data: top.value.map((r: any) => r.feeTotal),
      itemStyle: { color: '#b26a00' },
    },
  ],
}))

/** TOP10 手续费占平台手续费总额比例 */
function feeShare(row: any) {
  const total = Number(stats.value.totalFee) || 0
  if (!total) return '-'
  return ((Number(row.feeTotal || 0) / total) * 100).toFixed(2) + '%'
}
</script>

<template>
  <div class="page-container" v-loading="loading">
    <!-- 统计卡 -->
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

    <!-- 近 30 日趋势 -->
    <div class="chart-box">
      <div class="chart-title">
        近 30 日手续费与成交趋势
        <el-button size="small" style="margin-left: 12px" :loading="loading" @click="load">
          <el-icon><Refresh /></el-icon>&nbsp;刷新
        </el-button>
      </div>
      <EChart :option="trendOption" height="340px" />
    </div>

    <!-- 按藏品 TOP10 分布图 -->
    <div class="chart-box">
      <div class="chart-title">藏品手续费 TOP10</div>
      <EChart :option="topOption" height="320px" />
    </div>

    <!-- 按藏品明细表 -->
    <div class="table-card">
      <div class="table-header">
        <span class="table-title">按藏品手续费明细（TOP10）</span>
        <span class="table-tip">按手续费合计降序排列，成交口径为寄售挂单状态 sold</span>
      </div>

      <el-table :data="top" border stripe>
        <el-table-column label="排名" type="index" width="70" align="center" />
        <el-table-column label="藏品名称" min-width="220" show-overflow-tooltip>
          <template #default="{ row }">{{ row.name || '未关联藏品' }}</template>
        </el-table-column>
        <el-table-column label="成交笔数" prop="dealCount" width="110" align="center" />
        <el-table-column label="手续费合计（元）" width="140" align="right">
          <template #default="{ row }">
            <span class="amount">{{ money(row.feeTotal) }}</span>
          </template>
        </el-table-column>
        <el-table-column label="手续费占比" width="110" align="center">
          <template #default="{ row }">{{ feeShare(row) }}</template>
        </el-table-column>
      </el-table>
    </div>
  </div>
</template>

<style scoped lang="scss">
.table-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 14px;

  .table-title {
    font-size: 15px;
    font-weight: 600;
  }

  .table-tip {
    font-size: 12px;
    color: var(--sn-text-secondary);
  }
}

.amount {
  font-weight: 600;
  color: var(--sn-red);
  font-variant-numeric: tabular-nums;
}
</style>
