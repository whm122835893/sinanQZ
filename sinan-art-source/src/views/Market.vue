<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue'
import { useRoute, useRouter, RouterView } from 'vue-router'
import { useCollectionStore } from '@/stores/collection'
import { useSiteStore } from '@/stores/site'
import AppIcon from '@/components/AppIcon.vue'
import AppEmpty from '@/components/AppEmpty.vue'

const route = useRoute()
const router = useRouter()
const store = useCollectionStore()
const site = useSiteStore()

const tabs = [
  { name: 'market-following', label: '我的关注', to: '/market/following' },
  { name: 'market-activity', label: '活动市场', to: '/market/activity' },
  { name: 'market-free', label: '自由市场', to: '/market/free' }
]

// ---- 顶部 tab → 归属市场（2026-10-09：两个市场数据互不相通）----
// 我的关注是「收藏的市场藏品」，不该被市场归属切开，所以传 all 跨两个市场看
const MARKET_OF_TAB = {
  'market-activity': 'activity',
  'market-free': 'free',
  'market-following': 'all'
}
const currentMarketType = computed(() => MARKET_OF_TAB[route.name] || 'activity')

// 「推荐」胶囊：后台总开关（system_configs.market_recommend_tab_enabled）开启
// 且当前在活动市场时才出现；关掉即整枚胶囊隐藏
const showRecommendPill = computed(() =>
  site.marketRecommendTabEnabled && currentMarketType.value === 'activity'
)

// 二级分类（真实接口：GET /api/collections/categories?scene=market，
// 管理端「内容 → 分类管理」维护；失败兜底静态默认）
const FALLBACK_CATS = [
  { id: 0, name: '全部', code: 'all' },
  { id: 1, name: '水墨', code: 'ink' },
  { id: 2, name: '国潮', code: 'guochao' }
]
const categories = computed(() =>
  store.marketCategories.length ? store.marketCategories : FALLBACK_CATS
)

const viewOptions = [
  { value: 'grid', label: '网格视图' },
  { value: 'list', label: '列表视图' }
]
const showViewMenu = ref(false)
const currentViewLabel = computed(() =>
  viewOptions.find(o => o.value === store.marketViewMode)?.label || '卡片'
)

function isActive(name) {
  return route.name === name
}

// 分类切换：写回 filters.category（code），拉取市场列表（后端按 category_id 过滤）
// 分类与「推荐」是同一条胶囊栏里的互斥选项，点分类即退出推荐态
function selectCategory(cat) {
  if (store.filters.category === cat.code && !store.marketRecommend) return
  store.filters.category = cat.code
  store.marketRecommend = false
  store.fetchMarket().catch(() => {})
}

// 推荐：只看后台「上推荐」的藏品（仍受当前分类/关键词/排序约束）
function selectRecommend() {
  if (store.marketRecommend) return
  store.marketRecommend = true
  store.fetchMarket().catch(() => {})
}

function selectView(value) {
  store.marketViewMode = value
  showViewMenu.value = false
}

// 搜索：回车/按钮触发后端关键词检索（输入过程中仍有本地即时过滤）
function onSearch(e) {
  if (e?.target?.blur) e.target.blur()
  store.fetchMarket().catch(() => {})
}

// 顶部 tab 切换：换数据源重新拉取（旧版两个 tab 共用同一份列表，切 tab 不请求）
watch(() => route.name, (name) => {
  if (!MARKET_OF_TAB[name]) return
  store.setMarketType(MARKET_OF_TAB[name]).catch(() => {})
})

// 站点配置（含「推荐」分类总开关）在 main.js 里是不 await 的异步拉取，
// 冷启动可能晚于本页挂载。等它到位后再决定默认落点，否则开关明明开着，
// 首屏却因为时序问题停在「全部」。
let stopConfigWait = null
function enterCurrentTab() {
  store.setMarketType(currentMarketType.value).catch(() => {})
}

onMounted(() => {
  // 分类动态化 + 首次进入市场页按当前 tab 所属市场拉取列表
  store.fetchCategories('market').catch(() => {})
  if (site.loaded) {
    enterCurrentTab()
  } else {
    stopConfigWait = watch(() => site.loaded, (ok) => {
      if (!ok) return
      stopConfigWait?.()
      stopConfigWait = null
      enterCurrentTab()
    })
  }
})

onBeforeUnmount(() => stopConfigWait?.())
</script>

<template>
  <div class="market page">
    <!-- 顶部三栏 Tab -->
    <div class="market-tabs safe-top">
      <div
        v-for="tab in tabs"
        :key="tab.name"
        class="market-tabs__item"
        :class="{ active: isActive(tab.name) }"
        @click="router.push(tab.to)"
      >
        <span class="market-tabs__label">{{ tab.label }}</span>
        <span class="market-tabs__bar"></span>
      </div>
    </div>

    <!-- 二级分类 + 视图切换 -->
    <div class="market-sub">
      <div class="market-sub__cats no-scrollbar">
        <!-- 推荐：仅活动市场 + 后台开关开启时出现，位于「全部」左侧 -->
        <span
          v-if="showRecommendPill"
          class="market-sub__cat"
          :class="{ active: store.marketRecommend }"
          @click="selectRecommend"
        >推荐</span>
        <span
          v-for="cat in categories"
          :key="cat.code"
          class="market-sub__cat"
          :class="{ active: !store.marketRecommend && store.filters.category === cat.code }"
          @click="selectCategory(cat)"
        >{{ cat.name }}</span>
      </div>
      <div class="market-sub__view">
        <button class="market-sub__view-btn" @click="showViewMenu = !showViewMenu">
          <span>切换视图</span>
          <i class="market-sub__view-caret" :class="{ open: showViewMenu }"></i>
        </button>
        <div v-if="showViewMenu" class="market-sub__mask" @click="showViewMenu = false"></div>
        <div v-if="showViewMenu" class="market-sub__menu">
          <div
            v-for="opt in viewOptions"
            :key="opt.value"
            class="market-sub__menu-item"
            :class="{ active: store.marketViewMode === opt.value }"
            @click="selectView(opt.value)"
          >
            <span>{{ opt.label }}</span>
            <i v-if="store.marketViewMode === opt.value" class="market-sub__menu-check"></i>
          </div>
        </div>
      </div>
    </div>

    <!-- 内容区 -->
    <div class="market-list-bar">
      <div class="market-sort" :class="store.marketSort" @click="store.toggleMarketSort()">
        <span class="market-sort__text">价格排序</span>
        <i class="market-sort__arrow"></i>
      </div>
      <div class="market-search">
        <AppIcon name="search" :size="16" color="#999" />
        <input
          v-model="store.filters.keyword"
          class="market-search__input"
          type="text"
          placeholder="搜索藏品"
          @keyup.enter="onSearch($event)"
        />
        <button class="market-search__btn" @click="onSearch">搜索</button>
      </div>
    </div>
    <RouterView v-slot="{ Component }">
      <component :is="Component" v-if="Component" />
      <AppEmpty v-else description="暂无数据" />
    </RouterView>
  </div>
</template>

<style scoped lang="scss">
.market { padding-bottom: calc(#{$tabbar-height} + env(safe-area-inset-bottom) + 12px); }

.market-tabs {
  display: flex;
  background: transparent;
  border-bottom: 1px solid $color-border;
  padding-top: env(safe-area-inset-top);
  &__item {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 12px 0 8px;
    cursor: pointer;
  }
  &__label { font-size: 15px; color: $color-text-tertiary; font-weight: 500; }
  &__bar {
    margin-top: 6px; width: 22px; height: 4px; border-radius: 2px; background: transparent;
  }
  &__item.active &__label { color: $color-text-primary; font-weight: 700; }
  &__item.active &__bar { background: $color-primary; }
}

.market-sub {
  display: flex; align-items: center; gap: 8px;
  padding: 10px $page-padding; background: transparent;
  &__cats { flex: 1; display: flex; gap: 18px; overflow-x: auto; }
  &__cat {
    font-size: 14px; color: $color-text-secondary; white-space: nowrap; cursor: pointer;
    padding: 4px 2px; border-radius: 4px;
    &.active { color: $color-primary; font-weight: 600; background: $color-primary-bg; padding: 4px 10px; }
  }
  &__view { position: relative; flex-shrink: 0; }
  &__view-btn {
    display: flex; align-items: center; gap: 4px;
    font-size: 13px; color: $color-text-primary; font-weight: 500;
    background: transparent; border: none; cursor: pointer; padding: 4px 2px;
    white-space: nowrap;
  }
  &__view-caret {
    width: 0; height: 0;
    border-left: 4px solid transparent; border-right: 4px solid transparent;
    border-top: 5px solid $color-text-tertiary;
    transition: transform 0.2s;
    &.open { transform: rotate(180deg); }
  }
  &__mask {
    position: fixed; inset: 0; z-index: 20;
    background: transparent;
  }
  &__menu {
    position: absolute; top: calc(100% + 8px); right: 0; z-index: 21;
    min-width: 96px;
    background: $color-card; border: 1px solid $color-border; border-radius: $radius-md;
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.18);
    overflow: hidden;
  }
  &__menu-item {
    display: flex; align-items: center; justify-content: space-between;
    padding: 10px 14px; font-size: 14px; color: $color-text-primary; cursor: pointer;
    &:hover { background: $color-surface; }
    &.active { color: $color-primary; font-weight: 600; }
    &:not(:last-child) { border-bottom: 1px solid $color-border; }
  }
  &__menu-check {
    width: 6px; height: 11px;
    border-right: 2px solid $color-primary; border-bottom: 2px solid $color-primary;
    transform: rotate(45deg); margin-left: 10px; flex-shrink: 0;
  }
}

.market-list-bar {
  display: flex; align-items: center; justify-content: space-between;
  margin: 16px $page-padding 12px;
}
.market-sort {
  display: flex; align-items: center; gap: 4px;
  font-size: 13px; color: $color-text-primary; font-weight: 600; cursor: pointer;
  &__arrow {
    width: 0; height: 0;
    border-left: 4px solid transparent; border-right: 4px solid transparent;
    border-bottom: 5px solid $color-text-tertiary;
    transition: transform 0.2s;
  }
  &.price-desc &__arrow { transform: rotate(180deg); }
}
.market-search {
  flex: 1;
  margin-left: 10px;
  display: flex; align-items: center; gap: 6px;
  background: $color-surface; border-radius: $radius-md;
  padding: 0 6px 0 10px; height: 36px;
  &__input {
    flex: 1; border: none; outline: none; background: transparent;
    font-size: 14px; height: 100%; min-width: 0; color: $color-text-primary;
    &::placeholder { color: $color-text-tertiary; }
  }
  &__btn {
    border: none; cursor: pointer; background: $color-primary; color: #fff;
    font-size: 13px; height: 28px; padding: 0 12px; border-radius: $radius-md;
    white-space: nowrap;
  }
}
</style>
