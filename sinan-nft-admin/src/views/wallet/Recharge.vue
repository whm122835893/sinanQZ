<script setup lang="ts">
/**
 * 充值记录
 *
 * - 数据源：WalletController::recharge（trans_type = recharge 的钱包入账流水聚合，返回 list/total/totalAmount）
 * - 搜索：用户（userKeyword：UID 精确 / 手机号模糊）/ 支付方式（payMethod）/ 时间区间（后端 startDate/endDate）
 * - 顶部统计：充值总额（后端 totalAmount，随筛选条件变化）/ 充值笔数 / 笔均金额
 * - 表格字段与后端返回严格一致（后端已驼峰化：wallet_transactions t.* + uid/username）
 */
import { computed, onMounted, ref } from 'vue'
import { useListPage } from '@/utils/useListPage'
import { PAY_METHOD, money, datetime } from '@/utils/format'
import { fetchRechargeRecords } from '@/api/wallet'

/** 充值渠道选项（余额非充值渠道，排除 balance） */
const PAY_OPTIONS = Object.keys(PAY_METHOD).filter((k) => k !== 'balance')

/** 当前筛选条件下的充值总额（后端随列表返回的汇总） */
const totalAmount = ref(0)

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

/** 包装 fetcher：摘出后端返回的汇总 totalAmount 再透传给 useListPage */
function rechargeFetcher(params: Record<string, any>) {
  return withDateRange(fetchRechargeRecords)(params).then((res: any) => {
    totalAmount.value = Number(res?.totalAmount ?? 0)
    return res
  })
}

const pager = useListPage(rechargeFetcher, {
  userKeyword: '',
  payMethod: '',
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

/** 笔均金额 = 充值总额 ÷ 充值笔数 */
const avgAmount = computed(() => {
  const count = Number(pager.total) || 0
  return count > 0 ? totalAmount.value / count : 0
})

const statCards = computed(() => [
  { label: '充值总额（元）', value: money(totalAmount.value), sub: '当前筛选条件下的成功充值合计', icon: 'Coin', color: '' },
  { label: '充值笔数', value: pager.total ?? 0, sub: '钱包 recharge 入账流水', icon: 'Tickets', color: 'blue' },
  { label: '笔均金额（元）', value: money(avgAmount.value), sub: '充值总额 ÷ 充值笔数', icon: 'TrendCharts', color: 'green' },
])

/** 支付单号/流水号：优先业务单号 bizNo，缺省回退流水ID */
function payNo(row: any) {
  return row.bizNo || `#${row.id}`
}
</script>

<template>
  <div class="page-container">
    <!-- 统计卡（后端随列表返回 totalAmount 汇总） -->
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
        <el-form-item label="支付方式">
          <el-select v-model="pager.filters.payMethod" placeholder="全部方式" clearable style="width: 130px">
            <el-option v-for="key in PAY_OPTIONS" :key="key" :label="PAY_METHOD[key]" :value="key" />
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
        <span class="table-title">充值记录</span>
        <span class="table-tip">聚合自钱包 recharge 入账流水（均已到账成功）；支付方式字段后端暂未返回时显示 -</span>
      </div>

      <el-table v-loading="pager.loading" :data="pager.list" border stripe>
        <el-table-column label="用户" min-width="130">
          <template #default="{ row }">
            <div>{{ row.uid || row.userId }}</div>
            <div class="cell-sub">{{ row.username || '-' }}</div>
          </template>
        </el-table-column>
        <el-table-column label="金额（元）" width="110" align="right">
          <template #default="{ row }">
            <span class="amount-in">+{{ money(row.amount) }}</span>
          </template>
        </el-table-column>
        <el-table-column label="支付方式" width="100" align="center">
          <template #default="{ row }">{{ PAY_METHOD[row.payMethod] || row.payMethod || '-' }}</template>
        </el-table-column>
        <el-table-column label="支付单号 / 流水号" min-width="180" show-overflow-tooltip>
          <template #default="{ row }">{{ payNo(row) }}</template>
        </el-table-column>
        <el-table-column label="状态" width="90" align="center">
          <el-tag type="success" size="small" effect="plain">成功</el-tag>
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

.amount-in {
  color: #2e7d32;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}
</style>
