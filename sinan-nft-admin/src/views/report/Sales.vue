<script setup lang="ts">
/**
 * 销售报表：GMV/订单趋势、来源分布、支付方式分布、TOP 藏品榜
 *
 * 严谨性：
 * - 时间区间后端默认近 30 天、最长 366 天（超 92 天自动按月聚合）
 * - 仅「已完成」订单计入 GMV（与后口径一致）
 */
import { computed, onMounted, reactive, ref } from 'vue'
import type { EChartsOption } from 'echarts'
import EChart from '@/components/EChart.vue'
import { fetchSalesReport } from '@/api/misc'
import { money } from '@/utils/format'

const loading = ref(false)
const report = ref<any>({})

// 时间区间（date picker → startDate/endDate，与后端 dateRange() 对齐）
const range = ref<[string, string] | null>(null)

async function load() {
  loading.value = true
  try {
    const params: Record<string, any> = {}
    if (range.value) {
      params.startDate = range.value[0]
      params.endDate = range.value[1]
    }
    report.value = (await fetchSalesReport(params)) || {}
  } finally {
    loading.value = false
  }
}

onMounted(load)

const summary = computed<any>(() => report.value.summary || {})
const trend = computed<any[]>(() => report.value.trend || [])
const sources = computed<any[]>(() => report.value.sources || [])
const payments = computed<any[]>(() => report.value.payments || [])
const topCollectibles = computed<any[]>(() => report.value.topCollectibles || [])

const summaryCards = computed(() => [
  { label: 'GMV 总额（元）', value: money(summary.value.gmvTotal), sub: `区间订单 ${summary.value.orderTotal ?? 0} 笔` },
  { label: '订单总数', value: summary.value.orderTotal ?? 0, sub: `平均客单价 ${money(summary.value.avgOrderAmount)} 元` },
  { label: '成交件数', value: summary.value.quantityTotal ?? 0, sub: '仅已完成订单计入' },
  { label: '购买用户数', value: summary.value.buyerCount ?? 0, sub: '去重统计' },
])

// GMV + 订单量趋势
const trendOption = computed((): EChartsOption => ({
  tooltip: { trigger: 'axis' },
  legend: { data: ['GMV（元）', '订单量', '成交件数'], top: 0 },
  grid: { left: 8, right: 16, top: 36, bottom: 8, containLabel: true },
  xAxis: { type: 'category', data: trend.value.map((r: any) => r.stat_date), boundaryGap: false },
  yAxis: [{ type: 'value', axisLine: { show: false } }],
  series: [
    { name: 'GMV（元）', type: 'line', smooth: true, data: trend.value.map((r: any) => r.gmv), itemStyle: { color: '#b00000' }, areaStyle: { color: 'rgba(176,0,0,0.08)' } },
    { name: '订单量', type: 'bar', barMaxWidth: 18, data: trend.value.map((r: any) => r.order_count), itemStyle: { color: '#d56666' } },
    { name: '成交件数', type: 'line', smooth: true, data: trend.value.map((r: any) => r.quantity), itemStyle: { color: '#1565c0' } },
  ],
}))

// 订单来源分布（GMV 占比）
const sourceOption = computed((): EChartsOption => ({
  tooltip: { trigger: 'item', formatter: '{b}<br/>GMV：{c} 元（{d}%）' },
  legend: { bottom: 0 },
  series: [
    {
      type: 'pie',
      radius: ['42%', '68%'],
      center: ['50%', '44%'],
      data: sources.value.map((s: any) => ({ name: s.sourceName, value: s.gmv })),
      label: { show: false },
      itemStyle: { color: (p: any) => ['#b00000', '#d56666', '#1565c0', '#6a1b9a', '#00838f'][p.dataIndex % 5] },
    },
  ],
}))

// 支付方式分布（金额）
const payOption = computed((): EChartsOption => ({
  tooltip: { trigger: 'item', formatter: '{b}<br/>金额：{c} 元（{d}%）' },
  legend: { bottom: 0 },
  series: [
    {
      type: 'pie',
      radius: ['42%', '68%'],
      center: ['50%', '44%'],
      data: payments.value.map((p: any) => ({ name: payMethodName(p.method), value: p.amount })),
      label: { show: false },
    },
  ],
}))

function payMethodName(m: string): string {
  const map: Record<string, string> = { balance: '余额', alipay: '支付宝', wechat: '微信', huifu: '汇付', unionpay: '银联' }
  return map[m] || m
}
</script>

<template>
  <div class="page-container" v-loading="loading">
    <div class="search-bar">
      <el-form inline size="small">
        <el-form-item label="统计区间">
          <el-date-picker
            v-model="range"
            type="daterange"
            value-format="YYYY-MM-DD"
            range-separator="至"
            start-placeholder="开始日期"
            end-placeholder="结束日期"
            :clearable="true"
            style="width: 260px"
          />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="load">查询</el-button>
        </el-form-item>
        <el-form-item>
          <span class="text-xs text-gray-500">默认近 30 天；超 92 天自动按月聚合，最长 366 天</span>
        </el-form-item>
      </el-form>
    </div>

    <div class="stat-grid">
      <div v-for="card in summaryCards" :key="card.label" class="stat-card">
        <div class="stat-info">
          <div class="stat-label">{{ card.label }}</div>
          <div class="stat-value">{{ card.value }}</div>
          <div class="stat-sub">{{ card.sub }}</div>
        </div>
      </div>
    </div>

    <div class="chart-box">
      <div class="chart-title">GMV / 订单量趋势</div>
      <EChart :option="trendOption" height="320px" />
    </div>

    <div class="grid-2">
      <div class="chart-box">
        <div class="chart-title">订单来源分布（GMV）</div>
        <EChart :option="sourceOption" height="300px" />
      </div>
      <div class="chart-box">
        <div class="chart-title">支付方式分布（金额）</div>
        <EChart :option="payOption" height="300px" />
      </div>
    </div>

    <div class="chart-box">
      <div class="chart-title">TOP 藏品销售榜（按 GMV）</div>
      <el-table :data="topCollectibles" border>
        <el-table-column type="index" label="#" width="60" align="center" />
        <el-table-column label="藏品" min-width="220">
          <template #default="scope">
            <div class="flex items-center">
              <el-image class="table-img" :src="scope.row.cover_image" :preview-src-list="[scope.row.cover_image]" fit="cover" />
              <span class="ml-2">{{ scope.row.name }}</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="order_count" label="订单数" width="110" align="right" />
        <el-table-column prop="quantity" label="成交件数" width="110" align="right" />
        <el-table-column label="GMV（元）" width="140" align="right">
          <template #default="scope">{{ money(scope.row.gmv) }}</template>
        </el-table-column>
      </el-table>
    </div>
  </div>
</template>

<style scoped lang="scss">
.grid-2 {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 16px;

  @media (max-width: 1200px) {
    grid-template-columns: 1fr;
  }
}
</style>
