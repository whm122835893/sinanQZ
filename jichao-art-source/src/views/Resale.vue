<script setup>
import { ref, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useCollectionStore } from '@/stores/collection'
import { useLoginGate } from '@/utils/loginGate'
import AppNavBar from '@/components/AppNavBar.vue'
import request from '@/utils/request'

const route = useRoute()
const router = useRouter()
const store = useCollectionStore()
const { requireLogin } = useLoginGate()

const meta = ref(null)
const orders = ref([])           // 寄售挂单（onsale tab）
const buyRequests = ref([])      // 求购挂单（buying tab）
const delegates = ref([])        // 委托挂单（delegate tab）
const history = ref([])          // 成交动态（history tab）
const activeTab = ref('onsale')
const sort = ref('price-asc')
const loading = ref(false)

onMounted(loadAll)

watch(activeTab, (tab) => {
  if (tab === 'buying' && buyRequests.value.length === 0)  loadBuyRequests()
  if (tab === 'delegate' && delegates.value.length === 0)   loadDelegates()
  if (tab === 'history' && history.value.length === 0)      loadHistory()
})

async function loadAll() {
  loading.value = true
  try {
    const res = await store.fetchResale(route.params.id)
    meta.value = res.meta
    orders.value = res.orders
    // 并行拉求购和委托（mock server 会返回空数组或真实数据）
    await Promise.allSettled([loadBuyRequests(), loadDelegates(), loadHistory()])
  } finally {
    loading.value = false
  }
}

async function loadBuyRequests() {
  try {
    const id = route.params.id
    const res = await request.get('/buy-requests', { params: { collectibleId: id, status: 1, page: 1, pageSize: 50 } })
    buyRequests.value = (res.list || []).map((b) => ({
      id: b.id,
      price: Number(b.price).toFixed(2),
      quantity: b.quantity || 1,
      userName: b.userName || '匿名用户',
      createdAt: b.createdAt || '',
    }))
  } catch (e) {
    buyRequests.value = []
  }
}

async function loadDelegates() {
  try {
    const id = route.params.id
    const res = await request.get('/delegates', { params: { collectibleId: id, status: 1, page: 1, pageSize: 50 } })
    delegates.value = (res.list || []).map((d) => ({
      id: d.id,
      userName: d.userName || '经纪人',
      minPrice: Number(d.minPrice || 0).toFixed(2),
      successRate: d.successRate || 0,
      desc: d.remark || '专业代售',
    }))
  } catch (e) {
    delegates.value = []
  }
}

async function loadHistory() {
  try {
    const id = route.params.id
    const res = await request.get('/resale/history', { params: { collectibleId: id, page: 1, pageSize: 50 } })
    history.value = (res.list || []).map((h) => ({
      id: h.id,
      title: h.title || h.price ? `¥${h.price} 成交` : '',
      price: h.price ? Number(h.price).toFixed(2) : '',
      at: h.createdAt || '',
      fromUser: h.fromUser || h.userName || '',
    }))
  } catch (e) {
    history.value = []
  }
}

function sortPrice() {
  sort.value = sort.value === 'price-asc' ? 'price-desc' : 'price-asc'
  orders.value = [...orders.value].sort((a, b) => {
    const pa = parseFloat(a.price); const pb = parseFloat(b.price)
    return sort.value === 'price-asc' ? pa - pb : pb - pa
  })
}

function onQuickBuy() {
  if (!orders.value.length) return
  if (!requireLogin(route.fullPath)) return
  const min = orders.value.reduce((m, o) => (parseFloat(o.price) < parseFloat(m.price) ? o : m), orders.value[0])
  router.push({ name: 'pay', params: { mode: 'order', id: route.params.id, no: min.no } })
}

function goPay(o) {
  if (!requireLogin(route.fullPath)) return
  router.push({ name: 'pay', params: { mode: 'order', id: route.params.id, no: o.no } })
}

function goOrder(o) {
  router.push('/resale-order/' + route.params.id + '/' + encodeURIComponent(o.no))
}

function acceptBuyRequest(b) {
  if (!requireLogin(route.fullPath)) return
  // 我是卖家 → 接受此求购，自动创建订单
  request.post('/buy-requests/' + b.id + '/accept').then((res) => {
    router.push({ name: 'pay', params: { mode: 'order', id: route.params.id, no: res.no || '' } })
  })
}

function acceptDelegate(d) {
  if (!requireLogin(route.fullPath)) return
  // 选择经纪人挂单
  request.post('/delegates/' + d.id + '/accept', { collectibleId: route.params.id }).then(() => {
    alert('已委托该经纪人代售，订单号将通过站内消息告知')
  })
}

function openPostBuy() {
  if (!requireLogin(route.fullPath)) return
  alert('我要挂求购：\n后端尚未对接，但数据结构已预留\nPOST /api/buy-requests { collectibleId, price, quantity }')
}
</script>

<template>
  <div class="resale page--no-tabbar" v-if="meta">
    <AppNavBar title="资产交易" @click-left="$router.back()" />

    <section class="resale-asset">
      <div class="resale-asset__card">
        <img class="resale-asset__cover" :src="meta.coverImage" alt="" draggable="false" @contextmenu.prevent @click.prevent />
      </div>
      <h1 class="resale-asset__name">{{ meta.name }}</h1>
      <div class="resale-asset__stats">
        <div class="resale-asset__stat">
          <span class="resale-asset__label">发行量</span>
          <span class="resale-asset__value">{{ meta.issueCount }}</span>
        </div>
        <div class="resale-asset__stat">
          <span class="resale-asset__label">流通量</span>
          <span class="resale-asset__value">{{ meta.circulationCount }}</span>
        </div>
      </div>
    </section>

    <div class="resale-tabs">
      <span
        v-for="t in [
          { key: 'onsale', label: '当前寄售' },
          { key: 'buying', label: '当前求购' },
          { key: 'delegate', label: '当前委托' },
          { key: 'history', label: '成交动态' }
        ]"
        :key="t.key"
        class="resale-tabs__item"
        :class="{ active: activeTab === t.key }"
        @click="activeTab = t.key"
      >{{ t.label }}</span>
    </div>

    <div class="resale-toolbar">
      <div class="resale-sort" v-if="activeTab === 'onsale'">
        <span class="resale-sort__item active" :class="sort" @click="sortPrice">
          价格排序 <i class="arrow"></i>
        </span>
      </div>
      <div class="resale-toolbar__right" v-if="activeTab === 'buying'">
        <button class="resale-toolbar__btn" @click="openPostBuy">+ 我要挂求购</button>
      </div>
    </div>

    <section class="resale-list">
      <!-- 当前寄售 -->
      <template v-if="activeTab === 'onsale'">
        <div class="resale-list__item" v-for="o in orders" :key="o.no" @click="goOrder(o)">
          <img class="resale-list__thumb" :src="o.cover" alt="" draggable="false" @contextmenu.prevent />
          <div class="resale-list__info">
            <div class="resale-list__title">
              <span class="resale-list__name">{{ o.name }}</span>
              <span class="resale-list__pay">{{ o.payment }}</span>
            </div>
            <p class="resale-list__no">#{{ o.no }}</p>
          </div>
          <div class="resale-list__right">
            <span class="resale-list__price">¥{{ o.price }}</span>
            <button class="resale-list__buy" @click.stop="goPay(o)">购买</button>
          </div>
        </div>
      </template>

      <!-- 当前求购 -->
      <template v-else-if="activeTab === 'buying'">
        <div class="resale-list__item" v-for="b in buyRequests" :key="b.id">
          <div class="resale-buy">
            <div class="resale-buy__row">
              <span class="resale-buy__label">求购方</span>
              <span class="resale-buy__user">{{ b.userName }}</span>
            </div>
            <div class="resale-buy__row">
              <span class="resale-buy__label">求购数量</span>
              <span class="resale-buy__qty">×{{ b.quantity }}</span>
            </div>
            <div class="resale-buy__row">
              <span class="resale-buy__label">发布时间</span>
              <span class="resale-buy__time">{{ b.createdAt }}</span>
            </div>
          </div>
          <div class="resale-list__right">
            <span class="resale-list__price">¥{{ b.price }}</span>
            <button class="resale-list__buy resale-list__buy--green" @click="acceptBuyRequest(b)">接单</button>
          </div>
        </div>
      </template>

      <!-- 当前委托 -->
      <template v-else-if="activeTab === 'delegate'">
        <div class="resale-list__item" v-for="d in delegates" :key="d.id">
          <div class="resale-buy">
            <div class="resale-buy__row">
              <span class="resale-buy__label">经纪人</span>
              <span class="resale-buy__user">{{ d.userName }}</span>
            </div>
            <div class="resale-buy__row">
              <span class="resale-buy__label">底价</span>
              <span class="resale-buy__price">¥{{ d.minPrice }}</span>
            </div>
            <div class="resale-buy__row">
              <span class="resale-buy__label">成交率</span>
              <span class="resale-buy__rate">{{ d.successRate }}%</span>
            </div>
          </div>
          <div class="resale-list__right">
            <button class="resale-list__buy resale-list__buy--blue" @click="acceptDelegate(d)">委托TA</button>
          </div>
        </div>
      </template>

      <!-- 成交动态 -->
      <template v-else-if="activeTab === 'history'">
        <div class="resale-history__item" v-for="h in history" :key="h.id">
          <div class="resale-history__dot"></div>
          <div class="resale-history__body">
            <p class="resale-history__title">{{ h.title || (h.price ? '成交' : '动态') }}</p>
            <p class="resale-history__meta">
              <span v-if="h.fromUser">{{ h.fromUser }}</span>
              <span v-if="h.at"> · {{ h.at }}</span>
            </p>
          </div>
          <div class="resale-history__price" v-if="h.price">¥{{ h.price }}</div>
        </div>
      </template>

      <div v-if="(activeTab === 'onsale' && !orders.length) ||
                   (activeTab === 'buying' && !buyRequests.length) ||
                   (activeTab === 'delegate' && !delegates.length) ||
                   (activeTab === 'history' && !history.length)"
           class="resale-list__empty">
        <p>{{ loading ? '加载中...' : '暂无数据' }}</p>
      </div>
    </section>

    <div class="resale-float safe-bottom" v-if="activeTab === 'onsale'">
      <button class="resale-float__btn" @click="onQuickBuy">快捷购买</button>
    </div>
    <div class="resale-float safe-bottom" v-else-if="activeTab === 'buying'">
      <button class="resale-float__btn resale-float__btn--green" @click="openPostBuy">+ 我要挂求购</button>
    </div>
  </div>
</template>

<style scoped lang="scss">
.resale-toolbar__right {
  margin-left: auto;
}
.resale-toolbar__btn {
  border: none; cursor: pointer;
  font-size: 12px; font-weight: 500;
  padding: 5px 14px; border-radius: $radius-pill;
  background: rgba(255, 255, 255, 0.08);
  color: $color-text-primary;
}

.resale-buy {
  flex: 1; min-width: 0;
  &__row {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 4px;
  }
  &__label { font-size: 11px; color: $color-text-tertiary; }
  &__user { font-size: 13px; color: $color-text-primary; font-weight: 600; }
  &__qty  { font-size: 13px; color: $color-text-secondary; font-family: $font-price; }
  &__time { font-size: 11px; color: $color-text-tertiary; }
  &__price { font-size: 13px; color: $color-primary; font-family: $font-price; }
  &__rate { font-size: 11px; color: #22c55e; font-family: $font-price; }
}

.resale-list__buy--green {
  background: linear-gradient(135deg, #16a34a, #15803d);
}
.resale-list__buy--blue {
  background: linear-gradient(135deg, #3b82f6, #2563eb);
}

.resale-history__item {
  display: flex; gap: 12px; padding: 12px 14px; margin-bottom: 8px;
  background: $color-card; border-radius: $radius-lg; align-items: flex-start;
  position: relative;
}
.resale-history__dot {
  width: 8px; height: 8px; border-radius: 50%;
  background: $color-primary; margin-top: 6px; flex-shrink: 0;
  box-shadow: 0 0 0 3px rgba(208,0,0,0.2);
}
.resale-history__body { flex: 1; min-width: 0; }
.resale-history__title { margin: 0; font-size: 14px; color: $color-text-primary; font-weight: 500; }
.resale-history__meta  { margin: 2px 0 0; font-size: 11px; color: $color-text-tertiary; }
.resale-history__price { font-size: 15px; color: $color-primary; font-weight: 700; font-family: $font-price; }

.resale-float__btn--green {
  background: linear-gradient(135deg, #16a34a, #15803d);
  box-shadow: 0 6px 18px rgba(22, 163, 74, 0.3);
}
</style>
