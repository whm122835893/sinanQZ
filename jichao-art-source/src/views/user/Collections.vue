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

// 按类型筛选库存
const list = computed(() => {
  if (active.value === '数字藏品') return user.inventory.filter(i => i.type !== 'blindbox')
  if (active.value === '盲盒') return user.inventory.filter(i => i.type === 'blindbox')
  return []
})

// 点击藏品卡片 → 弹出该藏品所有编号
const showSerials = ref(false)
const current = ref(null)

function genSerials(it) {
  // 直接使用 nos 数组（已按实际入库数量生成）
  return it.nos || []
}
const currentSerials = computed(() => (current.value ? genSerials(current.value) : []))

function openSerials(it) {
  current.value = it
  showSerials.value = true
}
function goDetail(it, no) {
  showSerials.value = false
  const query = { no, from: 'warehouse' }
  if (it.type === 'blindbox') query.type = 'blindbox'
  router.push({ name: 'collection-detail', params: { id: it.id }, query })
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
          <span v-if="it.type === 'blindbox' && it.opened" class="collections-grid__badge collections-grid__badge--opened">已开启</span>
          <span v-else-if="it.type === 'blindbox'" class="collections-grid__badge">未开启</span>
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
          <p class="serials__title">{{ current?.type === 'blindbox' ? '查看编号' : current?.name }}</p>
          <p class="serials__sub">{{ current?.type === 'blindbox' ? current?.name + ' · ' : '' }}共持有 {{ currentSerials.length }} 个编号</p>
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
.collections-grid__badge {
  position: absolute; top: 8px; left: 8px; z-index: 2;
  font-size: 10px; font-weight: 600; color: #fff;
  background: linear-gradient(135deg, #D00000, #B00000);
  padding: 2px 8px; border-radius: 4px;
}
.collections-grid__badge--opened { background: #999; }
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
