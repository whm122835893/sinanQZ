<script setup>
import { useRouter } from 'vue-router'

const props = defineProps({
  item: { type: Object, required: true }
})

const router = useRouter()
function goResale() {
  router.push('/resale/' + props.item.id)
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
        <span class="resale-item__stat">发行量<b>{{ item.issueCount }}</b></span>
        <span class="resale-item__stat">流通量<b>{{ item.circulationCount }}</b></span>
      </div>
    </div>
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
    font-size: 11px; color: $color-text-tertiary;
    display: flex; flex-direction: column; gap: 3px;
    b { font-size: 13px; color: $color-text-primary; font-weight: 600; font-family: $font-price; }
  }
  &__right { display: flex; flex-direction: column; align-items: flex-end; justify-content: flex-end; gap: 3px; }
  &__floor { font-size: 11px; color: $color-text-tertiary; line-height: 1; }
  &__price { font-size: 18px; font-weight: 700; color: $color-primary; font-family: $font-price; }
}
</style>
