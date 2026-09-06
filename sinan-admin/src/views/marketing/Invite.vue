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
    <el-skeleton v-if="loading" :rows="8" animated style="padding: 20px" />
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
          <div class="iv__stat">
            <div class="price">{{ fmtNumber(activity.stats.invitedCount) }}</div>
            <div class="t-tertiary">累计邀请</div>
          </div>
          <div class="iv__stat">
            <div class="price">{{ fmtNumber(activity.stats.registerCount) }}</div>
            <div class="t-tertiary">注册成功</div>
          </div>
          <div class="iv__stat">
            <div class="price">{{ fmtNumber(activity.stats.rewardIssued) }}</div>
            <div class="t-tertiary">奖励发放</div>
          </div>
        </div>
      </div>

      <!-- 双方奖励配置 -->
      <div class="iv__rewards">
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
        <el-table :data="activity.records">
          <el-table-column label="邀请人" min-width="120">
            <template #default="{ row }">{{ row.inviter }}</template>
          </el-table-column>
          <el-table-column label="被邀请人" min-width="120">
            <template #default="{ row }">{{ row.invitee }}</template>
          </el-table-column>
          <el-table-column label="奖励内容" min-width="140">
            <template #default="{ row }">
              <el-tag type="success" effect="plain" size="small">{{ row.reward }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="发放状态" width="100" align="center">
            <template #default="{ row }">
              <el-tag type="success" effect="plain" size="small">已发放</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="时间" prop="time" width="150" />
        </el-table>
        <el-alert
          type="info"
          :closable="false"
          show-icon
          class="iv__tip"
          title="奖励发放时动态校验藏品配额预留 / 盲盒库存池，不足则挂起待补发并生成异常日志，禁止超发"
        />
      </div>
    </template>
  </div>
</template>

<style scoped lang="scss">
.iv__stats {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 10px;
  margin-top: 14px;
  text-align: center;
}

.iv__stat {
  padding: 14px 0;
  border-radius: 8px;
  background: $color-surface;
}

.iv__stat .price { font-size: 20px; }
.iv__stat .t-tertiary { font-size: 11px; margin-top: 2px; }

.iv__rewards {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 14px;

  @media (max-width: 768px) {
    grid-template-columns: 1fr;
  }
}

.iv__reward {
  display: flex;
  align-items: center;
  gap: 12px;

  img {
    width: 64px;
    height: 64px;
    border-radius: 8px;
    object-fit: cover;
    flex-shrink: 0;
    background: $color-surface;
  }
}

.iv__reward-name { font-size: 14px; font-weight: 600; }
.iv__tip { margin-top: 10px; }
</style>
