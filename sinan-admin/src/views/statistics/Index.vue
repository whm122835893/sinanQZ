<script setup>
import { ref, computed, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import {
  getSalesReport,
  getUserReport,
  getCollectibleReport,
  getBlindboxReport,
  getFinanceReport
} from '@/api'
import StatCard from '@/components/StatCard.vue'
import EChart from '@/components/EChart.vue'
import { fmtMoney, fmtNumber } from '@/utils/format'

// ============================================================
// 报表中心（五大报表 tab + 时间区间筛选）
// - 销售 / 用户 / 藏品 / 盲盒 / 财务对账，数据源各自独立
// - 后端默认最近 30 天，最长可查 366 天
// ============================================================

const activeTab = ref('sales')
const loading = ref(true)
const range = ref(null) // [start, end]（null = 默认近 30 天）

const sales = ref(null)
const users = ref(null)
const collectibles = ref(null)
const blindbox = ref(null)
const finance = ref(null)

const loaders = {
  sales: getSalesReport,
  users: getUserReport,
  collectibles: getCollectibleReport,
  blindbox: getBlindboxReport,
  finance: getFinanceReport
}
const stores = {
  sales: sales,
  users: users,
  collectibles: collectibles,
  blindbox: blindbox,
  finance: finance
}

async function load(tab = activeTab.value) {
  loading.value = true
  const res = await loaders[tab]({ range: range.value })
  if (res.code === 0) {
    stores[tab].value = res.data
  } else if (res.code !== -1) {
    ElMessage.error(res.message || '报表加载失败')
  }
  loading.value = false
}

function onRangeChange() {
  load()
}

onMounted(() => load())

const shortcuts = [
  { text: '近 7 天', value: () => { const e = new Date(); const s = new Date(e.getTime() - 6 * 864e5); return [s, e] } },
  { text: '近 30 天', value: () => { const e = new Date(); const s = new Date(e.getTime() - 29 * 864e5); return [s, e] } },
  { text: '近 90 天', value: () => { const e = new Date(); const s = new Date(e.getTime() - 89 * 864e5); return [s, e] } },
  { text: '近一年', value: () => { const e = new Date(); const s = new Date(e.getTime() - 365 * 864e5); return [s, e] } }
]

// ---------------- 销售报表 ----------------
const salesTrendOption = computed(() => {
  const d = sales.value
  if (!d) return {}
  const trend = d.trend || []
  return {
    tooltip: { trigger: 'axis' },
    legend: { data: ['GMV（元）', '订单量'], top: 0, textStyle: { color: '#666', fontSize: 11 } },
    grid: { left: 60, right: 50, top: 34, bottom: 24 },
    xAxis: { type: 'category', data: trend.map((t) => t.statDate), axisLine: { lineStyle: { color: '#ddd' } }, axisLabel: { color: '#999' } },
    yAxis: [
      { type: 'value', splitLine: { lineStyle: { color: '#f2f3f5' } }, axisLabel: { color: '#999' } },
      { type: 'value', splitLine: { show: false }, axisLabel: { color: '#999' } }
    ],
    series: [
      {
        name: 'GMV（元）', type: 'line', smooth: true, symbolSize: 5,
        data: trend.map((t) => Number(t.gmv)),
        lineStyle: { width: 2.5, color: '#C00000' }, itemStyle: { color: '#C00000' },
        areaStyle: { color: { type: 'linear', x: 0, y: 0, x2: 0, y2: 1, colorStops: [{ offset: 0, color: 'rgba(192,0,0,0.18)' }, { offset: 1, color: 'rgba(192,0,0,0.01)' }] } }
      },
      { name: '订单量', type: 'bar', yAxisIndex: 1, barWidth: 12, data: trend.map((t) => Number(t.orderCount)), itemStyle: { color: 'rgba(25,137,250,0.35)', borderRadius: [3, 3, 0, 0] } }
    ]
  }
})

const payShareOption = computed(() => {
  const d = sales.value
  if (!d) return {}
  return {
    tooltip: { trigger: 'item', formatter: '{b}: ¥{c}（{d}%）' },
    legend: { bottom: 0, icon: 'circle', itemWidth: 8, itemHeight: 8, textStyle: { color: '#666', fontSize: 11 } },
    series: [{
      type: 'pie', radius: ['46%', '70%'], center: ['50%', '44%'], label: { show: false },
      data: (d.payments || []).map((p, i) => ({
        name: p.method, value: Number(p.amount),
        itemStyle: { color: ['#C00000', '#D4A574', '#1989fa', '#07c160', '#8e7cc3'][i % 5] }
      }))
    }]
  }
})

const SOURCE_NAME = { release: '发售', market: '市场寄售', priority: '优先购', eligibility: '资格购' }

// ---------------- 用户报表 ----------------
const regTrendOption = computed(() => {
  const d = users.value
  if (!d) return {}
  const trend = d.regTrend || []
  return {
    tooltip: { trigger: 'axis' },
    grid: { left: 50, right: 16, top: 20, bottom: 24 },
    xAxis: { type: 'category', data: trend.map((t) => t.statDate), axisLine: { lineStyle: { color: '#ddd' } }, axisLabel: { color: '#999' } },
    yAxis: { type: 'value', splitLine: { lineStyle: { color: '#f2f3f5' } }, axisLabel: { color: '#999' } },
    series: [{
      name: '新增注册', type: 'line', smooth: true, symbolSize: 5,
      data: trend.map((t) => Number(t.regCount)),
      lineStyle: { width: 2.5, color: '#1989fa' }, itemStyle: { color: '#1989fa' },
      areaStyle: { color: { type: 'linear', x: 0, y: 0, x2: 0, y2: 1, colorStops: [{ offset: 0, color: 'rgba(25,137,250,0.22)' }, { offset: 1, color: 'rgba(25,137,250,0.02)' }] } }
    }]
  }
})

const holdingOption = computed(() => {
  const d = users.value
  if (!d) return {}
  return {
    tooltip: { trigger: 'item', formatter: '{b}: {c} 人（{d}%）' },
    legend: { bottom: 0, icon: 'circle', itemWidth: 8, itemHeight: 8, textStyle: { color: '#666', fontSize: 11 } },
    series: [{
      type: 'pie', radius: ['46%', '70%'], center: ['50%', '44%'], label: { show: false },
      data: (d.holdingBuckets || []).map((b, i) => ({
        name: b.label, value: Number(b.count),
        itemStyle: { color: ['#C00000', '#D4A574', '#1989fa', '#07c160'][i % 4] }
      }))
    }]
  }
})

// ---------------- 藏品报表 ----------------
const issueTrendOption = computed(() => {
  const d = collectibles.value
  if (!d) return {}
  const trend = d.issueTrend || []
  return {
    tooltip: { trigger: 'axis' },
    legend: { data: ['新增藏品数', '新增发行量'], top: 0, textStyle: { color: '#666', fontSize: 11 } },
    grid: { left: 50, right: 50, top: 34, bottom: 24 },
    xAxis: { type: 'category', data: trend.map((t) => t.statDate), axisLine: { lineStyle: { color: '#ddd' } }, axisLabel: { color: '#999' } },
    yAxis: [
      { type: 'value', splitLine: { lineStyle: { color: '#f2f3f5' } }, axisLabel: { color: '#999' } },
      { type: 'value', splitLine: { show: false }, axisLabel: { color: '#999' } }
    ],
    series: [
      { name: '新增藏品数', type: 'bar', barWidth: 14, data: trend.map((t) => Number(t.newCount)), itemStyle: { color: '#D4A574', borderRadius: [3, 3, 0, 0] } },
      { name: '新增发行量', type: 'line', yAxisIndex: 1, smooth: true, symbolSize: 5, data: trend.map((t) => Number(t.editionTotal)), lineStyle: { width: 2.5, color: '#C00000' }, itemStyle: { color: '#C00000' } }
    ]
  }
})

// ---------------- 盲盒报表 ----------------
const openTrendOption = computed(() => {
  const d = blindbox.value
  if (!d) return {}
  const trend = d.openTrend || []
  return {
    tooltip: { trigger: 'axis' },
    grid: { left: 50, right: 16, top: 20, bottom: 24 },
    xAxis: { type: 'category', data: trend.map((t) => t.statDate), axisLine: { lineStyle: { color: '#ddd' } }, axisLabel: { color: '#999' } },
    yAxis: { type: 'value', splitLine: { lineStyle: { color: '#f2f3f5' } }, axisLabel: { color: '#999' } },
    series: [{
      name: '开盒量', type: 'bar', barWidth: 18,
      data: trend.map((t) => Number(t.openCount)),
      itemStyle: { borderRadius: [4, 4, 0, 0], color: { type: 'linear', x: 0, y: 0, x2: 0, y2: 1, colorStops: [{ offset: 0, color: '#C00000' }, { offset: 1, color: 'rgba(192,0,0,0.4)' }] } }
    }]
  }
})

const PAY_METHOD_NAME = { balance: '余额支付', alipay: '支付宝', wechat: '微信支付', huifu: '汇付天下', unionpay: '云闪付' }
</script>

<template>
  <div class="adm-page rp">
    <!-- 时间区间 + 刷新 -->
    <div class="adm-card rp__bar">
      <div class="rp__bar-left">
        <span class="rp__bar-label">统计区间</span>
        <el-date-picker
          v-model="range"
          type="daterange"
          value-format="YYYY-MM-DD"
          range-separator="至"
          start-placeholder="开始日期"
          end-placeholder="结束日期"
          :shortcuts="shortcuts"
          :clearable="true"
          style="width: 280px"
          @change="onRangeChange"
        />
        <span class="t-tertiary" style="font-size: 12px">不选则统计最近 30 天（最长 366 天）</span>
      </div>
      <el-button :icon="'Refresh'" :loading="loading" @click="load()">刷新</el-button>
    </div>

    <el-tabs v-model="activeTab" @tab-change="load">
      <!-- ============ 销售报表 ============ -->
      <el-tab-pane label="销售报表" name="sales">
        <el-skeleton v-if="loading" :rows="6" animated style="padding: 16px" />
        <template v-else-if="sales">
          <div class="adm-grid">
            <StatCard icon="Coin" label="销售总额 GMV" :value="fmtMoney(sales.summary?.gmvTotal)" unit="元" tone="primary" />
            <StatCard icon="List" label="订单总量" :value="fmtNumber(sales.summary?.orderTotal)" unit="单" tone="gold" />
            <StatCard icon="User" label="购买用户数" :value="fmtNumber(sales.summary?.buyerCount)" unit="人" tone="blue" />
            <StatCard icon="TrendCharts" label="客单价" :value="fmtMoney(sales.summary?.avgOrderAmount)" unit="元" tone="green" />
          </div>
          <div class="rp__row">
            <div class="adm-card">
              <div class="adm-card__title">GMV 与订单量趋势（仅已完成订单）</div>
              <EChart :option="salesTrendOption" :height="300" />
            </div>
            <div class="adm-card">
              <div class="adm-card__title">支付方式分布（成功支付金额）</div>
              <EChart :option="payShareOption" :height="300" />
            </div>
          </div>
          <div class="adm-card">
            <div class="adm-card__title">TOP 藏品销售榜（按 GMV）</div>
            <el-table :data="sales.topCollectibles || []">
              <el-table-column label="排名" width="70" align="center">
                <template #default="{ $index }">
                  <span class="rp__rank" :class="`is-${$index + 1}`">{{ $index + 1 }}</span>
                </template>
              </el-table-column>
              <el-table-column label="藏品" min-width="220">
                <template #default="{ row }">
                  <div class="rp__cell">
                    <img v-if="row.coverImage" :src="row.coverImage" :alt="row.name" />
                    <span class="rp__cell-name">{{ row.name }}</span>
                  </div>
                </template>
              </el-table-column>
              <el-table-column label="订单数" prop="orderCount" width="100" align="right" />
              <el-table-column label="销量" prop="quantity" width="100" align="right" />
              <el-table-column label="GMV" width="140" align="right">
                <template #default="{ row }"><span class="price">¥{{ fmtNumber(row.gmv) }}</span></template>
              </el-table-column>
            </el-table>
          </div>
          <div class="adm-card">
            <div class="adm-card__title">订单来源分布</div>
            <el-table :data="sales.sources || []">
              <el-table-column label="来源" prop="sourceName" min-width="160" />
              <el-table-column label="订单数" prop="orderCount" width="120" align="right" />
              <el-table-column label="GMV" width="160" align="right">
                <template #default="{ row }">¥{{ fmtNumber(row.gmv) }}</template>
              </el-table-column>
            </el-table>
          </div>
        </template>
      </el-tab-pane>

      <!-- ============ 用户报表 ============ -->
      <el-tab-pane label="用户报表" name="users">
        <el-skeleton v-if="loading" :rows="6" animated style="padding: 16px" />
        <template v-else-if="users">
          <div class="adm-grid">
            <StatCard icon="User" label="注册用户总数" :value="fmtNumber(users.summary?.total)" unit="人" tone="primary" />
            <StatCard icon="Stamp" label="实名率" :value="`${users.summary?.realnameRate ?? 0}%`" tone="gold" />
            <StatCard icon="Wallet" label="持仓用户数" :value="fmtNumber(users.summary?.holdingUsers)" unit="人" tone="blue" />
            <StatCard icon="Warning" label="冻结 / 黑名单" :value="`${users.summary?.frozen ?? 0} / ${users.summary?.blacklisted ?? 0}`" unit="人" tone="green" />
          </div>
          <div class="rp__row">
            <div class="adm-card">
              <div class="adm-card__title">注册趋势</div>
              <EChart :option="regTrendOption" :height="300" />
            </div>
            <div class="adm-card">
              <div class="adm-card__title">用户持仓分布</div>
              <EChart :option="holdingOption" :height="300" />
            </div>
          </div>
          <div class="rp__row">
            <div class="adm-card">
              <div class="adm-card__title">注册渠道分布（区间内）</div>
              <el-table :data="users.sources || []">
                <el-table-column label="渠道" prop="sourceName" min-width="140" />
                <el-table-column label="注册数" prop="count" width="100" align="right" />
              </el-table>
            </div>
            <div class="adm-card">
              <div class="adm-card__title">邀请达人 TOP 10</div>
              <el-table :data="users.topInviters || []">
                <el-table-column label="邀请人 UID" prop="uid" width="120" />
                <el-table-column label="昵称" prop="username" min-width="140" />
                <el-table-column label="邀请人数" prop="inviteCount" width="100" align="right" />
              </el-table>
            </div>
          </div>
        </template>
      </el-tab-pane>

      <!-- ============ 藏品报表 ============ -->
      <el-tab-pane label="藏品报表" name="collectibles">
        <el-skeleton v-if="loading" :rows="6" animated style="padding: 16px" />
        <template v-else-if="collectibles">
          <div class="adm-grid">
            <StatCard icon="Picture" label="藏品总数" :value="fmtNumber(collectibles.summary?.total)" unit="款" tone="primary" />
            <StatCard icon="Sell" label="在售 / 待发售" :value="`${collectibles.summary?.onsale ?? 0}`" unit="款" tone="gold" />
            <StatCard icon="CircleCheck" label="已售罄" :value="fmtNumber(collectibles.summary?.soldout)" unit="款" tone="blue" />
            <StatCard icon="Gift" label="盲盒类藏品" :value="fmtNumber(collectibles.summary?.isBlindbox)" unit="款" tone="green" />
          </div>
          <div class="rp__row">
            <div class="adm-card">
              <div class="adm-card__title">发售趋势（新增藏品与发行量）</div>
              <EChart :option="issueTrendOption" :height="300" />
            </div>
            <div class="adm-card">
              <div class="adm-card__title">市场寄售概览</div>
              <div class="rp__mkt">
                <div class="rp__mkt-item">
                  <div class="t-tertiary" style="font-size: 12px">在售挂单</div>
                  <div class="rp__mkt-val">{{ fmtNumber(collectibles.market?.selling) }} 笔</div>
                  <div class="rp__mkt-sub">挂单金额 ¥{{ fmtNumber(collectibles.market?.sellingAmount) }}</div>
                </div>
                <div class="rp__mkt-item">
                  <div class="t-tertiary" style="font-size: 12px">已成交</div>
                  <div class="rp__mkt-val">{{ fmtNumber(collectibles.market?.sold) }} 笔</div>
                  <div class="rp__mkt-sub">成交金额 ¥{{ fmtNumber(collectibles.market?.soldAmount) }}</div>
                </div>
                <div class="rp__mkt-item">
                  <div class="t-tertiary" style="font-size: 12px">平台手续费</div>
                  <div class="rp__mkt-val">¥{{ fmtNumber(collectibles.market?.feeAmount) }}</div>
                  <div class="rp__mkt-sub">已取消 {{ fmtNumber(collectibles.market?.cancelled) }} 笔</div>
                </div>
              </div>
            </div>
          </div>
          <div class="rp__row">
            <div class="adm-card">
              <div class="adm-card__title">分类分布</div>
              <el-table :data="collectibles.categories || []">
                <el-table-column label="分类" prop="categoryName" min-width="120" />
                <el-table-column label="藏品数" prop="cnt" width="90" align="right" />
                <el-table-column label="累计售出" prop="soldTotal" width="100" align="right" />
                <el-table-column label="累计流通" prop="circulateTotal" width="100" align="right" />
              </el-table>
            </div>
            <div class="adm-card">
              <div class="adm-card__title">流通量 TOP 10</div>
              <el-table :data="collectibles.topCirculate || []">
                <el-table-column label="藏品" min-width="160">
                  <template #default="{ row }">
                    <div class="rp__cell">
                      <img v-if="row.coverImage" :src="row.coverImage" :alt="row.name" />
                      <span class="rp__cell-name">{{ row.name }}</span>
                    </div>
                  </template>
                </el-table-column>
                <el-table-column label="发行量" prop="edition" width="90" align="right" />
                <el-table-column label="流通量" prop="circulate" width="90" align="right" />
                <el-table-column label="已售" prop="sold" width="80" align="right" />
              </el-table>
            </div>
          </div>
        </template>
      </el-tab-pane>

      <!-- ============ 盲盒报表 ============ -->
      <el-tab-pane label="盲盒报表" name="blindbox">
        <el-skeleton v-if="loading" :rows="6" animated style="padding: 16px" />
        <template v-else-if="blindbox">
          <div class="adm-grid">
            <StatCard icon="Gift" label="盲盒系列数" :value="fmtNumber(blindbox.summary?.total)" unit="款" tone="primary" />
            <StatCard icon="MagicStick" label="累计开盒量" :value="fmtNumber(blindbox.summary?.openedTotal)" unit="次" tone="gold" />
          </div>
          <div class="adm-card">
            <div class="adm-card__title">开盒趋势</div>
            <EChart :option="openTrendOption" :height="300" />
          </div>
          <div class="rp__row">
            <div class="adm-card">
              <div class="adm-card__title">奖池分布（各奖品发放占比）</div>
              <el-table :data="blindbox.prizes || []">
                <el-table-column label="奖品" prop="prizeName" min-width="180" />
                <el-table-column label="覆盖盲盒数" prop="boxCount" width="110" align="right" />
                <el-table-column label="累计发放" prop="distributedTotal" width="100" align="right" />
                <el-table-column label="投放上限" prop="limitTotal" width="100" align="right" />
              </el-table>
            </div>
            <div class="adm-card">
              <div class="adm-card__title">盲盒开盒排行</div>
              <el-table :data="blindbox.boxRanking || []">
                <el-table-column label="盲盒" prop="name" min-width="180" />
                <el-table-column label="开盒次数" prop="openedCount" width="100" align="right" />
                <el-table-column label="单价" width="100" align="right">
                  <template #default="{ row }">¥{{ fmtNumber(row.price) }}</template>
                </el-table-column>
                <el-table-column label="奖品档数" prop="prizeCount" width="90" align="right" />
              </el-table>
            </div>
          </div>
        </template>
      </el-tab-pane>

      <!-- ============ 财务对账 ============ -->
      <el-tab-pane label="财务对账" name="finance">
        <el-skeleton v-if="loading" :rows="6" animated style="padding: 16px" />
        <template v-else-if="finance">
          <div class="adm-card">
            <div class="adm-card__title">资金总账（恒等式：余额合计 + 手续费 + 已提现 = 总充值 + 总奖励）</div>
            <div class="rp__ledger">
              <div class="rp__ledger-in">
                <div class="rp__ledger-cap t-tertiary">流入</div>
                <div class="rp__ledger-item">
                  <span>充值流入</span>
                  <b class="is-in">+¥{{ fmtNumber(finance.ledger?.rechargeIn) }}</b>
                </div>
                <div class="rp__ledger-item">
                  <span>奖励发放</span>
                  <b class="is-in">+¥{{ fmtNumber(finance.ledger?.rewardIn) }}</b>
                </div>
              </div>
              <div class="rp__ledger-eq">=</div>
              <div class="rp__ledger-out">
                <div class="rp__ledger-cap t-tertiary">流出 / 沉淀</div>
                <div class="rp__ledger-item">
                  <span>用户余额合计</span>
                  <b>¥{{ fmtNumber(finance.balanceSnapshot?.balanceTotal) }}</b>
                </div>
                <div class="rp__ledger-item">
                  <span>平台手续费收入</span>
                  <b>¥{{ fmtNumber(finance.ledger?.feeIncome) }}</b>
                </div>
                <div class="rp__ledger-item">
                  <span>已提现</span>
                  <b>-¥{{ fmtNumber(finance.ledger?.withdrawOut) }}</b>
                </div>
              </div>
              <div class="rp__ledger-side">
                <div class="rp__ledger-item"><span>消费流出</span><b>¥{{ fmtNumber(finance.ledger?.buyOut) }}</b></div>
                <div class="rp__ledger-item"><span>退款总额</span><b>¥{{ fmtNumber(finance.ledger?.refundAmount) }}</b></div>
                <div class="rp__ledger-item"><span>钱包总数</span><b>{{ fmtNumber(finance.balanceSnapshot?.walletCount) }} 个</b></div>
                <div class="rp__ledger-item"><span>冻结余额</span><b>¥{{ fmtNumber(finance.balanceSnapshot?.frozenTotal) }}</b></div>
              </div>
            </div>
          </div>
          <div class="adm-card">
            <div class="adm-card__title">支付渠道对账（净额 = 成功支付 - 已退款）</div>
            <el-table :data="finance.channels || []">
              <el-table-column label="渠道" min-width="120">
                <template #default="{ row }">{{ PAY_METHOD_NAME[row.method] || row.method }}</template>
              </el-table-column>
              <el-table-column label="成功笔数" prop="payCount" width="110" align="right" />
              <el-table-column label="支付总额" width="130" align="right">
                <template #default="{ row }">¥{{ fmtNumber(row.amountTotal) }}</template>
              </el-table-column>
              <el-table-column label="退款笔数" prop="refundCount" width="110" align="right" />
              <el-table-column label="退款总额" width="130" align="right">
                <template #default="{ row }">¥{{ fmtNumber(row.refundAmount) }}</template>
              </el-table-column>
              <el-table-column label="净收入" width="140" align="right">
                <template #default="{ row }"><span class="price">¥{{ fmtNumber(row.netAmount) }}</span></template>
              </el-table-column>
            </el-table>
          </div>
        </template>
      </el-tab-pane>
    </el-tabs>
  </div>
</template>

<style scoped lang="scss">
.rp__bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
}

.rp__bar-left {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}

.rp__bar-label {
  font-size: 13px;
  color: $color-text-secondary;
}

.rp__row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
  margin-top: 16px;

  &:first-of-type { margin-top: 0; }
}

.rp__rank {
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

.rp__cell {
  display: flex;
  align-items: center;
  gap: 10px;

  img {
    width: 40px;
    height: 40px;
    border-radius: 6px;
    object-fit: cover;
    background: $color-surface;
  }
}

.rp__cell-name {
  font-weight: 600;
  color: $color-text-primary;
}

.rp__mkt {
  display: flex;
  flex-direction: column;
  gap: 12px;
  height: 100%;
  justify-content: center;
}

.rp__mkt-item {
  background: $color-bg;
  border-radius: 8px;
  padding: 14px 16px;
}

.rp__mkt-val {
  font-size: 20px;
  font-weight: 700;
  color: $color-text-primary;
  margin-top: 4px;
}

.rp__mkt-sub {
  font-size: 12px;
  color: $color-text-tertiary;
  margin-top: 2px;
}

.rp__ledger {
  display: grid;
  grid-template-columns: 1fr 40px 1fr 1fr;
  gap: 16px;
  align-items: stretch;
}

.rp__ledger-cap {
  font-size: 12px;
  margin-bottom: 8px;
}

.rp__ledger-in,
.rp__ledger-out,
.rp__ledger-side {
  background: $color-bg;
  border-radius: 10px;
  padding: 14px 16px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.rp__ledger-eq {
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 22px;
  font-weight: 700;
  color: $color-text-tertiary;
}

.rp__ledger-item {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 13px;
  color: $color-text-secondary;

  b {
    font-size: 15px;
    color: $color-text-primary;

    &.is-in { color: #07c160; }
  }
}

@media (max-width: 992px) {
  .rp__row { grid-template-columns: 1fr; }
  .rp__ledger { grid-template-columns: 1fr; }
  .rp__ledger-eq { padding: 4px 0; }
}
</style>
