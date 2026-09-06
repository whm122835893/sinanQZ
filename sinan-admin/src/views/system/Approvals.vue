<script setup>
import { ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { getApprovals, handleApproval } from '@/api'
import AdminTablePage from '@/components/AdminTablePage.vue'
import StatusTag from '@/components/StatusTag.vue'
import { APPROVAL_STATUS, APPROVAL_TYPE } from '@/utils/maps'
import { fmtMoney } from '@/utils/format'

const filters = [
  {
    field: 'status',
    label: '状态',
    options: [
      { value: 'pending', label: '待审批' },
      { value: 'approved', label: '已通过' },
      { value: 'rejected', label: '已驳回' }
    ]
  },
  {
    field: 'type',
    label: '类型',
    options: Object.entries(APPROVAL_TYPE).map(([value, label]) => ({ value, label }))
  }
]

const listRef = ref(null)

// ---- 审批通过 ----
async function onApprove(a) {
  await ElMessageBox.confirm(
    `确认通过审批「${a.title}」？通过后系统将自动执行对应操作（退款资金回流 / 资产变更 / 配置生效）。`,
    '审批通过',
    { type: 'warning' }
  )
  const res = await handleApproval({ id: a.id, action: 'approve' })
  if (res.code === 0) {
    ElMessage.success('已通过，执行结果已写入审计日志')
    listRef.value?.refresh()
  }
}

// ---- 审批驳回 ----
async function onReject(a) {
  const { value } = await ElMessageBox.prompt('请填写驳回原因（申请人可见）', '驳回审批', {
    type: 'warning',
    inputPlaceholder: '请输入驳回原因',
    inputValidator: (v) => (v && v.trim() ? true : '驳回原因不能为空')
  })
  const res = await handleApproval({ id: a.id, action: 'reject', reason: value.trim() })
  if (res.code === 0) {
    ElMessage.success('已驳回')
    listRef.value?.refresh()
  }
}
</script>

<template>
  <div class="adm-page ap">
    <AdminTablePage
      ref="listRef"
      :fetch="getApprovals"
      :filters="filters"
      :defaults="{ status: 'pending' }"
      search-placeholder="搜索标题 / 申请人 / 理由"
    >
      <template #default="{ items }">
        <el-table-column label="审批事项" min-width="280" fixed="left" show-overflow-tooltip>
          <template #default="{ row }">{{ row.title }}</template>
        </el-table-column>
        <el-table-column label="类型" width="110" align="center">
          <template #default="{ row }">
            <el-tag type="info" effect="plain" size="small">{{ APPROVAL_TYPE[row.type] || row.type }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="涉及金额" width="120" align="right">
          <template #default="{ row }">
            <span v-if="row.amount" class="price">{{ fmtMoney(row.amount) }}</span>
            <span v-else class="t-tertiary">-</span>
          </template>
        </el-table-column>
        <el-table-column label="申请人" prop="applicant" width="100" />
        <el-table-column label="状态" width="90" align="center">
          <template #default="{ row }">
            <StatusTag :value="row.status" :map="APPROVAL_STATUS" />
          </template>
        </el-table-column>
        <el-table-column label="申请时间" prop="createTime" width="160" />
        <el-table-column label="审批信息" min-width="160" show-overflow-tooltip>
          <template #default="{ row }">
            <template v-if="row.status !== 'pending'">
              <div class="t-secondary">{{ row.reason || '-' }}</div>
              <div class="t-tertiary" style="font-size: 12px">{{ row.handler }} · {{ row.handleTime }}</div>
            </template>
            <span v-else class="t-secondary">{{ row.reason }}</span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="130" fixed="right">
          <template #default="{ row }">
            <template v-if="row.status === 'pending'">
              <el-button link type="primary" size="small" @click="onApprove(row)">通过</el-button>
              <el-button link type="danger" size="small" @click="onReject(row)">驳回</el-button>
            </template>
            <span v-else class="t-tertiary">已完结</span>
          </template>
        </el-table-column>
      </template>
    </AdminTablePage>

    <el-alert
      type="info"
      :closable="false"
      show-icon
      class="ap__tip"
      title="敏感操作审批工作流：大额退款、强制修改用户资产、修改支付配置、平台清库等操作需审批通过后方可执行（nft_approvals）"
    />
  </div>
</template>

<style scoped lang="scss">
.ap__tip { margin-top: 4px; }
</style>
