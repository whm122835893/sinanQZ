<script setup>
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import AppNavBar from '@/components/AppNavBar.vue'
import AppEmpty from '@/components/AppEmpty.vue'
import { useUserStore } from '@/stores/user'

const router = useRouter()
const user = useUserStore()
const tabs = ['数字藏品', '盲盒', '已售出']
const active = ref('数字藏品')

// 仅展示「数字藏品」库存（其余分类暂为空）
const list = computed(() =>
  active.value === '数字藏品' ? user.inventory : []
)

// 点击藏品卡片 → 弹出该藏品所有编号
const showSerials = ref(false)
const current = ref(null)

function genSerials(it) {
  // 挂单场景：单编号（no 已存在）
  if (it.no) return [it.no]
  // 发售场景：按数量生成序列号
  const qty = it.qty || 1
  return Array.from({ length: qty }, (_, i) => 'SN-' + it.id + '-' + String(i + 1).padStart(4, '0'))
}
const currentSerials = computed(() => (current.value ? genSerials(current.value) : []))

function openSerials(it) {
  current.value = it
  showSerials.value = true
}
function goDetail(it, no) {
  showSerials.value = false
  router.push({ name: 'collection-detail', params: { id: it.id }, query: { no, from: 'warehouse' } })
}
</script>

<template>
  <div class="collections page--no-tabbar">
    <AppNavBar title="我的藏品" @click-left="$router.back()" />

    <div class="collections-tabs">
      <div
        v-for="tab in tabs"
        :key="tab"
        class="collections-tabs__item"
        :class="{ active: active === tab }"
        @click="active = tab"
      >{{ tab }}</div>
    </div>

    <!-- 网格列表 -->
    <div class="collections-grid" v-if="list.length">
      <div class="collections-grid__item" v-for="(it, i) in list" :key="i" @click="openSerials(it)">
        <div class="collections-grid__cover-wrap">
          <img class="collections-grid__cover" :src="it.coverImage" alt="" />
        </div>
        <p class="collections-grid__name">{{ it.name }}</p>
        <p class="collections-grid__count">持有 {{ it.qty }} 件</p>
      </div>
    </div>

    <AppEmpty v-else description="空空如也" />

    <!-- 编号弹窗 -->
    <van-popup v-model:show="showSerials" position="bottom" round>
      <div class="serials">
        <div class="serials__head">
          <p class="serials__title">{{ current?.name }}</p>
          <p class="serials__sub">共持有 {{ currentSerials.length }} 个编号</p>
          <span class="serials__close" @click="showSerials = false">✕</span>
        </div>
        <div class="serials__grid">
          <div
            class="serials__cell"
            v-for="s in currentSerials"
            :key="s"
            @click="goDetail(current, s)"
          >{{ s }}</div>
        </div>
      </div>
    </van-popup>
  </div>
</template>

<style scoped lang="scss">
.collections-tabs {
  display: flex; gap: 8px; padding: 14px $page-padding; margin-bottom: 8px;
  &__item {
    flex: 1; text-align: center; padding: 10px 0; font-size: 14px; cursor: pointer;
    border-radius: $radius-md; background: $color-surface; color: $color-text-secondary;
  }
  &__item.active { background: #333333; color: #fff; font-weight: 600; }
}

/* 网格列表：两列 */
.collections-grid {
  display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px;
  padding: 0 $page-padding 16px;
}
.collections-grid__item {
  background: $color-card; border-radius: $radius-lg; padding: 10px; cursor: pointer;
}
.collections-grid__cover-wrap {
  position: relative; width: 100%; aspect-ratio: 1 / 1;
  border-radius: 10px; overflow: hidden; background: #141415;
}
.collections-grid__cover { width: 100%; height: 100%; object-fit: cover; display: block; }
.collections-grid__name {
  margin: 10px 0 4px; font-size: 14px; font-weight: 600; color: $color-text-primary;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.collections-grid__count { margin: 0; font-size: 12px; color: $color-text-tertiary; font-family: $font-price; }

/* 编号弹窗 */
.serials {
  padding: 16px $page-padding calc(24px + env(safe-area-inset-bottom));
  max-height: 64vh; display: flex; flex-direction: column;
  &__head {
    position: relative; text-align: center; padding-bottom: 14px; margin-bottom: 14px;
    border-bottom: 1px solid $color-border;
  }
  &__title { margin: 0 0 4px; font-size: 16px; font-weight: 700; color: $color-text-primary; }
  &__sub { margin: 0; font-size: 12px; color: $color-text-tertiary; font-family: $font-price; }
  &__close {
    position: absolute; top: 0; right: 0; font-size: 16px; color: $color-text-tertiary; cursor: pointer;
  }
  &__grid {
    display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px;
    overflow-y: auto;
  }
  &__cell {
    text-align: center; padding: 12px 4px; font-size: 12px; color: $color-text-primary;
    background: $color-surface; border: 1px solid $color-border; border-radius: 8px;
    font-family: $font-price; cursor: pointer;
    &:active { background: $color-primary; color: #fff; border-color: $color-primary; }
  }
}
</style>
