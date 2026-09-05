<script setup>
import { ref, onMounted } from 'vue'
import { getInviteActivity } from '@/api'
import StatusTag from '@/components/StatusTag.vue'
import { ACTIVITY_STATUS } from '@/utils/maps'
import { fmtNumber } from '@/utils/format'

const loading = ref(true)
const activity = ref(null)

onMounted(async () => {
  const res = await getInviteActivity()
  activity.value = res.data
  loading.value = false
})
</script>

<template>
  <div class="adm-page iv">
    <van-skeleton v-if="loading" title :row="6" style="padding: 16px" />
    <template v-else-if="activity">
      <!-- 活动概览 -->
      <div class="adm-card">
        <div class="adm-card__title">
          {{ activity.name }}
          <StatusTag :value="activity.status" :map="ACTIVITY_STATUS" />
        </div>
        <div class="adm-kv"><span class="k">发放模式</span><span class="v">{{ activity.mode === 'realtime' ? '实时发放' : '次日发放' }}</span></div>
        <div class="adm-kv"><span class="k">开始时间</span><span class="v">{{ activity.startTime }}</span></div>

        <div class="iv__stats">
          <div class="iv__stat"><div class="price">{{ fmtNumber(activity.stats.invitedCount) }}</div><div class="t-tertiary">累计邀请</div></div>
          <div class="iv__stat"><div class="price">{{ fmtNumber(activity.stats.registerCount) }}</div><div class="t-tertiary">注册成功</div></div>
          <div class="iv__stat"><div class="price">{{ fmtNumber(activity.stats.rewardIssued) }}</div><div class="t-tertiary">奖励发放</div></div>
        </div>
      </div>

      <!-- 奖励配置 -->
      <div class="adm-split">
        <div class="adm-card">
          <div class="adm-card__title">邀请方奖励</div>
          <div class="iv__reward">
            <img :src="activity.inviterReward.cover" :alt="activity.inviterReward.name" />
            <div>
              <div class="iv__reward-name">{{ activity.inviterReward.name }}</div>
              <div class="t-tertiary" style="font-size: 12px">每成功邀请 1 人 ×{{ activity.inviterReward.quantity }}</div>
            </div>
          </div>
        </div>
        <div class="adm-card">
          <div class="adm-card__title">被邀请方奖励</div>
          <div class="iv__reward">
            <img :src="activity.inviteeReward.cover" :alt="activity.inviteeReward.name" />
            <div>
              <div class="iv__reward-name">{{ activity.inviteeReward.name }}</div>
              <div class="t-tertiary" style="font-size: 12px">注册并实名后 ×{{ activity.inviteeReward.quantity }}</div>
            </div>
          </div>
        </div>
      </div>

      <!-- 邀请记录 -->
      <div class="adm-card">
        <div class="adm-card__title">邀请记录</div>
        <div v-for="r in activity.records" :key="r.id" class="adm-item">
          <div class="adm-item__body">
            <div class="adm-item__title">{{ r.inviter }} → {{ r.invitee }}</div>
            <div class="adm-item__desc">{{ r.time }}</div>
          </div>
          <div class="adm-item__side">
            <van-tag type="success" plain round size="medium">{{ r.reward }}</van-tag>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped lang="scss">
.iv__stats {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 10px;
  margin-top: 12px;
  text-align: center;
}

.iv__stat .price { font-size: 20px; }
.iv__stat .t-tertiary { font-size: 11px; margin-top: 2px; }

.iv__reward {
  display: flex;
  align-items: center;
  gap: 12px;

  img {
    width: 64px;
    height: 64px;
    border-radius: $radius-md;
    object-fit: cover;
    flex-shrink: 0;
    background: $color-surface;
  }
}

.iv__reward-name { font-size: 14px; font-weight: 600; }
</style>
