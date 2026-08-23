<script setup>
import { useRouter } from 'vue-router'
import { useNoticeStore } from '@/stores/notice'
import AppNavBar from '@/components/AppNavBar.vue'
import AppIcon from '@/components/AppIcon.vue'
import AppTag from '@/components/AppTag.vue'

const router = useRouter()
const store = useNoticeStore()

function setCategory(key) {
  store.activeCategory = key
}

function openNotice(id) {
  router.push('/notice/' + id)
}
</script>

<template>
  <div class="notice page">
    <AppNavBar title="公告列表" :left-arrow="false" transparent />

    <!-- 品牌搜索区 -->
    <div class="notice-brand">
      <div class="notice-brand__left">
        <span class="notice-brand__title">司南·公告</span>
        <span class="notice-brand__en">SINAN · MALL</span>
      </div>
      <div class="notice-brand__search">
        <AppIcon name="search" :size="16" color="#999" />
        <input
          v-model="store.searchKeyword"
          class="notice-brand__input"
          type="text"
          placeholder="搜索内容"
        />
        <button class="notice-brand__btn" @click="store.search(store.searchKeyword)">搜索</button>
      </div>
    </div>

    <!-- Tab -->
    <div class="notice-tabs no-scrollbar">
      <div
        v-for="cat in store.categories"
        :key="cat.key"
        class="notice-tabs__item"
        :class="{ active: store.activeCategory === cat.key }"
        @click="setCategory(cat.key)"
      >
        <span class="notice-tabs__label">{{ cat.label }}</span>
        <span class="notice-tabs__bar"></span>
      </div>
    </div>

    <!-- 列表 -->
    <div class="notice-list">
      <div v-for="item in store.filteredNotices" :key="item.id" class="notice-item" @click="openNotice(item.id)">
        <h3 class="notice-item__title">{{ item.title }}</h3>
        <p class="notice-item__summary">{{ item.summary }}</p>
        <div class="notice-item__foot">
          <AppTag type="primary">{{ item.categoryLabel }}</AppTag>
          <span class="notice-item__time">{{ item.time }}</span>
        </div>
      </div>
    </div>

    <p class="notice-end">没有更多了~</p>
  </div>
</template>

<style scoped lang="scss">
.notice { padding-bottom: calc(#{$tabbar-height} + env(safe-area-inset-bottom) + 12px); }

.notice-brand {
  display: flex; align-items: center; justify-content: space-between; gap: 12px;
  padding: 12px $page-padding; background: transparent;
  &__left { display: flex; flex-direction: column; }
  &__title { font-size: 18px; font-weight: 700; color: $color-text-primary; }
  &__en { font-size: 10px; color: $color-text-tertiary; letter-spacing: 1px; margin-top: 2px; }
  &__search {
    flex: 1; display: flex; align-items: center; gap: 6px;
    background: $color-surface; border-radius: $radius-md; padding: 0 6px 0 10px; height: 36px;
  }
  &__input {
    flex: 1; border: none; outline: none; background: transparent; font-size: 14px; height: 100%;
    &::placeholder { color: $color-text-tertiary; }
  }
  &__btn {
    border: none; cursor: pointer; background: $color-primary; color: #fff;
    font-size: 13px; height: 28px; padding: 0 12px; border-radius: $radius-md;
    writing-mode: horizontal-tb; white-space: nowrap; letter-spacing: 0;
  }
}

.notice-tabs {
  display: flex; gap: 22px; padding: 12px $page-padding; background: transparent;
  overflow-x: auto; border-bottom: 1px solid $color-border;
  &__item { display: flex; flex-direction: column; align-items: center; cursor: pointer; white-space: nowrap; }
  &__label { font-size: 14px; color: $color-text-secondary; }
  &__bar { margin-top: 6px; width: 18px; height: 3px; border-radius: 2px; background: transparent; }
  &__item.active &__label { color: $color-text-primary; font-weight: 700; }
  &__item.active &__bar { background: $color-primary; }
}

.notice-list { padding: 12px $page-padding 0; }
.notice-item {
  background: $color-card; border-radius: $radius-lg; padding: 16px;
  margin-bottom: 12px; border-bottom: 1px solid $color-border;
  &__title { margin: 0; font-size: 16px; font-weight: 700; color: $color-text-primary; @include ellipsis; }
  &__summary { margin: 8px 0 12px; font-size: 14px; color: $color-text-secondary; @include ellipsis; }
  &__foot { display: flex; align-items: center; justify-content: space-between; }
  &__time { font-size: 12px; color: $color-text-tertiary; }
}

.notice-end { text-align: center; font-size: 14px; color: $color-text-tertiary; margin: 16px 0; }
</style>
