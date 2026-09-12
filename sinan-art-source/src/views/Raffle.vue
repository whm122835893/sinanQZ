<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import request from '@/utils/request'
import { useUserStore } from '@/stores/user'
import { showToast } from 'vant'
import AppNavBar from '@/components/AppNavBar.vue'
import AppEmpty from '@/components/AppEmpty.vue'

const router = useRouter()
const user = useUserStore()

const activities = ref([])
const mine = ref([])
const loading = ref(true)
const tab = ref('list') // list 活动 / mine 我的抽签

// 活动阶段文案
const PHASE_TEXT = {
  upcoming: '抽签未开始',
  registering: '抽签进行中',
  drawing: '开签中',
  drawn: '已开奖',
  finished: '已结束'
}

async function fetchActivities() {
  loading.value = true
  try {
    const res = await request.get('/raffle/activities', { params: { page: 1, pageSize: 50 } })
    activities.value = res.list || []
  } catch (e) {
    showToast(e.message || '活动加载失败')
  } finally {
    loading.value = false
  }
}

// 我的抽签记录（含中签状态与购买入口）
async function fetchMine() {
  if (!user.isLoggedIn) return
  try {
    const res = await request.get('/raffle/registrations/mine', { params: { page: 1, pageSize: 50 } })
    mine.value = res.list || []
  } catch { /* 未登录静默 */ }
}

function goDetail(id) {
  router.push(`/raffle/${id}`)
}

onMounted(() => {
  fetchActivities()
  fetchMine()
})
</script>

<template>
  <div class="raffle page--no-tabbar">
    <AppNavBar title="司南·抽签购" @click-left="$router.back()" />

    <!-- Tab 切换 -->
    <div class="raffle__tabs">
      <button class="raffle__tab" :class="{ active: tab === 'list' }" @click="tab = 'list'">抽签活动</button>
      <button class="raffle__tab" :class="{ active: tab === 'mine' }" @click="tab = 'mine'">我的抽签</button>
    </div>

    <!-- 活动列表 -->
    <template v-if="tab === 'list'">
      <div v-if="loading" class="raffle__loading">加载中…</div>
      <AppEmpty v-else-if="!activities.length" text="暂无抽签活动" />
      <div v-else class="raffle__list">
        <div v-for="a in activities" :key="a.activityId" class="raffle-card" @click="goDetail(a.activityId)">
          <div class="raffle-card__img-wrap">
            <img class="raffle-card__img" :src="a.collectible?.image || '/images/platform-logo.png'" alt="" />
            <span class="raffle-card__phase" :class="a.phase">{{ PHASE_TEXT[a.phase] || a.phase }}</span>
          </div>
          <div class="raffle-card__body">
            <p class="raffle-card__name">{{ a.name }}</p>
            <p class="raffle-card__collectible">{{ a.collectible?.name }}</p>
            <div class="raffle-card__meta">
              <span class="raffle-card__price">¥{{ a.salePrice }}</span>
              <span class="raffle-card__count">{{ a.drawWinCount || a.winnerCount }} 个名额</span>
            </div>
            <p class="raffle-card__time">抽签：{{ a.registrationStart?.slice(5, 16) }} ~ {{ a.registrationEnd?.slice(5, 16) }}</p>
            <p class="raffle-card__time">开奖：{{ a.drawTime?.slice(5, 16) }}</p>
          </div>
        </div>
      </div>
    </template>

    <!-- 我的抽签 -->
    <template v-else>
      <AppEmpty v-if="!mine.length" text="暂无抽签记录" />
      <div v-else class="raffle__list">
        <div v-for="m in mine" :key="m.id" class="raffle-card" @click="goDetail(m.activityId)">
          <div class="raffle-card__img-wrap">
            <img class="raffle-card__img" :src="m.collectible?.image || '/images/platform-logo.png'" alt="" />
            <span class="raffle-card__phase" :class="m.drawStatus === 1 ? 'drawn' : m.drawStatus === 2 ? 'finished' : 'registering'">
              {{ m.drawStatus === 1 ? (m.winCount > 1 ? `已中签×${m.winCount}` : '已中签') : m.drawStatus === 2 ? '未中签' : '待开奖' }}
            </span>
          </div>
          <div class="raffle-card__body">
            <p class="raffle-card__name">{{ m.activityName }}</p>
            <p class="raffle-card__collectible">{{ m.collectible?.name }} · {{ m.ticketCount }} 票</p>
            <div class="raffle-card__meta">
              <span class="raffle-card__price" v-if="m.drawStatus === 1">¥{{ m.salePrice }}</span>
              <span v-if="m.drawStatus === 1" class="raffle-card__count">
                已购 {{ m.purchasedQuantity }}/{{ (m.winCount || 1) * m.saleQuantity }}
              </span>
            </div>
            <button v-if="m.purchasable" class="raffle-card__buy" @click.stop="goDetail(m.activityId)">
              中签购买
            </button>
            <p v-else-if="m.drawStatus === 1 && m.purchasedQuantity >= (m.winCount || 1) * m.saleQuantity" class="raffle-card__time">
              已购满
            </p>
          </div>
        </div>
      </div>
    </template>

    <p class="raffle__rule">活动规则：抽签截止后系统开奖，中签用户可在有效期内按中签价购买，逾期视为放弃。</p>
  </div>
</template>

<style scoped lang="scss">
.raffle {
  min-height: 100vh;
  background: $color-bg;
  padding-bottom: 24px;
}

.raffle__tabs {
  display: flex; gap: 10px;
  margin: 14px $page-padding 0;
}
.raffle__tab {
  flex: 1; height: 40px; border: 1px solid $color-border; border-radius: $radius-pill;
  background: $color-card; color: $color-text-tertiary;
  font-size: 14px; cursor: pointer;
  &.active {
    border-color: $color-primary; color: $color-primary; font-weight: 700;
    background: rgba(192, 0, 0, 0.04);
  }
}

.raffle__loading {
  padding: 60px 0; text-align: center;
  font-size: 13px; color: $color-text-tertiary;
}

.raffle__list {
  display: flex; flex-direction: column; gap: 12px;
  margin: 14px $page-padding 0;
}

.raffle-card {
  display: flex; gap: 12px;
  background: $color-card; border-radius: $radius-lg;
  padding: 12px; cursor: pointer;
}
.raffle-card__img-wrap {
  position: relative; width: 96px; height: 96px; flex-shrink: 0;
  border-radius: $radius-sm; overflow: hidden; background: $color-surface;
}
.raffle-card__img { width: 100%; height: 100%; object-fit: cover; display: block; }
.raffle-card__phase {
  position: absolute; top: 6px; left: 6px;
  padding: 2px 8px; border-radius: $radius-pill;
  font-size: 11px; font-weight: 700; color: #fff;
  background: rgba(0, 0, 0, 0.45);
  &.registering { background: $color-primary; }
  &.drawn { background: #07c160; }
  &.finished, &.drawing { background: #999; }
  &.upcoming { background: #e8b873; }
}
.raffle-card__body { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 4px; }
.raffle-card__name {
  margin: 0; font-size: 15px; font-weight: 700; color: $color-text-primary;
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.raffle-card__collectible {
  margin: 0; font-size: 12px; color: $color-text-tertiary;
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.raffle-card__meta { display: flex; align-items: baseline; gap: 10px; }
.raffle-card__price { font-size: 16px; font-weight: 800; color: $color-primary; }
.raffle-card__count { font-size: 12px; color: $color-text-tertiary; }
.raffle-card__time { margin: 0; font-size: 11px; color: $color-text-tertiary; }
.raffle-card__buy {
  margin-top: 2px; align-self: flex-start;
  height: 30px; padding: 0 16px; border: none; border-radius: $radius-pill;
  background: linear-gradient(135deg, #D00000, #B00000); color: #fff;
  font-size: 13px; font-weight: 700; cursor: pointer;
}

.raffle__rule {
  margin: 18px $page-padding 0;
  font-size: 12px; color: $color-text-tertiary; line-height: 1.6; text-align: center;
}
</style>
