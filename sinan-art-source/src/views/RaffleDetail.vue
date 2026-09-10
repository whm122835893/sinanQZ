<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import request from '@/utils/request'
import { useLoginGate } from '@/utils/loginGate'
import { showToast, showConfirmDialog } from 'vant'
import AppNavBar from '@/components/AppNavBar.vue'

const route = useRoute()
const router = useRouter()
const { requireLogin } = useLoginGate()

const activityId = Number(route.params.id)
const detail = ref(null)
const loading = ref(true)
const submitting = ref(false)

// 报名截止倒计时（每秒刷新）
const nowTs = ref(Date.now())
let tickTimer = null
const countdownText = computed(() => {
  const end = detail.value?.registrationEnd
  if (!end) return ''
  const endTs = new Date(String(end).replace(/-/g, '/')).getTime()
  if (isNaN(endTs)) return ''
  let diff = Math.floor((endTs - nowTs.value) / 1000)
  if (diff <= 0) return ''
  const d = Math.floor(diff / 86400)
  const h = Math.floor((diff % 86400) / 3600)
  const m = Math.floor((diff % 3600) / 60)
  const s = diff % 60
  return d > 0 ? `${d}天${h}小时` : `${h}时${m}分${s}秒`
})

async function fetchDetail() {
  loading.value = true
  try {
    const res = await request.get(`/raffle/activities/${activityId}`)
    detail.value = res
  } catch (e) {
    showToast(e.message || '活动加载失败')
  } finally {
    loading.value = false
  }
}

const myReg = computed(() => detail.value?.myRegistration || null)
const phase = computed(() => detail.value?.phase || '')
const canRegister = computed(() => phase.value === 'registering')
const canPurchase = computed(() => myReg.value?.purchasable === true)

// 报名（免费直接成功；收费弹确认后扣余额）
async function onRegister() {
  if (!requireLogin(route.fullPath)) return
  if (!canRegister.value || submitting.value) return
  const ticketPrice = detail.value?.ticketPrice || 0
  if (ticketPrice > 0) {
    try {
      await showConfirmDialog({
        title: '确认报名',
        message: `报名费 ¥${ticketPrice}/票，将从余额扣除，确认报名？`
      })
    } catch { return }
  }
  submitting.value = true
  try {
    await request.post(`/raffle/activities/${activityId}/register`, { ticketCount: 1 })
    showToast('报名成功')
    await fetchDetail()
  } catch (e) {
    showToast(e.message || '报名失败')
  } finally {
    submitting.value = false
  }
}

// 中签购买（有效期内按中签价即时成交）
async function onPurchase() {
  if (!requireLogin(route.fullPath)) return
  if (!canPurchase.value || submitting.value) return
  const d = detail.value
  try {
    await showConfirmDialog({
      title: '确认购买',
      message: `中签价 ¥${d.salePrice}，从余额即时扣款，确认购买 1 件？`
    })
  } catch { return }
  submitting.value = true
  try {
    const res = await request.post(`/raffle/activities/${activityId}/purchase`, { quantity: 1 })
    showToast('购买成功')
    await fetchDetail()
  } catch (e) {
    showToast(e.message || '购买失败')
  } finally {
    submitting.value = false
  }
}

onMounted(() => {
  fetchDetail()
  tickTimer = setInterval(() => { nowTs.value = Date.now() }, 1000)
})
onUnmounted(() => { if (tickTimer) clearInterval(tickTimer) })
</script>

<template>
  <div class="raffle-detail page--no-tabbar">
    <AppNavBar title="抽签详情" @click-left="router.back()" />

    <div v-if="loading" class="raffle-detail__loading">加载中…</div>

    <template v-else-if="detail">
      <div class="raffle-detail__hero">
        <img class="raffle-detail__img" :src="detail.collectible?.image || '/images/platform-logo.png'" alt="" />
        <div class="raffle-detail__hero-info">
          <p class="raffle-detail__name">{{ detail.name }}</p>
          <p class="raffle-detail__collectible">{{ detail.collectible?.name }}</p>
          <p class="raffle-detail__meta">
            <span class="raffle-detail__price">¥{{ detail.salePrice }}</span>
            <span>{{ detail.winnerCount }} 个名额 · 每人限购 {{ detail.saleQuantity }} 件</span>
          </p>
        </div>
      </div>

      <!-- 时间轴 -->
      <div class="raffle-detail__section">
        <div class="raffle-detail__row"><span>报名开始</span><b>{{ detail.registrationStart }}</b></div>
        <div class="raffle-detail__row"><span>报名截止</span><b>{{ detail.registrationEnd }}</b></div>
        <div class="raffle-detail__row"><span>开奖时间</span><b>{{ detail.drawTime }}</b></div>
        <div v-if="detail.purchaseStart || detail.purchaseEnd" class="raffle-detail__row">
          <span>中签购买期</span><b>{{ detail.purchaseStart || '不限' }} ~ {{ detail.purchaseEnd || '不限' }}</b>
        </div>
        <div class="raffle-detail__row">
          <span>报名费</span><b>{{ detail.ticketPrice > 0 ? `¥${detail.ticketPrice}/票（限 ${detail.limitPerUser} 票）` : '免费' }}</b>
        </div>
      </div>

      <!-- 报名中：倒计时 + 报名按钮 -->
      <div v-if="canRegister" class="raffle-detail__countdown">
        <template v-if="countdownText">距报名截止 <b>{{ countdownText }}</b></template>
        <template v-else>报名即将截止</template>
      </div>

      <!-- 我的报名状态 -->
      <div v-if="myReg" class="raffle-detail__section my-reg">
        <div class="raffle-detail__row">
          <span>我的报名</span>
          <b>{{ myReg.ticketCount }} 票<template v-if="myReg.payAmount > 0"> · 已付 ¥{{ myReg.payAmount }}</template></b>
        </div>
        <div v-if="myReg.drawStatus === 1" class="raffle-detail__row">
          <span>抽签结果</span><b class="win">已中签 🎉</b>
        </div>
        <div v-else-if="myReg.drawStatus === 2" class="raffle-detail__row">
          <span>抽签结果</span><b>未中签</b>
        </div>
        <div v-if="myReg.drawStatus === 1" class="raffle-detail__row">
          <span>已购数量</span><b>{{ myReg.purchasedQuantity }} / {{ detail.saleQuantity }}</b>
        </div>
      </div>

      <!-- 规则说明 -->
      <div v-if="detail.description" class="raffle-detail__desc">
        <p class="raffle-detail__desc-title">活动规则</p>
        <p class="raffle-detail__desc-text">{{ detail.description }}</p>
      </div>

      <!-- 底部操作 -->
      <div class="raffle-detail__footer">
        <button
          v-if="canRegister"
          class="raffle-detail__btn"
          :disabled="submitting"
          @click="onRegister"
        >{{ myReg ? '追加报名（+1票）' : '立即报名' }}</button>
        <button
          v-else-if="canPurchase"
          class="raffle-detail__btn raffle-detail__btn--gold"
          :disabled="submitting"
          @click="onPurchase"
        >中签购买 ¥{{ detail.salePrice }}</button>
        <p v-else class="raffle-detail__footer-tip">
          {{ phase === 'upcoming' ? '报名尚未开始' :
             phase === 'drawn' ? '已开奖，未中签或已购满' :
             phase === 'finished' ? '活动已结束' : '暂不可操作' }}
        </p>
      </div>
    </template>
  </div>
</template>

<style scoped lang="scss">
.raffle-detail {
  min-height: 100vh;
  background: $color-bg;
  padding-bottom: 96px;
}

.raffle-detail__loading {
  padding: 60px 0; text-align: center;
  font-size: 13px; color: $color-text-tertiary;
}

.raffle-detail__hero {
  display: flex; gap: 14px;
  margin: 14px $page-padding 0; padding: 14px;
  background: $color-card; border-radius: $radius-lg;
}
.raffle-detail__img {
  width: 120px; height: 120px; border-radius: $radius-sm;
  object-fit: cover; background: $color-surface; flex-shrink: 0;
}
.raffle-detail__hero-info { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 6px; }
.raffle-detail__name {
  margin: 0; font-size: 17px; font-weight: 800; color: $color-text-primary;
}
.raffle-detail__collectible { margin: 0; font-size: 13px; color: $color-text-tertiary; }
.raffle-detail__meta { margin: auto 0 0; display: flex; align-items: baseline; gap: 10px; font-size: 12px; color: $color-text-tertiary; }
.raffle-detail__price { font-size: 20px; font-weight: 800; color: $color-primary; }

.raffle-detail__section {
  margin: 12px $page-padding 0; padding: 4px 14px;
  background: $color-card; border-radius: $radius-lg;
}
.raffle-detail__row {
  display: flex; justify-content: space-between; align-items: center;
  padding: 11px 0; font-size: 13px; color: $color-text-tertiary;
  border-bottom: 1px solid $color-border;
  &:last-child { border-bottom: none; }
  b { color: $color-text-primary; font-weight: 600; }
  b.win { color: #07c160; }
}

.raffle-detail__countdown {
  margin: 12px $page-padding 0; padding: 10px 14px;
  border-radius: $radius-lg; text-align: center;
  background: rgba(192, 0, 0, 0.05); color: $color-text-tertiary;
  font-size: 13px;
  b { color: $color-primary; font-size: 15px; }
}

.raffle-detail__desc {
  margin: 12px $page-padding 0; padding: 14px;
  background: $color-card; border-radius: $radius-lg;
}
.raffle-detail__desc-title { margin: 0 0 8px; font-size: 14px; font-weight: 700; color: $color-text-primary; }
.raffle-detail__desc-text { margin: 0; font-size: 13px; color: $color-text-tertiary; line-height: 1.7; white-space: pre-wrap; }

.raffle-detail__footer {
  position: fixed; left: 0; right: 0; bottom: 0;
  padding: 12px $page-padding calc(12px + env(safe-area-inset-bottom));
  background: rgba(255, 255, 255, 0.96);
  border-top: 1px solid $color-border;
}
.raffle-detail__btn {
  width: 100%; height: 48px; border: none; border-radius: $radius-pill;
  background: linear-gradient(135deg, #D00000, #B00000); color: #fff;
  font-size: 16px; font-weight: 700; cursor: pointer;
  &:disabled { opacity: 0.6; }
  &--gold { background: linear-gradient(135deg, #E8B873, #D4A574); }
}
.raffle-detail__footer-tip {
  margin: 0; text-align: center; font-size: 13px; color: $color-text-tertiary;
}
</style>
