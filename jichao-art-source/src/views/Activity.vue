<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import AppNavBar from '@/components/AppNavBar.vue'
import AppEmpty from '@/components/AppEmpty.vue'
import { useActivityStore } from '@/stores/activity'

const router = useRouter()
const activityStore = useActivityStore()
const { synthesisActivities } = storeToRefs(activityStore)

// MOCK_REPLACED: 原为内联 mock 合成活动列表，现从后端拉取（GET /api/synthesis/activities）
onMounted(() => {
  activityStore.fetchSynthesisActivities().catch(() => {})
})

const tabs = ['活动', '置换']
const active = ref('活动')

function goSynthesis(id) {
  router.push({ name: 'activity-synthesis', params: { id } })
}

const now = Date.now()
function statusOf(a) {
  // permanent 类型活动常驻，不按时间窗判定
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

    <AppEmpty v-else description="空空如也" />
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
</style>
