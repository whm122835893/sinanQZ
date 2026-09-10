<script setup>
import { useCollectionStore } from '@/stores/collection'
import ResaleItem from '@/components/ResaleItem.vue'
import MarketCard from '@/components/MarketCard.vue'
import AppEmpty from '@/components/AppEmpty.vue'

const store = useCollectionStore()
</script>

<template>
  <div class="market-activity" :class="store.marketViewMode === 'grid' ? 'market-activity--grid' : 'market-activity--list'">
    <template v-if="store.sortedMarketCollections.length">
      <template v-if="store.marketViewMode === 'list'">
        <ResaleItem
          v-for="c in store.sortedMarketCollections"
          :key="c.id"
          :item="c"
        />
      </template>
      <template v-else>
        <MarketCard
          v-for="c in store.sortedMarketCollections"
          :key="c.id"
          :item="c"
        />
      </template>
    </template>
    <AppEmpty v-else description="未找到相关藏品" />
  </div>
</template>

<style scoped lang="scss">
.market-activity {
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
