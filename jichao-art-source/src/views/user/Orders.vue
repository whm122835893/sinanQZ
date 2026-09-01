<script setup>
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import AppNavBar from '@/components/AppNavBar.vue'
import AppEmpty from '@/components/AppEmpty.vue'
import { showToast } from 'vant'

const router = useRouter()

const tabs = [
  { key: 'all', label: '全部' },
  { key: 'pending', label: '待付款' },
  { key: 'shipping', label: '已取消' },
  { key: 'done', label: '已完成' }
]
const active = ref('all')

const orders = [
  { id: 1, cover: '/images/collections/cover-1.jpg', name: '司南·青铜纹样', no: '0086', price: 399, qty: 1, status: 'pending' },
  { id: 2, cover: '/images/collections/cover-3.jpg', name: '司南·鎏金面具', no: '0031', price: 880, qty: 1, status: 'shipping' },
  { id: 3, cover: '/images/collections/cover-2.jpg', name: '司南·雪山之眼', no: '0120', price: 268, qty: 2, status: 'done' },
  { id: 4, cover: '/images/collections/cover-5.jpg', name: '司南·敦煌飞天', no: '0007', price: 1280, qty: 1, status: 'done' }
]

const statusMeta = {
  pending: { text: '待付款', cls: 'pending' },
  shipping: { text: '已取消', cls: 'canceled' },
  done: { text: '已完成', cls: 'done' }
}

const list = computed(() =>
  active.value === 'all' ? orders : orders.filter(o => o.status === active.value)
)

function action(o) {
  if (o.status === 'pending') showToast('前往支付')
  else if (o.status === 'shipping') showToast('已取消的订单不可操作')
  else showToast('再次购买')
}
function goDetail(o) {
  router.push({ name: 'collection-detail', params: { id: o.id } })
}
</script>

<template>
  <div class="mine-orders page--no-tabbar">
    <AppNavBar title="我的订单" @click-left="$router.back()" />

    <div class="mine-orders__tabs">
      <div
        v-for="tab in tabs"
        :key="tab.key"
        class="mine-orders__tab"
        :class="{ active: active === tab.key }"
        @click="active = tab.key"
      >{{ tab.label }}</div>
    </div>

    <div class="mine-orders__list" v-if="list.length">
      <div v-for="o in list" :key="o.id" class="order-card">
        <div class="order-card__head">
          <span class="order-card__no">订单号 {{ o.id }}-{{ o.no }}</span>
          <span class="order-card__status" :class="statusMeta[o.status].cls">{{ statusMeta[o.status].text }}</span>
        </div>
        <div class="order-card__body" @click="goDetail(o)">
          <img class="order-card__cover" :src="o.cover" alt="" draggable="false" @contextmenu.prevent @pointerdown.prevent @click.prevent />
          <div class="order-card__info">
            <p class="order-card__name">{{ o.name }}</p>
            <p class="order-card__sub">编号 #{{ o.no }} · ×{{ o.qty }}</p>
          </div>
          <div class="order-card__price">
            <span>合计</span>
            <b>¥{{ (o.price * o.qty).toLocaleString() }}</b>
          </div>
        </div>
        <div class="order-card__foot">
          <button class="order-card__btn" @click="action(o)">
            {{ o.status === 'pending' ? '去支付' : o.status === 'shipping' ? '已取消' : '再次购买' }}
          </button>
        </div>
      </div>
    </div>

    <AppEmpty v-else description="暂无相关订单" />
  </div>
</template>

<style scoped lang="scss">
.mine-orders__tabs {
  display: flex; padding: 12px $page-padding; background: $color-card; gap: 24px;
  border-bottom: 1px solid $color-border; overflow-x: auto;
  .mine-orders__tab {
    font-size: 14px; color: $color-text-secondary; white-space: nowrap; cursor: pointer; position: relative; padding-bottom: 6px;
  }
  .mine-orders__tab.active { color: $color-text-primary; font-weight: 700; }
  .mine-orders__tab.active::after {
    content: ''; position: absolute; left: 50%; transform: translateX(-50%); bottom: 0;
    width: 18px; height: 3px; border-radius: 2px; background: $color-primary;
  }
}

.mine-orders__list { padding: 12px $page-padding; }
.order-card {
  background: $color-card; border-radius: $radius-lg; padding: 14px; margin-bottom: 12px;
  &__head {
    display: flex; align-items: center; justify-content: space-between;
    padding-bottom: 12px; border-bottom: 1px solid $color-border;
  }
  &__no { font-size: 12px; color: $color-text-tertiary; font-family: $font-price; }
  &__status { font-size: 12px; font-weight: 600; }
  &__status.pending { color: $color-primary; }
  &__status.canceled { color: #999; }
  &__status.done { color: $color-text-tertiary; }
  &__body {
    display: flex; gap: 12px; padding: 12px 0; cursor: pointer;
  }
  &__cover { width: 60px; height: 60px; border-radius: 8px; object-fit: cover; flex-shrink: 0; background: $color-surface; -webkit-user-drag: none; -webkit-touch-callout: none; user-select: none; pointer-events: none; }
  &__info { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 6px; }
  &__name { margin: 0; font-size: 15px; font-weight: 600; color: $color-text-primary; @include ellipsis; }
  &__sub { margin: 0; font-size: 12px; color: $color-text-tertiary; }
  &__price { display: flex; flex-direction: column; align-items: flex-end; gap: 2px; flex-shrink: 0; }
  &__price span { font-size: 11px; color: $color-text-tertiary; }
  &__price b { font-size: 16px; font-weight: 700; color: $color-primary; font-family: $font-price; }
  &__foot { display: flex; justify-content: flex-end; }
  &__btn {
    border: 1px solid $color-primary; color: $color-primary; background: #fff;
    font-size: 13px; height: 32px; padding: 0 18px; border-radius: $radius-pill; cursor: pointer;
  }
}
</style>
