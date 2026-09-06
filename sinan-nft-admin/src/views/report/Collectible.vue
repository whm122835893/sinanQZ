<script setup lang="ts">
/**
 * 藏品报表：总量分布、分类分布、发售趋势、市场寄售概览、流通 TOP
 *
 * 库存守恒：edition = sold + circulate(在途) + available_pool + reserved + airdropped + destroyed
 */
import { computed, onMounted, ref } from 'vue'
import type { EChartsOption } from 'echarts'
import EChart from '@/components/EChart.vue'
import { fetchCollectibleReport } from '@/api/misc'
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
    report.value = (await fetchCollectibleReport(params)) || {}
  } finally {
    loading.value = false
  }
}

onMounted(load)

const summary = computed<any>(() => report.value.summary || {})
const categories = computed<any[]>(() => report.value.categories || [])
const issueTrend = computed<any[]>(() => report.value.issueTrend || [])
const market = computed<any>(() => report.value.market || {})
const topCirculate = computed<any[]>(() => report.value.topCirculate || [])

const summaryCards = computed(() => [
  { label: '藏品总数', value: summary.value.total ?? 0, sub: `盲盒类 ${summary.value.isBlindbox ?? 0} 款` },
  { label: '在售/待售', value: summary.value.onsale ?? 0, sub: 'upcoming + onsale' },
  { label: '已售罄', value: summary.value.soldout ?? 0, sub: '存量清零' },
  { label: '已下架', value: summary.value.delisted ?? 0, sub: '停止发售' },
])

// 发售趋势：新增藏品数 + 发行量
const issueOption = computed((): EChartsOption => ({
  tooltip: { trigger: 'axis' },
  legend: { data: ['新增藏品数', '新增发行量'], top: 0 },
  grid: { left: 8, right: 16, top: 36, bottom: 8, containLabel: true },
  xAxis: { type: 'category', data: issueTrend.value.map((r: any) => r.stat_date), boundaryGap: false },
  yAxis: [{ type: 'value', minInterval: 1, axisLine: { show: false } }],
  series: [
    { name: '新增藏品数', type: 'line', smooth: true, data: issueTrend.value.map((r: any) => r.new_count), itemStyle: { color: '#b00000' }, areaStyle: { color: 'rgba(176,0,0,0.08)' } },
    { name: '新增发行量', type: 'bar', barMaxWidth: 18, data: issueTrend.value.map((r: any) => r.edition_total), itemStyle: { color: '#1565c0' } },
  ],
}))

// 分类分布（藏品数）
const categoryOption = computed((): EChartsOption => ({
  tooltip: { trigger: 'item', formatter: '{b}<br/>藏品数：{c}（{d}%）' },
  legend: { type: 'scroll', bottom: 0 },
  series: [
    {
      type: 'pie',
      radius: ['42%', '68%'],
      center: ['50%', '44%'],
      data: categories.value.map((c: any) => ({ name: c.category_name, value: c.cnt })),
      label: { show: false },
    },
  ],
}))

const marketCards = computed(() => [
  { label: '寄售中挂单', value: market.value.selling ?? 0, money: false },
  { label: '寄售中金额（元）', value: money(market.value.sellingAmount), money: true },
  { label: '已成交笔数', value: market.value.sold ?? 0, money: false },
  { label: '成交总额（元）', value: money(market.value.soldAmount), money: true },
  { label: '平台手续费（元）', value: money(market.value.feeAmount), money: true },
  { label: '已撤销笔数', value: market.value.cancelled ?? 0, money: false },
])
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
          <span class="text-xs text-gray-500">发售趋势按区间统计；总量与市场数据为全站实时快照</span>
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

    <div class="grid-2">
      <div class="chart-box">
        <div class="chart-title">发售趋势（区间新增）</div>
        <EChart :option="issueOption" height="300px" />
      </div>
      <div class="chart-box">
        <div class="chart-title">分类分布（藏品数）</div>
        <EChart :option="categoryOption" height="300px" />
      </div>
    </div>

    <div class="chart-box">
      <div class="chart-title">二级市场寄售概览（全站）</div>
      <div class="market-grid">
        <div v-for="card in marketCards" :key="card.label" class="market-item">
          <div class="market-num">{{ card.value }}</div>
          <div class="market-label">{{ card.label }}</div>
        </div>
      </div>
    </div>

    <div class="chart-box">
      <div class="chart-title">流通量 TOP 10（全站）</div>
      <el-table :data="topCirculate" border>
        <el-table-column type="index" label="#" width="60" align="center" />
        <el-table-column label="藏品" min-width="220">
          <template #default="scope">
            <div class="flex items-center">
              <el-image class="table-img" :src="scope.row.cover_image" :preview-src-list="[scope.row.cover_image]" fit="cover" />
              <span class="ml-2">{{ scope.row.name }}</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="edition" label="发行量" width="100" align="right" />
        <el-table-column prop="sold" label="已售" width="100" align="right" />
        <el-table-column prop="circulate" label="流通" width="100" align="right" />
        <el-table-column prop="airdropped_count" label="空投" width="90" align="right" />
        <el-table-column prop="reserved_count" label="预留" width="90" align="right" />
        <el-table-column prop="destroyed_count" label="销毁" width="90" align="right" />
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

.market-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
  gap: 12px;
}

.market-item {
  border: 1px solid var(--sn-border);
  border-radius: 8px;
  padding: 16px;
  text-align: center;

  .market-num {
    font-size: 22px;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
    color: var(--sn-red);
  }
  .market-label {
    margin-top: 4px;
    font-size: 12px;
    color: var(--sn-text-secondary);
  }
}
</style>
