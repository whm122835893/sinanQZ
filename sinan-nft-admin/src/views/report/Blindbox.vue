<script setup lang="ts">
/**
 * 盲盒报表：开盒趋势、奖池分布（发放/上限）、各盲盒开盒排行
 *
 * 严谨性：发放量恒不超过数量上限（quantity_distributed ≤ quantity_limit），
 * 前端同时展示两个字段便于人工核对。
 */
import { computed, onMounted, ref } from 'vue'
import type { EChartsOption } from 'echarts'
import EChart from '@/components/EChart.vue'
import { fetchBlindboxReport } from '@/api/misc'
import { money } from '@/utils/format'

const loading = ref(false)
const report = ref<any>({})
const range = ref<[string, string] | null>(null)

async function load() {
  loading.value = true
  try {
    const params: Record<string, any> = {}
    if (range.value) {
      params.startDate = range.value[0]
      params.endDate = range.value[1]
    }
    report.value = (await fetchBlindboxReport(params)) || {}
  } finally {
    loading.value = false
  }
}

onMounted(load)

const summary = computed<any>(() => report.value.summary || {})
const openTrend = computed<any[]>(() => report.value.openTrend || [])
const prizes = computed<any[]>(() => report.value.prizes || [])
const boxRanking = computed<any[]>(() => report.value.boxRanking || [])

const summaryCards = computed(() => [
  { label: '盲盒总数', value: summary.value.total ?? 0, sub: '未删除的有效盲盒' },
  { label: '累计开盒数', value: summary.value.openedTotal ?? 0, sub: '全站累计' },
])

const openOption = computed((): EChartsOption => ({
  tooltip: { trigger: 'axis' },
  grid: { left: 8, right: 16, top: 24, bottom: 8, containLabel: true },
  xAxis: { type: 'category', data: openTrend.value.map((r: any) => r.stat_date), boundaryGap: false },
  yAxis: [{ type: 'value', minInterval: 1, axisLine: { show: false } }],
  series: [
    {
      name: '开盒数',
      type: 'bar',
      barMaxWidth: 20,
      data: openTrend.value.map((r: any) => r.open_count),
      itemStyle: { color: '#b00000' },
    },
  ],
}))

// 奖池发放进度（发放量 vs 上限）
const prizeOption = computed((): EChartsOption => ({
  tooltip: { trigger: 'axis' },
  legend: { data: ['已发放', '数量上限'], top: 0 },
  grid: { left: 8, right: 16, top: 36, bottom: 8, containLabel: true },
  xAxis: {
    type: 'category',
    data: prizes.value.map((p: any) => p.prize_name),
    axisLabel: { interval: 0, rotate: 24, width: 90, overflow: 'truncate' },
  },
  yAxis: [{ type: 'value', minInterval: 1, axisLine: { show: false } }],
  series: [
    { name: '已发放', type: 'bar', barMaxWidth: 22, stack: 'prize', data: prizes.value.map((p: any) => p.distributed_total), itemStyle: { color: '#b00000' } },
    { name: '数量上限', type: 'bar', barMaxWidth: 22, stack: 'prize', data: prizes.value.map((p: any) => Math.max(0, p.limit_total - p.distributed_total)), itemStyle: { color: '#e0e0e0' } },
  ],
}))
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
          <span class="text-xs text-gray-500">开盒趋势按区间统计；总量为全站累计快照</span>
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
      <div class="chart-title">开盒趋势</div>
      <EChart :option="openOption" height="300px" />
    </div>

    <div class="chart-box">
      <div class="chart-title">奖池发放进度（堆积：红=已发放，灰=剩余额度）</div>
      <EChart :option="prizeOption" height="320px" />
    </div>

    <div class="chart-box">
      <div class="chart-title">盲盒开盒排行 TOP 10</div>
      <el-table :data="boxRanking" border>
        <el-table-column type="index" label="#" width="60" align="center" />
        <el-table-column prop="id" label="盲盒ID" width="100" />
        <el-table-column prop="name" label="盲盒名称" min-width="200" />
        <el-table-column prop="prize_count" label="奖品种类" width="110" align="right" />
        <el-table-column prop="opened_count" label="累计开盒" width="110" align="right" />
        <el-table-column label="单价（元）" width="120" align="right">
          <template #default="scope">{{ money(scope.row.price) }}</template>
        </el-table-column>
      </el-table>
    </div>

    <div class="chart-box">
      <div class="chart-title">奖品发放明细（发放量 ≤ 上限）</div>
      <el-table :data="prizes" border>
        <el-table-column type="index" label="#" width="60" align="center" />
        <el-table-column prop="prize_collectible_id" label="奖品ID" width="100" />
        <el-table-column prop="prize_name" label="奖品名称" min-width="200" />
        <el-table-column prop="box_count" label="涉及盲盒数" width="120" align="right" />
        <el-table-column prop="distributed_total" label="已发放" width="110" align="right">
          <template #default="scope">
            <span :class="Number(scope.row.distributed_total) > Number(scope.row.limit_total) ? 'text-red-600 font-bold' : ''">
              {{ scope.row.distributed_total }}
            </span>
          </template>
        </el-table-column>
        <el-table-column prop="limit_total" label="数量上限" width="110" align="right" />
        <el-table-column label="发放进度" min-width="160">
          <template #default="scope">
            <el-progress
              :percentage="Number(scope.row.limit_total) > 0 ? Math.min(100, Math.round(scope.row.distributed_total / scope.row.limit_total * 100)) : 0"
              :color="Number(scope.row.distributed_total) >= Number(scope.row.limit_total) ? '#b00000' : '#d56666'"
            />
          </template>
        </el-table-column>
      </el-table>
    </div>
  </div>
</template>
