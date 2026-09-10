<script setup>
import { ref, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import AppNavBar from '@/components/AppNavBar.vue'
import AppEmpty from '@/components/AppEmpty.vue'
import AppModal from '@/components/AppModal.vue'
import { useActivityStore } from '@/stores/activity'
import { useUserStore } from '@/stores/user'
import request from '@/utils/request'
import { useLoginGate } from '@/utils/loginGate'

const router = useRouter()
const activityStore = useActivityStore()
const userStore = useUserStore()
const { synthesisActivities } = storeToRefs(activityStore)
const { requireLogin } = useLoginGate()

const tabs = ['活动', '置换']
const active = ref('活动')

const swapOffers = ref([])
const swapLoading = ref(false)

// 发布置换弹窗
const showSwapModal = ref(false)
const swapForm = ref({ offerCollectibleId: '', targetCollectibleId: '', cashDiff: 0, remark: '' })
const swapPosting = ref(false)

watch(active, (t) => {
  if (t === '置换' && swapOffers.value.length === 0) loadSwaps()
})

onMounted(() => {
  activityStore.fetchSynthesisActivities().catch(() => {})
})

async function loadSwaps() {
  swapLoading.value = true
  try {
    const res = await request.get('/swap-offers', { params: { status: 1, page: 1, pageSize: 30 } })
    swapOffers.value = (res.list || []).map((s) => ({
      id: s.id,
      offerUserName: s.offerUserName || '置换方',
      offerCollectibleName: s.offerCollectibleName || '藏品',
      offerCollectibleImage: s.offerCollectibleImage || '',
      offerSerial: s.offerSerial || '',
      targetCollectibleName: s.targetCollectibleName || '期望藏品',
      targetCollectibleImage: s.targetCollectibleImage || '',
      cashDiff: Number(s.cashDiff || 0).toFixed(2),
      remark: s.remark || '',
      createdAt: (s.createdAt || '').slice(0, 16).replace('T', ' '),
    }))
  } catch (e) {
    swapOffers.value = []
  } finally {
    swapLoading.value = false
  }
}

function acceptSwap(s) {
  if (!requireLogin('/activity')) return
  request.post('/swap-offers/' + s.id + '/accept').then(() => {
    loadSwaps()
  }).catch(() => {})
}

// 打开发布置换弹窗
async function openPostSwap() {
  if (!requireLogin('/activity')) return
  // 加载我的藏品供选择
  if (!userStore.inventory.length) {
    await userStore.fetchInventory().catch(() => {})
  }
  swapForm.value = { offerCollectibleId: '', targetCollectibleId: '', cashDiff: 0, remark: '' }
  showSwapModal.value = true
}

// 提交置换挂单
async function submitSwap() {
  const offerId = parseInt(swapForm.value.offerCollectibleId)
  const targetId = parseInt(swapForm.value.targetCollectibleId)
  if (!offerId) {
    alert('请选择要置换出去的藏品')
    return
  }
  if (!targetId) {
    alert('请选择期望换得的藏品')
    return
  }
  swapPosting.value = true
  try {
    const inv = userStore.inventory.find((i) => String(i.id) === String(offerId))
    await request.post('/swap-offers', {
      offerCollectibleId: offerId,
      offerSerial: inv?.nos?.[0] || '',
      targetCollectibleId: targetId,
      cashDiff: Number(swapForm.value.cashDiff) || 0,
      remark: swapForm.value.remark || '',
    })
    showSwapModal.value = false
    await loadSwaps()
  } catch (e) {
    alert(e?.message || '发布置换失败')
  } finally {
    swapPosting.value = false
  }
}

function goSynthesis(id) {
  router.push({ name: 'activity-synthesis', params: { id } })
}

const now = Date.now()
function statusOf(a) {
  if (a.type === 'permanent') return { text: '进行中', cls: 'ing' }
  const s = new Date(String(a.startTime).replace(/-/g, '/')).getTime()
  const e = new Date(String(a.endTime).replace(/-/g, '/')).getTime()
  if (now < s) return { text: '未开始', cls: 'wait' }
  if (now > e) return { text: '已结束', cls: 'end' }
  return { text: '进行中', cls: 'ing' }
}
</script>

<template>
  <div class="activity page--no-tabbar">
    <AppNavBar title="活动中心" @click-left="$router.back()" />

    <div class="activity-tabs">
      <div
        v-for="tab in tabs"
        :key="tab"
        class="activity-tabs__item"
        :class="{ active: active === tab }"
        @click="active = tab"
      >
        <span class="activity-tabs__label">{{ tab }}</span>
        <span class="activity-tabs__bar"></span>
      </div>
    </div>

    <!-- 活动：合成活动列表 -->
    <div v-if="active === '活动'" class="act-list">
      <div
        v-for="a in synthesisActivities"
        :key="a.id"
        class="act-card"
        @click="goSynthesis(a.id)"
      >
        <img class="act-card__img" :src="a.coverImage" alt="" />
        <div class="act-card__body">
          <div class="act-card__top">
            <span class="act-card__title">{{ a.title }}</span>
            <span class="act-card__status" :class="'act-card__status--' + statusOf(a).cls">
              {{ statusOf(a).text }}
            </span>
          </div>
          <p class="act-card__time">开始：{{ a.startTime }}</p>
          <p class="act-card__time">结束：{{ a.endTime }}</p>
        </div>
        <span class="act-card__arrow">›</span>
      </div>
      <p v-if="!synthesisActivities.length" class="act-empty">暂无活动</p>
    </div>

    <!-- 置换：公开置换挂单池 -->
    <div v-else-if="active === '置换'" class="act-swap">
      <div
        v-for="s in swapOffers"
        :key="s.id"
        class="swap-card"
      >
        <div class="swap-card__side">
          <img class="swap-card__img" :src="s.offerCollectibleImage" alt="" />
          <div class="swap-card__body">
            <span class="swap-card__label">我出</span>
            <span class="swap-card__name">{{ s.offerCollectibleName }}</span>
            <span class="swap-card__serial" v-if="s.offerSerial">#{{ s.offerSerial }}</span>
          </div>
        </div>
        <div class="swap-card__arrow">
          <span class="swap-card__user">{{ s.offerUserName }}</span>
          <span class="swap-card__diff" v-if="Number(s.cashDiff) > 0">+¥{{ s.cashDiff }}</span>
          <span class="swap-card__diff swap-card__diff--neg" v-else-if="Number(s.cashDiff) < 0">需补¥{{ Math.abs(Number(s.cashDiff)).toFixed(2) }}</span>
          <span class="swap-card__vs">⇄</span>
        </div>
        <div class="swap-card__side swap-card__side--target">
          <img class="swap-card__img" :src="s.targetCollectibleImage" alt="" />
          <div class="swap-card__body">
            <span class="swap-card__label">换得</span>
            <span class="swap-card__name">{{ s.targetCollectibleName }}</span>
          </div>
        </div>
        <div class="swap-card__action">
          <button class="swap-card__btn" @click="acceptSwap(s)">接受置换</button>
        </div>
      </div>

      <div v-if="!swapOffers.length && !swapLoading" class="act-empty">
        <p>暂无置换挂单</p>
        <button class="swap-post-btn" @click="openPostSwap">+ 发布我的置换</button>
      </div>
    </div>

    <div class="act-float safe-bottom" v-if="active === '置换'">
      <button class="act-float__btn" @click="openPostSwap">+ 发布置换</button>
    </div>

    <!-- 发布置换弹窗 -->
    <AppModal v-model:show="showSwapModal" title="发布置换挂单">
      <div class="swap-form">
        <div class="swap-form-row">
          <label>我出（我的藏品）</label>
          <select v-model="swapForm.offerCollectibleId">
            <option value="">请选择藏品</option>
            <option v-for="i in userStore.inventory" :key="i.id" :value="i.id">{{ i.name }} (×{{ i.qty }})</option>
          </select>
        </div>
        <div class="swap-form-row">
          <label>换得（目标藏品ID）</label>
          <input v-model="swapForm.targetCollectibleId" type="number" placeholder="输入目标藏品ID" />
        </div>
        <div class="swap-form-row">
          <label>差价（¥，正=对方补我，负=我补对方）</label>
          <input v-model="swapForm.cashDiff" type="number" step="0.01" value="0" />
        </div>
        <div class="swap-form-row">
          <label>备注</label>
          <input v-model="swapForm.remark" type="text" placeholder="选填" />
        </div>
        <div class="swap-form-actions">
          <button class="swap-form-cancel" @click="showSwapModal = false">取消</button>
          <button class="swap-form-submit" :disabled="swapPosting" @click="submitSwap">
            {{ swapPosting ? '提交中...' : '确认发布' }}
          </button>
        </div>
      </div>
    </AppModal>
  </div>
</template>

<style scoped lang="scss">
.activity-tabs {
  display: flex; gap: 28px; padding: 14px $page-padding; background: $color-card; margin-bottom: 8px;
  &__item { display: flex; flex-direction: column; align-items: center; cursor: pointer; }
  &__label { font-size: 15px; color: $color-text-tertiary; font-weight: 500; }
  &__bar { margin-top: 6px; width: 20px; height: 3px; border-radius: 2px; background: transparent; }
  &__item.active &__label { color: $color-text-primary; font-weight: 700; }
  &__item.active &__bar { background: $color-primary; }
}

.act-list { padding: 12px $page-padding; }
.act-card {
  display: flex; align-items: center; gap: 12px;
  background: $color-card; border-radius: $radius-lg; padding: 14px 14px 14px 12px;
  margin-bottom: 12px; cursor: pointer;
  &:active { opacity: 0.92; }
}
.act-card__img {
  width: 56px; height: 56px; border-radius: $radius-md; object-fit: cover; flex-shrink: 0;
  background: $color-surface;
}
.act-card__body { flex: 1; min-width: 0; }
.act-card__top { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.act-card__title {
  font-size: 15px; font-weight: 700; color: $color-text-primary;
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.act-card__time { margin: 4px 0 0; font-size: 12px; color: $color-text-tertiary; line-height: 1.5; }
.act-card__status {
  flex-shrink: 0; font-size: 11px; padding: 2px 8px; border-radius: $radius-pill;
  &--ing { color: $color-primary; background: $color-primary-light; }
  &--wait { color: #B8860B; background: #FBF1DD; }
  &--end { color: $color-text-tertiary; background: $color-surface; }
}
.act-card__arrow { color: $color-text-tertiary; font-size: 22px; flex-shrink: 0; }
.act-empty { text-align: center; color: $color-text-tertiary; font-size: 14px; margin-top: 40px; }

/* ========== 置换卡 ========== */
.act-swap { padding: 12px $page-padding 80px; }
.swap-card {
  display: flex; flex-direction: column; gap: 10px;
  background: $color-card; border-radius: $radius-lg; padding: 14px; margin-bottom: 12px;
}
.swap-card__side {
  display: flex; align-items: center; gap: 10px;
  &--target {
    .swap-card__label { color: $color-primary; }
  }
}
.swap-card__img {
  width: 52px; height: 52px; border-radius: 10px; object-fit: cover; flex-shrink: 0;
  background: $color-surface;
  -webkit-user-drag: none; user-select: none; pointer-events: none;
}
.swap-card__body { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.swap-card__label { font-size: 11px; color: $color-text-tertiary; }
.swap-card__name {
  font-size: 14px; font-weight: 600; color: $color-text-primary;
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.swap-card__serial { font-size: 11px; color: $color-text-tertiary; font-family: $font-price; }
.swap-card__arrow {
  display: flex; align-items: center; justify-content: space-between;
  padding: 0 14px; height: 32px;
  background: linear-gradient(90deg, rgba(255,255,255,0.04), rgba(208,0,0,0.08), rgba(255,255,255,0.04));
  border-radius: 16px;
}
.swap-card__user { font-size: 11px; color: $color-text-tertiary; }
.swap-card__diff { font-size: 11px; color: #22c55e; font-weight: 600; }
.swap-card__diff--neg { color: $color-primary; }
.swap-card__vs { font-size: 16px; color: $color-primary; font-weight: 700; }
.swap-card__action { display: flex; justify-content: flex-end; }
.swap-card__btn {
  border: none; cursor: pointer; color: #fff; font-size: 12px; font-weight: 600;
  padding: 6px 18px; border-radius: $radius-pill;
  background: linear-gradient(135deg, #3b82f6, #2563eb);
}
.swap-post-btn {
  border: none; cursor: pointer;
  background: linear-gradient(135deg, $color-primary, #B00000); color: #fff;
  padding: 10px 24px; border-radius: $radius-pill;
  font-size: 13px; font-weight: 600; margin-top: 16px;
}

.act-float {
  position: fixed; left: 0; right: 0; bottom: 0; z-index: 100;
  display: flex; justify-content: center;
  padding: 12px $page-padding calc(12px + env(safe-area-inset-bottom));
  background: transparent;
}
.act-float__btn {
  width: 100%; height: 44px; border: none; border-radius: $radius-pill;
  background: linear-gradient(135deg, #3b82f6, #2563eb);
  color: #fff; font-size: 15px; font-weight: 600; cursor: pointer;
  box-shadow: 0 6px 18px rgba(59, 130, 246, 0.28);
}

.swap-form { padding: 8px 0; }
.swap-form-row {
  display: flex; flex-direction: column; gap: 6px;
  margin-bottom: 14px;
}
.swap-form-row label {
  font-size: 13px; color: $color-text-secondary; font-weight: 500;
}
.swap-form-row input, .swap-form-row select {
  padding: 10px 12px; border-radius: $radius-md;
  border: 1px solid rgba(0,0,0,0.1);
  font-size: 14px; color: $color-text-primary;
  background: $color-bg; outline: none;
}
.swap-form-row input:focus, .swap-form-row select:focus {
  border-color: $color-primary;
}
.swap-form-actions {
  display: flex; gap: 12px; margin-top: 8px;
}
.swap-form-cancel, .swap-form-submit {
  flex: 1; padding: 10px 0; border: none; border-radius: $radius-md;
  font-size: 14px; font-weight: 600; cursor: pointer;
}
.swap-form-cancel {
  background: rgba(0,0,0,0.06); color: $color-text-secondary;
}
.swap-form-submit {
  background: $color-primary; color: #fff;
}
.swap-form-submit:disabled {
  opacity: 0.6; cursor: not-allowed;
}
</style>
