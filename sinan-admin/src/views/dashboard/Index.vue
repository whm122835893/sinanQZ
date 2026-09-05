<script setup lang="ts">
// 数据大盘 P1 完整版（文档 8.3，#8~#13 六接口）
// #8 核心指标卡片 / #9 资金监控图表 / #10 库存预警面板 / #11 实时动态 / #12 趋势图 / #13 优先购统计
import { computed, onMounted, ref } from 'vue'
import type { EChartsOption } from 'echarts'
import EChartsWrapper from '@/components/EChartsWrapper.vue'
import { barChartOption, lineChartOption } from '@/utils/charts'
import type { AlertsPanel, ActivityEvent, DashboardMetrics, FinanceChart, PriorityStats, TrendData } from '@/types/api'
import {
  fetchMetrics,
  fetchFinanceChart,
  fetchAlerts,
  fetchActivities,
  fetchTrends,
  fetchPriorityStats
} from '@/api/dashboard'

const loading = ref(false)
const metrics = ref<DashboardMetrics | null>(null)

async function load(): Promise<void> {
  loading.value = true
  try {
    metrics.value = await fetchMetrics()
  } catch {
    // 拦截器已提示
  } finally {
    loading.value = false
  }
}

/** 指标卡描述 */
interface CardItem {
  key: keyof DashboardMetrics
  label: string
  icon: string
  prefix?: string
  tone: 'primary' | 'gold' | 'ink' | 'success' | 'info'
  hint: string
}

const cards: CardItem[] = [
  { key: 'newUsersToday', label: '今日新增用户', icon: 'UserFilled', tone: 'primary', hint: '含注册未实名' },
  { key: 'salesToday', label: '今日销售额', icon: 'Wallet', prefix: '¥', tone: 'gold', hint: '已支付完成订单' },
  { key: 'paidOrdersToday', label: '今日支付订单', icon: 'CreditCard', tone: 'ink', hint: '状态为已完成' },
  { key: 'ordersToday', label: '今日订单总数', icon: 'Document', tone: 'info', hint: '含待支付订单' },
  { key: 'onsaleCollectibles', label: '在售/待售藏品', icon: 'Picture', tone: 'primary', hint: 'onsale + upcoming' },
  { key: 'onsaleBlindboxes', label: '在售盲盒', icon: 'Box', tone: 'gold', hint: '可开启且在售' },
  { key: 'activeListings', label: '在售挂单', icon: 'Shop', tone: 'success', hint: '寄售市场挂单' },
  { key: 'totalUsers', label: '用户总数', icon: 'DataLine', tone: 'ink', hint: '未注销账号' }
]

// ============================================================================
// #9 资金监控图表（days=7/30，充值/销售/退款三线）
// ============================================================================
const financeDays = ref<7 | 30>(7)
const financeLoading = ref(false)
const finance = ref<FinanceChart | null>(null)

async function loadFinance(): Promise<void> {
  financeLoading.value = true
  try {
    finance.value = await fetchFinanceChart(financeDays.value)
  } catch {
    // 拦截器已提示
  } finally {
    financeLoading.value = false
  }
}

const financeOption = computed<EChartsOption | null>(() => {
  const f = finance.value
  if (!f) return null
  return lineChartOption({
    categories: f.series.map((s) => s.date.slice(5)),
    series: [
      { name: '充值', data: f.series.map((s) => s.recharge) },
      { name: '销售', data: f.series.map((s) => s.sales) },
      { name: '退款', data: f.series.map((s) => s.refund) }
    ],
    yAxisFormatter: (v: number) => (v >= 10000 ? `${(v / 10000).toFixed(1)}万` : String(v))
  })
})

const financeTotals = computed(() => {
  const f = finance.value
  if (!f) return null
  return [
    { label: '累计充值', value: `¥${f.totals.recharge}`, tone: 'in' },
    { label: '累计销售', value: `¥${f.totals.sales}`, tone: 'in' },
    { label: '累计退款', value: `¥${f.totals.refund}`, tone: 'out' }
  ]
})

// ============================================================================
// #10 库存预警面板（低库存/库存异常/盲盒短缺）
// ============================================================================
const alertsLoading = ref(false)
const alerts = ref<AlertsPanel | null>(null)

async function loadAlerts(): Promise<void> {
  alertsLoading.value = true
  try {
    alerts.value = await fetchAlerts()
  } catch {
    // 拦截器已提示
  } finally {
    alertsLoading.value = false
  }
}

const alertTab = ref('lowStock')

// ============================================================================
// #11 实时动态滚动（limit=20）
// ============================================================================
const activitiesLoading = ref(false)
const activities = ref<ActivityEvent[]>([])

async function loadActivities(): Promise<void> {
  activitiesLoading.value = true
  try {
    const res = await fetchActivities(20)
    activities.value = res.list
  } catch {
    // 拦截器已提示
  } finally {
    activitiesLoading.value = false
  }
}

/** 动态类型 tag 样式 */
const ACTIVITY_TAG: Record<string, string> = {
  order: 'success',
  user: 'primary',
  refund: 'warning',
  blindbox: 'info',
  collectible: 'primary',
  airdrop: 'warning',
  market: 'info'
}

// ============================================================================
// #12 趋势图（days + metric=sales/orders/blindbox）
// ============================================================================
const trendDays = ref<7 | 30>(7)
const trendMetric = ref<'sales' | 'orders' | 'blindbox'>('sales')
const trendLoading = ref(false)
const trend = ref<TrendData | null>(null)

const TREND_METRIC_MAP: Record<string, { label: string; unit: string }> = {
  sales: { label: '销售额', unit: '元' },
  orders: { label: '订单量', unit: '单' },
  blindbox: { label: '开盒量', unit: '次' }
}

async function loadTrends(): Promise<void> {
  trendLoading.value = true
  try {
    trend.value = await fetchTrends(trendDays.value, trendMetric.value)
  } catch {
    // 拦截器已提示
  } finally {
    trendLoading.value = false
  }
}

const trendOption = computed<EChartsOption | null>(() => {
  const t = trend.value
  if (!t) return null
  return barChartOption({
    categories: t.series.map((s) => s.date.slice(5)),
    series: [{ name: t.label, data: t.series.map((s) => s.value) }],
    yAxisFormatter: (v: number) => (v >= 10000 ? `${(v / 10000).toFixed(1)}万` : String(v))
  })
})

// ============================================================================
// #13 优先购统计
// ============================================================================
const priorityLoading = ref(false)
const priority = ref<PriorityStats | null>(null)

async function loadPriority(): Promise<void> {
  priorityLoading.value = true
  try {
    priority.value = await fetchPriorityStats()
  } catch {
    // 拦截器已提示
  } finally {
    priorityLoading.value = false
  }
}

const priorityCards = computed(() => {
  const p = priority.value
  if (!p) return null
  return [
    { label: '进行中活动', value: String(p.summary.activeActivities) },
    { label: '有效白名单', value: String(p.summary.validWhitelists) },
    { label: '发放总量', value: String(p.summary.totalGranted) },
    { label: '已使用', value: String(p.summary.totalUsed) },
    { label: '剩余可用', value: String(p.summary.totalRemaining) }
  ]
})

function loadAll(): void {
  load()
  loadFinance()
  loadAlerts()
  loadActivities()
  loadTrends()
  loadPriority()
}

onMounted(loadAll)
</script>

<template>
  <div v-loading="loading" class="page-container dashboard">
    <!-- 标题行 -->
    <div class="page-head sn-card">
      <div class="head-left">
        <h3 class="head-title">数据大盘</h3>
        <span class="head-sub">今日运营核心指标与趋势一览</span>
      </div>
      <el-button text :icon="'Refresh'" @click="loadAll">刷新</el-button>
    </div>

    <!-- #8 指标卡 -->
    <div class="metric-grid">
      <div v-for="card in cards" :key="card.key" class="metric-card sn-card" :class="`tone-${card.tone}`">
        <div class="metric-icon">
          <el-icon :size="22"><component :is="card.icon" /></el-icon>
        </div>
        <div class="metric-body">
          <div class="metric-label">{{ card.label }}</div>
          <div class="metric-value din">
            <span v-if="card.prefix" class="metric-prefix">{{ card.prefix }}</span>{{ metrics ? metrics[card.key] : '—' }}
          </div>
          <div class="metric-hint">{{ card.hint }}</div>
        </div>
      </div>
    </div>

    <!-- 第二行：资金监控（#9） + 库存预警（#10） -->
    <div class="dash-row">
      <div class="sn-card chart-card">
        <div class="card-title-row">
          <span class="card-title">资金监控</span>
          <div class="card-actions">
            <template v-if="financeTotals">
              <span v-for="t in financeTotals" :key="t.label" class="finance-total">
                {{ t.label }}：<b class="din" :class="t.tone === 'in' ? 'in-green' : 'out-red'">{{ t.value }}</b>
              </span>
            </template>
            <el-radio-group v-model="financeDays" size="small" @change="loadFinance">
              <el-radio-button :value="7">7 天</el-radio-button>
              <el-radio-button :value="30">30 天</el-radio-button>
            </el-radio-group>
          </div>
        </div>
        <EChartsWrapper
          v-loading="financeLoading"
          :option="financeOption ?? {}"
          :empty="!finance || !finance.series.length"
          :height="300"
        />
      </div>

      <div class="sn-card side-card">
        <div class="card-title-row">
          <span class="card-title">库存预警</span>
          <el-button text :icon="'Refresh'" size="small" @click="loadAlerts" />
        </div>
        <div v-loading="alertsLoading" class="alert-body">
          <template v-if="alerts">
            <el-tabs v-model="alertTab" class="alert-tabs">
              <el-tab-pane :label="`低库存（${alerts.lowStockCount}）`" name="lowStock">
                <el-empty v-if="!alerts.lowStock.length" description="无低库存藏品" :image-size="50" />
                <div v-for="a in alerts.lowStock" :key="a.collectibleId" class="alert-item alert-item--warn">
                  <div class="alert-main">
                    <span class="alert-name">{{ a.name }}</span>
                    <span class="alert-sub">#{{ a.collectibleId }} · 发行 {{ a.edition }} / 阈值 {{ a.threshold }}</span>
                  </div>
                  <span class="alert-value din danger">余 {{ a.stockPool }}</span>
                </div>
              </el-tab-pane>
              <el-tab-pane :label="`库存异常（${alerts.abnormalCount}）`" name="abnormal">
                <el-empty v-if="!alerts.abnormal.length" description="库存守恒一致" :image-size="50" />
                <div v-for="a in alerts.abnormal" :key="a.collectibleId" class="alert-item alert-item--danger">
                  <div class="alert-main">
                    <span class="alert-name">{{ a.name }}</span>
                    <span class="alert-sub">#{{ a.collectibleId }} · {{ a.issue }}</span>
                  </div>
                  <span class="alert-value din danger">池 {{ a.stockPool }}</span>
                </div>
              </el-tab-pane>
              <el-tab-pane :label="`盲盒短缺（${alerts.blindboxShortageCount}）`" name="blindbox">
                <el-empty v-if="!alerts.blindboxShortage.length" description="无短缺盲盒" :image-size="50" />
                <div v-for="a in alerts.blindboxShortage" :key="a.collectibleId" class="alert-item alert-item--warn">
                  <div class="alert-main">
                    <span class="alert-name">{{ a.name }}</span>
                    <span class="alert-sub">#{{ a.collectibleId }} · 发行 {{ a.edition }}</span>
                  </div>
                  <span class="alert-value din danger">余 {{ a.stockPool }}</span>
                </div>
              </el-tab-pane>
            </el-tabs>
          </template>
        </div>
      </div>
    </div>

    <!-- 第三行：趋势图（#12） + 实时动态（#11） -->
    <div class="dash-row">
      <div class="sn-card chart-card">
        <div class="card-title-row">
          <span class="card-title">运营趋势</span>
          <div class="card-actions">
            <el-radio-group v-model="trendMetric" size="small" @change="loadTrends">
              <el-radio-button value="sales">销售额</el-radio-button>
              <el-radio-button value="orders">订单量</el-radio-button>
              <el-radio-button value="blindbox">开盒量</el-radio-button>
            </el-radio-group>
            <el-radio-group v-model="trendDays" size="small" @change="loadTrends">
              <el-radio-button :value="7">7 天</el-radio-button>
              <el-radio-button :value="30">30 天</el-radio-button>
            </el-radio-group>
          </div>
        </div>
        <div class="trend-total">
          区间{{ trend?.label ?? '—' }}合计：<b class="din">{{ trend?.total ?? '—' }}</b>
          <span v-if="trend" class="trend-unit">{{ TREND_METRIC_MAP[trendMetric]?.unit }}</span>
        </div>
        <EChartsWrapper
          v-loading="trendLoading"
          :option="trendOption ?? {}"
          :empty="!trend || !trend.series.length"
          :height="280"
        />
      </div>

      <div class="sn-card side-card">
        <div class="card-title-row">
          <span class="card-title">实时动态</span>
          <el-button text :icon="'Refresh'" size="small" @click="loadActivities" />
        </div>
        <div v-loading="activitiesLoading" class="activity-body">
          <el-empty v-if="!activities.length" description="暂无动态" :image-size="50" />
          <el-scrollbar v-else>
            <div v-for="(ev, i) in activities" :key="i" class="activity-item">
              <div class="activity-dot" />
              <div class="activity-main">
                <div class="activity-line">
                  <el-tag :type="(ACTIVITY_TAG[ev.type] ?? 'info') as never" size="small" effect="plain">{{ ev.typeText }}</el-tag>
                  <span class="activity-time">{{ ev.createdAt }}</span>
                </div>
                <div class="activity-content">{{ ev.content }}</div>
              </div>
            </div>
          </el-scrollbar>
        </div>
      </div>
    </div>

    <!-- 第四行：优先购统计（#13） -->
    <div class="sn-card">
      <div class="card-title-row">
        <span class="card-title">优先购统计</span>
        <el-button text :icon="'Refresh'" size="small" @click="loadPriority" />
      </div>
      <div v-loading="priorityLoading">
        <template v-if="priority">
          <div v-if="priorityCards" class="priority-grid">
            <div v-for="c in priorityCards" :key="c.label" class="priority-stat">
              <span class="priority-label">{{ c.label }}</span>
              <span class="priority-value din">{{ c.value }}</span>
            </div>
          </div>
          <el-empty v-if="!priority.byActivity.length" description="暂无进行中的优先购活动" :image-size="60" />
          <el-table v-else :data="priority.byActivity" size="small">
            <el-table-column label="活动" min-width="200">
              <template #default="{ row }">
                <div class="priority-activity">
                  <span class="pa-name">{{ row.name }}</span>
                  <span class="pa-sub">#{{ row.activityId }} · 白名单 {{ row.whitelistCount }} 人</span>
                </div>
              </template>
            </el-table-column>
            <el-table-column label="活动窗口" min-width="280">
              <template #default="{ row }">{{ row.window.start }} ~ {{ row.window.end }}</template>
            </el-table-column>
            <el-table-column label="发放" prop="granted" width="80" align="right" />
            <el-table-column label="已用" prop="used" width="80" align="right" />
            <el-table-column label="剩余" width="80" align="right">
              <template #default="{ row }">
                <span class="din" :class="{ 'danger-num': row.remaining <= 10 }">{{ row.remaining }}</span>
              </template>
            </el-table-column>
          </el-table>
        </template>
      </div>
    </div>
  </div>
</template>

<script lang="ts">
export default { name: 'DashboardIndex' }
</script>

<style scoped lang="scss">
.dashboard {
  .page-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 12px;

    .head-left {
      display: flex;
      align-items: baseline;
      gap: 10px;

      .head-title {
        font-size: 17px;
        font-weight: 600;
        color: $sn-text-main;
      }

      .head-sub {
        font-size: 12px;
        color: $sn-text-muted;
      }
    }
  }
}

.metric-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 12px;
  margin-bottom: 12px;
}

.metric-card {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 16px;

  .metric-icon {
    width: 44px;
    height: 44px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    flex-shrink: 0;
  }

  .metric-body {
    min-width: 0;

    .metric-label {
      font-size: 12px;
      color: $sn-text-sub;
    }

    .metric-value {
      font-size: 22px;
      font-weight: 600;
      color: $sn-text-main;
      margin: 2px 0;

      .metric-prefix {
        font-size: 14px;
        margin-right: 2px;
      }
    }

    .metric-hint {
      font-size: 11px;
      color: $sn-text-muted;
    }
  }

  &.tone-primary .metric-icon {
    background: $sn-gradient-primary;
  }

  &.tone-gold .metric-icon {
    background: $sn-gradient-gold;
  }

  &.tone-ink .metric-icon {
    background: $sn-gradient-ink;
  }

  &.tone-success .metric-icon {
    background: linear-gradient(135deg, #34d399 0%, #059669 100%);
  }

  &.tone-info .metric-icon {
    background: linear-gradient(135deg, #94a3b8 0%, #64748b 100%);
  }
}

.dash-row {
  display: grid;
  grid-template-columns: 2fr 1fr;
  gap: 12px;
  margin-bottom: 12px;
}

.chart-card,
.side-card {
  min-width: 0;
}

.card-title-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
  gap: 8px;

  .card-title {
    font-size: 14px;
    font-weight: 600;
    color: $sn-text-main;

    &::before {
      content: '';
      display: inline-block;
      width: 3px;
      height: 12px;
      border-radius: 2px;
      background: $sn-primary;
      margin-right: 6px;
    }
  }

  .card-actions {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;

    .finance-total {
      font-size: 12px;
      color: $sn-text-muted;

      b {
        font-weight: 600;
      }
    }
  }
}

.in-green {
  color: $sn-success;
}

.out-red {
  color: $sn-danger;
}

.danger {
  color: $sn-danger;
}

.danger-num {
  color: $sn-danger;
  font-weight: 600;
}

// 预警面板
.alert-body {
  min-height: 300px;

  .alert-tabs {
    :deep(.el-tabs__header) {
      margin-bottom: 8px;
    }

    :deep(.el-tabs__item) {
      font-size: 12px;
      padding: 0 10px;
    }
  }

  .alert-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    padding: 8px 10px;
    border-radius: 8px;
    margin-bottom: 6px;

    &--warn {
      background: rgba(212, 165, 116, 0.08);
    }

    &--danger {
      background: rgba(192, 0, 0, 0.05);
    }

    .alert-main {
      min-width: 0;

      .alert-name {
        display: block;
        font-size: 13px;
        font-weight: 500;
        color: $sn-text-main;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
      }

      .alert-sub {
        display: block;
        font-size: 11px;
        color: $sn-text-muted;
        margin-top: 2px;
      }
    }

    .alert-value {
      font-size: 13px;
      font-weight: 600;
      flex-shrink: 0;
    }
  }
}

// 趋势
.trend-total {
  font-size: 12px;
  color: $sn-text-muted;
  margin-bottom: 6px;

  b {
    font-size: 14px;
    color: $sn-text-main;
  }

  .trend-unit {
    margin-left: 2px;
  }
}

// 实时动态
.activity-body {
  min-height: 300px;
  max-height: 340px;

  .activity-item {
    display: flex;
    gap: 10px;
    padding: 8px 0;
    border-bottom: 1px dashed $sn-border;

    &:last-child {
      border-bottom: none;
    }

    .activity-dot {
      width: 6px;
      height: 6px;
      border-radius: 50%;
      background: $sn-primary;
      margin-top: 6px;
      flex-shrink: 0;
    }

    .activity-main {
      min-width: 0;
      flex: 1;

      .activity-line {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;

        .activity-time {
          font-size: 11px;
          color: $sn-text-muted;
        }
      }

      .activity-content {
        font-size: 12px;
        color: $sn-text-main;
        margin-top: 4px;
        line-height: 1.5;
      }
    }
  }
}

// 优先购统计
.priority-grid {
  display: grid;
  grid-template-columns: repeat(5, 1fr);
  gap: 10px;
  margin-bottom: 14px;

  .priority-stat {
    background: $sn-bg;
    border-radius: 8px;
    padding: 12px;
    display: flex;
    flex-direction: column;
    gap: 4px;

    .priority-label {
      font-size: 12px;
      color: $sn-text-muted;
    }

    .priority-value {
      font-size: 18px;
      font-weight: 600;
      color: $sn-text-main;
    }
  }
}

.priority-activity {
  display: flex;
  flex-direction: column;
  gap: 2px;

  .pa-name {
    font-weight: 500;
    color: $sn-text-main;
  }

  .pa-sub {
    font-size: 12px;
    color: $sn-text-muted;
  }
}

@media (max-width: 1365px) {
  .metric-grid {
    grid-template-columns: repeat(2, 1fr);
  }

  .dash-row {
    grid-template-columns: 1fr;
  }

  .priority-grid {
    grid-template-columns: repeat(3, 1fr);
  }
}
</style>
