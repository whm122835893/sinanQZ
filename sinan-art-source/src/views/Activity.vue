<script setup>
import { ref, onMounted, watch, computed } from 'vue'
import { useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import AppNavBar from '@/components/AppNavBar.vue'
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

const tabs = ['活动', '分解']
const active = ref('活动')

// ---- 分解（真实接口：GET /api/decompose/rules、POST /api/decompose/execute、GET /api/decompose/records）----
const decomposeRules = ref([])
const rulesLoading = ref(false)
const records = ref([])
const recordsLoading = ref(false)
const subView = ref('rules') // rules 分解规则 | records 我的记录

// 确认分解弹窗
const showConfirmModal = ref(false)
const pendingRule = ref(null)
const executing = ref(false)
// 分解成功弹窗
const showResultModal = ref(false)
const lastResult = ref(null)

const hasRecords = computed(() => records.value.length > 0)

watch(active, (t) => {
  if (t === '分解') loadDecompose()
})
watch(subView, (v) => {
  if (v === 'records') loadRecords()
})

onMounted(() => {
  activityStore.fetchSynthesisActivities().catch(() => {})
})

async function loadDecompose() {
  rulesLoading.value = true
  try {
    const res = await request.get('/decompose/rules')
    decomposeRules.value = (res.list || []).map((r) => ({
      ruleId: r.ruleId,
      name: r.name || '',
      source: {
        collectibleId: r.source?.collectibleId,
        name: r.source?.name || '',
        image: r.source?.image || ''
      },
      results: (r.results || []).map((it) => ({
        collectibleId: it.collectibleId,
        name: it.name || '',
        image: it.image || '',
        quantityPer: Number(it.quantityPer || 1)
      })),
      myHeldCount: Number(r.myHeldCount || 0),
      myUsedCount: Number(r.myUsedCount || 0),
      perUserLimit: Number(r.perUserLimit || 0),
      startTime: r.startTime || '',
      endTime: r.endTime || ''
    }))
  } catch (e) {
    decomposeRules.value = []
  } finally {
    rulesLoading.value = false
  }
}

async function loadRecords() {
  if (!userStore.token) {
    records.value = []
    return
  }
  recordsLoading.value = true
  try {
    const res = await request.get('/decompose/records', { params: { page: 1, pageSize: 20 } })
    records.value = (res.list || []).map((r) => ({
      recordId: r.recordId,
      ruleName: r.ruleName || '',
      sourceName: r.source?.name || '',
      sourceImage: r.source?.image || '',
      sourceSerial: r.sourceSerial || '',
      results: (r.results || []).map((it) => ({
        name: it.name || '',
        image: it.image || '',
        quantityPer: Number(it.quantityPer || 1)
      })),
      createdAt: (r.createdAt || '').slice(0, 16).replace('T', ' ')
    }))
  } catch (e) {
    records.value = []
  } finally {
    recordsLoading.value = false
  }
}

// 可分解：持有源藏品 + 未达限次（0 = 不限）
function canDecompose(rule) {
  if (!rule.myHeldCount) return false
  if (rule.perUserLimit > 0 && rule.myUsedCount >= rule.perUserLimit) return false
  return true
}

// 弹出确认：校验登录与持有，取一个可分解资产实例
async function openConfirm(rule) {
  if (!requireLogin('/activity')) return
  // 拉取我的持有（取源藏品下第一个未寄售锁定的实例）
  if (!userStore.inventory.length) {
    await userStore.fetchInventory().catch(() => {})
  }
  const inv = userStore.inventory.find((i) => String(i.id) === String(rule.source.collectibleId))
  const asset = inv?.items?.find((x) => !x.isConsigned)
  if (!asset) {
    alert('暂无可分解的持有资产（可能均已寄售锁定）')
    return
  }
  pendingRule.value = rule
  pendingRule.value.assetSerial = asset.serial
  pendingRule.value.assetId = asset.userCollectibleId
  showConfirmModal.value = true
}

// 确认分解：POST /api/decompose/execute
async function confirmDecompose() {
  const rule = pendingRule.value
  if (!rule?.assetId) return
  executing.value = true
  try {
    const res = await request.post('/decompose/execute', {
      ruleId: rule.ruleId,
      userCollectibleId: rule.assetId
    })
    showConfirmModal.value = false
    lastResult.value = {
      consumedSerial: res.consumedSerial || '',
      results: (res.results || []).map((r) => {
        const meta = rule.results.find((x) => String(x.collectibleId) === String(r.collectibleId))
        return { name: meta?.name || '藏品', serial: r.serial || '' }
      })
    }
    showResultModal.value = true
    // 刷新规则（持有/已用次数）与持有资产
    await Promise.all([
      loadDecompose(),
      userStore.fetchInventory().catch(() => {})
    ])
    if (subView.value === 'records') loadRecords()
  } catch (e) {
    alert(e?.message || '分解失败，请重试')
  } finally {
    executing.value = false
  }
}

function goRecords() {
  subView.value = 'records'
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

    <!-- 分解 -->
    <template v-else>
      <div class="dc-sub">
        <div class="dc-sub__inner">
          <span class="dc-sub__item" :class="{ active: subView === 'rules' }" @click="subView = 'rules'">分解规则</span>
          <span class="dc-sub__item" :class="{ active: subView === 'records' }" @click="subView = 'records'">
            我的记录
          </span>
        </div>
      </div>

      <!-- 分解规则列表 -->
      <div v-if="subView === 'rules'" class="dc-list">
        <div v-for="r in decomposeRules" :key="r.ruleId" class="dc-card">
          <div class="dc-card__title">
            <span class="dc-card__name">{{ r.name || '分解' }}</span>
            <span class="dc-card__limit">
              {{ r.perUserLimit > 0 ? `每人限 ${r.perUserLimit} 次` : '不限次数' }}
            </span>
          </div>
          <div class="dc-card__flow">
            <div class="dc-card__src">
              <img class="dc-card__img" :src="r.source.image" alt="" />
              <span class="dc-card__label">分解</span>
              <span class="dc-card__cname">{{ r.source.name }}</span>
            </div>
            <span class="dc-card__arrow">➜</span>
            <div class="dc-card__outs">
              <div v-for="(it, idx) in r.results" :key="idx" class="dc-card__out">
                <img class="dc-card__img dc-card__img--sm" :src="it.image" alt="" />
                <span class="dc-card__cname">{{ it.name }}<em v-if="it.quantityPer > 1"> ×{{ it.quantityPer }}</em></span>
              </div>
            </div>
          </div>
          <div class="dc-card__foot">
            <span class="dc-card__held">
              持有 {{ r.myHeldCount }} 件<template v-if="r.perUserLimit > 0"> · 已分解 {{ r.myUsedCount }}/{{ r.perUserLimit }} 次</template>
            </span>
            <button class="dc-card__btn" :disabled="!canDecompose(r)" @click="openConfirm(r)">
              {{ !r.myHeldCount ? '未持有' : (r.perUserLimit > 0 && r.myUsedCount >= r.perUserLimit) ? '已达上限' : '立即分解' }}
            </button>
          </div>
        </div>

        <div v-if="!decomposeRules.length && !rulesLoading" class="act-empty">
          <p>暂无进行中的分解活动</p>
          <button v-if="hasRecords" class="dc-record-link" @click="goRecords">查看我的分解记录 ›</button>
        </div>
        <p v-if="rulesLoading" class="act-empty">加载中…</p>
      </div>

      <!-- 我的分解记录 -->
      <div v-else class="dc-list">
        <div v-for="rec in records" :key="rec.recordId" class="dc-rec">
          <img class="dc-rec__img" :src="rec.sourceImage" alt="" />
          <div class="dc-rec__body">
            <div class="dc-rec__top">
              <span class="dc-rec__rule">{{ rec.ruleName || '分解' }}</span>
              <span class="dc-rec__time">{{ rec.createdAt }}</span>
            </div>
            <p class="dc-rec__src">消耗：{{ rec.sourceName }} #{{ rec.sourceSerial }}</p>
            <p class="dc-rec__outs">
              产出：<span v-for="(it, idx) in rec.results" :key="idx">{{ it.name }}<em v-if="it.quantityPer > 1">×{{ it.quantityPer }}</em>{{ idx < rec.results.length - 1 ? '、' : '' }}</span>
            </p>
          </div>
        </div>
        <div v-if="!records.length && !recordsLoading" class="act-empty">
          <p>{{ userStore.token ? '暂无分解记录' : '登录后可查看分解记录' }}</p>
        </div>
        <p v-if="recordsLoading" class="act-empty">加载中…</p>
      </div>
    </template>

    <!-- 确认分解弹窗 -->
    <AppModal v-model:show="showConfirmModal" title="确认分解">
      <div v-if="pendingRule" class="dc-confirm">
        <p class="dc-confirm__tip">分解操作不可撤销，确认后立即执行</p>
        <div class="dc-confirm__row">
          <span class="dc-confirm__k">分解藏品</span>
          <span class="dc-confirm__v">{{ pendingRule.source.name }} #{{ pendingRule.assetSerial }}</span>
        </div>
        <div class="dc-confirm__row">
          <span class="dc-confirm__k">获得产物</span>
          <span class="dc-confirm__v">
            <template v-for="(it, idx) in pendingRule.results" :key="idx">
              {{ it.name }}<em v-if="it.quantityPer > 1"> ×{{ it.quantityPer }}</em>{{ idx < pendingRule.results.length - 1 ? '、' : '' }}
            </template>
          </span>
        </div>
        <div class="dc-confirm__actions">
          <button class="dc-confirm__cancel" @click="showConfirmModal = false">取消</button>
          <button class="dc-confirm__ok" :disabled="executing" @click="confirmDecompose">
            {{ executing ? '分解中…' : '确认分解' }}
          </button>
        </div>
      </div>
    </AppModal>

    <!-- 分解结果弹窗 -->
    <AppModal v-model:show="showResultModal" title="分解成功">
      <div v-if="lastResult" class="dc-result">
        <p class="dc-result__tip">已消耗 #{{ lastResult.consumedSerial }}，获得以下藏品</p>
        <div v-for="(r, idx) in lastResult.results" :key="idx" class="dc-result__item">
          <span class="dc-result__name">{{ r.name }}</span>
          <span class="dc-result__serial">#{{ r.serial }}</span>
        </div>
        <button class="dc-result__ok" @click="showResultModal = false">好的</button>
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

/* ========== 分解 ========== */
.dc-sub { padding: 2px $page-padding 0; }
.dc-sub__inner {
  display: inline-flex; background: $color-surface; border-radius: $radius-pill; padding: 3px;
}
.dc-sub__item {
  padding: 6px 18px; border-radius: $radius-pill; font-size: 13px; color: $color-text-tertiary;
  cursor: pointer; font-weight: 500;
  &.active { background: $color-card; color: $color-primary; font-weight: 700; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
}

.dc-list { padding: 12px $page-padding 24px; }
.dc-card {
  background: $color-card; border-radius: $radius-lg; padding: 14px; margin-bottom: 12px;
}
.dc-card__title { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.dc-card__name {
  font-size: 15px; font-weight: 700; color: $color-text-primary;
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.dc-card__limit { flex-shrink: 0; font-size: 11px; color: $color-text-tertiary; }
.dc-card__flow {
  display: flex; align-items: center; gap: 10px; margin-top: 12px;
  background: $color-surface; border-radius: $radius-md; padding: 10px;
}
.dc-card__src { display: flex; flex-direction: column; align-items: center; gap: 4px; flex-shrink: 0; width: 84px; }
.dc-card__img {
  width: 52px; height: 52px; border-radius: 10px; object-fit: cover;
  background: $color-card; -webkit-user-drag: none; user-select: none; pointer-events: none;
  &--sm { width: 40px; height: 40px; }
}
.dc-card__label { font-size: 10px; color: $color-primary; }
.dc-card__cname {
  font-size: 12px; color: $color-text-primary; font-weight: 600; text-align: center;
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 100%;
  em { font-style: normal; color: $color-primary; }
}
.dc-card__arrow { color: $color-primary; font-size: 16px; flex-shrink: 0; }
.dc-card__outs {
  flex: 1; min-width: 0; display: flex; gap: 10px; overflow-x: auto;
  &::-webkit-scrollbar { display: none; }
}
.dc-card__out {
  display: flex; flex-direction: column; align-items: center; gap: 4px; flex-shrink: 0; width: 68px;
}
.dc-card__foot {
  display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-top: 12px;
}
.dc-card__held { font-size: 12px; color: $color-text-tertiary; }
.dc-card__btn {
  border: none; cursor: pointer; color: #fff; font-size: 12px; font-weight: 600;
  padding: 7px 18px; border-radius: $radius-pill;
  background: linear-gradient(135deg, $color-primary, #B00000);
  &:disabled { background: $color-surface; color: $color-text-tertiary; cursor: not-allowed; }
}
.dc-record-link {
  border: none; background: none; cursor: pointer;
  color: $color-primary; font-size: 13px; margin-top: 8px;
}

.dc-rec {
  display: flex; gap: 10px; background: $color-card; border-radius: $radius-lg;
  padding: 12px 14px; margin-bottom: 12px;
}
.dc-rec__img {
  width: 48px; height: 48px; border-radius: 10px; object-fit: cover; flex-shrink: 0;
  background: $color-surface;
}
.dc-rec__body { flex: 1; min-width: 0; }
.dc-rec__top { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.dc-rec__rule { font-size: 14px; font-weight: 700; color: $color-text-primary; }
.dc-rec__time { font-size: 11px; color: $color-text-tertiary; }
.dc-rec__src, .dc-rec__outs {
  margin: 4px 0 0; font-size: 12px; color: $color-text-secondary; line-height: 1.6;
  word-break: break-all;
  em { font-style: normal; color: $color-primary; }
}

/* 确认分解弹窗 */
.dc-confirm { padding: 8px 0; }
.dc-confirm__tip {
  font-size: 12px; color: $color-primary; background: $color-primary-light;
  border-radius: $radius-md; padding: 8px 12px; margin-bottom: 14px;
}
.dc-confirm__row {
  display: flex; gap: 12px; margin-bottom: 12px; font-size: 13px; line-height: 1.6;
}
.dc-confirm__k { flex-shrink: 0; color: $color-text-tertiary; width: 64px; }
.dc-confirm__v { color: $color-text-primary; font-weight: 500; word-break: break-all; }
.dc-confirm__actions { display: flex; gap: 12px; margin-top: 16px; }
.dc-confirm__cancel, .dc-confirm__ok {
  flex: 1; padding: 10px 0; border: none; border-radius: $radius-md;
  font-size: 14px; font-weight: 600; cursor: pointer;
}
.dc-confirm__cancel { background: rgba(0,0,0,0.06); color: $color-text-secondary; }
.dc-confirm__ok { background: $color-primary; color: #fff; }
.dc-confirm__ok:disabled { opacity: 0.6; cursor: not-allowed; }

/* 分解结果弹窗 */
.dc-result { padding: 8px 0; }
.dc-result__tip { font-size: 12px; color: $color-text-tertiary; margin-bottom: 12px; }
.dc-result__item {
  display: flex; align-items: center; justify-content: space-between;
  background: $color-surface; border-radius: $radius-md; padding: 10px 12px; margin-bottom: 8px;
}
.dc-result__name { font-size: 14px; font-weight: 600; color: $color-text-primary; }
.dc-result__serial { font-size: 12px; color: $color-primary; font-family: $font-price; }
.dc-result__ok {
  width: 100%; padding: 10px 0; margin-top: 8px; border: none; border-radius: $radius-md;
  background: $color-primary; color: #fff; font-size: 14px; font-weight: 600; cursor: pointer;
}
</style>
