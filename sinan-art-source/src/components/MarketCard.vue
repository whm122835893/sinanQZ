<script setup>
import { useRouter } from 'vue-router'
import { showToast } from 'vant'
import { useCollectionStore } from '@/stores/collection'
import AppIcon from '@/components/AppIcon.vue'

const props = defineProps({
  item: { type: Object, required: true }
})

const router = useRouter()
const store = useCollectionStore()

function goResale() {
  router.push('/resale/' + props.item.id)
}

function onFav() {
  const fav = store.toggleFavorite(props.item.id)
  showToast(fav ? '已关注' : '已取消关注')
}
</script>

<template>
  <div class="market-card" @click="goResale">
    <div class="market-card__cover-wrap">
      <img class="market-card__cover" :src="item.coverImage" alt="" draggable="false" @contextmenu.prevent @click.prevent />
      <span class="market-card__fav" :class="{ active: store.isFavorite(item.id) }" @click.stop="onFav">
        <AppIcon :name="store.isFavorite(item.id) ? 'heartFill' : 'heart'" :size="14" />
      </span>
    </div>
    <p class="market-card__name">{{ item.name }}</p>
    <div class="market-card__meta">
      <span class="market-card__stat">发行 {{ item.issueCount }}</span>
      <span class="market-card__stat">流通 {{ item.circulationCount }}</span>
    </div>
    <div class="market-card__footer">
      <span class="market-card__floor">地板价</span>
      <span class="market-card__price">¥{{ item.price }}</span>
    </div>
  </div>
</template>

<style scoped lang="scss">
.market-card {
  background: $color-card;
  border-radius: $radius-lg;
  padding: 10px;
  cursor: pointer;
  display: flex;
  flex-direction: column;
  &__cover-wrap {
    position: relative;
    width: 100%;
    aspect-ratio: 1 / 1;
    border-radius: 10px;
    overflow: hidden;
    background: #141415;
  }
  &__cover {
    width: 100%; height: 100%; object-fit: cover; display: block;
    -webkit-user-drag: none; -webkit-touch-callout: none; user-select: none; pointer-events: none;
  }
  &__fav {
    position: absolute; top: 8px; right: 8px; z-index: 2;
    width: 26px; height: 26px; border-radius: 50%;
    background: rgba(0, 0, 0, 0.35);
    display: flex; align-items: center; justify-content: center;
    color: #C6C6C6; cursor: pointer;
    &.active { color: $color-primary; }
  }
  &__name {
    margin: 10px 0 0;
    font-size: 14px; font-weight: 600; color: $color-text-primary;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  }
  &__meta {
    display: flex; align-items: center; gap: 14px;
    flex-wrap: nowrap; margin-top: 6px;
  }
  &__stat {
    font-size: 10px; color: $color-text-tertiary; font-weight: 500;
    display: flex; align-items: center; gap: 4px; white-space: nowrap;
  }
  &__floor {
    font-size: 12px; color: $color-text-tertiary;
    display: flex; align-items: baseline; line-height: 1;
  }
  &__footer {
    display: flex; align-items: baseline; justify-content: space-between;
    margin-top: auto; padding-top: 10px; border-top: 1px solid $color-border;
  }
  &__price { font-size: 18px; font-weight: 700; color: $color-primary; font-family: $font-price; line-height: 1; }
}
</style>
