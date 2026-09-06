<script setup lang="ts">
/**
 * 用户报表：注册趋势、实名率、持仓分层、注册渠道、邀请 TOP
 */
import { computed, onMounted, ref } from 'vue'
import type { EChartsOption } from 'echarts'
import EChart from '@/components/EChart.vue'
import { fetchUserReport } from '@/api/misc'

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
    report.value = (await fetchUserReport(params)) || {}
  } finally {
    loading.value = false
  }
}

onMounted(load)

const summary = computed<any>(() => report.value.summary || {})
const regTrend = computed<any[]>(() => report.value.regTrend || [])
const holdingBuckets = computed<any[]>(() => report.value.holdingBuckets || [])
const sources = computed<any[]>(() => report.value.sources || [])
const topInviters = computed<any[]>(() => report.value.topInviters || [])

const summaryCards = computed(() => [
  { label: '注册用户总数', value: summary.value.total ?? 0, sub: `区间新增 ${summary.value.newInRange ?? 0}` },
  { label: '实名认证', value: summary.value.realname ?? 0, sub: `实名率 ${summary.value.realnameRate ?? 0}%` },
  { label: '持有用户数', value: summary.value.holdingUsers ?? 0, sub: '至少持有 1 份藏品' },
  { label: '冻结 / 黑名单', value: `${summary.value.frozen ?? 0} / ${summary.value.blacklisted ?? 0}`, sub: '风控受限账户' },
])

const regOption = computed((): EChartsOption => ({
  tooltip: { trigger: 'axis' },
  grid: { left: 8, right: 16, top: 24, bottom: 8, containLabel: true },
  xAxis: { type: 'category', data: regTrend.value.map((r: any) => r.stat_date), boundaryGap: false },
  yAxis: [{ type: 'value', minInterval: 1, axisLine: { show: false } }],
  series: [
    {
      name: '新增注册',
      type: 'line',
      smooth: true,
      data: regTrend.value.map((r: any) => r.reg_count),
      itemStyle: { color: '#b00000' },
      areaStyle: { color: 'rgba(176,0,0,0.08)' },
    },
  ],
}))

const holdingOption = computed((): EChartsOption => ({
  tooltip: { trigger: 'item', formatter: '{b}<br/>用户数：{c}（{d}%）' },
  legend: { bottom: 0 },
  series: [
    {
      type: 'pie',
      radius: ['42%', '68%'],
      center: ['50%', '44%'],
      data: holdingBuckets.value.map((b: any) => ({ name: b.label, value: b.count })),
      label: { show: false },
      itemStyle: { color: (p: any) => ['#90a4ae', '#b00000', '#d56666', '#1565c0'][p.dataIndex % 4] },
    },
  ],
}))

const sourceOption = computed((): EChartsOption => ({
  tooltip: { trigger: 'item', formatter: '{b}<br/>注册数：{c}（{d}%）' },
  legend: { bottom: 0 },
  series: [
    {
      type: 'pie',
      radius: ['42%', '68%'],
      center: ['50%', '44%'],
      data: sources.value.map((s: any) => ({ name: s.sourceName, value: s.count })),
      label: { show: false },
      itemStyle: { color: (p: any) => ['#b00000', '#1565c0'][p.dataIndex % 2] },
    },
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
          <span class="text-xs text-gray-500">注册趋势按区间统计；总量为全站实时快照</span>
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
      <div class="chart-title">注册趋势</div>
      <EChart :option="regOption" height="300px" />
    </div>

    <div class="grid-2">
      <div class="chart-box">
        <div class="chart-title">用户持仓分层</div>
        <EChart :option="holdingOption" height="300px" />
      </div>
      <div class="chart-box">
        <div class="chart-title">注册渠道分布（区间）</div>
        <EChart :option="sourceOption" height="300px" />
      </div>
    </div>

    <div class="chart-box">
      <div class="chart-title">邀请 TOP 10（区间）</div>
      <el-table :data="topInviters" border>
        <el-table-column type="index" label="#" width="60" align="center" />
        <el-table-column prop="uid" label="UID" width="140" />
        <el-table-column prop="username" label="用户名" min-width="160" />
        <el-table-column prop="invite_count" label="邀请人数" width="140" align="right" />
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
