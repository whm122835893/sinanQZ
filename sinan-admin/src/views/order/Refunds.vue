<script setup>
import { ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { getRefundList, refundAction } from '@/api'
import AdminTablePage from '@/components/AdminTablePage.vue'
import StatusTag from '@/components/StatusTag.vue'
import { REFUND_STATUS } from '@/utils/maps'
import { fmtMoney } from '@/utils/format'

const filters = [
  {
    field: 'status',
    label: '状态',
    options: [
      { value: 'pending', label: '待审批' },
      { value: 'approved', label: '已退款' },
      { value: 'rejected', label: '已驳回' }
    ]
  }
]

async function onAction(r, action) {
  await ElMessageBox.confirm(
    action === 'approve'
      ? `确认向「${r.userName}」退款 ¥${fmtMoney(r.amount)}？退款将原路退回余额，并联动订单状态为已退款。`
      : `确认驳回「${r.userName}」的退款申请？`,
    action === 'approve' ? '同意退款' : '驳回退款',
    { type: action === 'approve' ? 'warning' : 'error' }
  )
  const res = await refundAction(r.id, action)
  if (res.code === 0) {
    r.status = res.data
    ElMessage.success(action === 'approve' ? '已退款' : '已驳回')
  }
}
</script>

<template>
  <div class="adm-page">
    <AdminTablePage
      :fetch="getRefundList"
      :filters="filters"
      :defaults="{ status: 'pending' }"
      search-placeholder="搜索订单号 / 用户 / 藏品"
    >
      <template #default="{ items }">
        <el-table-column label="订单号" width="180" prop="orderNo" fixed="left" />

        <el-table-column label="藏品" min-width="160" prop="collectibleName" />

        <el-table-column label="申请人" width="160">
          <template #default="{ row }">
            <div>{{ row.userName }}</div>
            <div class="t-tertiary" style="font-size: 12px">{{ row.userPhone }}</div>
          </template>
        </el-table-column>

        <el-table-column label="退款金额" width="110" align="right">
          <template #default="{ row }">
            <span class="price">¥{{ fmtMoney(row.amount) }}</span>
          </template>
        </el-table-column>

        <el-table-column label="退款原因" min-width="180" show-overflow-tooltip prop="reason" />

        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <StatusTag :value="row.status" :map="REFUND_STATUS" />
          </template>
        </el-table-column>

        <el-table-column label="申请时间" width="150" prop="applyTime" />

        <el-table-column label="处理时间" width="150">
          <template #default="{ row }">{{ row.handleTime || '-' }}</template>
        </el-table-column>

        <el-table-column label="操作" width="130" fixed="right">
          <template #default="{ row }">
            <template v-if="row.status === 'pending'">
              <el-button link type="danger" size="small" @click="onAction(row, 'reject')">驳回</el-button>
              <el-button link type="primary" size="small" @click="onAction(row, 'approve')">同意退款</el-button>
            </template>
            <span v-else class="t-tertiary">—</span>
          </template>
        </el-table-column>
      </template>
    </AdminTablePage>
  </div>
</template>
