<script setup>
import { ref, computed, onMounted } from 'vue'
import { getStatistics } from '@/api'
import StatCard from '@/components/StatCard.vue'
import EChart from '@/components/EChart.vue'
import { fmtMoney, fmtNumber } from '@/utils/format'

const loading = ref(true)
const data = ref(null)

onMounted(async () => {
  const res = await getStatistics()
  data.value = res.data
  loading.value = false
})

// ---- 用户增长趋势 ----
const userTrendOption = computed(() => ({
  tooltip: { trigger: 'axis' },
  grid: { left: 50, right: 16, top: 20, bottom: 24 },
  xAxis: {
    type: 'category',
    data: data.value?.userTrend.map((t) => t.date) || [],
    axisLine: { lineStyle: { color: '#ddd' } },
    axisLabel: { color: '#999' }
  },
  yAxis: { type: 'value', splitLine: { lineStyle: { color: '#f2f3f5' } }, axisLabel: { color: '#999' } },
  series: [{
    name: '新增注册',
    type: 'line',
    smooth: true,
    symbolSize: 6,
    data: data.value?.userTrend.map((t) => t.value) || [],
    lineStyle: { width: 2.5, color: '#1989fa' },
    itemStyle: { color: '#1989fa' },
    areaStyle: {
      color: {
        type: 'linear', x: 0, y: 0, x2: 0, y2: 1,
        colorStops: [
          { offset: 0, color: 'rgba(25,137,250,0.22)' },
          { offset: 1, color: 'rgba(25,137,250,0.02)' }
        ]
      }
    }
  }]
}))

// ---- DAU + 留存 ----
const dauOption = computed(() => {
  const d = data.value
  if (!d) return {}
  return {
    tooltip: { trigger: 'axis' },
    legend: { data: ['日活 DAU'], top: 0, textStyle: { color: '#666', fontSize: 11 } },
    grid: { left: 50, right: 16, top: 30, bottom: 24 },
    xAxis: {
      type: 'category',
      data: ['周一', '周二', '周三', '周四', '周五', '周六', '周日'],
      axisLine: { lineStyle: { color: '#ddd' } },
      axisLabel: { color: '#999' }
    },
    yAxis: { type: 'value', splitLine: { lineStyle: { color: '#f2f3f5' } }, axisLabel: { color: '#999' } },
    series: [{
      name: '日活 DAU',
      type: 'bar',
      barWidth: 22,
      data: d.dauTrend,
      itemStyle: {
        borderRadius: [4, 4, 0, 0],
        color: { type: 'linear', x: 0, y: 0, x2: 0, y2: 1, colorStops: [
          { offset: 0, color: '#C00000' }, { offset: 1, color: 'rgba(192,0,0,0.4)' }
        ] }
      }
    }]
  }
})

const retentionOption = computed(() => ({
  tooltip: { trigger: 'item', formatter: '{b}: {c}%' },
  legend: { bottom: 0, icon: 'circle', itemWidth: 8, itemHeight: 8, textStyle: { color: '#666', fontSize: 11 } },
  series: [{
    type: 'pie',
    radius: ['48%', '72%'],
    center: ['50%', '44%'],
    label: { show: false },
    data: (data.value?.retention || []).map((r, i) => ({
      ...r,
      itemStyle: { color: ['#C00000', '#D4A574', '#1989fa'][i % 3] }
    }))
  }]
}))

// ---- 财务报表 ----
const finance = computed(() => data.value?.finance || {})

const incomeOption = computed(() => ({
  tooltip: { trigger: 'axis', valueFormatter: (v) => `¥${Number(v).toLocaleString()}` },
  grid: { left: 60, right: 16, top: 20, bottom: 24 },
  xAxis: {
    type: 'category',
    data: finance.value.incomeTrend?.map((t) => t.date) || [],
    axisLine: { lineStyle: { color: '#ddd' } },
    axisLabel: { color: '#999' }
  },
  yAxis: { type: 'value', splitLine: { lineStyle: { color: '#f2f3f5' } }, axisLabel: { color: '#999' } },
  series: [{
    name: '日收入',
    type: 'line',
    smooth: true,
    symbolSize: 6,
    data: finance.value.incomeTrend?.map((t) => t.value) || [],
    lineStyle: { width: 2.5, color: '#D4A574' },
    itemStyle: { color: '#D4A574' },
    areaStyle: {
      color: {
        type: 'linear', x: 0, y: 0, x2: 0, y2: 1,
        colorStops: [
          { offset: 0, color: 'rgba(212,165,116,0.25)' },
          { offset: 1, color: 'rgba(212,165,116,0.02)' }
        ]
      }
    }
  }]
}))

const feeShareOption = computed(() => ({
  tooltip: { trigger: 'item', formatter: '{b}: {d}%' },
  legend: { bottom: 0, icon: 'circle', itemWidth: 8, itemHeight: 8, textStyle: { color: '#666', fontSize: 11 } },
  series: [{
    type: 'pie',
    radius: ['48%', '72%'],
    center: ['50%', '44%'],
    label: { show: false },
    data: (finance.value.feeShare || []).map((s, i) => ({
      ...s,
      itemStyle: { color: ['#C00000', '#D4A574', '#07c160'][i % 3] }
    }))
  }]
}))

// ---- 热门藏品排行 ----
const salesRank = computed(() => data.value?.salesRank || [])
</script>

<template>
  <div class="adm-page st">
    <el-skeleton v-if="loading" :rows="8" animated style="padding: 20px" />
    <template v-else-if="data">
      <!-- 用户核心指标 -->
      <div class="adm-grid">
        <StatCard icon="User" label="日活用户（DAU）" :value="fmtNumber(data.dau)" unit="人" tone="primary" />
        <StatCard icon="TrendCharts" label="次日留存" :value="`${data.retention[0]?.value ?? 0}%`" tone="gold" />
        <StatCard icon="Coin" label="本月收入" :value="fmtMoney(finance.monthIncome)" unit="元" tone="blue" />
        <StatCard icon="Wallet" label="本月手续费" :value="fmtMoney(finance.monthFee)" unit="元" tone="green" />
      </div>

      <!-- 用户增长 & DAU -->
      <div class="st__row">
        <div class="adm-card">
          <div class="adm-card__title">用户增长趋势（近 7 日注册）</div>
          <EChart :option="userTrendOption" :height="300" />
        </div>
        <div class="adm-card">
          <div class="adm-card__title">日活 DAU（近 7 日）</div>
          <EChart :option="dauOption" :height="300" />
        </div>
      </div>

      <!-- 留存 & 财务收入 -->
      <div class="st__row">
        <div class="adm-card">
          <div class="adm-card__title">用户留存</div>
          <EChart :option="retentionOption" :height="300" />
        </div>
        <div class="adm-card">
          <div class="adm-card__title">平台日收入趋势</div>
          <EChart :option="incomeOption" :height="300" />
        </div>
      </div>

      <!-- 财务总览 & 手续费构成 -->
      <div class="st__row">
        <div class="adm-card">
          <div class="adm-card__title">财务月度总览</div>
          <div class="st__finance">
            <div class="st__fin-item">
              <div class="t-tertiary" style="font-size: 12px">本月充值</div>
              <div class="st__fin-val">¥{{ fmtNumber(finance.monthRecharge) }}</div>
            </div>
            <div class="st__fin-item">
              <div class="t-tertiary" style="font-size: 12px">本月提现</div>
              <div class="st__fin-val">¥{{ fmtNumber(finance.monthWithdraw) }}</div>
            </div>
            <div class="st__fin-item">
              <div class="t-tertiary" style="font-size: 12px">平台收入</div>
              <div class="st__fin-val">¥{{ fmtNumber(finance.monthIncome) }}</div>
            </div>
            <div class="st__fin-item">
              <div class="t-tertiary" style="font-size: 12px">手续费收入</div>
              <div class="st__fin-val">¥{{ fmtNumber(finance.monthFee) }}</div>
            </div>
          </div>
        </div>
        <div class="adm-card">
          <div class="adm-card__title">手续费构成</div>
          <EChart :option="feeShareOption" :height="300" />
        </div>
      </div>

      <!-- 热门藏品排行 -->
      <div class="adm-card">
        <div class="adm-card__title">热门藏品排行（按销量）</div>
        <el-table :data="salesRank">
          <el-table-column label="排名" width="70" align="center">
            <template #default="{ $index }">
              <span class="st__rank" :class="`is-${$index + 1}`">{{ $index + 1 }}</span>
            </template>
          </el-table-column>
          <el-table-column label="藏品" min-width="200">
            <template #default="{ row }">
              <div class="st__rank-cell">
                <img :src="row.cover" :alt="row.name" />
                <div>
                  <div class="st__rank-name">{{ row.name }}</div>
                  <div class="t-tertiary" style="font-size: 12px">{{ row.category }}</div>
                </div>
              </div>
            </template>
          </el-table-column>
          <el-table-column label="价格" width="120" align="right">
            <template #default="{ row }">¥{{ fmtMoney(row.price) }}</template>
          </el-table-column>
          <el-table-column label="销量" prop="sold" width="100" align="right">
            <template #default="{ row }">{{ fmtNumber(row.sold) }} 份</template>
          </el-table-column>
          <el-table-column label="销售额" width="140" align="right">
            <template #default="{ row }">
              <span class="price">¥{{ fmtNumber(row.price * row.sold) }}</span>
            </template>
          </el-table-column>
        </el-table>
      </div>
    </template>
  </div>
</template>

<style scoped lang="scss">
.st__row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;

  & + & { margin-top: 16px; }
}

.st__finance {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
}

.st__fin-item {
  background: $color-bg;
  border-radius: 8px;
  padding: 16px;
}

.st__fin-val {
  font-size: 22px;
  font-weight: 700;
  color: $color-text-primary;
  margin-top: 6px;
}

.st__rank {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 24px;
  height: 24px;
  border-radius: 50%;
  font-size: 12px;
  font-weight: 700;
  background: $color-surface;
  color: $color-text-secondary;

  &.is-1 { background: $color-primary; color: #fff; }
  &.is-2 { background: $color-gold; color: #fff; }
  &.is-3 { background: #b08d55; color: #fff; }
}

.st__rank-cell {
  display: flex;
  align-items: center;
  gap: 10px;

  img {
    width: 44px;
    height: 44px;
    border-radius: 8px;
    object-fit: cover;
    background: $color-surface;
  }
}

.st__rank-name {
  font-weight: 600;
  color: $color-text-primary;
}

@media (max-width: 992px) {
  .st__row { grid-template-columns: 1fr; }
}
</style>
