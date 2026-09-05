<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import request from '@/utils/request'
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
      <button class="invite-code__poster" @click="showToast('生成海报')">生成邀请海报</button>
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
</style>
