<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import AppNavBar from '@/components/AppNavBar.vue'
import AppEmpty from '@/components/AppEmpty.vue'
import { showToast, showConfirmDialog } from 'vant'
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
  { key: 'pending', label: '待支付' },
  { key: 'cancelled', label: '已取消' },
  { key: 'airdrop', label: '空投' }
]
const active = ref('all')

// 每秒刷新：待支付倒计时（订单超时由后端自动失效，前端仅展示）
const now = ref(Date.now())
let timer = null
onMounted(() => {
  // 未登录统一弹全局登录提示
  requireLogin(route.fullPath)
  orderStore.fetchOrders().catch(() => {})
  orderStore.fetchAirdrops().catch(() => {})
  timer = setInterval(() => {
    now.value = Date.now()
  }, 1000)
})
onUnmounted(() => { if (timer) clearInterval(timer) })

const statusMeta = {
  pending: { text: '待支付', cls: 'pending' },
  done: { text: '已完成', cls: 'done' },
  cancelled: { text: '已取消', cls: 'canceled' },
  issued: { text: '已发放', cls: 'done' },
  failed: { text: '发放失败', cls: 'canceled' }
}

// 待支付订单剩余支付时间 mm:ss
function remainText(o) {
  const r = Math.max(0, Math.ceil((o.expiresAt - now.value) / 1000))
  const m = String(Math.floor(r / 60)).padStart(2, '0')
  const s = String(r % 60).padStart(2, '0')
  return m + ':' + s
}

const fmtTime = (ts) => {
  if (!ts) return '-'
  const d = new Date(ts)
  const p = (n) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`
}

// 统一标记：给两类数据都加上 isAirdrop 标记，并按时间倒序合并
const merged = computed(() => {
  const orders = (orderStore.orders || []).map(o => ({ ...o, isAirdrop: false, kindText: o.kind === 'resale' ? '市场购买' : '发售购买' }))
  const airdrops = (orderStore.airdrops || []).map(a => ({ ...a, isAirdrop: true, kindText: '官方空投' }))
  const all = [...orders, ...airdrops]
  all.sort((a, b) => (b.createdAt || 0) - (a.createdAt || 0))
  return all
})

// 当前 tab 对应的列表
const list = computed(() => {
  if (!user.isLoggedIn) return []
  switch (active.value) {
    case 'all':      return merged.value
    case 'done':     return merged.value.filter(x => x.status === 'done' || x.status === 'issued')
    case 'pending':  return merged.value.filter(x => x.status === 'pending')
    case 'cancelled':return merged.value.filter(x => x.status === 'cancelled' || x.status === 'failed')
    case 'airdrop':  return merged.value.filter(x => x.isAirdrop)
    default:         return []
  }
})

// 是否空列表
const isEmpty = computed(() => list.value.length === 0)

function action(o) {
  if (o.isAirdrop) {
    if (o.status === 'issued') showToast('空投藏品已存入我的库存')
    else if (o.status === 'failed') showToast('发放失败，请联系客服')
    else showToast('空投处理中')
  } else {
    if (o.status === 'pending') showToast('订单待支付，请在支付页完成支付')
    else if (o.status === 'cancelled') showToast('订单已超时取消，库存已释放')
    else showToast('藏品已存入我的库存')
  }
}

function goDetail(o) {
  if (!o.itemId) return
  router.push({ name: 'collection-detail', params: { id: o.itemId } })
}

let cancellingId = null
async function onCancel(o) {
  if (cancellingId || o.status !== 'pending' || o.isAirdrop) return
  try {
    await showConfirmDialog({
      title: '取消订单',
      message: '确定取消此待支付订单？库存将被释放。',
      confirmButtonText: '确定取消',
      confirmButtonColor: '#D00000'
    })
  } catch { return }
  cancellingId = o.id
  try {
    await orderStore.cancelOrder(o.id)
    showToast('订单已取消')
  } catch (e) {
    showToast(e.message || '取消失败，请稍后再试')
  } finally {
    cancellingId = null
  }
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

    <!-- 统一列表 -->
    <div class="mine-orders__list" v-if="!isEmpty">
      <div v-for="o in list" :key="o.id" class="order-card" :class="{ 'airdrop-card': o.isAirdrop }">
        <div class="order-card__head">
          <span class="order-card__no">{{ o.isAirdrop ? '空投单号 #' : '订单号 ' }}{{ o.id }}</span>
          <span class="order-card__status" :class="statusMeta[o.status]?.cls || ''">
            <template v-if="o.isAirdrop">
              {{ statusMeta[o.status]?.text || o.status }}
            </template>
            <template v-else>
              {{ o.status === 'pending' ? '待支付 ' + remainText(o) : statusMeta[o.status]?.text || o.status }}
            </template>
          </span>
        </div>
        <div class="order-card__body" @click="goDetail(o)">
          <img class="order-card__cover" :src="o.cover" alt="" draggable="false" @contextmenu.prevent @pointerdown.prevent @click.prevent />
          <div class="order-card__info">
            <p class="order-card__name">{{ o.name }}</p>
            <p class="order-card__sub">
              {{ o.kindText }}{{ o.isAirdrop ? '' : (o.no ? ' · 编号 #' + o.no : '') }} · ×{{ o.qty }}
            </p>
            <p class="order-card__time">{{ o.isAirdrop ? '发放于 ' : '' }}{{ fmtTime(o.createdAt) }}</p>
          </div>
          <div class="order-card__price" v-if="!o.isAirdrop">
            <span>合计</span>
            <b>¥{{ (o.price * o.qty).toFixed(2) }}</b>
          </div>
        </div>
        <div class="order-card__foot">
          <button v-if="!o.isAirdrop && o.status === 'pending'" class="order-card__btn order-card__btn--ghost" :disabled="cancellingId === o.id" @click="onCancel(o)">
            {{ cancellingId === o.id ? '取消中...' : '取消订单' }}
          </button>
          <button class="order-card__btn" @click="goDetail(o)">查看藏品</button>
        </div>
      </div>
    </div>

    <AppEmpty v-if="isEmpty" description="暂无相关记录" />
  </div>
</template>

<style scoped lang="scss">
.mine-orders__tabs {
  display: flex; gap: 8px; padding: 14px $page-padding; margin-bottom: 8px;
  .mine-orders__tab {
    flex: 1; text-align: center; padding: 10px 0; font-size: 14px; cursor: pointer;
    border-radius: $radius-md; background: $color-surface; color: $color-text-secondary;
  }
  .mine-orders__tab.active { background: $color-primary; color: #fff; font-weight: 600; }
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
  &__foot { display: flex; justify-content: flex-end; gap: 10px; }
  &__btn {
    border: 1px solid $color-primary; color: $color-primary; background: #fff;
    font-size: 13px; height: 32px; padding: 0 18px; border-radius: $radius-pill; cursor: pointer;
  }
  &__btn--ghost {
    border-color: $color-border; color: $color-text-secondary;
    &:disabled { opacity: 0.5; cursor: not-allowed; }
  }
}

.airdrop-card {
  .order-card__price { display: none; }
}
</style>
