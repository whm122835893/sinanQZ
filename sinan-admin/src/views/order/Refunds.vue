<script setup>
import { ref } from 'vue'
import { showSuccessToast, showConfirmDialog } from 'vant'
import { getRefundList, refundAction } from '@/api'
import AdminListPage from '@/components/AdminListPage.vue'
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
  await showConfirmDialog({
    title: action === 'approve' ? '同意退款' : '驳回退款',
    message: action === 'approve'
      ? `确认向「${r.userName}」退款 ¥${fmtMoney(r.amount)}？退款将原路退回余额。`
      : `确认驳回「${r.userName}」的退款申请？`
  })
  const res = await refundAction(r.id, action)
  if (res.code === 0) {
    r.status = res.data
    showSuccessToast(action === 'approve' ? '已退款' : '已驳回')
  }
}
</script>

<template>
  <div class="adm-page">
    <AdminListPage
      :fetch="getRefundList"
      :filters="filters"
      :defaults="{ status: 'pending' }"
      search-placeholder="搜索订单号 / 用户 / 藏品"
    >
      <template #default="{ items }">
        <div v-for="r in items" :key="r.id" class="adm-card rf">
          <div class="adm-item" style="padding: 0; border-bottom: 1px solid var(--color-border)">
            <div class="adm-item__body">
              <div class="adm-item__title">
                {{ r.collectibleName }}
                <StatusTag :value="r.status" :map="REFUND_STATUS" />
              </div>
              <div class="adm-item__desc">{{ r.userName }} · {{ r.userPhone }}</div>
              <div class="adm-item__desc">订单 {{ r.orderNo }}</div>
              <div class="adm-item__desc">申请时间 {{ r.applyTime }}</div>
            </div>
            <div class="adm-item__side">
              <div class="price" style="font-size: 17px">¥{{ fmtMoney(r.amount) }}</div>
            </div>
          </div>

          <div class="rf__reason">
            <van-icon name="chat-o" />
            退款原因：{{ r.reason }}
          </div>

          <div v-if="r.status === 'pending'" class="rf__actions">
            <van-button size="small" round plain type="danger" @click="onAction(r, 'reject')">驳回</van-button>
            <van-button size="small" round type="primary" @click="onAction(r, 'approve')">同意退款</van-button>
          </div>
          <div v-else class="rf__handled t-tertiary">
            处理时间 {{ r.handleTime }}
          </div>
        </div>
      </template>
    </AdminListPage>
  </div>
</template>

<style scoped lang="scss">
.rf__reason {
  display: flex;
  align-items: flex-start;
  gap: 6px;
  margin-top: 10px;
  padding: 8px 10px;
  border-radius: $radius-md;
  background: $color-surface;
  font-size: 12px;
  color: $color-text-secondary;
}

.rf__actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 10px;
}

.rf__handled {
  font-size: 11px;
  margin-top: 10px;
  text-align: right;
}
</style>
