<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { getCheckinConfig, getLuckyDraws, getSynthesisList, getInviteActivity, getPrioritySales } from '@/api'
import StatusTag from '@/components/StatusTag.vue'
import { ACTIVITY_STATUS } from '@/utils/maps'
import { fmtNumber } from '@/utils/format'

const router = useRouter()
const loading = ref(true)
const checkin = ref(null)
const lucky = ref([])
const synthesis = ref([])
const invite = ref(null)
const priority = ref([])

onMounted(async () => {
  const [c, l, s, i, p] = await Promise.all([
    getCheckinConfig(), getLuckyDraws(), getSynthesisList(), getInviteActivity(), getPrioritySales()
  ])
  checkin.value = c.data
  lucky.value = l.data
  synthesis.value = s.data
  invite.value = i.data
  priority.value = p.data
  loading.value = false
})

const entries = [
  { to: '/marketing/checkin', icon: 'calendar-o', title: '签到配置', desc: '连续签到奖励', tone: 'primary' },
  { to: '/marketing/luckydraw', icon: 'medal-o', title: '抽奖活动', desc: '转盘奖池管理', tone: 'gold' },
  { to: '/marketing/synthesis', icon: 'cluster-o', title: '合成活动', desc: '材料合成玩法', tone: 'blue' },
  { to: '/marketing/invite', icon: 'contact', title: '邀请活动', desc: '邀友注册奖励', tone: 'green' },
  { to: '/marketing/priority', icon: 'coupon-o', title: '优先购管理', desc: '白名单资格', tone: 'primary' }
]
</script>

<template>
  <div class="adm-page mk">
    <van-skeleton v-if="loading" title :row="6" style="padding: 16px" />
    <template v-else>
      <!-- 功能入口 -->
      <div class="adm-card">
        <div class="adm-card__title">营销玩法</div>
        <div class="mk__entries">
          <div v-for="e in entries" :key="e.to" class="mk__entry" :class="`is-${e.tone}`" @click="router.push(e.to)">
            <van-icon :name="e.icon" size="24" />
            <div class="mk__entry-title">{{ e.title }}</div>
            <div class="mk__entry-desc">{{ e.desc }}</div>
          </div>
        </div>
      </div>

      <!-- 签到概览 -->
      <div class="adm-card">
        <div class="adm-card__title">
          每日签到
          <StatusTag :value="checkin.enabled === 1 ? 'enabled' : 'disabled'" :map="ACTIVITY_STATUS" />
          <span class="adm-card__more" @click="router.push('/marketing/checkin')">配置<van-icon name="arrow" /></span>
        </div>
        <div class="mk__stats">
          <div class="mk__stat"><div class="price">{{ fmtNumber(checkin.todayCount) }}</div><div class="t-tertiary">今日签到</div></div>
          <div class="mk__stat"><div class="price">{{ fmtNumber(checkin.monthCount) }}</div><div class="t-tertiary">本月签到</div></div>
          <div class="mk__stat"><div class="price">{{ checkin.rules.length }}</div><div class="t-tertiary">奖励档位</div></div>
        </div>
      </div>

      <!-- 抽奖概览 -->
      <div class="adm-card">
        <div class="adm-card__title">
          抽奖活动（{{ lucky.filter(a => a.status === 'enabled').length }} 个进行中）
          <span class="adm-card__more" @click="router.push('/marketing/luckydraw')">管理<van-icon name="arrow" /></span>
        </div>
        <div v-for="a in lucky" :key="a.id" class="adm-item" @click="router.push('/marketing/luckydraw')">
          <div class="adm-item__body">
            <div class="adm-item__title">
              {{ a.name }}
              <StatusTag :value="a.status" :map="ACTIVITY_STATUS" />
            </div>
            <div class="adm-item__desc">{{ a.startTime }} ~ {{ a.endTime }}</div>
          </div>
          <div class="adm-item__side">
            <div class="price" style="font-size: 14px">{{ fmtNumber(a.chancesUsed) }}</div>
            <div class="t-tertiary" style="font-size: 11px">已消耗次数</div>
          </div>
        </div>
      </div>

      <!-- 合成概览 -->
      <div class="adm-card">
        <div class="adm-card__title">
          合成活动
          <span class="adm-card__more" @click="router.push('/marketing/synthesis')">管理<van-icon name="arrow" /></span>
        </div>
        <div v-for="a in synthesis" :key="a.id" class="adm-item" @click="router.push('/marketing/synthesis')">
          <div class="adm-item__body">
            <div class="adm-item__title">
              {{ a.title }}
              <StatusTag :value="a.status" :map="ACTIVITY_STATUS" />
            </div>
            <div class="adm-item__desc">{{ a.rules }}</div>
          </div>
          <div class="adm-item__side">
            <div class="price" style="font-size: 14px">{{ fmtNumber(a.usedCount) }}</div>
            <div class="t-tertiary" style="font-size: 11px">已合成</div>
          </div>
        </div>
      </div>

      <!-- 邀请概览 -->
      <div class="adm-card">
        <div class="adm-card__title">
          邀请活动
          <StatusTag :value="invite.status" :map="ACTIVITY_STATUS" />
          <span class="adm-card__more" @click="router.push('/marketing/invite')">配置<van-icon name="arrow" /></span>
        </div>
        <div class="mk__stats">
          <div class="mk__stat"><div class="price">{{ fmtNumber(invite.stats.invitedCount) }}</div><div class="t-tertiary">累计邀请</div></div>
          <div class="mk__stat"><div class="price">{{ fmtNumber(invite.stats.registerCount) }}</div><div class="t-tertiary">注册成功</div></div>
          <div class="mk__stat"><div class="price">{{ fmtNumber(invite.stats.rewardIssued) }}</div><div class="t-tertiary">奖励发放</div></div>
        </div>
      </div>

      <!-- 优先购概览 -->
      <div class="adm-card">
        <div class="adm-card__title">
          优先购 / 资格购
          <span class="adm-card__more" @click="router.push('/marketing/priority')">管理<van-icon name="arrow" /></span>
        </div>
        <div v-for="s in priority" :key="s.id" class="adm-item" @click="router.push('/marketing/priority')">
          <img class="adm-item__thumb" style="width: 40px; height: 40px" :src="s.cover" :alt="s.name" />
          <div class="adm-item__body">
            <div class="adm-item__title">{{ s.name }}</div>
            <div class="adm-item__desc">{{ s.startTime }} ~ {{ s.endTime }}</div>
          </div>
          <div class="adm-item__side">
            <div class="price" style="font-size: 14px">{{ s.whitelistCount }}</div>
            <div class="t-tertiary" style="font-size: 11px">白名单</div>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped lang="scss">
.mk__entries {
  display: grid;
  grid-template-columns: repeat(5, 1fr);
  gap: 8px;
}

.mk__entry {
  border-radius: $radius-md;
  padding: 14px 4px;
  text-align: center;
  cursor: pointer;
  transition: transform 0.15s ease;

  &:active { transform: scale(0.95); }

  &.is-primary { background: rgba(192, 0, 0, 0.06); color: $color-primary; }
  &.is-gold { background: rgba(212, 165, 116, 0.12); color: $color-gold-dark; }
  &.is-blue { background: rgba(25, 137, 250, 0.06); color: var(--color-blue); }
  &.is-green { background: rgba(7, 193, 96, 0.06); color: var(--color-success); }
}

.mk__entry-title {
  font-size: 13px;
  font-weight: 600;
  color: $color-text-primary;
  margin-top: 6px;
}

.mk__entry-desc { font-size: 10px; color: $color-text-tertiary; margin-top: 2px; }

.mk__stats {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 10px;
  text-align: center;
}

.mk__stat .price { font-size: 18px; }
.mk__stat .t-tertiary { font-size: 11px; margin-top: 2px; }

@media (max-width: 480px) {
  .mk__entries { grid-template-columns: repeat(3, 1fr); }
}
</style>
