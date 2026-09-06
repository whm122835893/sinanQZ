<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { Calendar, Trophy, MagicStick, Share, Timer, Key, ArrowRight } from '@element-plus/icons-vue'
import { getCheckinConfig, getLuckyDraws, getSynthesisList, getInviteActivity, getPrioritySales } from '@/api'
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
  { to: '/marketing/checkin', icon: Calendar, title: '签到配置', desc: '连续签到奖励', tone: 'primary' },
  { to: '/marketing/luckydraw', icon: Trophy, title: '抽奖活动', desc: '转盘奖池管理', tone: 'gold' },
  { to: '/marketing/synthesis', icon: MagicStick, title: '合成活动', desc: '材料合成玩法', tone: 'blue' },
  { to: '/marketing/invite', icon: Share, title: '邀请活动', desc: '邀友注册奖励', tone: 'green' },
  { to: '/marketing/priority', icon: Timer, title: '优先购管理', desc: '白名单资格', tone: 'primary' },
  { to: '/marketing/qualification', icon: Key, title: '资格购管理', desc: '购买门槛配置', tone: 'gold' }
]

function statOf(entry) {
  if (!checkin.value) return ''
  switch (entry.to) {
    case '/marketing/checkin':
      return checkin.value.enabled === 1 ? `今日 ${fmtNumber(checkin.value.todayCount)} 人签到` : '已停用'
    case '/marketing/luckydraw': {
      const on = lucky.value.filter((a) => a.status === 'enabled').length
      return `${on} 个进行中`
    }
    case '/marketing/synthesis': {
      const on = synthesis.value.filter((a) => a.status === 'enabled').length
      return `${on} 个进行中`
    }
    case '/marketing/invite':
      return `累计邀请 ${fmtNumber(invite.value.stats.invitedCount)} 人`
    case '/marketing/priority':
      return `${priority.value.length} 个活动`
    case '/marketing/qualification':
      return '门槛 / 白名单'
    default:
      return ''
  }
}
</script>

<template>
  <div class="adm-page mk">
    <el-skeleton v-if="loading" :rows="6" animated style="padding: 20px" />
    <template v-else>
      <!-- 功能入口 -->
      <div class="mk__grid">
        <div v-for="e in entries" :key="e.to" class="mk__entry adm-card" @click="router.push(e.to)">
          <div class="mk__icon" :class="`is-${e.tone}`">
            <el-icon :size="22"><component :is="e.icon" /></el-icon>
          </div>
          <div class="mk__body">
            <div class="mk__title">{{ e.title }}</div>
            <div class="mk__desc">{{ e.desc }}</div>
            <div class="mk__stat">{{ statOf(e) }}</div>
          </div>
          <el-icon class="mk__arrow"><ArrowRight /></el-icon>
        </div>
      </div>

      <!-- 奖励类型说明 -->
      <div class="adm-card">
        <div class="adm-card__title">统一奖励类型（全平台活动奖励同构）</div>
        <div class="mk__types">
          <div class="mk__type">
            <el-tag type="primary" effect="plain">藏品</el-tag>
            <span class="mk__type-desc">选择藏品 + 数量（计划上限，发放时动态校验配额预留）</span>
          </div>
          <div class="mk__type">
            <el-tag type="info" effect="plain">优先购白名单资格</el-tag>
            <span class="mk__type-desc">选择优先购活动 + 资格数量 + 有效期</span>
          </div>
          <div class="mk__type">
            <el-tag type="info" effect="plain">资格购资格</el-tag>
            <span class="mk__type-desc">选择目标藏品 + 有效期（精确到时分秒）</span>
          </div>
          <div class="mk__type">
            <el-tag type="success" effect="plain">抽奖次数</el-tag>
            <span class="mk__type-desc">输入次数</span>
          </div>
          <div class="mk__type">
            <el-tag type="warning" effect="plain">司南币</el-tag>
            <span class="mk__type-desc">输入金额（钱包流水入账）</span>
          </div>
          <div class="mk__type">
            <el-tag type="danger" effect="plain">盲盒</el-tag>
            <span class="mk__type-desc">选择盲盒 + 数量（计划上限，发放时动态校验盲盒库存池）</span>
          </div>
        </div>
        <el-alert
          type="info"
          :closable="false"
          show-icon
          class="mk__tip"
          title="所有批量发放生成完整发放记录台账，支持按活动 / 用户 / 时间筛选导出；奖励发放时动态校验库存（配额预留 / 盲盒库存池），不足则拦截提示「配额预留不足，当前剩余 X 份」"
        />
      </div>
    </template>
  </div>
</template>

<style scoped lang="scss">
.mk__grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 14px;

  @media (max-width: 992px) {
    grid-template-columns: repeat(2, 1fr);
  }

  @media (max-width: 640px) {
    grid-template-columns: 1fr;
  }
}

.mk__entry {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 18px;
  cursor: pointer;
  transition: transform 0.15s, box-shadow 0.15s;

  &:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08);
  }
}

.mk__icon {
  width: 46px;
  height: 46px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;

  &.is-primary { background: $color-primary-bg; color: $color-primary; }
  &.is-gold { background: rgba(212, 165, 116, 0.15); color: var(--color-gold-dark); }
  &.is-blue { background: rgba(25, 137, 250, 0.1); color: var(--color-blue); }
  &.is-green { background: rgba(7, 193, 96, 0.1); color: var(--color-success); }
}

.mk__body { flex: 1; min-width: 0; }

.mk__title {
  font-size: 15px;
  font-weight: 600;
  color: $color-text-primary;
}

.mk__desc {
  font-size: 12px;
  color: $color-text-secondary;
  margin-top: 2px;
}

.mk__stat {
  font-size: 11px;
  color: $color-text-tertiary;
  margin-top: 4px;
}

.mk__arrow {
  color: $color-text-tertiary;
  flex-shrink: 0;
}

.mk__types {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 10px;

  @media (max-width: 768px) {
    grid-template-columns: 1fr;
  }
}

.mk__type {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 12px;
  border-radius: 8px;
  background: $color-surface;
}

.mk__type-desc {
  font-size: 12px;
  color: $color-text-secondary;
}

.mk__tip { margin-top: 12px; }
</style>
