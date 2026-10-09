<script setup>
import { computed } from 'vue'
import { useCollectionStore } from '@/stores/collection'
import ResaleItem from '@/components/ResaleItem.vue'
import MarketCard from '@/components/MarketCard.vue'
import AppEmpty from '@/components/AppEmpty.vue'

const store = useCollectionStore()

// 我关注过的藏品：来自 store.followedCollections（关注全集 = /user/favorites + 市场行补价格），
// 跟随市场的关键词筛选与价格排序。市场里查不到的（寄售开关关着）也会在这里出现，卡片显示「暂无寄售」。
const followed = computed(() => store.followedCollections)
</script>

<template>
  <div class="market-following" :class="store.marketViewMode === 'grid' ? 'market-following--grid' : 'market-following--list'">
    <template v-if="followed.length">
      <template v-if="store.marketViewMode === 'list'">
        <ResaleItem
          v-for="c in followed"
          :key="c.id"
          :item="c"
        />
      </template>
      <template v-else>
        <MarketCard
          v-for="c in followed"
          :key="c.id"
          :item="c"
        />
      </template>
    </template>
    <AppEmpty v-else description="暂无关注的藏品" />
  </div>
</template>

<style scoped lang="scss">
.market-following {
  padding: 0 $page-padding;
  &--list {
    display: flex; flex-direction: column; gap: 12px;
  }
  &--grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
  }
}
</style>
