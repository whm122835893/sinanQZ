<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import AppNavBar from '@/components/AppNavBar.vue'
import AppEmpty from '@/components/AppEmpty.vue'
import { showToast } from 'vant'
import { useUserStore } from '@/stores/user'
import { useOrderStore } from '@/stores/order'
import { useLoginGate } from '@/utils/loginGate'

const route = useRoute()
const router = useRouter()
const user = useUserStore()
const orderStore = useOrderStore()
const { requireLogin } = useLoginGate()

const tabs = [
  { key: 'all', label: '全部' },
  { key: 'done', label: '已完成' },
  { key: 'resale', label: '市场购买' },
  { key: 'release', label: '发售购买' }
]
const active = ref('all')

// 每秒刷新：待支付倒计时（订单超时由后端自动失效，前端仅展示）
const now = ref(Date.now())
let timer = null
onMounted(() => {
  // 未登录统一弹全局登录提示
  requireLogin(route.fullPath)
  // MOCK_REPLACED: 原为本地内存订单，现从后端拉取（GET /api/orders）
  orderStore.fetchOrders().catch(() => {})
  timer = setInterval(() => {
    now.value = Date.now()
  }, 1000)
})
onUnmounted(() => { if (timer) clearInterval(timer) })

const statusMeta = {
  pending: { text: '待支付', cls: 'pending' },
  done: { text: '已完成', cls: 'done' },
  cancelled: { text: '已取消', cls: 'canceled' }
}

// 待支付订单剩余支付时间 mm:ss
function remainText(o) {
  const r = Math.max(0, Math.ceil((o.expiresAt - now.value) / 1000))
  const m = String(Math.floor(r / 60)).padStart(2, '0')
  const s = String(r % 60).padStart(2, '0')
  return m + ':' + s
}

const fmtTime = (ts) => {
  const d = new Date(ts)
  const p = (n) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`
}

const list = computed(() => {
  if (!user.isLoggedIn) return []
  if (active.value === 'all') return orderStore.orders
  return orderStore.orders.filter(o => o.kind === active.value)
})

function action(o) {
  if (o.status === 'pending') showToast('订单待支付，请在支付页完成支付')
  else if (o.status === 'cancelled') showToast('订单已超时取消，库存已释放')
  else showToast('藏品已存入我的库存')
}
function goDetail(o) {
  router.push({ name: 'collection-detail', params: { id: o.itemId || o.id } })
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
          <span class="order-card__no">订单号 {{ o.id }}</span>
          <span class="order-card__status" :class="statusMeta[o.status].cls">
            {{ o.status === 'pending' ? '待支付 ' + remainText(o) : statusMeta[o.status].text }}
          </span>
        </div>
        <div class="order-card__body" @click="goDetail(o)">
          <img class="order-card__cover" :src="o.cover" alt="" draggable="false" @contextmenu.prevent @pointerdown.prevent @click.prevent />
          <div class="order-card__info">
            <p class="order-card__name">{{ o.name }}</p>
            <p class="order-card__sub">{{ o.kind === 'resale' ? '市场购买' : '发售购买' }}{{ o.no ? ' · 编号 #' + o.no : '' }} · ×{{ o.qty }}</p>
            <p class="order-card__time">{{ fmtTime(o.createdAt) }}</p>
          </div>
          <div class="order-card__price">
            <span>合计</span>
            <b>¥{{ (o.price * o.qty).toFixed(2) }}</b>
          </div>
        </div>
        <div class="order-card__foot">
          <button class="order-card__btn" @click="action(o)">查看藏品</button>
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
  &__status.canceled { color: #999; }
  &__status.pending { color: $color-primary; font-family: $font-price; }
  &__status.done { color: $color-text-tertiary; }
  &__body {
    display: flex; gap: 12px; padding: 12px 0; cursor: pointer;
  }
  &__cover { width: 60px; height: 60px; border-radius: 8px; object-fit: cover; flex-shrink: 0; background: $color-surface; -webkit-user-drag: none; -webkit-touch-callout: none; user-select: none; pointer-events: none; }
  &__info { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 6px; }
  &__name { margin: 0; font-size: 15px; font-weight: 600; color: $color-text-primary; @include ellipsis; }
  &__sub { margin: 0; font-size: 12px; color: $color-text-tertiary; }
  &__time { margin: 0; font-size: 11px; color: $color-text-tertiary; font-family: $font-price; }
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
