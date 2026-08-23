<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useCollectionStore } from '@/stores/collection'
import { useUserStore } from '@/stores/user'
import AppNavBar from '@/components/AppNavBar.vue'
import AppIcon from '@/components/AppIcon.vue'
import { useCountdown } from '@/utils/useCountdown'
import { showToast } from 'vant'

const route = useRoute()
const router = useRouter()
const store = useCollectionStore()
const user = useUserStore()

// 路由区分：发售支付(mode=release) / 挂单支付(mode=order) 单路由，按 mode 决定数据源与限购
const isRelease = computed(() => route.params.mode === 'release')
const id = route.params.id
const no = route.params.no

const meta = ref(null)                  // { name, coverImage, issueCount, circulationCount }
const unitPrice = ref('0')
const orderNo = ref('')
const payMethods = ['微信', '支付宝', '汇']
const payMethod = ref('微信')

onMounted(async () => {
  if (!user.isLoggedIn) {
    showToast('请先登录')
    router.replace('/auth/login')
    return
  }
  if (isRelease.value) {
    const d = await store.fetchDetail(id)
    meta.value = {
      name: d.title,
      coverImage: d.coverImage,
      issueCount: d.issueCount,
      circulationCount: d.circulationCount
    }
    unitPrice.value = d.price
    payMethod.value = '微信'
  } else {
    const res = await store.fetchResale(id)
    meta.value = res.meta
    const target = res.orders.find(o => o.no === no) || res.orders[0]
    unitPrice.value = target.price
    orderNo.value = target.no
    payMethod.value = target.payment || '微信'
  }
  start()
})

// 购买数量：发售每人限购 5 个；挂单为指定编号，固定 1 件
const MAX = 5
const qty = ref(1)
function inc() { if (qty.value < MAX) qty.value++ }
function dec() { if (qty.value > 1) qty.value-- }
const total = computed(() => (parseFloat(unitPrice.value) * qty.value).toFixed(2))

// 倒计时 5 分钟
const { remain, start, stop } = useCountdown(300)
const started = ref(false)
const expired = computed(() => started.value && remain.value <= 0)
const remainText = computed(() => {
  const m = Math.floor(remain.value / 60)
  const s = remain.value % 60
  return String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0')
})
onMounted(() => { started.value = true })

// 支付密码：底部弹出自定义数字键盘
const payPwd = ref('')
const pwdSheet = ref(false)
const keypad = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '', '0', '⌫']

function openPwd() {
  if (!meta.value || expired.value) return
  payPwd.value = ''
  pwdSheet.value = true
}
function onKey(k) {
  if (k === '') return
  if (k === '⌫') { payPwd.value = payPwd.value.slice(0, -1); return }
  if (payPwd.value.length >= 6) return
  payPwd.value += k
  if (payPwd.value.length === 6) setTimeout(submit, 150)
}
function submit() {
  if (!user.verifyPaymentPassword(payPwd.value)) {
    showToast('支付密码错误')
    payPwd.value = ''
    return
  }
  user.addToInventory({
    id,
    name: meta.value.name,
    coverImage: meta.value.coverImage,
    price: unitPrice.value,
    qty: qty.value,
    no: orderNo.value,
    type: isRelease.value ? 'release' : 'order'
  })
  stop()
  pwdSheet.value = false
  success.value = true
  showToast('支付成功，藏品已入库')
}
function onConfirmClick() {
  if (!meta.value) return
  if (expired.value) { showToast('支付超时，请重新下单'); return }
  openPwd()
}

const success = ref(false)
function goInventory() { router.replace('/user/collections') }
function goHome() { router.replace('/') }
</script>

<template>
  <div class="pay page--no-tabbar" v-if="!success">
    <AppNavBar title="支付订单" @click-left="$router.back()" />

    <div class="pay-body" v-if="meta">
      <!-- 藏品信息 -->
      <section class="pay-card">
        <img class="pay-card__cover" :src="meta.coverImage" alt="" />
        <div class="pay-card__info">
          <p class="pay-card__name">{{ meta.name }}</p>
          <div class="pay-card__row">
            <span>发行份数</span><b>{{ meta.issueCount }}</b>
          </div>
          <div class="pay-card__row">
            <span>流通份数</span><b>{{ meta.circulationCount }}</b>
          </div>
          <div class="pay-card__row" v-if="orderNo">
            <span>挂单编号</span><b>{{ orderNo }}</b>
          </div>
        </div>
      </section>

      <!-- 购买数量 -->
      <section class="pay-section" v-if="isRelease">
        <div class="pay-section__head">
          <span>购买数量</span>
          <span class="pay-section__limit">每人限购 {{ MAX }} 个</span>
        </div>
        <div class="pay-stepper">
          <button class="pay-stepper__btn" :disabled="qty <= 1" @click="dec">−</button>
          <span class="pay-stepper__val">{{ qty }}</span>
          <button class="pay-stepper__btn" :disabled="qty >= MAX" @click="inc">+</button>
        </div>
      </section>
      <section class="pay-section" v-else>
        <div class="pay-section__head">
          <span>购买数量</span>
          <span class="pay-section__limit">1 件（该挂单编号）</span>
        </div>
      </section>

      <!-- 支付方式 -->
      <section class="pay-section">
        <div class="pay-section__head"><span>支付方式</span></div>
        <div class="pay-pay">
          <div
            v-for="m in payMethods"
            :key="m"
            class="pay-pay__item"
            :class="{ active: payMethod === m }"
            @click="payMethod = m"
          >
            <span class="pay-pay__name">{{ m }}</span>
            <i class="pay-pay__radio" :class="{ active: payMethod === m }"></i>
          </div>
        </div>
      </section>

      <!-- 倒计时 -->
      <section class="pay-countdown" :class="{ expired }">
        <span>剩余支付时间</span>
        <b>{{ expired ? '已超时' : remainText }}</b>
      </section>

    </div>

    <!-- 底部确认条 -->
    <div class="pay-buy safe-bottom">
      <div class="pay-buy__total">
        <span>合计</span><b>¥{{ total }}</b>
      </div>
      <button class="pay-buy__btn" :disabled="expired || !meta" @click="onConfirmClick">
        {{ expired ? '支付超时' : '确认支付' }}
      </button>
    </div>

    <!-- 支付密码键盘 -->
    <transition name="sheet">
      <div class="pwd-mask" v-if="pwdSheet" @click.self="pwdSheet = false">
        <div class="pwd-sheet">
          <div class="pwd-sheet__head">
            <span class="pwd-sheet__close" @click="pwdSheet = false">✕</span>
            <span class="pwd-sheet__title">请输入支付密码</span>
          </div>
          <div class="pwd-sheet__amount">¥{{ total }}</div>
          <div class="pwd-sheet__dots">
            <i v-for="n in 6" :key="n" :class="{ filled: n <= payPwd.length }"></i>
          </div>
          <div class="pwd-keypad">
            <button
              v-for="(k, i) in keypad"
              :key="i"
              class="pwd-keypad__key"
              :class="{ empty: k === '' }"
              @click="onKey(k)"
            >{{ k === '⌫' ? '⌫' : k }}</button>
          </div>
        </div>
      </div>
    </transition>
  </div>

  <!-- 支付成功 -->
  <div class="pay-success page--no-tabbar" v-else>
    <AppNavBar title="支付成功" :left-arrow="false" transparent />
    <div class="pay-success__icon"><AppIcon name="check" :size="32" color="#fff" /></div>
    <p class="pay-success__title">支付成功</p>
    <p class="pay-success__desc">《{{ meta?.name }}》已存入您的藏品库</p>
    <div class="pay-success__btns">
      <button class="pay-success__btn ghost" @click="goHome">返回首页</button>
      <button class="pay-success__btn solid" @click="goInventory">查看我的藏品</button>
    </div>
  </div>
</template>

<style scoped lang="scss">
.pay { min-height: 100vh; background: $color-bg; padding-bottom: calc(84px + env(safe-area-inset-bottom)); }
.pay-body { padding: 12px $page-padding 0; }

.pay-card {
  display: flex; gap: 12px; background: $color-card; border-radius: $radius-lg; padding: 14px;
  &__cover {
    width: 84px; height: 84px; border-radius: 10px; object-fit: cover; flex-shrink: 0; background: #141415;
  }
  &__info { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 6px; }
  &__name { margin: 0 0 2px; font-size: 16px; font-weight: 700; color: $color-text-primary; }
  &__row {
    display: flex; align-items: center; justify-content: space-between;
    font-size: 13px; color: $color-text-tertiary;
    b { color: $color-text-primary; font-family: $font-price; }
  }
}

.pay-section { margin-top: 12px; }
.pay-section__head {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 10px; font-size: 14px; color: $color-text-primary; font-weight: 600;
}
.pay-section__limit { font-size: 12px; color: $color-text-tertiary; font-weight: 400; }

.pay-stepper {
  display: flex; align-items: center; gap: 0; width: fit-content;
  background: $color-surface; border-radius: $radius-md; overflow: hidden;
  &__btn {
    width: 40px; height: 36px; border: none; background: transparent; cursor: pointer;
    font-size: 20px; color: $color-text-primary;
    &:disabled { color: $color-text-tertiary; cursor: not-allowed; }
  }
  &__val {
    min-width: 48px; text-align: center; font-size: 15px; font-weight: 700; color: $color-text-primary;
    border-left: 1px solid $color-border; border-right: 1px solid $color-border; height: 36px; line-height: 36px;
  }
}

.pay-pay {
  background: $color-card; border-radius: $radius-lg; padding: 4px 14px;
  &__item {
    display: flex; align-items: center; justify-content: space-between;
    padding: 13px 0; cursor: pointer;
    &:not(:last-child) { border-bottom: 1px solid $color-border; }
    &.active &__name { color: $color-primary; }
  }
  &__name { font-size: 14px; color: $color-text-primary; }
  &__radio {
    width: 18px; height: 18px; border-radius: 50%; border: 1.5px solid $color-text-tertiary; flex-shrink: 0;
    position: relative; transition: all 0.2s;
    &.active { border-color: $color-primary; }
    &.active::after {
      content: ''; position: absolute; inset: 3px; border-radius: 50%; background: $color-primary;
    }
  }
}

.pay-countdown {
  display: flex; align-items: center; justify-content: space-between;
  margin-top: 12px; padding: 12px 14px; background: $color-card; border-radius: $radius-lg;
  font-size: 14px; color: $color-text-secondary;
  b { font-family: $font-price; font-size: 16px; color: $color-primary; }
  &.expired b { color: $color-text-tertiary; }
}

.pay-buy {
  position: fixed; left: 0; right: 0; bottom: 0; background: $color-card;
  padding: 12px $page-padding; border-top: 1px solid $color-border; z-index: 50;
  display: flex; align-items: center; gap: 12px;
  &__total {
    display: flex; flex-direction: column; gap: 2px; flex-shrink: 0;
    span { font-size: 11px; color: $color-text-tertiary; }
    b { font-size: 20px; font-weight: 700; color: $color-primary; font-family: $font-price; }
  }
  &__btn {
    flex: 1; height: 48px; border: none; cursor: pointer; color: #fff; font-size: 16px; font-weight: 500;
    border-radius: $radius-pill; background: linear-gradient(135deg, #D00000, #B00000);
    &:disabled { background: #cccccc; cursor: not-allowed; }
  }
}

/* 支付密码键盘 */
.pwd-mask {
  position: fixed; inset: 0; z-index: 200;
  background: rgba(0, 0, 0, 0.5);
  display: flex; align-items: flex-end;
}
.pwd-sheet {
  width: 100%; background: $color-card;
  border-radius: 16px 16px 0 0; padding: 16px $page-padding calc(20px + env(safe-area-inset-bottom));
  animation: sheetUp 0.25s ease;
}
@keyframes sheetUp { from { transform: translateY(100%); } to { transform: translateY(0); } }
.pwd-sheet__head {
  display: flex; align-items: center; justify-content: center; position: relative; margin-bottom: 14px;
  .pwd-sheet__close { position: absolute; left: 0; font-size: 16px; color: $color-text-tertiary; cursor: pointer; }
  .pwd-sheet__title { font-size: 15px; font-weight: 600; color: $color-text-primary; }
}
.pwd-sheet__amount {
  text-align: center; font-size: 26px; font-weight: 800; color: $color-text-primary;
  font-family: $font-price; margin-bottom: 14px;
}
.pwd-sheet__dots {
  display: flex; justify-content: center; gap: 14px; margin-bottom: 20px;
  i {
    width: 12px; height: 12px; border-radius: 50%; border: 1.5px solid $color-text-tertiary;
    &.filled { background: $color-primary; border-color: $color-primary; }
  }
}
.pwd-keypad {
  display: grid; grid-template-columns: repeat(3, 1fr); gap: 1px;
  background: $color-border; border-radius: 12px; overflow: hidden;
  .pwd-keypad__key {
    height: 54px; border: none; background: #fff; font-size: 22px; font-weight: 600;
    color: $color-text-primary; cursor: pointer;
    &:active { background: #ececec; }
    &.empty { background: #fff; }
  }
}

.pay-success {
  min-height: 100vh; background: $color-bg;
  display: flex; flex-direction: column; align-items: center; padding: 60px $page-padding 0;
  &__icon {
    width: 64px; height: 64px; border-radius: 50%; background: $color-primary;
    color: #fff; font-size: 34px; display: flex; align-items: center; justify-content: center;
    margin-bottom: 18px; box-shadow: 0 8px 20px rgba(192,0,0,0.3);
  }
  &__title { margin: 0 0 8px; font-size: 20px; font-weight: 700; color: $color-text-primary; }
  &__desc { margin: 0 0 36px; font-size: 14px; color: $color-text-tertiary; }
  &__btns { display: flex; gap: 12px; width: 100%; }
  &__btn {
    flex: 1; height: 46px; border-radius: $radius-pill; font-size: 15px; font-weight: 600; cursor: pointer;
    &.ghost { background: $color-card; color: $color-text-primary; border: 1px solid $color-border; }
    &.solid { background: linear-gradient(135deg, $color-primary, #A00000); color: #fff; border: none; }
  }
}
</style>
