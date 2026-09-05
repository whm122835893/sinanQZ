<script setup>
import { ref, onMounted } from 'vue'
import { getWalletTransactions, getWalletStats } from '@/api'
import AdminListPage from '@/components/AdminListPage.vue'
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

onMounted(async () => {
  const res = await getWalletStats()
  stats.value = res.data
  loading.value = false
})
</script>

<template>
  <div class="adm-page wl">
    <van-skeleton v-if="loading" title :row="4" style="padding: 16px" />
    <template v-else>
      <div class="adm-grid adm-grid--desktop-4">
        <div class="adm-card" style="margin: 0">
          <div class="t-tertiary" style="font-size: 12px">今日充值</div>
          <div class="price" style="font-size: 20px; margin-top: 4px">¥{{ fmtNumber(stats.todayRecharge) }}</div>
        </div>
        <div class="adm-card" style="margin: 0">
          <div class="t-tertiary" style="font-size: 12px">今日消费</div>
          <div class="price" style="font-size: 20px; margin-top: 4px">¥{{ fmtNumber(stats.todayConsume) }}</div>
        </div>
        <div class="adm-card" style="margin: 0">
          <div class="t-tertiary" style="font-size: 12px">今日活动奖励</div>
          <div class="price" style="font-size: 20px; margin-top: 4px">¥{{ fmtNumber(stats.todayReward) }}</div>
        </div>
        <div class="adm-card" style="margin: 0">
          <div class="t-tertiary" style="font-size: 12px">本月充值</div>
          <div class="price" style="font-size: 20px; margin-top: 4px">¥{{ fmtNumber(stats.monthRecharge) }}</div>
        </div>
      </div>
    </template>

    <div style="height: 12px" />

    <AdminListPage :fetch="getWalletTransactions" :filters="filters" search-placeholder="搜索用户 / 流水标题">
      <template #default="{ items }">
        <div class="adm-card" style="padding: 0">
          <div
            v-for="t in items"
            :key="t.id"
            class="adm-item"
            style="padding: 12px 14px"
          >
            <div class="wl__icon" :class="`is-${t.type}`">
              <van-icon :name="{ recharge: 'gold-coin-o', consume: 'shopping-cart-o', reward: 'gift-o', refund: 'refund-o' }[t.type]" size="18" />
            </div>
            <div class="adm-item__body">
              <div class="adm-item__title">{{ t.title }}</div>
              <div class="adm-item__desc">{{ t.userName }} · {{ t.userPhone }}</div>
              <div class="adm-item__desc">{{ t.createTime }} · 余额 {{ fmtMoney(t.balanceAfter) }}</div>
            </div>
            <div class="adm-item__side">
              <div class="price" :class="t.direction > 0 ? 't-success' : ''" style="font-size: 15px">
                {{ t.direction > 0 ? '+' : '-' }}{{ fmtMoney(t.amount) }}
              </div>
              <div style="margin-top: 4px"><StatusTag :value="t.type" :map="WALLET_TYPE" /></div>
            </div>
          </div>
        </div>
      </template>
    </AdminListPage>
  </div>
</template>

<style scoped lang="scss">
.wl__icon {
  width: 36px;
  height: 36px;
  border-radius: 10px;
  @include flex-center;
  flex-shrink: 0;

  &.is-recharge { background: rgba(192, 0, 0, 0.06); color: $color-primary; }
  &.is-consume { background: rgba(255, 151, 106, 0.1); color: var(--color-warning); }
  &.is-reward { background: rgba(7, 193, 96, 0.08); color: var(--color-success); }
  &.is-refund { background: rgba(153, 153, 153, 0.12); color: $color-text-tertiary; }
}
</style>
