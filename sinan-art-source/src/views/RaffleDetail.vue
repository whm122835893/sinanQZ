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

// 关联藏品（后端返回完整字段，便于复用藏品详情页样式）
const collectible = computed(() => detail.value?.collectible || {})

const intro = computed(() => {
  if (!collectible.value.name) return ''
  return '《' + collectible.value.name + '》为平台精选数字藏品，已完成链上确权，支持自由寄售与流转，该藏品具体信息以下方为准。'
})

// 抽签截止倒计时（每秒刷新）
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

// 我的总可购额度 = 中签次数 × 每签限购（未中签为 0）
const myQuota = computed(() => myReg.value?.purchaseQuota || 0)

// 邀请得码是否已达上限（已得邀请码 ≥ 邀请可得码上限）
const inviteCapped = computed(() =>
  !!myReg.value && (myReg.value.inviteRewarded || 0) >= (detail.value?.inviteCodeLimit || 0)
)

// 购买码是否已达上限（已参与且后端判定不可再买）
const buyCapped = computed(() =>
  !!myReg.value && detail.value?.drawCodeEnabled === true && detail.value?.canBuyDrawCode === false
)

// 我的码号列表（状态栏码片展示：中签的码红色边框 + 已中签）
const myCodes = computed(() => detail.value?.drawCodes || [])

// 时间线（按时间先后：抽签开始 → 抽签截止 → 开奖 → 购买开始 → 购买结束）
const timeline = computed(() => {
  const d = detail.value
  if (!d) return []
  const items = [
    { label: '抽签开始', value: d.registrationStart },
    { label: '抽签截止', value: d.registrationEnd },
    { label: '开奖时间', value: d.drawTime }
  ]
  if (d.purchaseStart) items.push({ label: '购买开始', value: d.purchaseStart })
  if (d.purchaseEnd) items.push({ label: '购买结束', value: d.purchaseEnd })
  return items
})

// 横向步骤条：已完成节点数（upcoming 0 / registering 1 / drawing 2 / 已抽签后全部完成）
const doneCount = computed(() => {
  const p = phase.value
  if (p === 'upcoming') return 0
  if (p === 'registering') return 1
  if (p === 'drawing') return 2
  return timeline.value.length
})

// 连线定位（CSS 变量）：首末节点中心位置 + 红色进度实线宽度
const lineVars = computed(() => {
  const n = timeline.value.length || 1
  const edge = 100 / (2 * n)
  const k = Math.min(doneCount.value, n - 1)
  const target = ((2 * k + 1) / (2 * n)) * 100
  return { '--edge': edge + '%', '--prog': Math.max(0, target - edge) + '%' }
})

function formatTime(v) {
  if (!v) return '-'
  const s = String(v)
  const m = s.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/)
  return m ? `${m[2]}-${m[3]} ${m[4]}:${m[5]}` : s
}

// 参与抽签（免费，成功后发放抽签码）
async function onRegister() {
  if (!requireLogin(route.fullPath)) return
  if (!canRegister.value || submitting.value) return
  submitting.value = true
  try {
    await request.post(`/raffle/activities/${activityId}/register`, { ticketCount: 1 })
    showToast('参与抽签成功，已发放抽签码')
    await fetchDetail()
  } catch (e) {
    showToast(e.message || '抽签失败')
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
    await request.post(`/raffle/activities/${activityId}/purchase`, { quantity: 1 })
    showToast('购买成功')
    await fetchDetail()
  } catch (e) {
    showToast(e.message || '购买失败')
  } finally {
    submitting.value = false
  }
}

// 购买抽签码（每活动开关）
async function onBuyDrawCode() {
  if (!requireLogin(route.fullPath)) return
  if (submitting.value) return
  const d = detail.value
  try {
    await showConfirmDialog({
      title: '购买抽签码',
      message: `单价 ¥${d.drawCodePrice}/码，从余额扣除，确认购买 1 码？`
    })
  } catch { return }
  submitting.value = true
  try {
    await request.post(`/raffle/activities/${activityId}/purchase-draw-code`, { quantity: 1 })
    showToast('购买成功，已发放抽签码')
    await fetchDetail()
  } catch (e) {
    showToast(e.message || '购买失败')
  } finally {
    submitting.value = false
  }
}

// 邀请好友 +1 抽签码
function goInvite() {
  if (!requireLogin(route.fullPath)) return
  router.push('/user/invite')
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
      <!-- 主视觉卡片（与藏品详情一致） -->
      <div class="detail-hero">
        <img
          class="detail-hero__cover"
          :src="collectible.image || '/images/platform-logo.png'"
          alt=""
          draggable="false"
          @contextmenu.prevent
          @click.prevent
        />
        <div class="detail-hero__metal"><span>SINAN DIGITAI</span></div>
      </div>

      <!-- 藏品名 -->
      <p class="detail-hero__name">{{ collectible.name || detail.name }}</p>

      <!-- 发行量 / 流通量 -->
      <div class="detail-stats">
        <div class="detail-stats__item">
          <span class="detail-stats__label">发行量</span>
          <b class="detail-stats__value">{{ collectible.issueCount ?? '-' }}</b>
        </div>
        <div class="detail-stats__item">
          <span class="detail-stats__label">流通量</span>
          <b class="detail-stats__value">{{ collectible.circulationCount ?? '-' }}</b>
        </div>
      </div>

      <!-- 抽签活动（藏品介绍上方） -->
      <section class="detail-block">
        <h2 class="detail-block__title"><span class="red">抽签</span>活动</h2>
        <!-- 时间线（横向步骤条：实线 + 圆点，按时间先后；置于「抽签活动」标题下方卡片内） -->
        <div class="raffle-steps-card">
          <div class="raffle-steps" :style="lineVars">
            <div
              v-for="(item, i) in timeline"
              :key="item.label"
              class="raffle-steps__node"
              :class="{ 'is-done': i < doneCount, 'is-next': i === doneCount }"
            >
              <span class="raffle-steps__dot"></span>
              <b class="raffle-steps__time">{{ formatTime(item.value) }}</b>
              <span class="raffle-steps__label">{{ item.label }}</span>
            </div>
          </div>
        </div>

        <div class="raffle-box">
          <!-- 抽签码（显示码号；开奖后中签的码红框选中 + 已中签小字） -->
          <div class="raffle-box__code">
            <span class="raffle-box__code-label">我的抽签码</span>
            <div class="raffle-box__code-list">
              <template v-if="(detail.drawCodes || []).length">
                <div
                  v-for="c in detail.drawCodes"
                  :key="c.code"
                  class="raffle-box__code-chip"
                  :class="{ 'is-won': c.won }"
                >
                  <span class="raffle-box__code-no">{{ c.code }}</span>
                  <span v-if="c.won" class="raffle-box__code-win">已中签</span>
                </div>
              </template>
              <span v-else class="raffle-box__code-empty">暂无抽签码，参与抽签后获得</span>
            </div>
          </div>

          <!-- 获取更多抽签码：邀请好友（后台开关）/ 购买（后台开关，位于邀请下方，样式一致） -->
          <div v-if="detail.inviteEnabled || detail.drawCodeEnabled" class="raffle-box__actions">
            <template v-if="detail.inviteEnabled">
              <button
                class="raffle-box__action"
                :disabled="inviteCapped"
                @click="goInvite"
              >{{ inviteCapped ? `邀请码已得满（${myReg.inviteRewarded}/${detail.inviteCodeLimit}）` : '邀请好友获取抽签码' }}</button>
              <p v-if="myReg && !inviteCapped" class="raffle-box__hint">
                每邀请 {{ detail.inviteUserNeeded }} 名好友参与得 1 码，已得 {{ myReg.inviteRewarded }}/{{ detail.inviteCodeLimit }} 码
              </p>
            </template>
            <template v-if="detail.drawCodeEnabled">
              <button
                class="raffle-box__action"
                :disabled="buyCapped || submitting"
                @click="onBuyDrawCode"
              >{{ buyCapped ? `购买码已达上限（${detail.buyCodeLimit}）` : '购买抽签码' }}</button>
              <p class="raffle-box__hint">¥{{ detail.drawCodePrice }}/码，每人最多可购 {{ detail.buyCodeLimit }} 码</p>
            </template>
          </div>

          <!-- 我的抽签信息（中签状态由底部按钮与抽签码选中态展示） -->
          <div v-if="myReg" class="raffle-box__status">
            <span class="raffle-box__status-item">已参与抽签</span>
            <span class="raffle-box__status-item">持有 {{ myReg.codeCount }} 码</span>
            <template v-if="myReg.drawStatus === 1">
              <span class="raffle-box__status-item">中签 {{ myReg.winCount || 1 }} 签</span>
              <span class="raffle-box__status-item">已购 {{ myReg.purchasedQuantity }} / {{ myQuota }}</span>
            </template>
          </div>

          <!-- 状态栏码号：中签的码号红色带边框，码号下方显示已中签 -->
          <div v-if="myReg && myCodes.length" class="raffle-box__status-codes">
            <span
              v-for="c in myCodes"
              :key="c.code"
              class="raffle-box__code-chip"
              :class="{ 'is-won': c.won }"
            >
              <span class="raffle-box__code-no">{{ c.code }}</span>
              <span v-if="c.won" class="raffle-box__code-win">已中签</span>
            </span>
          </div>

          <!-- 抽签进行中：倒计时 -->
          <div v-if="canRegister" class="raffle-box__countdown">
            <template v-if="countdownText">距抽签截止 <b>{{ countdownText }}</b></template>
            <template v-else>抽签即将截止</template>
          </div>
        </div>
      </section>

      <!-- 藏品信息 -->
      <section class="detail-block">
        <h2 class="detail-block__title"><span class="red">藏品</span>信息</h2>
        <div class="detail-meta">
          <div class="detail-meta__row">
            <span class="detail-meta__label">发行方</span>
            <span class="detail-meta__value">{{ collectible.issuer || '司南文创' }}</span>
          </div>
        </div>
      </section>

      <!-- 藏品介绍 -->
      <section class="detail-block">
        <h2 class="detail-block__title"><span class="red">藏品</span>介绍</h2>
        <div class="detail-card"><p class="detail-block__body">{{ intro }}</p></div>
      </section>

      <!-- 活动规则 -->
      <section class="detail-block" v-if="detail.description">
        <h2 class="detail-block__title"><span class="red">活动</span>规则</h2>
        <div class="detail-card"><p class="detail-block__body">{{ detail.description }}</p></div>
      </section>

      <!-- 底部操作条：抽签 → 开奖后按中签结果展示（已中签/未中签/立即购买） -->
      <div class="detail-buy safe-bottom">
        <div class="detail-buy__price" v-if="canPurchase">
          <b>¥{{ detail.salePrice }}</b>
        </div>
        <button
          v-if="canRegister"
          class="detail-buy__btn"
          :class="{ 'detail-buy__btn--win': myReg }"
          :disabled="submitting || !!myReg"
          @click="onRegister"
        >{{ myReg ? '已参与抽签' : '立即抽签' }}</button>
        <!-- 中签且到购买时间：立即购买 -->
        <button
          v-else-if="canPurchase"
          class="detail-buy__btn"
          :disabled="submitting"
          @click="onPurchase"
        >立即购买</button>
        <!-- 中签未到购买时间 / 已购满 -->
        <button
          v-else-if="phase === 'drawn' && myReg && myReg.drawStatus === 1"
          class="detail-buy__btn detail-buy__btn--win"
          disabled
        >{{ myReg.purchasedQuantity >= myQuota ? '已购满' : '已中签' }}</button>
        <!-- 未中签 -->
        <button
          v-else-if="phase === 'drawn' && myReg && myReg.drawStatus === 2"
          class="detail-buy__btn detail-buy__btn--lose"
          disabled
        >未中签</button>
        <p v-else class="detail-buy__tip">
          {{ phase === 'upcoming' ? '抽签尚未开始' :
             phase === 'drawn' ? (myReg ? '等待开奖结果' : '您未参与本场抽签') :
             phase === 'drawing' ? '开签中' :
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
  padding-bottom: calc(84px + env(safe-area-inset-bottom));
}

.raffle-detail__loading {
  padding: 60px 0; text-align: center;
  font-size: 13px; color: $color-text-tertiary;
}

/* ---------- 与藏品详情一致的主视觉 / 发行量 / 通用块 ---------- */
.detail-hero {
  position: relative; margin: 12px $page-padding; border-radius: $radius-lg;
  background: $color-card; height: 320px; overflow: hidden;
  display: flex; align-items: center; justify-content: center;
  &__cover {
    position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; z-index: 1;
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
  &__label { font-size: 12px; color: $color-text-tertiary; font-family: $font-price; font-weight: 400; }
  &__value { font-size: 15px; font-weight: 700; color: $color-text-primary; font-family: $font-price; }
}

.detail-block { padding: 18px $page-padding 0; }
.detail-block__title { margin: 0 0 10px; font-size: 17px; font-weight: 700; color: $color-text-primary; .red { color: #C00000; } }
.detail-card { background: $color-card; border-radius: $radius-lg; padding: 14px; }
.detail-block__body { margin: 0; font-size: 14px; color: $color-text-secondary; line-height: 1.7; }

.detail-meta { background: $color-card; border-radius: $radius-lg; padding: 4px 14px; }
.detail-meta__row {
  display: flex; align-items: center; justify-content: space-between;
  padding: 13px 0;
}
.detail-meta__label { font-size: 14px; color: $color-text-tertiary; }
.detail-meta__value { font-size: 14px; color: $color-text-primary; font-family: $font-price; }

/* ---------- 抽签活动 ---------- */
.raffle-box { background: $color-card; border-radius: $radius-lg; padding: 4px 14px 14px; }

.raffle-box__code {
  padding: 14px 0;
  border-bottom: 1px solid $color-border;
}
.raffle-box__code-label {
  display: block;
  font-size: 14px; color: $color-text-tertiary;
  margin-bottom: 10px;
}
.raffle-box__code-list {
  display: flex; flex-wrap: wrap; gap: 8px;
}
.raffle-box__code-chip {
  display: flex; flex-direction: column; align-items: center; gap: 2px;
  padding: 6px 12px; border-radius: $radius-sm;
  background: $color-bg; border: 1px solid $color-border;
  /* 开奖后中签：红色边框包裹 */
  &.is-won {
    border-color: $color-primary;
    background: rgba(208, 0, 0, 0.04);
  }
}
.raffle-box__code-no {
  font-size: 13px; color: $color-text-primary; font-family: $font-price; letter-spacing: 1px;
}
.raffle-box__code-win {
  font-size: 10px; line-height: 1; color: $color-primary; letter-spacing: 2px;
}
.raffle-box__code-empty { font-size: 12px; color: $color-text-tertiary; }

.raffle-box__actions {
  display: flex; flex-direction: column; gap: 8px; padding: 12px 0;
  border-bottom: 1px solid $color-border;
}
.raffle-box__action {
  width: 100%; height: 40px; border: 1px solid $color-primary; border-radius: $radius-md;
  background: transparent; cursor: pointer; font-size: 14px; color: $color-primary;
  &:disabled { opacity: 0.6; }
}
.raffle-box__hint {
  margin: 0; font-size: 11px; line-height: 1.4; color: $color-text-tertiary; text-align: center;
}

/* 时间线卡片（置于「抽签活动」标题上方，与 raffle-box 同款卡片） */
.raffle-steps-card {
  background: $color-card;
  border-radius: $radius-lg;
  padding: 4px 14px 10px;
  /* 与下方抽签码卡片留出间距，不连在一起 */
  margin-bottom: 10px;
}

/* 时间线（横向步骤条：实线 + 圆点） */
.raffle-steps {
  position: relative;
  display: flex;
  padding: 16px 0 6px;
  /* 灰色基准实线（贯穿首末节点中心） */
  &::before {
    content: ''; position: absolute; top: 20px;
    left: var(--edge, 10%); right: var(--edge, 10%);
    height: 2px; border-radius: 1px; background: $color-border;
  }
  /* 红色进度实线（已到达部分） */
  &::after {
    content: ''; position: absolute; top: 20px;
    left: var(--edge, 10%); width: var(--prog, 0%);
    height: 2px; border-radius: 1px;
    background: linear-gradient(90deg, $color-primary, #8B0000);
  }
}
.raffle-steps__node {
  flex: 1; position: relative; z-index: 1;
  display: flex; flex-direction: column; align-items: center; text-align: center;
  &.is-done .raffle-steps__dot {
    background: linear-gradient(135deg, $color-primary, #8B0000);
    border-color: rgba(208, 0, 0, 0.25);
  }
  &.is-done .raffle-steps__time { color: $color-primary; }
  &.is-next .raffle-steps__dot {
    border-color: $color-primary;
    box-shadow: 0 0 0 4px rgba(208, 0, 0, 0.1);
  }
  &.is-next .raffle-steps__time,
  &.is-next .raffle-steps__label { color: $color-primary; }
  &.is-next .raffle-steps__label { font-weight: 600; }
}
.raffle-steps__dot {
  width: 10px; height: 10px; border-radius: 50%; flex: none;
  box-sizing: border-box;
  background: $color-card;
  border: 2px solid #D9D9D9;
}
.raffle-steps__time {
  margin-top: 7px; font-size: 10px; font-weight: 600;
  font-family: $font-price; color: $color-text-tertiary; white-space: nowrap;
}
.raffle-steps__label {
  margin-top: 3px; font-size: 11px; line-height: 1.3; color: $color-text-tertiary;
}

.raffle-box__status {
  display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; padding-top: 10px;
  border-top: 1px dashed $color-border;
}
.raffle-box__status-item {
  font-size: 12px; color: $color-text-secondary;
  padding: 3px 8px; border-radius: $radius-sm; background: $color-bg;
}

/* 状态栏码号行：复用上方码片样式，中签的码红色边框 + 已中签 */
.raffle-box__status-codes {
  display: flex; flex-wrap: wrap; gap: 8px;
  margin-top: 8px; padding-top: 10px;
  border-top: 1px dashed $color-border;
}

.raffle-box__countdown {
  margin-top: 10px; text-align: center; font-size: 13px; color: $color-text-tertiary;
  b { color: $color-primary; font-size: 15px; }
}

/* ---------- 底部操作条：抽签 / 已中签 / 未中签 / 立即购买 ---------- */
.detail-buy {
  position: fixed; left: 0; right: 0; bottom: 0; background: $color-card;
  padding: 12px $page-padding; border-top: 1px solid $color-border; z-index: 50;
  display: flex; align-items: center; gap: 12px;
  &__price {
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
    b { font-size: 24px; font-weight: 800; color: $color-primary; font-family: $font-price; }
  }
  &__tip { margin: 0 auto; text-align: center; font-size: 13px; color: $color-text-tertiary; }
  &__btn {
    flex: 1; height: 48px; border: none; cursor: pointer; color: #fff; font-size: 16px; font-weight: 500;
    border-radius: $radius-pill; background: linear-gradient(135deg, #D00000, #B00000);
    &:disabled { opacity: 0.6; }
    /* 已中签：金色状态（不可点，保持饱满不发灰） */
    &--win {
      background: linear-gradient(135deg, #E8B873, #D4A574);
      cursor: default;
      &:disabled { opacity: 1; }
    }
    /* 未中签：灰色状态 */
    &--lose {
      background: #C6C6C6;
      cursor: default;
      &:disabled { opacity: 1; }
    }
  }
}
</style>