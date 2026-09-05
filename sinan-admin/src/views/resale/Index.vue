<script setup>
import { showSuccessToast, showConfirmDialog } from 'vant'
import { getResaleList, resaleAction } from '@/api'
import AdminListPage from '@/components/AdminListPage.vue'
import StatusTag from '@/components/StatusTag.vue'
import { RESALE_STATUS } from '@/utils/maps'
import { fmtMoney } from '@/utils/format'

const filters = [
  {
    field: 'status',
    label: '状态',
    options: [
      { value: 'onsale', label: '挂单中' },
      { value: 'frozen', label: '已冻结' },
      { value: 'sold', label: '已成交' },
      { value: 'cancelled', label: '已取消' }
    ]
  }
]

async function onAction(r, action) {
  const map = {
    freeze: { title: '冻结挂单', msg: `确认冻结「${r.collectibleName}」的寄售挂单？冻结期间不可被购买。` },
    unfreeze: { title: '解除冻结', msg: `确认恢复该挂单为在售状态？` },
    cancel: { title: '强制下架', msg: `确认强制下架该挂单？藏品将退回卖家账户。` }
  }
  const cfg = map[action]
  await showConfirmDialog({ title: cfg.title, message: cfg.msg })
  const res = await resaleAction(r.id, action)
  if (res.code === 0) {
    r.status = res.data
    showSuccessToast('操作成功')
  }
}
</script>

<template>
  <div class="adm-page">
    <AdminListPage :fetch="getResaleList" :filters="filters" search-placeholder="搜索挂单号 / 卖家 / 藏品">
      <template #default="{ items }">
        <div v-for="r in items" :key="r.id" class="adm-card rs">
          <div class="adm-item" style="padding: 0; border-bottom: 1px solid var(--color-border)">
            <img class="adm-item__thumb" style="width: 46px; height: 46px" :src="r.cover" :alt="r.collectibleName" />
            <div class="adm-item__body">
              <div class="adm-item__title">
                {{ r.collectibleName }}
                <StatusTag :value="r.status" :map="RESALE_STATUS" />
              </div>
              <div class="adm-item__desc">{{ r.serial }}</div>
              <div class="adm-item__desc">{{ r.sellerName }} · {{ r.userPhone }}</div>
            </div>
            <div class="adm-item__side">
              <div class="price" style="font-size: 16px">¥{{ fmtMoney(r.price) }}</div>
              <div class="t-tertiary" style="font-size: 11px; margin-top: 4px">寄售价</div>
            </div>
          </div>

          <div class="rs__foot">
            <span class="t-tertiary">{{ r.listingNo }} · {{ r.createTime }}</span>
            <span v-if="r.status === 'onsale' || r.status === 'frozen'" class="rs__ops">
              <van-button v-if="r.status === 'onsale'" size="small" round plain type="warning" @click="onAction(r, 'freeze')">冻结</van-button>
              <van-button v-else size="small" round plain type="primary" @click="onAction(r, 'unfreeze')">解冻</van-button>
              <van-button size="small" round plain type="danger" @click="onAction(r, 'cancel')">强制下架</van-button>
            </span>
          </div>
        </div>
      </template>
    </AdminListPage>
  </div>
</template>

<style scoped lang="scss">
.rs__foot {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 11px;
  margin-top: 10px;
}

.rs__ops { display: flex; gap: 6px; }
</style>
