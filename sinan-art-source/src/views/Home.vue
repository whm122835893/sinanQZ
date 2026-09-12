<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { showToast } from 'vant'
import request from '@/utils/request'
import { useCollectionStore } from '@/stores/collection'
import { useIconThemeStore } from '@/stores/iconTheme'
import { useSiteStore } from '@/stores/site'
import { useLoginGate } from '@/utils/loginGate'
import AppButton from '@/components/AppButton.vue'
import AppIcon from '@/components/AppIcon.vue'

const route = useRoute()
const router = useRouter()
const store = useCollectionStore()
const iconTheme = useIconThemeStore()
const site = useSiteStore()
const { requireLogin } = useLoginGate()
const featured = store.featured

// 功能入口图标（跟随当前主题动态切换）
const calendarIcon = computed(() => iconTheme.getFeatureIcon('calendar'))
const activityIcon = computed(() => iconTheme.getFeatureIcon('activity'))
const lotteryIcon  = computed(() => iconTheme.getFeatureIcon('lottery'))

// 发售倒计时：每秒刷新状态
const now = ref(Date.now())
let saleTimer = null
const STATUS_TEXT = { selling: '发售中', soldout: '已售罄' }

// 首发日历：动态日期，午夜自动更新
const today = ref(new Date())
const calendarDay = computed(() => today.value.getDate())
const calendarYm  = computed(() => {
  const y = today.value.getFullYear()
  const m = String(today.value.getMonth() + 1).padStart(2, '0')
  return `${y}/${m}`
})
let midnightTimer = null
function scheduleMidnightUpdate() {
  const now = new Date()
  const next = new Date(now.getFullYear(), now.getMonth(), now.getDate() + 1, 0, 0, 1)
  const ms = next - now
  midnightTimer = setTimeout(() => {
    today.value = new Date()
    scheduleMidnightUpdate()
  }, ms)
}

const featuredWithStatus = computed(() => {
  now.value  // touch for reactivity
  return store.featured.map(item => ({
    ...item,
    status: store.getSaleStatus(item),
    saleTimeText: formatSaleTime(item.saleTime)
  }))
})

// 抽签发售（混入藏品发售区：卡片大小与正常藏品一致，左上角角标"抽签购"，点击进抽签详情）
const raffleSales = ref([])
const RAFFLE_PHASE_TEXT = {
  upcoming: '抽签未开始',
  registering: '抽签进行中',
  drawing: '开签中',
  drawn: '已开奖',
  finished: '已结束'
}
async function fetchRaffleSales() {
  try {
    const res = await request.get('/raffle/activities', { params: { page: 1, pageSize: 50 } })
    raffleSales.value = (res.list || []).map((a) => ({
      id: String(a.activityId),
      name: a.collectible?.name || a.name,
      coverImage: a.collectible?.image || '/images/platform-logo.png',
      price: Number(a.salePrice).toFixed(2),
      total: `${a.saleQuantity}份`,
      tag: '抽签购',
      type: 'raffle',
      phase: a.phase
    }))
  } catch { /* 抽签活动拉取失败静默，不影响藏品发售 */ }
}

// 首页"藏品发售"区：正常发售藏品 + 抽签发售
const releaseItems = computed(() => [...featuredWithStatus.value, ...raffleSales.value])

// 发售时间文案：2026.12.09  17:00
function formatSaleTime(ts) {
  if (!ts) return ''
  const d = new Date(ts)
  const p = (n) => String(n).padStart(2, '0')
  return `${d.getFullYear()}.${p(d.getMonth() + 1)}.${p(d.getDate())}  ${p(d.getHours())}:${p(d.getMinutes())}`
}

// 背景轮播：横向无缝滚动 + 手势拖动（真实接口：GET /api/banners，失败兜底本地图）
const slides = ref([
  '/images/hero/slide-1.jpg',
  '/images/hero/slide-2.jpg',
  '/images/hero/slide-3.jpg'
])
const n = computed(() => slides.value.length)
const renderSlides = computed(() => [...slides.value, slides.value[0]]) // 末尾追加首图副本，向左无缝循环
const pos = ref(0)              // 连续位移（单位：张），0..n
const transitionOn = ref(true)  // 是否启用过渡动画
const activeIndex = computed(() => n.value ? Math.round(pos.value) % n.value : 0)
const bgRef = ref(null)
let timer = null

const trackStyle = computed(() => ({
  transform: `translate3d(${-pos.value * 100}%, 0, 0)`,
  transition: transitionOn.value ? 'transform 0.5s ease' : 'none'
}))

const RESET_MS = 520 // 略大于过渡时长，确保滑到副本后再无感复位
function startAuto() { stopAuto(); timer = setInterval(autoNext, 3500) }
function stopAuto() { if (timer) { clearInterval(timer); timer = null } }
function autoNext() {
  pos.value = Math.round(pos.value) + 1
  // 滚到末尾副本（=首图视觉）后，在过渡结束瞬间无感复位到 0，向左无限循环、不跳变
  if (pos.value >= n.value) {
    setTimeout(() => {
      transitionOn.value = false
      pos.value = 0
      requestAnimationFrame(() => { transitionOn.value = true })
    }, RESET_MS)
  }
}

// 手势拖动
let dragging = false
let startX = 0
let startY = 0
let startPos = 0
let lockDir = null
function vpWidth() { return bgRef.value ? bgRef.value.clientWidth : 375 }

function onTouchStart(e) {
  dragging = true
  stopAuto()
  transitionOn.value = false
  startX = e.touches[0].clientX
  startY = e.touches[0].clientY
  startPos = pos.value
  lockDir = null
}
function onTouchMove(e) {
  if (!dragging) return
  const dx = e.touches[0].clientX - startX
  const dy = e.touches[0].clientY - startY
  if (lockDir === null) {
    if (Math.abs(dx) > Math.abs(dy)) lockDir = 'h'
    else if (Math.abs(dy) > Math.abs(dx)) { dragging = false; transitionOn.value = true; return }
  }
  if (lockDir !== 'h') return
  if (e.cancelable) e.preventDefault()
  const w = vpWidth()
  let p = startPos - dx / w // 向左拖(dx<0) → 看下一张 → 向左轮播
  // 软边界：首图与副本之间可临时越界，随后吸附回环
  if (p < -0.5) p = -0.5 + (p + 0.5) * 0.3
  if (p > n.value + 0.5) p = n.value + 0.5 + (p - (n.value + 0.5)) * 0.3
  pos.value = p
}
function onTouchEnd(e) {
  if (!dragging) return
  dragging = false
  const dx = (e.changedTouches ? e.changedTouches[0].clientX : startX) - startX
  const w = vpWidth()
  const thr = w * 0.2
  transitionOn.value = true
  if (dx <= -thr) {
    pos.value = Math.round(startPos - dx / w)           // 左滑 → 下一张
  } else if (dx >= thr) {
    if (Math.round(pos.value) <= 0) {                  // 右滑到首图 → 跳副本再回环
      transitionOn.value = false
      pos.value = n.value
      requestAnimationFrame(() => requestAnimationFrame(() => {
        transitionOn.value = true
        pos.value = n.value - 1
      }))
    } else {
      pos.value = Math.round(startPos - dx / w)
    }
  } else {
    pos.value = Math.round(pos.value)                  // 回弹吸附
  }
  startAuto()
}

onMounted(() => {
  if (Math.round(pos.value) >= n.value) pos.value = 0
  startAuto()
  saleTimer = setInterval(() => { now.value = Date.now() }, 1000)
  scheduleMidnightUpdate()
  store.fetchFeatured().catch(() => {})
  fetchRaffleSales()
  fetchNotices()
  fetchBanners()
})
onUnmounted(() => { stopAuto(); if (saleTimer) clearInterval(saleTimer); if (midnightTimer) clearTimeout(midnightTimer) })

// 首页轮播图（真实接口：GET /api/banners，管理端「内容→轮播管理」维护；失败保留本地兜底图）
async function fetchBanners() {
  try {
    const res = await request.get('/banners')
    const list = (Array.isArray(res) ? res : res.list || [])
      .map((b) => b.image)
      .filter(Boolean)
    if (list.length) {
      slides.value = list
      // 图片源切换后无感复位到第一张
      transitionOn.value = false
      pos.value = 0
      requestAnimationFrame(() => { transitionOn.value = true })
    }
  } catch { /* 拉取失败时保留本地兜底图 */ }
}

// 公告轮播（真实接口：GET /api/announcements，仅已发布且到生效时间的公告，置顶优先）
const notices = ref(['欢迎来到司南艺术·数字藏品平台'])
async function fetchNotices() {
  try {
    const res = await request.get('/announcements', { params: { page: 1, pageSize: 5 } })
    const list = (res.list || []).map((a) => a.title).filter(Boolean)
    if (list.length) notices.value = list
  } catch { /* 拉取失败时保留默认欢迎文案 */ }
}

function goCalendar() { router.push('/calendar') }
function goActivity() { router.push('/activity') }
function goLottery() { router.push('/lottery') }
function goDetail(id) { router.push('/collection/' + id) }
function onCardClick(item) { router.push(item.type === 'raffle' ? '/raffle/' + item.id : '/collection/' + item.id) }

// 关注/取消关注藏品
function onFav(id) {
  const fav = store.toggleFavorite(id)
  showToast(fav ? '已关注' : '已取消关注')
}
function onSign() {
  if (!requireLogin(route.fullPath)) return
  router.push('/sign')
}
</script>

<template>
  <div class="home page">
    <!-- 顶部英雄区（背景轮播） -->
    <section class="home-hero">
      <div class="home-hero__bg" ref="bgRef">
        <div
          class="home-hero__track"
          :style="trackStyle"
          @touchstart.passive="onTouchStart"
          @touchmove="onTouchMove"
          @touchend="onTouchEnd"
        >
          <img
            v-for="(s, i) in renderSlides"
            :key="i"
            class="home-hero__slide"
            :src="s"
            alt=""
            draggable="false"
            @contextmenu.prevent
          />
        </div>
      </div>
      <div class="home-hero__dots">
        <span
          v-for="(s, i) in slides"
          :key="i"
          class="home-hero__dot"
          :class="{ active: i === activeIndex }"
        />
      </div>

      <div class="home-hero__top safe-top">
        <div class="home-hero__sign" @click="onSign">
          <img class="home-hero__sign-icon" src="/images/tab/tab-gift-line.png" alt="" draggable="false" @contextmenu.prevent />
          <span>签到有礼</span>
        </div>
      </div>

    </section>

    <!-- 品牌卡片（站点装修配置：头像 + 名称动态化） -->
    <div class="brand-card">
      <img class="brand-card__logo" :src="site.siteAvatar || site.siteLogo || '/images/brand-logo.png'" alt="" draggable="false" @contextmenu.prevent />
      <img v-if="!site.siteName" class="brand-card__text" src="/images/brand-text-xiaozhuan.png" alt="千年司南｜一器载道" draggable="false" @contextmenu.prevent />
      <div v-else class="brand-card__name calligraphy">{{ site.siteName }}</div>
    </div>

    <!-- 公告流动区 -->
    <section class="home-notice">
      <AppIcon name="quote" :size="28" color="#CCCCCC" class="home-notice__q home-notice__q--l" />
      <div class="home-notice__marquee">
        <div class="home-notice__track">
          <template v-for="rep in 2" :key="rep">
            <span
              v-for="(t, i) in notices"
              :key="rep + '-' + i"
              class="home-notice__item"
            >{{ t }}</span>
          </template>
        </div>
      </div>
      <AppIcon name="quote" :size="28" color="#CCCCCC" class="home-notice__q home-notice__q--r" />
    </section>

    <!-- 功能入口网格 -->
    <section class="home-grid">
      <div class="grid-main" @click="goCalendar">
        <div class="grid-main__head">
          <span class="grid-main__title">首发日历</span>
          <span class="grid-main__sub">独家发售，先到先得</span>
        </div>
        <div class="grid-main__cal">
          <img v-if="calendarIcon?.type === 'image'" :src="calendarIcon.image" class="grid-main__cal-icon" alt="" draggable="false" @contextmenu.prevent />
          <AppIcon v-else-if="calendarIcon?.type === 'svg'" :name="calendarIcon.icon" :size="44" color="#C00000" class="grid-main__cal-icon" />
          <div class="grid-main__date">
            <strong>{{ calendarDay }}</strong>
            <span>{{ calendarYm }}</span>
          </div>
        </div>
        <AppButton size="small" @click.stop="goCalendar">去看看</AppButton>
      </div>

      <div class="grid-side">
        <div class="side-card" @click="goActivity">
          <div class="side-card__text">
            <div class="side-card__head">
              <span class="side-card__title">活动中心</span>
            </div>
            <span class="side-card__sub">让你资产换新</span>
          </div>
          <span class="side-card__tag">
            <img v-if="activityIcon?.type === 'image'" :src="activityIcon.image" alt="" draggable="false" @contextmenu.prevent />
            <AppIcon v-else-if="activityIcon?.type === 'svg'" :name="activityIcon.icon" :size="30" color="#C00000" />
          </span>
        </div>
        <div class="side-card" @click="goLottery">
          <div class="side-card__text">
            <div class="side-card__head">
              <span class="side-card__title">幸运抽奖</span>
            </div>
            <span class="side-card__sub">幸运好礼相送</span>
          </div>
          <span class="side-card__tag">
            <img v-if="lotteryIcon?.type === 'image'" :src="lotteryIcon.image" alt="" draggable="false" @contextmenu.prevent />
            <AppIcon v-else-if="lotteryIcon?.type === 'svg'" :name="lotteryIcon.icon" :size="30" color="#C00000" />
          </span>
        </div>
      </div>
    </section>

    <!-- 藏品发售区 -->
    <section class="home-releases">
      <div class="home-releases__head">
        <span class="home-releases__title"><i class="red">藏品</i>发售</span>
        <span class="home-releases__more" @click="router.push('/market')">查看更多 &gt;</span>
      </div>
      <div class="home-releases__grid">
        <div
          v-for="item in releaseItems"
          :key="item.id"
          class="release-card"
          @click="onCardClick(item)"
        >
          <div class="release-card__cover">
            <img class="release-card__img" :src="item.coverImage" alt="" draggable="false" @contextmenu.prevent @click.prevent />
            <span class="release-card__tag">{{ item.tag }}</span>
            <span v-if="item.type !== 'raffle'" class="release-card__fav" :class="{ active: store.isFavorite(item.id) }" @click.stop="onFav(item.id)">
              <AppIcon :name="store.isFavorite(item.id) ? 'heartFill' : 'heart'" :size="14" />
            </span>
            <!-- 液态玻璃：发售时间/状态（抽签发售显示抽签阶段） -->
            <div class="release-card__glass">
              <span v-if="item.type === 'raffle'" class="release-card__status">{{ RAFFLE_PHASE_TEXT[item.phase] || '抽签购' }}</span>
              <template v-else>
                <span v-if="item.status === 'countdown'" class="release-card__status is-time">发售时间：{{ item.saleTimeText }}</span>
                <span v-else class="release-card__status" :class="{ 'is-soldout': item.status === 'soldout' }">{{ STATUS_TEXT[item.status] }}</span>
              </template>
            </div>
          </div>
          <p class="release-card__name">{{ item.name }}</p>
          <div class="release-card__meta">
            <span class="release-card__total">限量<b>{{ item.total }}</b></span>
            <span class="release-card__price">¥{{ item.price }}</span>
          </div>
        </div>
      </div>
    </section>
  </div>
</template>

<style scoped lang="scss">
.home { padding-bottom: calc(#{$tabbar-height} + env(safe-area-inset-bottom) + 12px); }

/* 英雄区 */
.home-hero {
  position: relative;
  min-height: 340px;
  padding: 0 $page-padding 44px;
  overflow: hidden;
  &__bg { position: absolute; inset: 0; z-index: 0; overflow: hidden; }
  &__track {
    display: flex; height: 100%; width: 100%;
    will-change: transform; touch-action: pan-y;
  }
  &__slide {
    flex: 0 0 100%; width: 100%; height: 100%;
    object-fit: cover; display: block;
    -webkit-user-drag: none; -webkit-touch-callout: none; user-select: none; pointer-events: none;
  }
  &__dots {
    position: absolute; z-index: 1; left: 50%; bottom: 14px;
    transform: translateX(-50%); display: flex; gap: 6px;
  }
  &__dot {
    width: 6px; height: 6px; border-radius: 50%;
    background: rgba(255, 255, 255, 0.4); transition: all 0.3s ease;
    &.active { width: 16px; border-radius: 3px; background: $color-primary; }
  }
  > *:not(.home-hero__bg):not(.home-hero__dots) { position: relative; z-index: 1; }

  &__top {
    display: flex;
    align-items: flex-start;
    justify-content: flex-end;
    padding-top: 14px;
  }
  &__sign {
    display: flex; align-items: center; gap: 4px;
    /* 液态玻璃：半透明白 + 背景模糊，红框红字保留 */
    background: rgba(255, 255, 255, 0.5);
    backdrop-filter: blur(20px) saturate(160%);
    -webkit-backdrop-filter: blur(20px) saturate(160%);
    border: 1px solid #C00000; border-radius: 14px;
    padding: 5px 10px; font-size: 12px; color: #C00000; font-weight: 500;
    box-shadow: inset 0 1px 1px rgba(255, 255, 255, 0.4);
  }
  &__sign-icon { width: 16px; height: 16px; -webkit-user-drag: none; -webkit-touch-callout: none; user-select: none; pointer-events: none; }
}

/* 品牌卡：云雷纹白底金纹 + 液态玻璃 */
.brand-card {
  margin: -60px $page-padding 0;
  text-align: center;
  position: relative;
  z-index: 2;
  padding: 10px 16px;
  border-radius: 22px;
  overflow: hidden;
  /* 液态玻璃基底 */
  background: rgba(255, 255, 255, 0.5);
  backdrop-filter: blur(20px) saturate(160%);
  -webkit-backdrop-filter: blur(20px) saturate(160%);
  border-top: 1px solid #D9D9D9;
  border-bottom: 1px solid #D9D9D9;
  border-left: 1px solid #D9D9D9;
  border-right: 1px solid #D9D9D9;
  box-shadow:
    inset 0 1px 1px rgba(255, 255, 255, 0.25);

  /* 云雷纹：白底金纹平铺 */
  &::before {
    content: '';
    position: absolute;
    inset: 0;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='22' height='22' viewBox='0 0 22 22'%3E%3Cpath d='M3 3h16v16h-12v-12h8v8h-4v-4' fill='none' stroke='%23D4A574' stroke-width='1.3'/%3E%3C/svg%3E");
    background-size: 22px 22px;
    opacity: 0.4;
    z-index: 0;
    pointer-events: none;
  }
  /* 玻璃对角高光反射（克制，顶部不再强白） */
  &::after {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(
      135deg,
      rgba(255, 255, 255, 0.12) 0%,
      rgba(255, 255, 255, 0) 32%,
      rgba(255, 255, 255, 0) 68%,
      rgba(255, 255, 255, 0.1) 100%
    );
    z-index: 0;
    pointer-events: none;
  }

  &__logo {
    position: relative; z-index: 1; display: block; height: 60px; margin: 0 auto; object-fit: contain;
    -webkit-user-drag: none; -webkit-touch-callout: none; user-select: none; pointer-events: none;
  }
  &__text {
    position: relative;
    z-index: 1;
    display: block;
    width: 100%;
    height: 19px;
    margin: 4px auto 0;
    object-fit: contain;
    image-rendering: -webkit-optimize-contrast;
    image-rendering: crisp-edges;
    -webkit-user-drag: none; -webkit-touch-callout: none; user-select: none; pointer-events: none;
  }
  &__name {
    position: relative;
    z-index: 1;
    margin: 4px auto 0;
    height: 19px;
    line-height: 19px;
    font-size: 14px;
    letter-spacing: 4px;
    color: $color-dark;
    -webkit-user-drag: none; user-select: none;
  }
}

/* 公告流动 */
.home-notice {
  position: relative; margin: 12px $page-padding 0; padding: 18px 30px 14px;
  background: $color-card; border-radius: $radius-lg;
  overflow: hidden;
  &__q { position: absolute; top: 50%; transform: translateY(-50%); opacity: .35; z-index: 0; }
  &__q--l { left: 4px; }
  &__q--r { right: 4px; transform: translateY(-50%) rotate(180deg); }
  &__marquee { position: relative; z-index: 1; overflow: hidden; white-space: nowrap; padding: 0 24px; }
  &__track {
    display: inline-flex;
    animation: notice-marquee 18s linear infinite;
    will-change: transform;
  }
  &__item {
    flex: none; padding: 0 26px; font-size: 14px; color: $color-text-primary;
    position: relative; line-height: 1.4;
    &::after { content: '·'; position: absolute; right: 0; color: $color-text-tertiary; }
  }
}
@keyframes notice-marquee {
  from { transform: translateX(0); }
  to { transform: translateX(-50%); }
}

/* 功能网格 */
.home-grid {
  display: flex; gap: 12px; margin: 12px $page-padding 0;
  .grid-main {
    flex: 1; background: $color-card; border-radius: $radius-lg; padding: 10px 14px;
    display: flex; flex-direction: column; gap: 10px;
    &__title { font-size: 15px; font-weight: 700; color: $color-text-primary; }
    &__sub { display: block; font-size: 11px; color: $color-text-tertiary; margin-top: 4px; }
    &__cal { display: flex; align-items: center; gap: 8px; }
    // 图标视觉放大到 66px，负边距抵消增量，卡片高度不变
    &__cal-icon { width: 66px; height: 66px; margin: -11px 0; flex: none; object-fit: contain; -webkit-user-drag: none; -webkit-touch-callout: none; user-select: none; pointer-events: none; }
    &__date { display: flex; flex-direction: column; align-items: center; line-height: 1.1; margin-left: 6px;
      strong { font-size: 26px; color: $color-primary; font-family: $font-price; }
      span { font-size: 13px; color: $color-text-tertiary; }
    }
    :deep(.app-btn) { margin-top: auto; border-radius: 8px; }
  }
  .grid-side { flex: 1; display: flex; flex-direction: column; gap: 12px; }
  .side-card {
    flex: 1; background: $color-card; border-radius: $radius-lg; padding: 10px 14px;
    display: flex; align-items: center; justify-content: space-between; gap: 8px;
    &__text { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
    &__head { display: flex; align-items: center; }
    &__title { font-size: 15px; font-weight: 700; color: $color-text-primary; }
    &__tag {
      width: 40px; height: 40px; border-radius: 8px; background: transparent; flex: none; overflow: visible;
      display: flex; align-items: center; justify-content: center;
      // 图标视觉放大到 56px，超出 40px 容器但不影响卡片高度
      img { width: 56px; height: 56px; object-fit: contain; -webkit-user-drag: none; -webkit-touch-callout: none; user-select: none; pointer-events: none; }
    }
    &__sub { font-size: 11px; color: $color-text-tertiary; }
  }
}

/* 藏品发售区 */
.home-releases {
  margin: 12px $page-padding 0;
  &__head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
  &__title { font-size: 17px; font-weight: 700; color: $color-text-primary; .red { color: $color-primary; font-style: normal; } }
  &__more { font-size: 12px; color: $color-text-tertiary; }
  &__grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
}
.release-card {
  background: $color-card; border-radius: $radius-lg; padding: 10px; cursor: pointer;
  &__cover {
    position: relative; width: 100%; aspect-ratio: 1 / 1;
    border-radius: 10px; overflow: hidden; background: #141415;
  }
  &__img {
    width: 100%; height: 100%; object-fit: cover; display: block;
    -webkit-user-drag: none; -webkit-touch-callout: none; user-select: none; pointer-events: none;
  }
  &__tag {
    position: absolute; top: 8px; left: 8px; z-index: 2;
    font-size: 10px; font-weight: 600; color: #fff;
    background: linear-gradient(135deg, $color-primary, #8B0000);
    padding: 2px 6px; border-radius: 4px;
  }
  &__fav {
    position: absolute; top: 8px; right: 8px; z-index: 2;
    width: 26px; height: 26px; border-radius: 50%;
    background: rgba(0, 0, 0, 0.35);
    display: flex; align-items: center; justify-content: center;
    color: #C6C6C6; cursor: pointer;
    &.active { color: $color-primary; }
  }
  &__glass {
    position: absolute; bottom: 0; left: 0; right: 0; height: 32px; z-index: 1;
    background: rgba(255, 255, 255, 0.08);
    backdrop-filter: blur(14px) saturate(160%);
    -webkit-backdrop-filter: blur(14px) saturate(160%);
    border-top: 1px solid rgba(255, 255, 255, 0.14);
    display: flex; align-items: center; justify-content: center;
  }
  &__status {
    font-size: 12px; font-weight: 600; color: #fff;
    text-shadow: 0 1px 4px rgba(0, 0, 0, 0.45);
    letter-spacing: 1px;
    &.is-soldout { color: rgba(255, 255, 255, 0.6); }
    &.is-time { font-size: 11px; font-weight: 500; letter-spacing: 0; font-family: $font-price; }
  }
  &__name {
    margin: 10px 0 6px;
    font-size: 14px; font-weight: 600; color: $color-text-primary;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  }
  &__meta {
    display: flex; align-items: flex-end; justify-content: space-between;
    margin-top: 10px; padding-top: 10px; border-top: 1px solid $color-border;
  }
  &__total {
    font-size: 10px; color: $color-text-tertiary;
    display: flex; flex-direction: column; gap: 2px;
    b { font-size: 12px; color: $color-text-primary; font-weight: 600; font-family: $font-price; line-height: 1; }
  }
  &__price { font-size: 18px; font-weight: 700; color: $color-primary; font-family: $font-price; line-height: 1; }
}
</style>
