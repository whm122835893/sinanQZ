<script setup>
import { ref, computed } from 'vue'
import { useRoute, useRouter, RouterView } from 'vue-router'
import { useCollectionStore } from '@/stores/collection'
import AppIcon from '@/components/AppIcon.vue'
import AppEmpty from '@/components/AppEmpty.vue'

const route = useRoute()
const router = useRouter()
const store = useCollectionStore()

const tabs = [
  { name: 'market-following', label: '我的关注', to: '/market/following' },
  { name: 'market-activity', label: '活动市场', to: '/market/activity' },
  { name: 'market-free', label: '自由市场', to: '/market/free' }
]

const categories = ['全部', '水墨', '国潮', '盲盒', '实物', '联名']
const activeCat = ref('全部')

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

function selectView(value) {
  store.marketViewMode = value
  showViewMenu.value = false
}
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
        <span
          v-for="cat in categories"
          :key="cat"
          class="market-sub__cat"
          :class="{ active: activeCat === cat }"
          @click="activeCat = cat"
        >{{ cat }}</span>
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
          @keyup.enter="$event.target.blur()"
        />
        <button class="market-search__btn" @click="$event.target.blur()">搜索</button>
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
