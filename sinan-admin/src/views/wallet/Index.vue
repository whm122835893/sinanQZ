<script setup>
import { ref, onMounted } from 'vue'
import { getWalletTransactions, getWalletStats } from '@/api'
import AdminTablePage from '@/components/AdminTablePage.vue'
import StatusTag from '@/components/StatusTag.vue'
import { WALLET_TYPE } from '@/utils/maps'
import { fmtMoney, fmtNumber } from '@/utils/format'

const loading = ref(true)
const stats = ref(null)

const filters = [
  {
    field: 'type',
    label: '类型',
    options: [
      { value: 'recharge', label: '充值' },
      { value: 'consume', label: '消费' },
      { value: 'reward', label: '奖励' },
      { value: 'refund', label: '退款' }
    ]
  }
]

const statCards = [
  { key: 'todayRecharge', label: '今日充值' },
  { key: 'todayConsume', label: '今日消费' },
  { key: 'todayReward', label: '今日活动奖励' },
  { key: 'monthRecharge', label: '本月充值' }
]

onMounted(async () => {
  const res = await getWalletStats()
  stats.value = res.data
  loading.value = false
})
</script>

<template>
  <div class="adm-page wl">
    <div class="adm-grid adm-grid--desktop-4">
      <div v-for="s in statCards" :key="s.key" class="adm-card" style="margin: 0">
        <div class="t-tertiary" style="font-size: 12px">{{ s.label }}</div>
        <div class="price" style="font-size: 20px; margin-top: 4px">
          <el-skeleton v-if="loading" :rows="1" animated style="width: 80px" />
          <template v-else>¥{{ fmtNumber(stats?.[s.key]) }}</template>
        </div>
      </div>
    </div>

    <div style="height: 12px" />

    <AdminTablePage :fetch="getWalletTransactions" :filters="filters" search-placeholder="搜索用户 / 流水标题">
      <template #default="{ items }">
        <el-table-column label="流水标题" min-width="220" fixed="left" prop="title" />

        <el-table-column label="用户" width="160">
          <template #default="{ row }">
            <div>{{ row.userName }}</div>
            <div class="t-tertiary" style="font-size: 12px">{{ row.userPhone }}</div>
          </template>
        </el-table-column>

        <el-table-column label="类型" width="90">
          <template #default="{ row }">
            <StatusTag :value="row.type" :map="WALLET_TYPE" />
          </template>
        </el-table-column>

        <el-table-column label="方向" width="80" align="center">
          <template #default="{ row }">
            <el-tag :type="row.direction > 0 ? 'success' : 'danger'" effect="plain" size="small" disable-transitions>
              {{ row.direction > 0 ? '收入' : '支出' }}
            </el-tag>
          </template>
        </el-table-column>

        <el-table-column label="发生额（元）" width="120" align="right">
          <template #default="{ row }">
            <span :class="row.direction > 0 ? 't-success' : ''" class="price">
              {{ row.direction > 0 ? '+' : '-' }}{{ fmtMoney(row.amount) }}
            </span>
          </template>
        </el-table-column>

        <el-table-column label="余额快照" width="110" align="right">
          <template #default="{ row }">{{ fmtMoney(row.balanceAfter) }}</template>
        </el-table-column>

        <el-table-column label="时间" width="160" prop="createTime" />
      </template>
    </AdminTablePage>
  </div>
</template>

<style scoped lang="scss">
.t-success { color: var(--color-success); }
</style>
