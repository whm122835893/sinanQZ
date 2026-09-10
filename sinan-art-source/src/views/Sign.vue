<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useUserStore } from '@/stores/user'
import { useLoginGate } from '@/utils/loginGate'
import { showToast } from 'vant'
import AppNavBar from '@/components/AppNavBar.vue'

const route = useRoute()
const store = useUserStore()
const { requireLogin } = useLoginGate()

const signed = computed(() => store.todaySigned)
const records = computed(() => store.signState.records)
const signedSet = computed(() => new Set(store.signState.records.map(r => r.date)))

const weekMap = ['日', '一', '二', '三', '四', '五', '六']
function fmtDateCN(dateStr) {
  const [y, m, d] = dateStr.split('-').map(Number)
  const dt = new Date(y, m - 1, d)
  return `${m}月${d}日 · 周${weekMap[dt.getDay()]}`
}

// 当月签到日历
const now = new Date()
const calYear = now.getFullYear()
const calMonth = now.getMonth() + 1
const pad2 = (n) => String(n).padStart(2, '0')
const todayStr = `${calYear}-${pad2(calMonth)}-${pad2(now.getDate())}`

const calendar = computed(() => {
  const y = now.getFullYear()
  const m = now.getMonth()
  const lead = new Date(y, m, 1).getDay() // 当月 1 号周几（0=周日）
  const days = new Date(y, m + 1, 0).getDate()
  const cells = []
  for (let i = 0; i < lead; i++) cells.push(null)
  for (let d = 1; d <= days; d++) {
    cells.push({ d, dateStr: `${y}-${pad2(m + 1)}-${pad2(d)}` })
  }
  return cells
})

function isCounted(dateStr) { return signedSet.value.has(dateStr) }
function isToday(dateStr) { return dateStr === todayStr }

function onSign() {
  if (!requireLogin(route.fullPath)) return
  if (signed.value) {
    showToast('今日已签到，明天再来吧')
    return
  }
  store.doSign().then((r) => {
    if (r.already) { showToast('今日已签到'); return }
    showToast('签到成功')
  })
}
</script>

<template>
  <div class="sign page--no-tabbar">
    <AppNavBar title="每日签到" @click-left="$router.back()" />

    <!-- 顶部签到卡 -->
    <section class="sign-hero">
      <p class="sign-hero__date">{{ new Date().getFullYear() }} 年 {{ new Date().getMonth() + 1 }} 月 {{ new Date().getDate() }} 日</p>
      <p class="sign-hero__title">{{ signed ? '今日已签到' : '今日尚未签到' }}</p>
      <div class="sign-hero__streak">
        <span class="sign-hero__num">{{ store.signState.day }}</span>
        <span class="sign-hero__unit">已连续签到 天</span>
      </div>
      <p class="sign-hero__tip">坚持每日签到，奖励由平台发放</p>
    </section>

    <!-- 签到日历 -->
    <section class="sign-cal">
      <div class="sign-cal__head">
        <span>签到日历</span>
        <span class="sign-cal__month">{{ calYear }} 年 {{ calMonth }} 月</span>
      </div>
      <div class="sign-cal__weeks">
        <span v-for="(w, i) in weekMap" :key="i">{{ w }}</span>
      </div>
      <div class="sign-cal__grid">
        <span
          v-for="(cell, i) in calendar"
          :key="i"
          class="sign-cal__cell"
          :class="{ empty: !cell, counted: cell && isCounted(cell.dateStr), today: cell && isToday(cell.dateStr) }"
        >{{ cell ? cell.d : '' }}</span>
      </div>
      <div class="sign-cal__legend">
        <span><i class="dot dot--counted"></i>已签到</span>
        <span><i class="dot dot--today"></i>今日</span>
      </div>
    </section>

    <!-- 签到按钮 -->
    <button class="sign-btn" :class="{ 'is-signed': signed }" :disabled="signed" @click="onSign">
      {{ signed ? '今日已签到' : '立即签到' }}
    </button>
    <p class="sign-btn__hint" v-if="!signed">每日 00:00 后可再次签到</p>

    <!-- 签到记录 -->
    <section class="sign-records">
      <div class="sign-records__head">
        <span>签到记录</span>
      </div>
      <div v-if="records.length" class="sign-records__list">
        <div v-for="(r, i) in records" :key="i" class="sign-record">
          <span class="sign-record__dot"></span>
          <span class="sign-record__date">{{ fmtDateCN(r.date) }}</span>
        </div>
      </div>
      <p v-else class="sign-records__empty">暂无签到记录，今天开始打卡吧～</p>
    </section>

    <p class="sign-rule">签到规则：每天可签到 1 次，连续签到天数次日清零重计；签到奖励由平台管理员后台统一配置后发放。</p>
  </div>
</template>

<style scoped lang="scss">
.sign {
  min-height: 100vh;
  background:
    radial-gradient(120% 50% at 50% 0%, rgba(192, 0, 0, 0.12), rgba(192, 0, 0, 0) 60%),
    $color-bg;
}

/* 顶部签到卡 */
.sign-hero {
  margin: 14px $page-padding 0;
  padding: 24px 18px;
  border-radius: $radius-lg;
  background:
    linear-gradient(135deg, rgba(208, 0, 0, 0.16), rgba(208, 0, 0, 0) 55%),
    $color-card;
  box-shadow: 0 8px 22px rgba(0, 0, 0, 0.12);
  text-align: center;
  &__date { margin: 0; font-size: 12px; color: $color-text-tertiary; }
  &__title { margin: 8px 0 18px; font-size: 18px; font-weight: 700; color: $color-text-primary; }
  &__streak { display: flex; align-items: baseline; justify-content: center; gap: 8px; }
  &__num {
    font-size: 56px; font-weight: 800; color: $color-primary; font-family: $font-price; line-height: 1;
  }
  &__unit { font-size: 14px; color: $color-text-secondary; }
  &__tip { margin: 14px 0 0; font-size: 12px; color: $color-text-tertiary; }
}

/* 签到按钮 */
.sign-btn {
  width: calc(100% - #{$page-padding} * 2);
  margin: $page-padding auto 0;
  height: 50px; border: none; border-radius: $radius-pill;
  background: linear-gradient(135deg, #D00000, #B00000); color: #fff;
  font-size: 16px; font-weight: 700; cursor: pointer;
  box-shadow: 0 6px 16px rgba(176, 0, 0, 0.35);
  display: block;
  &.is-signed {
    background: #d9d9d9; box-shadow: none; color: #fff; cursor: default;
  }
}
.sign-btn__hint { margin: 10px $page-padding 0; font-size: 11px; color: $color-text-tertiary; text-align: center; }

/* 签到日历 */
.sign-cal {
  margin: $page-padding $page-padding 0;
  background: $color-card; border-radius: $radius-lg; padding: 14px;
  &__head {
    display: flex; align-items: center; justify-content: space-between;
    font-size: 14px; font-weight: 700; color: $color-text-primary;
    padding-bottom: 12px;
  }
  &__month { font-size: 12px; font-weight: 400; color: $color-text-tertiary; }
  &__weeks {
    display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px;
    span { font-size: 12px; color: $color-text-tertiary; text-align: center; padding: 4px 0; }
  }
  &__grid {
    display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; margin-top: 6px;
  }
  &__cell {
    aspect-ratio: 1 / 1;
    display: flex; align-items: center; justify-content: center;
    font-size: 13px; color: $color-text-primary;
    border-radius: 8px; background: $color-surface;
    &.empty { background: transparent; }
    &.counted {
      background: linear-gradient(135deg, #D00000, #B00000);
      color: #fff; font-weight: 700;
      border: 1px solid #B00000;
    }
    &.today {
      box-shadow: 0 0 0 2px $color-primary inset;
      color: $color-primary; font-weight: 700;
    }
  }
  &__legend {
    display: flex; gap: 18px; justify-content: flex-end;
    margin-top: 12px; padding-top: 10px; border-top: 1px solid $color-border;
    span { display: flex; align-items: center; gap: 5px; font-size: 11px; color: $color-text-secondary; }
    .dot { width: 8px; height: 8px; border-radius: 50%; &--counted { background: linear-gradient(135deg, #D00000, #B00000); } &--today { background: #fff; box-shadow: 0 0 0 1.5px $color-primary; } }
  }
}

/* 签到记录 */
.sign-records {
  margin: $page-padding $page-padding 0;
  background: $color-card; border-radius: $radius-lg; padding: 14px 14px 6px;
  &__head {
    display: flex; align-items: center; justify-content: space-between;
    font-size: 14px; font-weight: 700; color: $color-text-primary;
    padding-bottom: 10px; border-bottom: 1px solid $color-border;
  }
  &__list { max-height: 260px; overflow-y: auto; }
}
.sign-record {
  display: flex; align-items: center; gap: 8px;
  padding: 11px 0; border-bottom: 1px solid $color-border;
  &:last-child { border-bottom: none; }
  &__dot { width: 6px; height: 6px; border-radius: 50%; background: $color-primary; flex-shrink: 0; }
  &__date { flex: 1; font-size: 13px; color: $color-text-primary; }
}
.sign-records__empty {
  margin: 14px 0; font-size: 13px; color: $color-text-tertiary; text-align: center;
}
.sign-rule {
  margin: $page-padding $page-padding 24px;
  font-size: 12px; color: $color-text-tertiary; line-height: 1.6;
}
</style>