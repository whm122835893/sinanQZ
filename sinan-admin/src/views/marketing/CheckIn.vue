<script setup>
import { ref, onMounted } from 'vue'
import { showSuccessToast } from 'vant'
import { getCheckinConfig, toggleCheckin } from '@/api'
import StatusTag from '@/components/StatusTag.vue'
import { ACTIVITY_STATUS } from '@/utils/maps'
import { fmtNumber } from '@/utils/format'

const loading = ref(true)
const config = ref(null)

onMounted(async () => {
  const res = await getCheckinConfig()
  config.value = res.data
  loading.value = false
})

async function onToggle() {
  const res = await toggleCheckin()
  if (res.code === 0) {
    config.value.enabled = res.data
    showSuccessToast(res.data === 1 ? '已开启签到' : '已关闭签到')
  }
}
</script>

<template>
  <div class="adm-page ck">
    <van-skeleton v-if="loading" title :row="6" style="padding: 16px" />
    <template v-else-if="config">
      <!-- 开关与统计 -->
      <div class="adm-card">
        <div class="adm-card__title">
          签到功能
          <StatusTag :value="config.enabled === 1 ? 'enabled' : 'disabled'" :map="ACTIVITY_STATUS" />
        </div>
        <div class="ck__toggle">
          <div>
            <div class="ck__toggle-label">{{ config.enabled === 1 ? '签到功能已开启' : '签到功能已关闭' }}</div>
            <div class="t-tertiary" style="font-size: 11px; margin-top: 3px">关闭后 C 端签到入口隐藏，连续天数冻结</div>
          </div>
          <van-switch :model-value="config.enabled === 1" size="24px" @click="onToggle" />
        </div>
        <div class="ck__stats">
          <div class="ck__stat"><div class="price">{{ fmtNumber(config.todayCount) }}</div><div class="t-tertiary">今日签到</div></div>
          <div class="ck__stat"><div class="price">{{ fmtNumber(config.monthCount) }}</div><div class="t-tertiary">本月签到</div></div>
        </div>
      </div>

      <!-- 连续天数榜单 -->
      <div class="adm-card">
        <div class="adm-card__title">连续签到榜</div>
        <div v-for="(u, i) in config.streakTop" :key="u.nickname" class="adm-item">
          <div class="ck__rank" :class="{ 'is-top': i === 0 }">{{ i + 1 }}</div>
          <div class="adm-item__body">
            <div class="adm-item__title">{{ u.nickname }}</div>
          </div>
          <div class="adm-item__side"><span class="price">{{ u.streak }}</span> <span class="t-tertiary" style="font-size:11px">天</span></div>
        </div>
      </div>

      <!-- 奖励规则 -->
      <div class="adm-card">
        <div class="adm-card__title">奖励规则</div>
        <div class="ck__rules">
          <div v-for="r in config.rules" :key="r.day" class="ck__rule">
            <div class="ck__rule-day">第 {{ r.day }} 天</div>
            <van-tag :type="r.type === 'collectible' ? 'primary' : 'warning'" plain round size="medium">
              {{ r.type === 'collectible' ? '藏品' : '司南币' }}
            </van-tag>
            <div class="ck__rule-reward">{{ r.label }}</div>
          </div>
        </div>
        <van-notice-bar
          left-icon="info-o"
          text="修改奖励规则后立即生效，历史签到记录不受影响（联调后由后端审计变更）。"
          style="margin-top: 12px"
        />
      </div>
    </template>
  </div>
</template>

<style scoped lang="scss">
.ck__toggle {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.ck__toggle-label { font-size: 14px; font-weight: 600; }

.ck__stats {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 10px;
  margin-top: 14px;
  text-align: center;
}

.ck__stat .price { font-size: 20px; }
.ck__stat .t-tertiary { font-size: 11px; margin-top: 2px; }

.ck__rank {
  width: 20px;
  height: 20px;
  border-radius: 6px;
  background: $color-surface;
  color: $color-text-tertiary;
  font-size: 11px;
  font-weight: 700;
  @include flex-center;
  flex-shrink: 0;

  &.is-top { background: var(--color-primary-bg); color: $color-primary; }
}

.ck__rules {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 8px;
}

.ck__rule {
  border: 1px solid $color-border;
  border-radius: $radius-md;
  padding: 10px 8px;
  text-align: center;
}

.ck__rule-day {
  font-size: 12px;
  color: $color-text-secondary;
  font-weight: 600;
}

.ck__rule-reward {
  font-size: 13px;
  font-weight: 700;
  margin-top: 6px;
  color: $color-text-primary;
}
</style>
