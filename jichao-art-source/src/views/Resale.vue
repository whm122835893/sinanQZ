<script setup>
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useCollectionStore } from '@/stores/collection'
import { useLoginGate } from '@/utils/loginGate'
import AppNavBar from '@/components/AppNavBar.vue'

const route = useRoute()
const router = useRouter()
const store = useCollectionStore()
const { requireLogin } = useLoginGate()
const meta = ref(null)
const orders = ref([])
const activeTab = ref('onsale')
const sort = ref('price-asc')

onMounted(async () => {
  const res = await store.fetchResale(route.params.id)
  meta.value = res.meta
  orders.value = res.orders
})

function sortPrice() {
  sort.value = sort.value === 'price-asc' ? 'price-desc' : 'price-asc'
  orders.value = [...orders.value].sort((a, b) => {
    const pa = parseFloat(a.price)
    const pb = parseFloat(b.price)
    return sort.value === 'price-asc' ? pa - pb : pb - pa
  })
}

function onQuickBuy() {
  if (!orders.value.length) return
  if (!requireLogin(route.fullPath)) return
  // 快捷购买：自动选择价格最低的挂单
  const min = orders.value.reduce(
    (m, o) => (parseFloat(o.price) < parseFloat(m.price) ? o : m),
    orders.value[0]
  )
  router.push({ name: 'pay', params: { mode: 'order', id: route.params.id, no: min.no } })
}

function goPay(o) {
  if (!requireLogin(route.fullPath)) return
  router.push({ name: 'pay', params: { mode: 'order', id: route.params.id, no: o.no } })
}

function goOrder(o) {
  router.push('/resale-order/' + route.params.id + '/' + encodeURIComponent(o.no))
}
</script>

<template>
  <div class="resale page--no-tabbar" v-if="meta">
    <AppNavBar
      title="资产交易"
      @click-left="$router.back()"
    />

    <!-- 藏品展示区 -->
    <section class="resale-asset">
      <div class="resale-asset__card">
        <img class="resale-asset__cover" :src="meta.coverImage" alt="" />
      </div>
      <h1 class="resale-asset__name">{{ meta.name }}</h1>

      <div class="resale-asset__stats">
        <div class="resale-asset__stat">
          <span class="resale-asset__label">发行份数</span>
          <span class="resale-asset__value">{{ meta.issueCount }}</span>
        </div>
        <div class="resale-asset__stat">
          <span class="resale-asset__label">流通份数</span>
          <span class="resale-asset__value">{{ meta.circulationCount }}</span>
        </div>
        <div class="resale-asset__stat">
          <span class="resale-asset__label">今日成交</span>
          <span class="resale-asset__value">{{ meta.todayCount }}</span>
        </div>
        <div class="resale-asset__stat">
          <span class="resale-asset__label">交易限价</span>
          <span class="resale-asset__value">¥{{ meta.limitPrice }}</span>
        </div>
      </div>
    </section>

    <!-- 标签栏 -->
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

    <!-- 排序 -->
    <div class="resale-toolbar">
      <div class="resale-sort">
        <span class="resale-sort__item active" :class="sort" @click="sortPrice">
          价格排序 <i class="arrow"></i>
        </span>
      </div>
    </div>

    <!-- 挂单列表 -->
    <section class="resale-list">
      <template v-if="activeTab === 'onsale'">
        <div class="resale-list__item" v-for="o in orders" :key="o.no" @click="goOrder(o)">
          <img class="resale-list__thumb" :src="o.cover" alt="" />
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

      <div v-else class="resale-list__empty">
        <p>暂无数据</p>
      </div>
    </section>

    <!-- 悬浮快捷购买 -->
    <div class="resale-float safe-bottom">
      <button class="resale-float__btn" @click="onQuickBuy">快捷购买</button>
    </div>
  </div>
</template>

<style scoped lang="scss">
.resale {
  min-height: 100vh;
  background: $color-bg;
  padding-bottom: calc(84px + env(safe-area-inset-bottom));
}

.resale-asset {
  padding: 16px $page-padding 20px;
  &__card {
    width: 240px; height: 240px; margin: 0 auto;
    border-radius: $radius-lg; overflow: hidden;
    background: #141415; box-shadow: 0 8px 24px rgba(0,0,0,0.35);
    display: flex; align-items: center; justify-content: center;
  }
  &__cover {
    width: 100%; height: 100%; object-fit: cover; display: block;
  }
  &__name {
    margin: 16px 0 0; text-align: center;
    font-size: 20px; font-weight: 700; color: $color-text-primary;
  }
  &__stats {
    display: flex; align-items: center; justify-content: space-between;
    background: $color-card; border-radius: $radius-lg;
    padding: 14px 10px; margin-top: 14px;
  }
  &__stat {
    flex: 1; display: flex; flex-direction: column; align-items: center; gap: 6px;
    position: relative;
    &:not(:last-child)::after {
      content: ''; position: absolute; right: 0; top: 50%; transform: translateY(-50%);
      width: 1px; height: 24px; background: $color-border;
    }
  }
  &__label { font-size: 11px; color: $color-text-tertiary; }
  &__value { font-size: 14px; font-weight: 700; color: $color-text-primary; font-family: $font-price; }
}

.resale-tabs {
  display: flex; align-items: center; justify-content: space-between;
  padding: 0 $page-padding; margin: 6px 0 14px;
  &__item {
    font-size: 14px; color: $color-text-tertiary; padding-bottom: 8px; position: relative;
    &.active {
      color: $color-text-primary; font-weight: 700;
      &::after {
        content: ''; position: absolute; left: 50%; bottom: 0; transform: translateX(-50%);
        width: 20px; height: 3px; border-radius: 2px; background: $color-primary;
      }
    }
  }
}

.resale-toolbar {
  padding: 0 $page-padding 10px;
}
.resale-sort {
  display: flex; align-items: center; justify-content: flex-end;
  margin-top: 12px;
  &__item {
    font-size: 13px; color: $color-text-tertiary; display: flex; align-items: center; gap: 4px;
    &.active { color: $color-text-primary; font-weight: 600; }
    &.disabled { opacity: 0.6; }
  }
  .arrow {
    width: 0; height: 0;
    border-left: 4px solid transparent; border-right: 4px solid transparent;
    border-bottom: 5px solid $color-text-tertiary;
    transform: rotate(0deg); transition: transform 0.2s;
  }
  .price-desc .arrow { transform: rotate(180deg); }
}

.resale-list {
  padding: 0 $page-padding;
  &__item {
    background: $color-card; border-radius: $radius-lg;
    padding: 12px 14px; margin-bottom: 10px;
    display: flex; align-items: center; gap: 12px;
  }
  &__thumb {
    width: 48px; height: 48px; border-radius: 8px; object-fit: cover; flex-shrink: 0;
    background: #141415;
  }
  &__info { flex: 1; min-width: 0; }
  &__title {
    display: flex; align-items: center; gap: 8px; margin-bottom: 6px;
  }
  &__name { font-size: 15px; font-weight: 600; color: $color-text-primary; }
  &__pay {
    font-size: 11px; color: $color-text-tertiary;
    background: rgba(255,255,255,0.08); padding: 2px 6px; border-radius: 4px;
  }
  &__no { margin: 0; font-size: 12px; color: $color-text-tertiary; font-family: $font-price; }
  &__right {
    display: flex; flex-direction: column; align-items: flex-end; gap: 8px;
  }
  &__price { font-size: 16px; font-weight: 700; color: $color-primary; font-family: $font-price; }
  &__buy {
    border: none; cursor: pointer; color: #fff; font-size: 12px; font-weight: 500;
    padding: 5px 14px; border-radius: $radius-pill;
    background: linear-gradient(135deg, #D00000, #B00000);
  }
  &__empty {
    text-align: center; padding: 48px 0; color: $color-text-tertiary; font-size: 14px;
  }
}

.resale-float {
  position: fixed; left: 0; right: 0; bottom: 0; z-index: 100;
  display: flex; justify-content: center;
  padding: 12px $page-padding calc(12px + env(safe-area-inset-bottom));
  background: transparent;
  &__btn {
    width: 100%; height: 44px; border: none; border-radius: $radius-pill;
    background: linear-gradient(135deg, $color-primary, #A00000);
    color: #fff; font-size: 15px; font-weight: 600; cursor: pointer;
    box-shadow: 0 6px 18px rgba(192,0,0,0.28);
  }
}
</style>
