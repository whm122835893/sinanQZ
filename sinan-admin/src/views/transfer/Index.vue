<script setup>
import { ElMessage, ElMessageBox } from 'element-plus'
import { Right } from '@element-plus/icons-vue'
import { getTransferList, transferAction } from '@/api'
import AdminTablePage from '@/components/AdminTablePage.vue'
import StatusTag from '@/components/StatusTag.vue'
import { TRANSFER_STATUS } from '@/utils/maps'

const filters = [
  {
    field: 'status',
    label: '状态',
    options: [
      { value: 'pending', label: '待接收' },
      { value: 'completed', label: '已完成' },
      { value: 'rejected', label: '已拒绝' },
      { value: 'revoked', label: '已撤销' }
    ]
  }
]

async function onAction(t, action) {
  const map = {
    approve: { title: '强制完成', msg: `确认强制完成「${t.fromUser}」→「${t.toUser}」的转赠？藏品将直接过户。`, type: 'warning' },
    reject: { title: '强制拒绝', msg: '确认强制拒绝该转赠？藏品将退回转出方账户。', type: 'info' },
    revoke: {
      title: '撤销已完成转赠',
      msg: `确认撤销「${t.fromUser}」→「${t.toUser}」已完成的转赠？系统将校验接收方是否仍持有该资产，若已发生二次流转（再次转赠 / 寄售 / 合成 / 盲盒消耗）则拦截。`,
      type: 'error'
    }
  }
  const cfg = map[action]
  await ElMessageBox.confirm(cfg.msg, cfg.title, { type: cfg.type })
  const res = await transferAction(t.id, action)
  if (res.code === 0) {
    t.status = res.data
    ElMessage.success(action === 'revoke' ? '已撤销，藏品退回转出方并写入审计日志' : '操作成功，已写入审计日志')
  } else {
    ElMessage.error(res.message)
  }
}
</script>

<template>
  <div class="adm-page">
    <AdminTablePage :fetch="getTransferList" :filters="filters" search-placeholder="搜索转出人 / 接收人 / 藏品">
      <template #default="{ items }">
        <el-table-column label="藏品" min-width="200" fixed="left">
          <template #default="{ row }">
            <div class="tf__cell">
              <img class="tf__cover" :src="row.cover" :alt="row.collectibleName" />
              <div>
                <div class="tf__name">{{ row.collectibleName }}</div>
                <div class="t-tertiary" style="font-size: 11px">{{ row.serial }}</div>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="转赠流向" min-width="190">
          <template #default="{ row }">
            <div class="tf__flow">
              <span class="tf__party">{{ row.fromUser }}</span>
              <el-icon class="tf__arrow" :class="{ 'is-done': row.status === 'completed' }"><Right /></el-icon>
              <span class="tf__party">{{ row.toUser }}</span>
            </div>
            <div class="t-tertiary" style="font-size: 11px; margin-top: 2px">接收方 {{ row.toPhone }}</div>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <StatusTag :value="row.status" :map="TRANSFER_STATUS" />
          </template>
        </el-table-column>
        <el-table-column label="发起时间" prop="createTime" width="150" />
        <el-table-column label="操作" width="180" fixed="right">
          <template #default="{ row }">
            <template v-if="row.status === 'pending'">
              <el-button link type="primary" size="small" @click="onAction(row, 'approve')">强制完成</el-button>
              <el-button link type="danger" size="small" @click="onAction(row, 'reject')">强制拒绝</el-button>
            </template>
            <el-button
              v-else-if="row.status === 'completed'"
              link type="warning" size="small"
              @click="onAction(row, 'revoke')"
            >撤销转赠</el-button>
            <span v-else class="t-tertiary" style="font-size: 12px">-</span>
          </template>
        </el-table-column>
      </template>
    </AdminTablePage>

    <el-alert
      type="info"
      :closable="false"
      show-icon
      title="转赠开关独立：关闭后用户端「转赠」按钮置灰（已发起待确认的转赠不受影响）；撤销仅针对「已完成」转赠，接收方已再次流转则拦截并明确提示原因"
    />
  </div>
</template>

<style scoped lang="scss">
.tf__cell {
  display: flex;
  align-items: center;
  gap: 10px;
}

.tf__cover {
  width: 40px;
  height: 40px;
  border-radius: 6px;
  object-fit: cover;
  flex-shrink: 0;
  background: $color-surface;
}

.tf__name {
  font-size: 13px;
  font-weight: 600;
  color: $color-text-primary;
}

.tf__flow {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
}

.tf__party {
  font-weight: 600;
  color: $color-text-primary;
  @include ellipsis;
}

.tf__arrow {
  color: $color-primary;
  flex-shrink: 0;

  &.is-done { color: var(--color-success); }
}
</style>
