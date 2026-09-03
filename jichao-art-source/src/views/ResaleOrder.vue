<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useCollectionStore } from '@/stores/collection'
import { useLoginGate } from '@/utils/loginGate'
import AppNavBar from '@/components/AppNavBar.vue'

const route = useRoute()
const router = useRouter()
const store = useCollectionStore()
const { requireLogin } = useLoginGate()

const meta = ref(null)
const order = ref(null)

onMounted(async () => {
  const no = decodeURIComponent(route.params.no)
  const res = await store.fetchResale(route.params.id)
  meta.value = res.meta
  order.value = res.orders.find(o => o.no === no) || res.orders[0]
})

const intro = computed(() => {
  if (!meta.value) return ''
  return '《' + meta.value.name + '》为平台精选数字藏品，已完成链上确权，支持自由寄售与流转，该挂单具体信息以下方为准。'
})

const notices = [
  '该挂单由卖家自主发布，价格与库存以链上数据为准。',
  '购买前请确认藏品信息与支付方式，交易密码验证通过后下单不可撤销。',
  '平台仅提供信息撮合服务，交易风险由买卖双方自行承担。'
]

function onBuy() {
  if (!requireLogin(route.fullPath)) return
  router.push({ name: 'pay', params: { mode: 'order', id: route.params.id, no: order.value.no } })
}
</script>

<template>
  <div class="detail page--no-tabbar" v-if="meta && order">
    <AppNavBar title="藏品详情" @click-left="$router.back()" />

    <!-- 主视觉卡片 -->
    <div class="detail-hero">
      <img class="detail-hero__cover" :src="meta.coverImage" alt="" draggable="false" @contextmenu.prevent @pointerdown.prevent @click.prevent />
      <div class="detail-hero__metal"><span>SINAN DIGITAI</span></div>
    </div>

    <!-- 藏品名 -->
    <p class="detail-hero__name">{{ meta.name }}</p>

    <!-- 发行量 / 流通量 -->
    <div class="detail-stats">
      <div class="detail-stats__item">
        <span class="detail-stats__label">发行量</span>
        <b class="detail-stats__value">{{ meta.issueCount }}</b>
      </div>
      <div class="detail-stats__item">
        <span class="detail-stats__label">流通量</span>
        <b class="detail-stats__value">{{ meta.circulationCount }}</b>
      </div>
    </div>

    <!-- 藏品信息 -->
    <section class="detail-block">
      <h2 class="detail-block__title"><span class="red">藏品</span>信息</h2>
      <div class="detail-meta">
        <div class="detail-meta__row">
          <span class="detail-meta__label">藏品编号</span>
          <span class="detail-meta__value">#{{ order.no }}</span>
        </div>
        <div class="detail-meta__row">
          <span class="detail-meta__label">发行方</span>
          <span class="detail-meta__value">司南文创</span>
        </div>
      </div>
    </section>

    <!-- 藏品介绍 -->
    <section class="detail-block">
      <h2 class="detail-block__title"><span class="red">藏品</span>介绍</h2>
      <div class="detail-card"><p class="detail-block__body">{{ intro }}</p></div>
    </section>

    <!-- 购买须知 -->
    <section class="detail-block">
      <h2 class="detail-block__title"><span class="red">购买</span>须知</h2>
      <div class="detail-card"><ol class="detail-block__list">
        <li v-for="(n, i) in notices" :key="i">{{ n }}</li>
      </ol></div>
    </section>

    <!-- 底部购买条（价格 + 立即购买 一行） -->
    <div class="detail-buy safe-bottom">
      <div class="detail-buy__price">
        <b>¥{{ order.price }}</b>
      </div>
      <button class="detail-buy__btn" @click="onBuy">立即购买</button>
    </div>
  </div>
</template>

<style scoped lang="scss">
.detail { padding-bottom: calc(72px + env(safe-area-inset-bottom)); }

.detail-hero {
  position: relative; margin: 12px $page-padding; border-radius: $radius-lg;
  background: $color-card; height: 320px; overflow: hidden;
  display: flex; align-items: center; justify-content: center;
  &__cover { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; z-index: 1; -webkit-user-drag: none; -webkit-touch-callout: none; user-select: none; pointer-events: none; }
  &__metal {
    position: absolute; z-index: 2; bottom: 0; left: 0; right: 0; height: 46px;
    background: rgba(255, 255, 255, 0.08);
    backdrop-filter: blur(14px) saturate(160%);
    -webkit-backdrop-filter: blur(14px) saturate(160%);
    border-top: 1px solid rgba(255, 255, 255, 0.14);
    display: flex; align-items: center; justify-content: center;
    span {
      font-size: 16px; font-weight: 800; letter-spacing: 4px; color: #fff;
      text-shadow: 0 1px 4px rgba(0, 0, 0, 0.45);
    }
  }
  &__name {
    margin: 12px $page-padding 0;
    text-align: center;
    font-size: 18px; font-weight: 700; color: $color-text-primary;
  }
}

.detail-block { padding: 18px $page-padding 0; }
.detail-block__title { margin: 0 0 10px; font-size: 17px; font-weight: 700; color: $color-text-primary; .red { color: #C00000; } }
.detail-card { background: $color-card; border-radius: $radius-lg; padding: 14px; }
.detail-block__body { margin: 0; font-size: 14px; color: $color-text-secondary; line-height: 1.6; }
.detail-block__list { margin: 0; padding-left: 18px; }
.detail-block__list li { font-size: 14px; color: $color-text-secondary; line-height: 1.6; margin-bottom: 6px; }

.detail-stats {
  display: grid; grid-template-columns: repeat(2, 1fr);
  margin: 12px $page-padding 0; padding: 14px 0;
  background: $color-card; border-radius: $radius-lg;
  &__item {
    position: relative; display: flex; flex-direction: column; align-items: center; gap: 5px;
    &:first-child::after {
      content: ''; position: absolute; right: 0; top: 50%; transform: translateY(-50%);
      width: 1px; height: 28px; background: $color-border;
    }
  }
  &__label { font-size: 12px; color: $color-text-tertiary; font-family: $font-price; font-weight: 400; letter-spacing: 0; }
  &__value { font-size: 15px; font-weight: 700; color: $color-text-primary; font-family: $font-price; letter-spacing: 0; }
}

.detail-meta { background: $color-card; border-radius: $radius-lg; padding: 4px 14px; }
.detail-meta__row {
  display: flex; align-items: center; justify-content: space-between;
  padding: 13px 0;
  &:not(:last-child) { border-bottom: 1px solid $color-border; }
}
.detail-meta__label { font-size: 14px; color: $color-text-tertiary; }
.detail-meta__value { font-size: 14px; color: $color-text-primary; font-family: $font-price; }

.detail-buy {
  position: fixed; left: 0; right: 0; bottom: 0; background: $color-card;
  padding: 12px $page-padding; border-top: 1px solid $color-border; z-index: 50;
  display: flex; align-items: center; gap: 12px;
  &__price {
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
    b { font-size: 24px; font-weight: 800; color: $color-primary; font-family: $font-price; }
  }
  &__btn {
    flex: 1; height: 48px; margin-right: 5px; border: none; cursor: pointer; color: #fff; font-size: 16px; font-weight: 500;
    border-radius: $radius-pill; background: linear-gradient(135deg, #D00000, #B00000);
  }
}
</style>
