<script setup>
import { ref, onMounted, onUnmounted, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useCollectionStore } from '@/stores/collection'
import { useUserStore } from '@/stores/user'
import { useLoginGate } from '@/utils/loginGate'
import { showToast } from 'vant'
import AppNavBar from '@/components/AppNavBar.vue'
import AppIcon from '@/components/AppIcon.vue'

const route = useRoute()
const router = useRouter()
const store = useCollectionStore()
const userStore = useUserStore()
const { requireLogin } = useLoginGate()
const detail = ref(null)
const serialNo = computed(() => route.query.no)
// 仓库进入：隐藏购买/价格，底部改为「立即寄售」
const isWarehouse = computed(() => route.query.from === 'warehouse')
// 盲盒进入：底部显示「开启盲盒」+「立即寄售(灰色)」
const isBlindbox = computed(() => route.query.type === 'blindbox')

onMounted(async () => {
  detail.value = await store.fetchDetail(route.params.id)
})

// ---- 发售状态：倒计时 / 发售中 / 已售罄 ----
const now = ref(Date.now())
let saleTimer = null
onMounted(() => {
  saleTimer = setInterval(() => { now.value = Date.now() }, 1000)
})
onUnmounted(() => { if (saleTimer) clearInterval(saleTimer) })

const featuredItem = computed(() => store.getFeaturedById(route.params.id))

const saleStatus = computed(() => {
  if (!featuredItem.value) return 'selling'
  return store.getSaleStatus(featuredItem.value)
})

const countdownText = computed(() => {
  if (!featuredItem.value || !featuredItem.value.saleTime) return ''
  const diff = featuredItem.value.saleTime - now.value
  if (diff <= 0) return ''
  const h = Math.floor(diff / 3600000)
  const m = Math.floor((diff % 3600000) / 60000)
  const s = Math.floor((diff % 60000) / 1000)
  const pad = (n) => String(n).padStart(2, '0')
  return pad(h) + ':' + pad(m) + ':' + pad(s)
})

const buyButtonText = computed(() => {
  if (saleStatus.value === 'countdown') return countdownText.value || '即将发售'
  if (saleStatus.value === 'selling') return '立即购买'
  return '已售罄'
})

const intro = computed(() => {
  if (!detail.value) return ''
  return '《' + detail.value.title + '》为平台精选数字藏品，已完成链上确权，支持自由寄售与流转，该藏品具体信息以下方为准。'
})

// 盲盒子藏品列表
const blindboxItems = computed(() => featuredItem.value?.items || [])

function onBuy() {
  if (saleStatus.value !== 'selling') return
  if (!requireLogin(route.fullPath)) return
  const query = {}
  if (featuredItem.value?.type === 'blindbox') query.type = 'blindbox'
  router.push({ name: 'pay', params: { mode: 'release', id: route.params.id }, query })
}

/* ---------- 寄售流程 ---------- */
const FEE_RATE = 0.05
const showConsign = ref(false)
const showSuccess = ref(false)
const price = ref('')
const consigning = ref(false)
const lastActual = ref(0)

// 交易密码（与购买一致：自定义 6 位键盘，mock 密码 123456）
const pwdStep = ref(false)       // false=填写价格  true=输入交易密码
const payPwd = ref('')
const keypad = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '', '0', '⌫']

const priceNum = computed(() => {
  const n = parseFloat(price.value)
  return isNaN(n) || n <= 0 ? 0 : n
})
const fee = computed(() => (priceNum.value * FEE_RATE).toFixed(2))
const actual = computed(() => (priceNum.value - priceNum.value * FEE_RATE).toFixed(2))
const priceError = computed(() => {
  if (!price.value) return ''
  return priceNum.value > 0 ? '' : '请输入大于 0 的价格'
})
const canSubmit = computed(() => priceNum.value > 0 && !consigning.value)

function openConsign() {
  if (!requireLogin(route.fullPath)) return
  price.value = ''; payPwd.value = ''; pwdStep.value = false; showConsign.value = true
}
function closeConsign() { showConsign.value = false; pwdStep.value = false; payPwd.value = '' }

// 价格步骤：确认寄售 -> 进入交易密码步骤
function onConsign() {
  if (!canSubmit.value) return
  pwdStep.value = true
  payPwd.value = ''
}

// 密码键盘
function onPwdKey(k) {
  if (k === '') return
  if (k === '⌫') { payPwd.value = payPwd.value.slice(0, -1); return }
  if (payPwd.value.length >= 6) return
  payPwd.value += k
  if (payPwd.value.length === 6) setTimeout(doConsign, 150)
}

// 校验交易密码 -> 寄售入库
async function doConsign() {
  if (!userStore.verifyPaymentPassword(payPwd.value)) {
    showToast('交易密码错误')
    payPwd.value = ''
    return
  }
  consigning.value = true
  // 模拟提交请求
  await new Promise(r => setTimeout(r, 600))
  await userStore.consign({
    id: route.params.id,
    name: detail.value.title,
    coverImage: detail.value.coverImage,
    no: serialNo.value,
    price: priceNum.value,
    fee: Number(fee.value),
    actual: Number(actual.value)
  })
  consigning.value = false
  lastActual.value = Number(actual.value)
  showConsign.value = false
  showSuccess.value = true
}

function onSuccessDone() {
  showSuccess.value = false
  router.back()
}

/* ---------- 盲盒开启 ---------- */
const showOpenResult = ref(false)
const revealItem = ref(null)
const opening = ref(false)

async function onOpenBlindbox() {
  if (opening.value) return
  opening.value = true
  await new Promise(r => setTimeout(r, 800))
  const reveal = await userStore.openBlindbox(route.params.id, serialNo.value)
  opening.value = false
  if (reveal) {
    revealItem.value = reveal
    showOpenResult.value = true
  }
}

function onOpenResultDone() {
  showOpenResult.value = false
  router.back()
}
</script>

<template>
  <div class="detail page--no-tabbar" v-if="detail">
    <AppNavBar :title="isWarehouse ? '我的藏品' : '藏品详情'" @click-left="$router.back()" />

    <!-- 主视觉卡片 -->
    <div class="detail-hero">
      <img class="detail-hero__cover" :src="detail.coverImage" alt="" draggable="false" @contextmenu.prevent @click.prevent />
      <div class="detail-hero__metal"><span>SINAN DIGITAI</span></div>
    </div>

    <!-- 藏品名 -->
    <p class="detail-hero__name">{{ detail.title }}</p>

    <!-- 盲盒内容 -->
    <section class="detail-block" v-if="blindboxItems.length">
      <h2 class="detail-block__title"><span class="red">盲盒</span>内容</h2>
      <div class="blindbox-items">
        <div class="blindbox-items__row" v-for="(it, i) in blindboxItems" :key="i">
          <img class="blindbox-items__cover" :src="it.coverImage" alt="" draggable="false" @contextmenu.prevent @click.prevent />
          <div class="blindbox-items__info">
            <span class="blindbox-items__name">{{ it.name }}</span>
            <span class="blindbox-items__rarity" :class="'blindbox-items__rarity--' + it.rarity">{{ it.rarity }}</span>
          </div>
          <span class="blindbox-items__prob">{{ it.probability }}</span>
        </div>
      </div>
    </section>

    <!-- 藏品信息 -->
    <section class="detail-block">
      <h2 class="detail-block__title"><span class="red">藏品</span>信息</h2>
      <div class="detail-meta">
        <div class="detail-meta__row" v-if="serialNo">
          <span class="detail-meta__label">藏品编号</span>
          <span class="detail-meta__value">#{{ serialNo }}</span>
        </div>
        <div class="detail-meta__row">
          <span class="detail-meta__label">发行份数</span>
          <span class="detail-meta__value">{{ detail.issueCount }}</span>
        </div>
        <div class="detail-meta__row">
          <span class="detail-meta__label">流通份数</span>
          <span class="detail-meta__value">{{ detail.circulationCount }}</span>
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
        <li v-for="(n, i) in detail.notice" :key="i">{{ n }}</li>
      </ol></div>
    </section>

    <!-- 底部操作条 -->
    <!-- 市场：价格 + 立即购买 -->
    <div class="detail-buy safe-bottom" v-if="!isWarehouse">
      <div class="detail-buy__price">
        <b>¥{{ detail.price }}</b>
      </div>
      <button class="detail-buy__btn" :class="{ 'is-soldout': saleStatus === 'soldout' }" :disabled="saleStatus !== 'selling'" @click="onBuy">{{ buyButtonText }}</button>
    </div>
    <!-- 仓库：盲盒 → 立即寄售(灰色) + 开启盲盒 -->
    <div class="detail-buy safe-bottom" v-else-if="isBlindbox">
      <button class="detail-buy__btn detail-buy__btn--disabled" disabled>立即寄售</button>
      <button class="detail-buy__btn" @click="onOpenBlindbox">{{ opening ? '开启中…' : '开启盲盒' }}</button>
    </div>
    <!-- 仓库：普通藏品 → 仅立即寄售 -->
    <div class="detail-buy safe-bottom" v-else>
      <button class="detail-buy__btn" @click="openConsign">立即寄售</button>
    </div>

    <!-- 寄售弹窗 -->
    <van-popup v-model:show="showConsign" position="bottom" round :close-on-click-overlay="!consigning && !pwdStep">
      <div class="consign">
        <div class="consign__head">
          <p class="consign__title">{{ pwdStep ? '交易密码验证' : '藏品寄售' }}</p>
          <span class="consign__close" @click="closeConsign">✕</span>
        </div>

        <!-- 步骤一：价格 -->
        <template v-if="!pwdStep">
          <!-- 藏品信息 -->
          <div class="consign__item">
            <img class="consign__cover" :src="detail.coverImage" alt="" draggable="false" @contextmenu.prevent @click.prevent />
            <div class="consign__info">
              <p class="consign__name">{{ detail.title }}</p>
              <p class="consign__no" v-if="serialNo">编号：{{ serialNo }}</p>
            </div>
          </div>

          <!-- 价格输入 -->
          <div class="consign__field">
            <label class="consign__label">寄售价格</label>
            <div class="consign-price" :class="{ 'is-error': !!priceError }">
              <span class="consign-price__sym">¥</span>
              <input
                class="consign-price__input"
                type="number"
                inputmode="decimal"
                v-model="price"
                placeholder="0.00"
              />
            </div>
            <p class="consign__error" v-if="priceError">{{ priceError }}</p>
          </div>

          <!-- 费用明细 -->
          <div class="consign__calc" v-if="priceNum > 0">
            <div class="consign__row">
              <span>平台手续费（{{ (FEE_RATE * 100).toFixed(0) }}%）</span>
              <span class="consign__minus">- ¥{{ fee }}</span>
            </div>
            <div class="consign__row consign__row--total">
              <span>实际到账</span>
              <span class="consign__total">¥{{ actual }}</span>
            </div>
          </div>

          <button class="consign__btn" :disabled="!canSubmit" @click="onConsign">确认寄售</button>
        </template>

        <!-- 步骤二：交易密码 -->
        <template v-else>
          <p class="consign__amount">¥{{ priceNum.toFixed(2) }}</p>
          <p class="consign__hint">请输入 6 位交易密码以完成寄售</p>
          <div class="consign__dots">
            <i v-for="n in 6" :key="n" :class="{ filled: n <= payPwd.length }"></i>
          </div>
          <div class="consign__keypad">
            <button
              v-for="(k, i) in keypad"
              :key="i"
              class="consign__key"
              :class="{ empty: k === '' }"
              @click="onPwdKey(k)"
            >{{ k === '⌫' ? '⌫' : k }}</button>
          </div>
          <button class="consign__back" @click="pwdStep = false">返回修改价格</button>
        </template>
      </div>
    </van-popup>

    <!-- 寄售成功 -->
    <van-popup v-model:show="showSuccess" class="consign-success-pop" :close-on-click-overlay="false">
      <div class="consign-success">
        <div class="consign-success__icon"><AppIcon name="check" :size="28" color="#fff" /></div>
        <p class="consign-success__title">寄售成功</p>
        <p class="consign-success__sub">藏品已挂单，实际到账 ¥{{ lastActual.toFixed(2) }}</p>
        <button class="consign-success__btn" @click="onSuccessDone">完成</button>
      </div>
    </van-popup>

    <!-- 盲盒开启结果 -->
    <van-overlay :show="showOpenResult" :z-index="100" @click="onOpenResultDone">
      <div class="open-result" @click.stop>
        <p class="open-result__title">盲盒开启</p>
        <div class="open-result__img-wrap">
          <img :src="revealItem?.coverImage" alt="" />
        </div>
        <p class="open-result__name">{{ revealItem?.name }}</p>
        <p class="open-result__tip">恭喜获得以上藏品，已存入您的仓库</p>
        <button class="open-result__btn" @click="onOpenResultDone">收下藏品</button>
      </div>
    </van-overlay>
  </div>
</template>

<style scoped lang="scss">
.detail { padding-bottom: calc(72px + env(safe-area-inset-bottom)); }

.detail-hero {
  position: relative; margin: 12px $page-padding; border-radius: $radius-lg;
  background: $color-card; height: 320px; overflow: hidden;
  display: flex; align-items: center; justify-content: center;
  &__cover { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; z-index: 1;
    -webkit-user-drag: none; -webkit-touch-callout: none; user-select: none; pointer-events: none;
  }
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
    &:disabled { opacity: .6; }
    &.is-soldout { background: #cccccc; cursor: not-allowed; opacity: 1; }
    &--disabled { background: #cccccc; cursor: not-allowed; opacity: 1; color: #999; }
  }
}

/* ---------- 盲盒内容列表 ---------- */
.blindbox-items {
  background: $color-card; border-radius: $radius-lg; padding: 4px 14px;
  &__row {
    display: flex; align-items: center; gap: 12px;
    padding: 10px 0;
    &:not(:last-child) { border-bottom: 1px solid $color-border; }
  }
  &__cover {
    width: 48px; height: 48px; border-radius: $radius-md; object-fit: cover; flex-shrink: 0; background: #141415;
    -webkit-user-drag: none; -webkit-touch-callout: none; user-select: none; pointer-events: none;
  }
  &__info { flex: 1; display: flex; flex-direction: column; gap: 4px; min-width: 0; }
  &__name {
    font-size: 14px; font-weight: 600; color: $color-text-primary;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  }
  &__rarity {
    display: inline-block; width: fit-content;
    font-size: 10px; font-weight: 600; color: #fff;
    padding: 1px 8px; border-radius: 4px;
    &--普通 { background: rgba(120, 120, 120, 0.85); }
    &--稀有 { background: linear-gradient(135deg, #2563EB, #1D4ED8); }
    &--史诗 { background: linear-gradient(135deg, #9333EA, #7C2DD4); }
    &--传说 { background: linear-gradient(135deg, #F59E0B, #D97706); }
  }
  &__prob { font-size: 14px; font-weight: 600; color: $color-primary; font-family: $font-price; flex-shrink: 0; }
}

/* ---------- 寄售弹窗 ---------- */
.consign {
  padding: 18px $page-padding calc(22px + env(safe-area-inset-bottom));
  &__head {
    position: relative; text-align: center; padding-bottom: 16px;
    border-bottom: 1px solid $color-border; margin-bottom: 16px;
  }
  &__title { margin: 0; font-size: 17px; font-weight: 700; color: $color-text-primary; }
  &__close { position: absolute; top: 0; right: 0; font-size: 16px; color: $color-text-tertiary; cursor: pointer; }
  &__item {
    display: flex; align-items: center; gap: 12px; margin-bottom: 18px;
  }
  &__cover {
    width: 56px; height: 56px; border-radius: $radius-md; object-fit: cover; flex-shrink: 0;
    -webkit-user-drag: none; -webkit-touch-callout: none; user-select: none; pointer-events: none;
  }
  &__info { min-width: 0; }
  &__name { margin: 0 0 4px; font-size: 15px; font-weight: 600; color: $color-text-primary; }
  &__no { margin: 0; font-size: 12px; color: $color-text-tertiary; font-family: $font-price; }
  &__field { margin-bottom: 16px; }
  &__label { display: block; font-size: 14px; color: $color-text-primary; margin-bottom: 8px; font-weight: 500; }
  &__error { margin: 6px 0 0; font-size: 12px; color: $color-primary; }
  &__calc {
    background: $color-surface; border-radius: $radius-md; padding: 12px 14px; margin-bottom: 18px;
  }
  &__row {
    display: flex; align-items: center; justify-content: space-between;
    font-size: 13px; color: $color-text-secondary;
    &:not(:last-child) { margin-bottom: 10px; }
  }
  &__minus { font-family: $font-price; color: $color-text-secondary; }
  &__row--total { padding-top: 10px; border-top: 1px dashed $color-border; }
  &__total { font-size: 18px; font-weight: 700; color: $color-primary; font-family: $font-price; }
  &__btn {
    width: 100%; height: 48px; border: none; cursor: pointer; color: #fff; font-size: 16px; font-weight: 500;
    border-radius: $radius-pill; background: linear-gradient(135deg, #D00000, #B00000);
    &:disabled { opacity: .6; }
  }
}
.consign-price {
  display: flex; align-items: center; height: 52px; background: $color-surface;
  border-radius: $radius-md; padding: 0 14px; border: 1px solid transparent;
  &__sym { font-size: 20px; color: $color-text-primary; font-family: $font-price; margin-right: 6px; }
  &__input {
    flex: 1; height: 100%; border: none; outline: none; background: transparent;
    font-size: 20px; color: $color-text-primary; font-family: $font-price;
    &::placeholder { color: $color-text-tertiary; font-size: 16px; }
  }
  &.is-error { border-color: $color-primary; }
}

/* 交易密码步骤 */
.consign__amount {
  margin: 8px 0 4px; text-align: center; font-size: 28px; font-weight: 800;
  color: $color-text-primary; font-family: $font-price;
}
.consign__hint { margin: 0 0 18px; text-align: center; font-size: 13px; color: $color-text-tertiary; }
.consign__dots {
  display: flex; justify-content: center; gap: 14px; margin-bottom: 20px;
  i {
    width: 12px; height: 12px; border-radius: 50%; border: 1.5px solid $color-text-tertiary;
    &.filled { background: $color-primary; border-color: $color-primary; }
  }
}
.consign__keypad {
  display: grid; grid-template-columns: repeat(3, 1fr); gap: 1px;
  background: $color-border; border-radius: 12px; overflow: hidden;
  .consign__key {
    height: 54px; border: none; background: #fff; font-size: 22px; font-weight: 600;
    color: $color-text-primary; cursor: pointer;
    &:active { background: #ececec; }
    &.empty { background: #fff; }
  }
}
.consign__back {
  width: 100%; margin-top: 14px; background: transparent; border: none; cursor: pointer;
  font-size: 13px; color: $color-text-tertiary;
}

/* ---------- 寄售成功 ---------- */
.consign-success-pop { background: transparent; }
.consign-success {
  width: 280px; background: $color-card; border-radius: $radius-lg; padding: 28px 20px 20px;
  text-align: center;
  &__icon {
    width: 56px; height: 56px; margin: 0 auto 14px; border-radius: 50%;
    background: linear-gradient(135deg, #D00000, #B00000); color: #fff;
    font-size: 30px; font-weight: 700; line-height: 56px;
  }
  &__title { margin: 0 0 8px; font-size: 18px; font-weight: 700; color: $color-text-primary; }
  &__sub { margin: 0 0 22px; font-size: 13px; color: $color-text-secondary; }
  &__btn {
    width: 100%; height: 44px; border: none; cursor: pointer; color: #fff; font-size: 15px; font-weight: 500;
    border-radius: $radius-pill; background: linear-gradient(135deg, #D00000, #B00000);
  }
}

/* ---------- 盲盒开启结果 ---------- */
.open-result {
  position: absolute; top: 50%; left: 50%;
  transform: translate(-50%, -50%);
  width: 300px; background: #fff; border-radius: 20px;
  padding: 26px 22px 22px; text-align: center;
  box-shadow: 0 16px 40px rgba(0, 0, 0, 0.22);
  &__title { margin: 0 0 14px; font-size: 16px; font-weight: 700; color: $color-text-tertiary; }
  &__img-wrap {
    width: 120px; height: 120px; margin: 0 auto 14px;
    border-radius: 16px; background: $color-surface;
    display: flex; align-items: center; justify-content: center; overflow: hidden;
    img { width: 112px; height: 112px; border-radius: 12px; object-fit: cover; display: block; }
  }
  &__name { margin: 0 0 6px; font-size: 22px; font-weight: 800; color: $color-primary; }
  &__tip { margin: 0 0 20px; font-size: 12px; color: $color-text-tertiary; }
  &__btn {
    width: 100%; height: 44px; border: none; border-radius: $radius-pill;
    background: linear-gradient(135deg, #D00000, #B00000); color: #fff;
    font-size: 15px; font-weight: 600; cursor: pointer;
  }
}
</style>
