<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import request from '@/utils/request'
import html2canvas from 'html2canvas'
import AppNavBar from '@/components/AppNavBar.vue'
import AppButton from '@/components/AppButton.vue'
import AppEmpty from '@/components/AppEmpty.vue'
import { showToast } from 'vant'
import { useLoginGate } from '@/utils/loginGate'

const route = useRoute()
const router = useRouter()
const { requireLogin } = useLoginGate()

const inviteCode = ref('')
const inviteeCount = ref(0)
const activity = ref(null)
const records = ref([])
const loading = ref(false)

// MOCK_REPLACED: 原为内联 mock 邀请码（jKet17636）与统计常量，
// 现从后端拉取：GET /api/invite/info（邀请码/活动/人数）、GET /api/invite/records（名单）
async function fetchInfo() {
  const res = await request.get('/invite/info')
  inviteCode.value = res.inviteCode || ''
  inviteeCount.value = res.inviteeCount || 0
  activity.value = res.activity || null
}

async function fetchRecords() {
  const res = await request.get('/invite/records', { params: { page: 1, pageSize: 100 } })
  records.value = (res.list || []).map((r) => ({
    id: r.recordId,
    phone: r.inviteePhone,
    status: r.status,
    time: String(r.createdAt || '').slice(0, 16)
  }))
}

onMounted(async () => {
  if (!requireLogin(route.fullPath)) return
  loading.value = true
  try {
    await Promise.all([fetchInfo(), fetchRecords()])
  } catch (e) {
    showToast(e.message || '加载失败')
  } finally {
    loading.value = false
  }
})

// 注册链接：Hash 路由，携带邀请码（Register 页自动回填）
const regUrl = computed(() =>
  inviteCode.value ? `${location.origin}${location.pathname}#/auth/register?code=${inviteCode.value}` : ''
)

// 统计：以 invite_records 状态为准（后端无实名/开钱包维度，展示真实可得数据）
const stats = computed(() => [
  { label: '邀请注册', value: inviteeCount.value },
  { label: '已注册', value: records.value.filter((r) => r.status === 'registered').length },
  { label: '待注册', value: records.value.filter((r) => r.status === 'pending').length }
])

const statusText = { registered: '已注册', pending: '待注册' }

function copy(text, msg) {
  if (navigator.clipboard) {
    navigator.clipboard.writeText(text).then(() => showToast(msg)).catch(() => showToast('复制失败'))
  } else {
    showToast('当前环境不支持复制')
  }
}

/* ---------- 邀请海报（Canvas 生成） ---------- */
const showPoster = ref(false)
const posterLoading = ref(false)
const posterSrc = ref(null)
const posterImage = ref('')

async function generatePoster() {
  if (!inviteCode.value) return showToast('邀请码获取中，请稍候')
  posterLoading.value = true
  posterImage.value = ''
  showPoster.value = true

  // 等 DOM 渲染
  await new Promise((r) => setTimeout(r, 120))

  try {
    const canvas = await html2canvas(posterSrc.value, {
      backgroundColor: '#1a1a1a',
      useCORS: true,
      scale: 2,
      logging: false,
    })
    posterImage.value = canvas.toDataURL('image/png')
  } catch (e) {
    posterImage.value = await drawPosterFallback()
  } finally {
    posterLoading.value = false
  }
}

function downloadPoster() {
  if (!posterImage.value) return
  const a = document.createElement('a')
  a.download = `邀请海报_${inviteCode.value}_${Date.now()}.png`
  a.href = posterImage.value
  a.click()
}

// 兜底：直接用 Canvas API 绘制
async function drawPosterFallback() {
  const w = 750, h = 1000
  const c = document.createElement('canvas')
  c.width = w; c.height = h
  const ctx = c.getContext('2d')
  // 背景渐变
  const grad = ctx.createLinearGradient(0, 0, w, h)
  grad.addColorStop(0, '#1a1a1a'); grad.addColorStop(1, '#2d0000')
  ctx.fillStyle = grad; ctx.fillRect(0, 0, w, h)
  // 标题
  ctx.fillStyle = '#fff'; ctx.font = 'bold 40px sans-serif'; ctx.textAlign = 'center'
  ctx.fillText('邀好友 共藏国宝', w / 2, 90)
  ctx.fillStyle = '#aaa'; ctx.font = '24px sans-serif'
  ctx.fillText('输入邀请码注册，双方均得好礼', w / 2, 140)
  // 邀请码
  ctx.fillStyle = '#D00000'; ctx.font = 'bold 72px monospace'
  ctx.fillText(inviteCode.value, w / 2, h / 2 + 20)
  ctx.strokeStyle = '#D00000'; ctx.lineWidth = 2
  ctx.strokeRect(w / 2 - 240, h / 2 - 60, 480, 130)
  // 底部品牌
  ctx.fillStyle = '#aaa'; ctx.font = '22px sans-serif'
  ctx.fillText('司南数字藏品 · SINAN DIGITAL', w / 2, h - 80)
  return c.toDataURL('image/png')
}
</script>

<template>
  <div class="invite page--no-tabbar">
    <AppNavBar title="我的好友" @click-left="$router.back()">
      <template #right>
        <span class="invite-top-btn" @click="showToast('邀请规则开发中')">邀请规则</span>
      </template>
    </AppNavBar>

    <!-- 邀请活动横幅（后端 nft_invite_activities 配置，未配置不展示） -->
    <div v-if="activity && activity.enabled" class="invite-activity">
      <p class="invite-activity__title">{{ activity.name }}</p>
      <p class="invite-activity__desc">
        邀请好友注册，双方各得
        <template v-if="activity.inviterReward">《{{ activity.inviterReward.name }}》</template>
        <template v-if="activity.inviterReward && activity.inviteeReward">、</template>
        <template v-if="activity.inviteeReward">《{{ activity.inviteeReward.name }}》</template>
      </p>
    </div>

    <!-- 邀请码 -->
    <div class="invite-code">
      <div class="invite-code__main">
        <span class="invite-code__label">我的邀请码</span>
        <span class="invite-code__value">{{ inviteCode || '-' }}</span>
      </div>
      <button class="invite-code__poster" @click="generatePoster">生成邀请海报</button>
    </div>

    <!-- 注册链接 -->
    <div class="invite-link">
      <span class="invite-link__label">注册链接</span>
      <p class="invite-link__url">{{ regUrl || '登录后获取专属邀请链接' }}</p>
      <AppButton @click="inviteCode && copy(inviteCode, '邀请码已复制')">复制邀请码</AppButton>
      <AppButton type="outline" style="margin-top:12px" @click="regUrl && copy(regUrl, '注册链接已复制')">复制注册链接</AppButton>
    </div>

    <!-- 统计 -->
    <div class="invite-stats">
      <div v-for="s in stats" :key="s.label" class="invite-stats__item">
        <span class="invite-stats__title">{{ s.label }}</span>
        <span class="invite-stats__value">{{ s.value }}</span>
      </div>
    </div>

    <!-- 邀请名单 -->
    <div class="invite-list-title"><span class="red">邀请</span>名单</div>
    <div v-if="records.length" class="invite-list">
      <div v-for="r in records" :key="r.id" class="invite-list__item">
        <div class="invite-list__info">
          <p class="invite-list__phone">{{ r.phone }}</p>
          <p class="invite-list__time">{{ r.time }}</p>
        </div>
        <span class="invite-list__status" :class="'is-' + r.status">{{ statusText[r.status] || r.status }}</span>
      </div>
    </div>
    <div v-else-if="loading" class="invite-list__loading">加载中...</div>
    <AppEmpty v-else description="空空如也" />

    <!-- 邀请海报弹层 -->
    <van-popup v-model:show="showPoster" position="center" :style="{ background: 'transparent' }" :z-index="200">
      <div class="poster-wrap">
        <!-- html2canvas 渲染源（藏在屏幕外，仅用于截图） -->
        <div v-show="false" ref="posterSrc" class="poster-src">
          <div class="poster-src__title">邀好友 共藏国宝</div>
          <div class="poster-src__sub">输入邀请码注册，双方均得好礼</div>
          <div class="poster-src__code">{{ inviteCode }}</div>
          <div class="poster-src__brand">司南数字藏品 · SINAN DIGITAL</div>
        </div>

        <!-- 渲染结果 -->
        <div class="poster-result">
          <p v-if="posterLoading" class="poster-result__loading">生成中...</p>
          <img v-else-if="posterImage" class="poster-result__img" :src="posterImage" alt="邀请海报" />
          <p v-else class="poster-result__err">海报生成失败</p>
        </div>

        <div class="poster-actions">
          <button class="poster-actions__btn poster-actions__btn--ghost" @click="showPoster = false">取消</button>
          <button class="poster-actions__btn poster-actions__btn--primary" :disabled="!posterImage" @click="downloadPoster">下载海报</button>
        </div>
      </div>
    </van-popup>
  </div>
</template>

<style scoped lang="scss">
.invite-top-btn { font-size: 14px; color: $color-text-primary; cursor: pointer; }

.invite-activity {
  margin: 12px $page-padding 0; padding: 14px 16px;
  background: linear-gradient(135deg, rgba(192, 0, 0, 0.08), rgba(232, 184, 115, 0.12));
  border-radius: $radius-lg;
  &__title { margin: 0 0 6px; font-size: 15px; font-weight: 700; color: $color-text-primary; }
  &__desc { margin: 0; font-size: 12px; color: $color-text-secondary; line-height: 1.6; }
}

.invite-code {
  display: flex; align-items: center; justify-content: space-between;
  padding: 16px $page-padding; background: $color-card; margin: 12px $page-padding 0; border-radius: $radius-lg;
  &__main { display: flex; flex-direction: column; gap: 6px; }
  &__label { font-size: 12px; color: $color-text-tertiary; }
  &__value { font-size: 22px; font-weight: 700; color: $color-text-primary; }
  &__poster {
    border: none; cursor: pointer; background: $color-surface; color: $color-text-primary;
    font-size: 13px; padding: 8px 14px; border-radius: $radius-pill;
  }
}

.invite-link {
  margin: 12px $page-padding 0; background: $color-surface; border-radius: $radius-lg; padding: 16px;
  &__label { font-size: 14px; color: $color-text-primary; font-weight: 500; }
  &__url { margin: 8px 0 16px; font-size: 14px; color: $color-text-primary; word-break: break-all; line-height: 1.5; }
}

.invite-stats { display: flex; gap: 12px; padding: 16px $page-padding; }
.invite-stats__item {
  flex: 1; background: $color-card; border-radius: $radius-lg; padding: 14px;
  display: flex; flex-direction: column; align-items: center; gap: 6px;
  &__title { font-size: 12px; color: $color-text-tertiary; }
  &__value { font-size: 16px; font-weight: 700; color: $color-text-primary; }
}

.invite-list-title { font-size: 17px; font-weight: 700; color: $color-text-primary; padding: 16px $page-padding 4px; .red { color: #C00000; } }
.invite-list { padding: 0 $page-padding; background: transparent; }
.invite-list__item {
  display: flex; align-items: center; justify-content: space-between;
  background: $color-card; border-radius: $radius-lg; padding: 14px 16px; margin-bottom: 10px;
  &:last-child { margin-bottom: 0; }
}
.invite-list__info { min-width: 0; }
.invite-list__phone { margin: 0 0 4px; font-size: 14px; color: $color-text-primary; }
.invite-list__time { margin: 0; font-size: 12px; color: $color-text-tertiary; }
.invite-list__status {
  font-size: 12px; padding: 3px 10px; border-radius: $radius-pill; flex-shrink: 0;
  &.is-registered { background: rgba(7, 193, 96, 0.1); color: #07c160; }
  &.is-pending { background: $color-surface; color: $color-text-tertiary; }
}
.invite-list__loading { padding: 24px 0; text-align: center; font-size: 13px; color: $color-text-tertiary; }

/* ---------- 邀请海报 ---------- */
.poster-wrap {
  width: 320px; background: #1a1a1a; border-radius: 14px; padding: 12px;
  box-shadow: 0 20px 60px rgba(0,0,0,0.5);
}
.poster-src {
  position: fixed; top: -9999px; left: -9999px;
  width: 600px; background: linear-gradient(135deg, #1a1a1a, #2d0000);
  padding: 60px 40px 48px; border-radius: 16px;
  display: flex; flex-direction: column; align-items: center; gap: 20px;
}
.poster-src__title { font-size: 48px; font-weight: 700; color: #fff; }
.poster-src__sub { font-size: 26px; color: #aaa; }
.poster-src__code {
  font-size: 64px; font-weight: 700; color: #D00000; font-family: monospace;
  padding: 20px 60px; border: 3px solid #D00000; border-radius: 12px;
}
.poster-src__brand { font-size: 22px; color: #aaa; font-weight: 600; }
.poster-result {
  width: 100%; aspect-ratio: 3/4;
  background: #0f0f10; border-radius: 10px; overflow: hidden;
  display: flex; align-items: center; justify-content: center;
  margin-bottom: 12px;
}
.poster-result__img { width: 100%; height: 100%; object-fit: cover; }
.poster-result__loading, .poster-result__err { color: #aaa; font-size: 13px; }
.poster-actions { display: flex; gap: 10px; }
.poster-actions__btn {
  flex: 1; height: 40px; border: none; border-radius: 20px;
  font-size: 13px; font-weight: 600; cursor: pointer;
}
.poster-actions__btn--ghost { background: rgba(255,255,255,0.08); color: #aaa; }
.poster-actions__btn--primary { background: linear-gradient(135deg, #D00000, #B00000); color: #fff; }
.poster-actions__btn:disabled { opacity: 0.5; }
</style>
