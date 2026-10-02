<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import AppNavBar from '@/components/AppNavBar.vue'
import AppTag from '@/components/AppTag.vue'
import { useCollectionStore } from '@/stores/collection'

// 发售日历语义：历史发售记录 + 即将发售提醒（倒计时）
// 数据源为专属接口 /api/collections/calendar（含售罄历史），深链直接进入也会自行拉取
const store = useCollectionStore()
const loading = ref(true)
const now = ref(Date.now())
const timer = setInterval(() => { now.value = Date.now() }, 1000)
onUnmounted(() => clearInterval(timer))

onMounted(async () => {
  try { await store.fetchCalendar() } finally { loading.value = false }
})

function formatDate(ts) {
  if (!ts) return ''
  const d = new Date(ts)
  const pad = (n) => String(n).padStart(2, '0')
  return d.getFullYear() + '年' + pad(d.getMonth() + 1) + '月' + pad(d.getDate()) + '日 ' + pad(d.getHours()) + ':' + pad(d.getMinutes())
}

// 状态：countdown 待发售 | selling 发售中 | soldout 已售罄 | ended 已结束（后两类进历史）
function statusOf(item) {
  if (item.soldOut || item.stock <= 0) return 'soldout'
  if (item.saleTime && now.value < item.saleTime) return 'countdown'
  if (item.saleEndTime && now.value >= item.saleEndTime) return 'ended'
  return 'selling'
}
const STATUS_MAP = { countdown: '待发售', selling: '发售中', soldout: '已售罄', ended: '已结束' }

// 距开售倒计时（仅待发售条目）
function countdownText(item) {
  const diff = item.saleTime - now.value
  if (diff <= 0) return ''
  const d = Math.floor(diff / 86400000)
  const h = Math.floor((diff % 86400000) / 3600000)
  const m = Math.floor((diff % 3600000) / 60000)
  const s = Math.floor((diff % 60000) / 1000)
  const pad = (n) => String(n).padStart(2, '0')
  return (d > 0 ? d + '天 ' : '') + pad(h) + ':' + pad(m) + ':' + pad(s)
}

function toRow(item) {
  const st = statusOf(item)
  return {
    time: formatDate(item.saleTime),
    title: item.name,
    count: item.total,
    price: item.price,
    coverImage: item.coverImage,
    status: STATUS_MAP[st],
    countdown: st === 'countdown' ? countdownText(item) : ''
  }
}

// 即将发售（含发售中）按开售时间升序，最近的排前作为提醒；历史按时间倒序
const upcoming = computed(() =>
  store.calendar
    .filter((i) => ['countdown', 'selling'].includes(statusOf(i)))
    .slice()
    .sort((a, b) => (a.saleTime || 0) - (b.saleTime || 0))
    .map(toRow)
)
const history = computed(() =>
  store.calendar
    .filter((i) => ['soldout', 'ended'].includes(statusOf(i)))
    .slice()
    .sort((a, b) => (b.saleTime || 0) - (a.saleTime || 0))
    .map(toRow)
)

function renderTimeline(items) {
  return items.map((item, i) => ({ ...item, last: i === items.length - 1 }))
}
const upcomingRows = computed(() => renderTimeline(upcoming.value))
const historyRows = computed(() => renderTimeline(history.value))
</script>

<template>
  <div class="calendar page--no-tabbar">
    <AppNavBar title="首发日历" @click-left="$router.back()">
      <template #right>
        <img class="cal-logo-img" src="/images/platform-logo.png" alt="" />
      </template>
    </AppNavBar>

    <p class="calendar-sub">独家发售，先到先得</p>

    <p v-if="loading" class="calendar-empty">加载中…</p>
    <template v-else>
      <!-- 即将发售提醒（倒计时 + 发售中） -->
      <section v-if="upcomingRows.length">
        <h2 class="calendar-section">即将发售</h2>
        <ul class="timeline">
          <li v-for="(item, i) in upcomingRows" :key="i" class="timeline__item">
            <div class="timeline__axis">
              <span class="timeline__dot timeline__dot--live"></span>
              <span v-if="!item.last" class="timeline__line"></span>
            </div>
            <div class="timeline__content">
              <div class="timeline__time">
                <span>{{ item.time }}</span>
                <AppTag>{{ item.status }}</AppTag>
                <span v-if="item.countdown" class="timeline__countdown">距开售 {{ item.countdown }}</span>
              </div>
              <div class="timeline__card">
                <img class="timeline__thumb" :src="item.coverImage" alt="" draggable="false" @contextmenu.prevent @click.prevent />
                <div class="timeline__info">
                  <span class="timeline__title">{{ item.title }}</span>
                  <div class="timeline__meta">
                    <span>发售数量 <b>{{ item.count }}</b></span>
                    <span>发售价格 <b class="price">¥{{ item.price }}</b></span>
                  </div>
                </div>
              </div>
            </div>
          </li>
        </ul>
      </section>

      <!-- 历史发售记录 -->
      <section v-if="historyRows.length">
        <h2 class="calendar-section">历史发售</h2>
        <ul class="timeline">
          <li v-for="(item, i) in historyRows" :key="i" class="timeline__item">
            <div class="timeline__axis">
              <span class="timeline__dot"></span>
              <span v-if="!item.last" class="timeline__line"></span>
            </div>
            <div class="timeline__content">
              <div class="timeline__time">
                <span>{{ item.time }}</span>
                <AppTag type="gray">{{ item.status }}</AppTag>
              </div>
              <div class="timeline__card">
                <img class="timeline__thumb" :src="item.coverImage" alt="" draggable="false" @contextmenu.prevent @click.prevent />
                <div class="timeline__info">
                  <span class="timeline__title">{{ item.title }}</span>
                  <div class="timeline__meta">
                    <span>发售数量 <b>{{ item.count }}</b></span>
                    <span>发售价格 <b class="price">¥{{ item.price }}</b></span>
                  </div>
                </div>
              </div>
            </div>
          </li>
        </ul>
      </section>

      <p v-if="!upcomingRows.length && !historyRows.length" class="calendar-empty">暂无发售记录</p>
    </template>
  </div>
</template>

<style scoped lang="scss">
.cal-logo-img { width: 28px; height: 28px; border-radius: 6px; object-fit: cover; -webkit-user-drag: none; -webkit-touch-callout: none; user-select: none; pointer-events: none; }
.calendar-sub { margin: 14px $page-padding; font-size: 14px; color: $color-text-secondary; }
.calendar-section { margin: 18px $page-padding 10px; font-size: 16px; font-weight: 700; color: $color-text-primary; }
.calendar-empty { margin: 40px $page-padding; text-align: center; font-size: 14px; color: $color-text-tertiary; }

.timeline { padding: 0 $page-padding 24px; }
.timeline__item { display: flex; gap: 12px; }
.timeline__axis { display: flex; flex-direction: column; align-items: center; padding-top: 4px; }
.timeline__dot {
  width: 12px; height: 12px; border-radius: 50%; border: 2px solid $color-text-tertiary; background: #fff; flex-shrink: 0;
}
.timeline__dot--live { border-color: $color-primary; }
.timeline__line { width: 2px; flex: 1; background: $color-border; margin: 4px 0; }
.timeline__content { flex: 1; padding-bottom: 16px; }
.timeline__time { display: flex; align-items: center; gap: 10px; font-size: 14px; color: $color-text-secondary; margin-bottom: 10px; }
.timeline__countdown { font-size: 12px; color: $color-primary; font-variant-numeric: tabular-nums; }
.timeline__card {
  display: flex; gap: 12px; background: $color-card; border-radius: $radius-lg; padding: 16px;
}
.timeline__thumb {
  width: 56px; height: 56px; border-radius: 8px; object-fit: cover; flex-shrink: 0;
  -webkit-user-drag: none; -webkit-touch-callout: none; user-select: none; pointer-events: none;
}
.timeline__info { flex: 1; display: flex; flex-direction: column; gap: 8px; }
.timeline__title { font-size: 16px; font-weight: 700; color: $color-text-primary; }
.timeline__meta { display: flex; gap: 16px; font-size: 13px; color: $color-text-secondary; b { color: $color-text-primary; font-weight: 600; } }
</style>
