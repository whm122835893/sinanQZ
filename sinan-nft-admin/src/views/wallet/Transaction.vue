<script setup lang="ts">
/**
 * 钱包交易流水
 *
 * - 搜索：用户UID/手机号（userKeyword）/ 流水类型（transType）/ 方向（direction）/ 时间区间（后端 startDate/endDate）
 * - 表格：字段与 WalletController::transactions 返回严格一致（后端已驼峰化：wallet_transactions t.* + uid/username）
 * - 方向：1 收入（绿）/ 2 支出（红）；金额按方向带 +/- 符号展示
 */
import { onMounted } from 'vue'
import { useListPage } from '@/utils/useListPage'
import { TRANS_TYPE, TRANS_DIRECTION, money, datetime } from '@/utils/format'
import { fetchTransactions } from '@/api/wallet'

/** 类型标签配色（TRANS_TYPE 字典的展示补充） */
const TYPE_TAG: Record<string, 'primary' | 'success' | 'warning' | 'danger' | 'info'> = {
  recharge: 'success',
  buy: 'primary',
  refund: 'warning',
  withdraw: 'danger',
  reward: 'success',
  resale_income: 'success',
  resale_fee: 'warning',
  resale_buy: 'primary',
  airdrop: 'info',
  adjustment: 'warning',
}

/** 后端 transactions() 支持的类型筛选白名单（recharge/buy/withdraw/reward） */
const TYPE_OPTIONS = ['recharge', 'buy', 'withdraw', 'reward']

interface TransRow {
  id: number
  userId: number
  uid: string
  username: string
  transType: string
  title: string
  direction: number
  amount: number | string
  balanceAfter: number | string
  bizNo?: string
  createdAt: string
}

/** 时间区间数组 → 后端 startDate/endDate，并剔除空参数 */
function withDateRange(fetcher: (p: Record<string, any>) => Promise<any>) {
  return (params: Record<string, any>) => {
    const { dateRange, ...rest } = params
    const query: Record<string, any> = {}
    Object.keys(rest).forEach((k) => {
      if (rest[k] !== '' && rest[k] !== null && rest[k] !== undefined) query[k] = rest[k]
    })
    if (Array.isArray(dateRange)) {
      if (dateRange[0]) query.startDate = dateRange[0]
      if (dateRange[1]) query.endDate = dateRange[1]
    }
    return fetcher(query)
  }
}

const pager = useListPage(withDateRange(fetchTransactions), {
  userKeyword: '',
  transType: '',
  direction: '',
  dateRange: [],
})

onMounted(() => {
  pager.refresh()
})

function handlePageChange() {
  pager.load()
}

function handleSizeChange() {
  pager.page = 1
  pager.load()
}

/** 流水号：优先业务单号 bizNo，缺省回退流水ID */
function transNo(row: TransRow) {
  return row.bizNo || `#${row.id}`
}

/** 是否收入方向（direction = 1） */
function isIn(row: TransRow) {
  return Number(row.direction) === 1
}
</script>

<template>
  <div class="page-container">
    <!-- 搜索栏 -->
    <div class="search-bar">
      <el-form inline @submit.prevent>
        <el-form-item label="用户">
          <el-input
            v-model="pager.filters.userKeyword"
            placeholder="用户UID / 手机号"
            clearable
            style="width: 200px"
          />
        </el-form-item>
        <el-form-item label="流水类型">
          <el-select v-model="pager.filters.transType" placeholder="全部类型" clearable style="width: 130px">
            <el-option v-for="key in TYPE_OPTIONS" :key="key" :label="TRANS_TYPE[key]" :value="key" />
          </el-select>
        </el-form-item>
        <el-form-item label="方向">
          <el-select v-model="pager.filters.direction" placeholder="全部方向" clearable style="width: 120px">
            <el-option v-for="(label, key) in TRANS_DIRECTION" :key="key" :label="label" :value="key" />
          </el-select>
        </el-form-item>
        <el-form-item label="时间区间">
          <el-date-picker
            v-model="pager.filters.dateRange"
            type="daterange"
            value-format="YYYY-MM-DD"
            range-separator="至"
            start-placeholder="开始日期"
            end-placeholder="结束日期"
            style="width: 240px"
          />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="pager.search">查询</el-button>
          <el-button @click="pager.reset">重置</el-button>
        </el-form-item>
      </el-form>
    </div>

    <!-- 表格卡片 -->
    <div class="table-card">
      <div class="table-header">
        <span class="table-title">交易流水</span>
        <span class="table-tip">类型筛选支持充值 / 购买 / 提现 / 奖励；金额为正表示入账、负表示出账</span>
      </div>

      <el-table v-loading="pager.loading" :data="pager.list" border stripe>
        <el-table-column label="流水号" min-width="170" fixed="left" show-overflow-tooltip>
          <template #default="{ row }">{{ transNo(row) }}</template>
        </el-table-column>
        <el-table-column label="用户" min-width="130">
          <template #default="{ row }">
            <div>{{ row.uid || row.userId }}</div>
            <div class="cell-sub">{{ row.username || '-' }}</div>
          </template>
        </el-table-column>
        <el-table-column label="类型" width="110" align="center">
          <template #default="{ row }">
            <el-tag :type="TYPE_TAG[row.transType] || 'info'" size="small" effect="plain">
              {{ TRANS_TYPE[row.transType] || row.transType || '-' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="方向" width="90" align="center">
          <template #default="{ row }">
            <el-tag :type="isIn(row) ? 'success' : 'danger'" size="small">
              {{ TRANS_DIRECTION[Number(row.direction)] || '-' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="金额（元）" width="120" align="right">
          <template #default="{ row }">
            <span :class="isIn(row) ? 'amount-in' : 'amount-out'">
              {{ (isIn(row) ? '+' : '-') + money(row.amount) }}
            </span>
          </template>
        </el-table-column>
        <el-table-column label="变动后余额（元）" width="130" align="right">
          <template #default="{ row }">{{ money(row.balanceAfter) }}</template>
        </el-table-column>
        <el-table-column label="备注" min-width="150" show-overflow-tooltip>
          <template #default="{ row }">{{ row.title || '-' }}</template>
        </el-table-column>
        <el-table-column label="时间" width="170">
          <template #default="{ row }">{{ datetime(row.createdAt) }}</template>
        </el-table-column>
      </el-table>

      <div class="pagination-wrap">
        <el-pagination
          v-model:current-page="pager.page"
          v-model:page-size="pager.pageSize"
          :total="pager.total"
          :page-sizes="[10, 20, 50, 100]"
          layout="total, sizes, prev, pager, next, jumper"
          @current-change="handlePageChange"
          @size-change="handleSizeChange"
        />
      </div>
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

.cell-sub {
  font-size: 12px;
  color: var(--sn-text-secondary);
}

.amount-in,
.amount-out {
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}

.amount-in {
  color: #2e7d32;
}

.amount-out {
  color: #c62828;
}
</style>
