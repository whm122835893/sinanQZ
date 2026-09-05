<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { getDashboard } from '@/api'
import StatCard from '@/components/StatCard.vue'
import MiniChart from '@/components/MiniChart.vue'
import { fmtMoney, fmtNumber } from '@/utils/format'

const router = useRouter()
const loading = ref(true)
const data = ref(null)

const pct = (today, yesterday) =>
  yesterday ? Number((((today - yesterday) / yesterday) * 100).toFixed(1)) : null

const stat = computed(() => data.value ? {
  gmvTrend: pct(data.value.todayGmv, data.value.yesterdayGmv),
  orderTrend: pct(data.value.todayOrders, data.value.yesterdayOrders),
  userTrend: pct(data.value.todayNewUsers, data.value.yesterdayNewUsers)
} : {})

const todos = computed(() => data.value ? [
  { label: '实名待审核', value: data.value.pendingRealname, to: '/user/realname', icon: 'shield-o', tone: 'gold' },
  { label: '退款待审批', value: data.value.pendingRefunds, to: '/order/refunds', icon: 'refund-o', tone: 'primary' },
  { label: '转赠待处理', value: data.value.pendingTransfers, to: '/transfer', icon: 'logistics', tone: 'blue' },
  { label: '异常订单', value: data.value.abnormalOrders, to: '/order', icon: 'warning-o', tone: 'green' }
] : [])

onMounted(async () => {
  const res = await getDashboard()
  data.value = res.data
  loading.value = false
})
</script>

<template>
  <div class="adm-page dash">
    <van-skeleton v-if="loading" title :row="6" style="padding: 16px" />
    <template v-else-if="data">
      <!-- 核心指标 -->
      <div class="adm-grid adm-grid--desktop-4 dash__stats">
        <StatCard icon="gold-coin-o" label="今日 GMV" :value="fmtMoney(data.todayGmv)" unit="元" :trend="stat.gmvTrend" tone="primary" />
        <StatCard icon="orders-o" label="今日订单" :value="fmtNumber(data.todayOrders)" unit="单" :trend="stat.orderTrend" tone="gold" />
        <StatCard icon="user-o" label="今日新增用户" :value="fmtNumber(data.todayNewUsers)" unit="人" :trend="stat.userTrend" tone="blue" />
        <StatCard icon="friends-o" label="累计用户" :value="fmtNumber(data.totalUsers)" unit="人" tone="green" />
      </div>

      <!-- 待办事项 -->
      <div class="adm-card dash__todos">
        <div class="adm-card__title">待办事项</div>
        <div class="dash__todo-grid">
          <div
            v-for="t in todos"
            :key="t.label"
            class="dash__todo"
            :class="`is-${t.tone}`"
            @click="router.push(t.to)"
          >
            <van-icon :name="t.icon" size="20" />
            <div class="dash__todo-num price">{{ t.value }}</div>
            <div class="dash__todo-label">{{ t.label }}</div>
          </div>
        </div>
      </div>

      <div class="adm-split">
        <div class="adm-card">
          <div class="adm-card__title">近 7 日 GMV 趋势<span class="t-tertiary" style="font-size:12px;font-weight:400">（元）</span></div>
          <MiniChart
            type="line"
            :labels="data.trend.map(t => t.date)"
            :values="data.trend.map(t => t.gmv)"
            :height="160"
          />
        </div>
        <div class="adm-card">
          <div class="adm-card__title">藏品分类销售占比</div>
          <MiniChart type="donut" :series="data.categoryShare" :height="160" />
        </div>
      </div>

      <div class="adm-split">
        <div class="adm-card">
          <div class="adm-card__title">近 7 日订单量</div>
          <MiniChart
            type="bar"
            :labels="data.trend.map(t => t.date)"
            :values="data.trend.map(t => t.orders)"
            color="#D4A574"
            :height="160"
          />
        </div>
        <div class="adm-card">
          <div class="adm-card__title">热销榜 TOP5</div>
          <div v-for="(t, i) in data.topCollectibles" :key="t.name" class="adm-item">
            <div class="dash__rank" :class="{ 'is-top': i < 3 }">{{ i + 1 }}</div>
            <img class="adm-item__thumb" :src="t.cover" :alt="t.name" />
            <div class="adm-item__body">
              <div class="adm-item__title">{{ t.name }}</div>
              <div class="adm-item__desc">已售 {{ t.sold }} 份</div>
            </div>
            <div class="adm-item__side">
              <div class="price" style="font-size:14px">¥{{ fmtNumber(t.amount) }}</div>
            </div>
          </div>
        </div>
      </div>

      <!-- 累计数据 -->
      <div class="adm-card">
        <div class="adm-card__title">平台累计</div>
        <div class="dash__total">
          <div class="dash__total-item">
            <div class="dash__total-value price">¥{{ fmtNumber(data.totalGmv) }}</div>
            <div class="dash__total-label">累计 GMV</div>
          </div>
          <div class="dash__total-item">
            <div class="dash__total-value price">{{ fmtNumber(data.totalUsers) }}</div>
            <div class="dash__total-label">累计用户</div>
          </div>
          <div class="dash__total-item">
            <div class="dash__total-value price">{{ fmtNumber(data.trend.reduce((s, t) => s + t.orders, 0)) }}</div>
            <div class="dash__total-label">近 7 日订单</div>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped lang="scss">
.dash__todo-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 8px;
}

.dash__todo {
  border-radius: $radius-md;
  padding: 12px 6px;
  text-align: center;
  cursor: pointer;
  transition: transform 0.15s ease;

  &:active { transform: scale(0.96); }

  &.is-primary { background: rgba(192, 0, 0, 0.06); color: $color-primary; }
  &.is-gold { background: rgba(212, 165, 116, 0.12); color: $color-gold-dark; }
  &.is-blue { background: rgba(25, 137, 250, 0.06); color: var(--color-blue); }
  &.is-green { background: rgba(7, 193, 96, 0.06); color: var(--color-success); }
}

.dash__todo-num {
  font-size: 20px;
  margin-top: 4px;
}

.dash__todo-label {
  font-size: 11px;
  color: $color-text-secondary;
  margin-top: 2px;
}

.dash__rank {
  width: 20px;
  height: 20px;
  border-radius: 6px;
  background: $color-surface;
  color: $color-text-tertiary;
  font-size: 11px;
  font-weight: 700;
  @include flex-center;
  flex-shrink: 0;

  &.is-top {
    background: var(--color-primary-bg);
    color: $color-primary;
  }
}

.dash__total {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 10px;
  text-align: center;
}

.dash__total-value { font-size: 18px; }

.dash__total-label {
  font-size: 11px;
  color: $color-text-tertiary;
  margin-top: 3px;
}

@media (max-width: 480px) {
  .dash__todo-grid { grid-template-columns: repeat(2, 1fr); }
}
</style>
