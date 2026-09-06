<script setup lang="ts">
/**
 * 财务对账：资金总账（收入/支出分量）、支付渠道对账、全站余额快照
 *
 * 对账口径（与后端一致）：
 * - 充值流入 / 奖励流入 / 购买流出 / 提现流出：按钱包流水 trans_type + direction 统计
 * - 退款：refunds.status=3 且 refunded_at 落在区间
 * - 手续费：resale_listings.status=sold 且 updated_at 落在区间
 */
import { computed, onMounted, ref } from 'vue'
import { fetchFinanceReport } from '@/api/misc'
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
    report.value = (await fetchFinanceReport(params)) || {}
  } finally {
    loading.value = false
  }
}

onMounted(load)

const ledger = computed<any>(() => report.value.ledger || {})
const channels = computed<any[]>(() => report.value.channels || [])
const snapshot = computed<any>(() => report.value.balanceSnapshot || {})

// 资金总账：流入 / 流出
const ledgerRows = computed(() => [
  { name: '充值流入', amount: ledger.value.rechargeIn ?? 0, dir: 'in', desc: 'wallet_transactions: recharge + direction=1' },
  { name: '奖励流入', amount: ledger.value.rewardIn ?? 0, dir: 'in', desc: '营销奖励 / 寄售收入等' },
  { name: '平台手续费收入', amount: ledger.value.feeIncome ?? 0, dir: 'in', desc: '二级市场成交手续费' },
  { name: '购买流出', amount: ledger.value.buyOut ?? 0, dir: 'out', desc: 'wallet_transactions: buy + direction=2' },
  { name: '提现流出', amount: ledger.value.withdrawOut ?? 0, dir: 'out', desc: 'wallet_transactions: withdraw + direction=2' },
  { name: '退款流出', amount: ledger.value.refundAmount ?? 0, dir: 'out', desc: 'refunds: status=3 已退款' },
])

const totalIn = computed(() => (ledger.value.rechargeIn ?? 0) + (ledger.value.rewardIn ?? 0) + (ledger.value.feeIncome ?? 0))
const totalOut = computed(() => (ledger.value.buyOut ?? 0) + (ledger.value.withdrawOut ?? 0) + (ledger.value.refundAmount ?? 0))

const summaryCards = computed(() => [
  { label: '区间总流入（元）', value: money(totalIn.value), sub: '充值 + 奖励 + 手续费' },
  { label: '区间总流出（元）', value: money(totalOut.value), sub: '购买 + 提现 + 退款' },
  { label: '全站余额合计（元）', value: money(snapshot.value.balanceTotal), sub: `可用 ${money(snapshot.value.availableTotal)} · 冻结 ${money(snapshot.value.frozenTotal)}` },
  { label: '钱包账户数', value: snapshot.value.walletCount ?? 0, sub: 'balance = available + frozen' },
])

function payMethodName(m: string): string {
  const map: Record<string, string> = { balance: '余额', alipay: '支付宝', wechat: '微信', huifu: '汇付', unionpay: '银联' }
  return map[m] || m
}
</script>

<template>
  <div class="page-container" v-loading="loading">
    <div class="search-bar">
      <el-form inline size="small">
        <el-form-item label="对账区间">
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
          <span class="text-xs text-gray-500">默认近 30 天；余额快照为全站实时值</span>
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
      <div class="chart-title">资金总账</div>
      <el-table :data="ledgerRows" border>
        <el-table-column prop="name" label="科目" width="160" />
        <el-table-column label="方向" width="100">
          <template #default="scope">
            <el-tag :type="scope.row.dir === 'in' ? 'success' : 'danger'">
              {{ scope.row.dir === 'in' ? '流入' : '流出' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="金额（元）" width="160" align="right">
          <template #default="scope">
            <span :class="scope.row.dir === 'in' ? 'text-green-700' : 'text-red-700'">
              {{ scope.row.dir === 'in' ? '+' : '-' }}{{ money(scope.row.amount) }}
            </span>
          </template>
        </el-table-column>
        <el-table-column prop="desc" label="统计口径" min-width="280" />
      </el-table>
    </div>

    <div class="chart-box">
      <div class="chart-title">支付渠道对账（净额 = 支付成功 - 已退款）</div>
      <el-table :data="channels" border show-summary :summary-method="(param: any) => {
        const { columns, data } = param
        const sums: string[] = []
        columns.forEach((col: any, i: number) => {
          if (i === 0) { sums[i] = '合计'; return }
          if (['payCount', 'refundCount'].includes(col.property)) {
            sums[i] = data.reduce((s: number, r: any) => s + Number(r[col.property] ?? 0), 0).toString()
          } else if (['amountTotal', 'refundAmount', 'netAmount'].includes(col.property)) {
            sums[i] = money(data.reduce((s: number, r: any) => s + Number(r[col.property] ?? 0), 0))
          } else {
            sums[i] = ''
          }
        })
        return sums
      }">
        <el-table-column label="渠道" width="120">
          <template #default="scope">{{ payMethodName(scope.row.method) }}</template>
        </el-table-column>
        <el-table-column prop="payCount" label="支付笔数" width="110" align="right" />
        <el-table-column label="支付总额（元）" width="140" align="right">
          <template #default="scope">{{ money(scope.row.amountTotal) }}</template>
        </el-table-column>
        <el-table-column prop="refundCount" label="退款笔数" width="110" align="right" />
        <el-table-column label="退款总额（元）" width="140" align="right">
          <template #default="scope">{{ money(scope.row.refundAmount) }}</template>
        </el-table-column>
        <el-table-column label="净额（元）" width="140" align="right">
          <template #default="scope">
            <span class="font-bold">{{ money(scope.row.netAmount) }}</span>
          </template>
        </el-table-column>
      </el-table>
      <el-empty v-if="!channels.length && !loading" description="区间内无支付成功记录" :image-size="60" />
    </div>
  </div>
</template>
