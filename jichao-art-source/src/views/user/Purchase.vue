<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import AppNavBar from '@/components/AppNavBar.vue'
import AppEmpty from '@/components/AppEmpty.vue'
import { useOrderStore } from '@/stores/order'
import { useLoginGate } from '@/utils/loginGate'

const route = useRoute()
const orderStore = useOrderStore()
const { requireLogin } = useLoginGate()

const tabs = [
  { key: 'all', label: '全部' },
  { key: 'pending', label: '待确认' },
  { key: 'accepted', label: '已完成' },
  { key: 'rejected', label: '已拒绝' }
]
const active = ref('all')

// MOCK_REPLACED: 原为写死空数组（“转赠功能暂未上线”），现接入真实接口 GET /api/transfers/mine
onMounted(() => {
  requireLogin(route.fullPath)
  orderStore.fetchPurchaseOrders().catch(() => {})
})

// 转赠状态：pending待确认 / accepted已接受 / rejected已拒绝 / cancelled已取消
const statusMeta = {
  pending: { text: '待确认', cls: 'pending' },
  accepted: { text: '已完成', cls: 'done' },
  rejected: { text: '已拒绝', cls: 'canceled' },
  cancelled: { text: '已取消', cls: 'canceled' }
}

const list = computed(() =>
  active.value === 'all'
    ? orderStore.purchaseOrders
    : orderStore.purchaseOrders.filter(o => o.status === active.value)
)

const fmtTime = (ts) => {
  const d = new Date(ts)
  const p = (n) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`
}
</script>

<template>
  <div class="purchase page--no-tabbar">
    <AppNavBar title="转赠记录" @click-left="$router.back()" />

    <div class="purchase-tabs">
      <div
        v-for="tab in tabs"
        :key="tab.key"
        class="purchase-tabs__item"
        :class="{ active: active === tab.key }"
        @click="active = tab.key"
      >{{ tab.label }}</div>
    </div>

    <div class="purchase-list" v-if="list.length">
      <div v-for="o in list" :key="o.id" class="transfer-card">
        <div class="transfer-card__head">
          <span class="transfer-card__dir">{{ o.direction === 'sent' ? '转出' : '收到' }} · {{ o.counterpart }}</span>
          <span class="transfer-card__status" :class="statusMeta[o.status]?.cls">{{ statusMeta[o.status]?.text || o.status }}</span>
        </div>
        <div class="transfer-card__body">
          <img class="transfer-card__cover" :src="o.cover" alt="" draggable="false" />
          <div class="transfer-card__info">
            <p class="transfer-card__name">{{ o.name }}</p>
            <p class="transfer-card__sub">{{ o.no ? '编号 #' + o.no : '' }}</p>
            <p class="transfer-card__time">{{ fmtTime(o.createdAt) }}</p>
          </div>
        </div>
      </div>
    </div>

    <AppEmpty v-else description="暂无相关转赠" />
  </div>
</template>

<style scoped lang="scss">
.purchase-tabs {
  display: flex; gap: 10px; padding: 14px $page-padding; background: $color-card; margin-bottom: 8px;
  &__item {
    flex: 1; text-align: center; padding: 8px 0; font-size: 14px; cursor: pointer;
    border-radius: $radius-pill; background: $color-surface; color: $color-text-secondary;
  }
  &__item.active { background: $color-primary; color: #fff; font-weight: 600; }
}
.purchase-list { padding: 12px $page-padding; }
.transfer-card {
  background: $color-card; border-radius: $radius-lg; padding: 14px; margin-bottom: 12px;
  &__head {
    display: flex; align-items: center; justify-content: space-between;
    padding-bottom: 12px; border-bottom: 1px solid $color-border;
  }
  &__dir { font-size: 13px; color: $color-text-secondary; }
  &__status { font-size: 12px; font-weight: 600; }
  &__status.pending { color: $color-primary; }
  &__status.done { color: $color-text-tertiary; }
  &__status.canceled { color: #999; }
  &__body { display: flex; gap: 12px; padding: 12px 0 0; }
  &__cover {
    width: 60px; height: 60px; border-radius: 8px; object-fit: cover; flex-shrink: 0;
    background: $color-surface; pointer-events: none; user-select: none;
  }
  &__info { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 6px; }
  &__name { margin: 0; font-size: 15px; font-weight: 600; color: $color-text-primary; @include ellipsis; }
  &__sub { margin: 0; font-size: 12px; color: $color-text-tertiary; }
  &__time { margin: 0; font-size: 11px; color: $color-text-tertiary; font-family: $font-price; }
}
</style>
