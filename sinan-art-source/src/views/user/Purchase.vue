<script setup>
import { ref, computed, onMounted } from 'vue'
import AppNavBar from '@/components/AppNavBar.vue'
import AppEmpty from '@/components/AppEmpty.vue'
import { useOrderStore } from '@/stores/order'
import { useLoginGate } from '@/utils/loginGate'
import { showToast, showConfirmDialog } from 'vant'

const orderStore = useOrderStore()
const { requireLogin } = useLoginGate()

const tabs = [
  { key: 'all', label: '全部' },
  { key: 'pending', label: '待确认' },
  { key: 'accepted', label: '已完成' },
  { key: 'rejected', label: '已拒绝' }
]
const active = ref('all')
const busyId = ref(null)

onMounted(() => {
  requireLogin(window.location.pathname)
  orderStore.fetchPurchaseOrders().catch(() => {})
})

const statusMeta = {
  pending: { text: '待确认', cls: 'pending' },
  accepted: { text: '已完成', cls: 'done' },
  rejected: { text: '已拒绝', cls: 'canceled' },
  cancelled: { text: '已取消', cls: 'canceled' }
}

const list = computed(() =>
  active.value === 'all'
    ? orderStore.purchaseOrders
    : orderStore.purchaseOrders.filter((o) => o.status === active.value)
)

const fmtTime = (ts) => {
  const d = new Date(ts)
  const p = (n) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`
}

function canHandle(o) {
  return o.direction === 'received' && o.status === 'pending'
}

async function accept(o) {
  try {
    await showConfirmDialog({
      title: '接收转赠',
      message: `确认接收「${o.name}」吗？接收后藏品将进入您的库存。`,
      confirmButtonText: '确认接收',
      confirmButtonColor: '#d32f2f'
    })
  } catch {
    return
  }
  busyId.value = o.id
  try {
    await orderStore.handleTransfer(o.id, 'accept')
    showToast('已接收，藏品进入库存')
  } catch (e) {
    showToast(e?.message || '操作失败')
  } finally {
    busyId.value = null
  }
}

async function reject(o) {
  try {
    await showConfirmDialog({
      title: '拒绝转赠',
      message: `确认拒绝「${o.name}」吗？拒绝后藏品将退回给发起方。`,
      confirmButtonText: '确认拒绝',
      confirmButtonColor: '#999'
    })
  } catch {
    return
  }
  busyId.value = o.id
  try {
    await orderStore.handleTransfer(o.id, 'reject')
    showToast('已拒绝，藏品退回发起方')
  } catch (e) {
    showToast(e?.message || '操作失败')
  } finally {
    busyId.value = null
  }
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
        <div v-if="canHandle(o)" class="transfer-card__actions">
          <button
            class="btn btn--ghost"
            :disabled="busyId === o.id"
            @click="reject(o)"
          >拒绝</button>
          <button
            class="btn btn--primary"
            :disabled="busyId === o.id"
            @click="accept(o)"
          >接收</button>
        </div>
      </div>
    </div>

    <AppEmpty v-else description="暂无相关转赠" />
  </div>
</template>

<style lang="scss" scoped>
.purchase-tabs {
  display: flex; gap: 8px; padding: 14px $page-padding; margin-bottom: 8px;
  &__item {
    flex: 1; text-align: center; padding: 10px 0; font-size: 14px; cursor: pointer;
    border-radius: $radius-md; background: $color-surface; color: $color-text-secondary;
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
  &__actions {
    display: flex; justify-content: flex-end; gap: 10px; padding-top: 12px; margin-top: 12px;
    border-top: 1px solid $color-border;
  }
}

.btn {
  height: 32px; padding: 0 16px; border-radius: 8px; font-size: 13px;
  border: 1px solid transparent; cursor: pointer;
  &--primary { background: #d32f2f; color: #fff; &:disabled { opacity: 0.5; } }
  &--ghost { background: transparent; border-color: #ddd; color: #666; &:disabled { opacity: 0.5; } }
}
</style>
