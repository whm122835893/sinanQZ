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
  <div class="resale-item" @click="goResale">
    <div class="resale-item__cover-wrap">
      <img class="resale-item__cover" :src="item.coverImage" alt="" draggable="false" @contextmenu.prevent @click.prevent />
    </div>
    <div class="resale-item__info">
      <p class="resale-item__name">{{ item.name }}</p>
      <div class="resale-item__stats">
        <span class="resale-item__stat">发行 {{ item.issueCount }}</span>
        <span class="resale-item__stat">流通 {{ item.circulationCount }}</span>
      </div>
    </div>
    <span class="resale-item__fav" :class="{ active: store.isFavorite(item.id) }" @click.stop="onFav">
      <AppIcon :name="store.isFavorite(item.id) ? 'heartFill' : 'heart'" :size="16" />
    </span>
    <div class="resale-item__right">
      <span class="resale-item__floor">地板价</span>
      <span class="resale-item__price">¥{{ item.price }}</span>
    </div>
  </div>
</template>

<style scoped lang="scss">
.resale-item {
  display: flex; align-items: stretch; gap: 12px;
  background: $color-card; border-radius: $radius-lg; padding: 12px; cursor: pointer;
  &__cover-wrap {
    position: relative; flex-shrink: 0;
    border-radius: 8px; overflow: hidden;
  }
  &__cover {
    width: 60px; height: 60px; border-radius: 8px; object-fit: cover; display: block;
    background: #141415;
    -webkit-user-drag: none; -webkit-touch-callout: none; user-select: none; pointer-events: none;
  }
  &__info { flex: 1; min-width: 0; display: flex; flex-direction: column; }
  &__name { margin: 0 0 8px; font-size: 15px; font-weight: 600; color: $color-text-primary; }
  &__stats { display: flex; gap: 16px; margin-top: auto; }
  &__stat {
    font-size: 10px; color: $color-text-tertiary; font-weight: 500; letter-spacing: 0;
  }
  &__right { display: flex; flex-direction: column; align-items: flex-end; justify-content: flex-end; gap: 3px; }
  &__floor { font-size: 11px; color: $color-text-tertiary; line-height: 1; }
  &__price { font-size: 18px; font-weight: 700; color: $color-primary; font-family: $font-price; }
  &__fav {
    align-self: center; flex: 1; min-width: 0;
    display: flex; align-items: center; justify-content: center;
    color: #C6C6C6; cursor: pointer; line-height: 1;
    &.active { color: $color-primary; }
  }
}
</style>
