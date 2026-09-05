<script setup>
import { showSuccessToast, showConfirmDialog } from 'vant'
import { getTransferList, transferAction } from '@/api'
import AdminListPage from '@/components/AdminListPage.vue'
import StatusTag from '@/components/StatusTag.vue'
import { TRANSFER_STATUS } from '@/utils/maps'

const filters = [
  {
    field: 'status',
    label: '状态',
    options: [
      { value: 'pending', label: '待接收' },
      { value: 'completed', label: '已完成' },
      { value: 'rejected', label: '已拒绝' }
    ]
  }
]

async function onAction(t, action) {
  await showConfirmDialog({
    title: action === 'approve' ? '强制完成' : '强制拒绝',
    message: action === 'approve'
      ? `确认强制完成「${t.fromUser}」→「${t.toUser}」的转赠？藏品将直接过户。`
      : `确认强制拒绝该转赠？藏品将退回转出方账户。`
  })
  const res = await transferAction(t.id, action)
  if (res.code === 0) {
    t.status = res.data
    showSuccessToast('操作成功')
  }
}
</script>

<template>
  <div class="adm-page">
    <AdminListPage :fetch="getTransferList" :filters="filters" search-placeholder="搜索转出人 / 接收人 / 藏品">
      <template #default="{ items }">
        <div v-for="t in items" :key="t.id" class="adm-card tf">
          <div class="tf__line">
            <img class="adm-item__thumb" style="width: 46px; height: 46px" :src="t.cover" :alt="t.collectibleName" />
            <div class="adm-item__body">
              <div class="adm-item__title">
                {{ t.collectibleName }}
                <StatusTag :value="t.status" :map="TRANSFER_STATUS" />
              </div>
              <div class="adm-item__desc">{{ t.serial }}</div>
              <div class="adm-item__desc">{{ t.createTime }}</div>
            </div>
          </div>

          <div class="tf__flow">
            <div class="tf__party">
              <div class="tf__avatar">{{ t.fromUser.slice(0, 1) }}</div>
              <div class="tf__name">{{ t.fromUser }}</div>
              <div class="tf__role">转出方</div>
            </div>
            <div class="tf__arrow">
              <van-icon name="arrow" :color="t.status === 'completed' ? 'var(--color-success)' : 'var(--color-primary)'" />
            </div>
            <div class="tf__party">
              <div class="tf__avatar">{{ t.toUser.slice(0, 1) }}</div>
              <div class="tf__name">{{ t.toUser }}</div>
              <div class="tf__role">接收方 · {{ t.toPhone }}</div>
            </div>
          </div>

          <div v-if="t.status === 'pending'" class="tf__ops">
            <van-button size="small" round plain type="danger" @click="onAction(t, 'reject')">强制拒绝</van-button>
            <van-button size="small" round plain type="primary" @click="onAction(t, 'approve')">强制完成</van-button>
          </div>
        </div>
      </template>
    </AdminListPage>
  </div>
</template>

<style scoped lang="scss">
.tf__line {
  display: flex;
  gap: 10px;
  padding-bottom: 10px;
  border-bottom: 1px solid $color-border;
}

.tf__flow {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 12px 0 2px;
}

.tf__party {
  flex: 1;
  text-align: center;
  min-width: 0;
}

.tf__avatar {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  margin: 0 auto;
  @include flex-center;
  background: var(--color-primary-bg);
  color: $color-primary;
  font-weight: 700;
}

.tf__name {
  font-size: 12px;
  font-weight: 600;
  margin-top: 6px;
  @include ellipsis;
}

.tf__role { font-size: 10px; color: $color-text-tertiary; margin-top: 2px; }

.tf__ops {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 10px;
}
</style>
