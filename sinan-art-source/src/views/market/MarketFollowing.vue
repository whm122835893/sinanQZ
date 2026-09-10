<script setup>
import { computed } from 'vue'
import { useCollectionStore } from '@/stores/collection'
import ResaleItem from '@/components/ResaleItem.vue'
import MarketCard from '@/components/MarketCard.vue'
import AppEmpty from '@/components/AppEmpty.vue'

const store = useCollectionStore()

// 已关注的市场藏品（跟随价格排序与关键词过滤）
const followed = computed(() =>
  store.sortedMarketCollections.filter(c => store.isFavorite(c.id))
)
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
