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
  <div class="market-card" @click="goResale">
    <div class="market-card__cover-wrap">
      <img class="market-card__cover" :src="item.coverImage" alt="" draggable="false" @contextmenu.prevent @click.prevent />
    </div>
    <p class="market-card__name">{{ item.name }}</p>
    <div class="market-card__meta">
      <span class="market-card__stat">发行<b>{{ item.issueCount }}</b></span>
      <span class="market-card__stat">流通<b>{{ item.circulationCount }}</b></span>
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
    font-size: 11px; color: $color-text-tertiary;
    display: flex; align-items: center; gap: 4px; white-space: nowrap;
    b { font-size: 13px; color: $color-text-primary; font-weight: 400; font-family: $font-price; line-height: 1; }
  }
  &__floor {
    font-size: 12px; color: $color-text-tertiary;
    display: flex; align-items: baseline; line-height: 1;
  }
  &__footer {
    display: flex; align-items: flex-end; justify-content: space-between;
    margin-top: 10px; padding-top: 10px; border-top: 1px solid $color-border;
  }
  &__price { font-size: 18px; font-weight: 700; color: $color-primary; font-family: $font-price; line-height: 1; }
}
</style>
