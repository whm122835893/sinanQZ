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
      <img class="market-card__cover" :src="item.coverImage" alt="" />
      <span class="market-card__tag">寄售</span>
    </div>
    <p class="market-card__name">{{ item.name }}</p>
    <div class="market-card__footer">
      <div class="market-card__stats">
        <span class="market-card__stat">发行量<b>{{ item.issueCount }}</b></span>
        <span class="market-card__stat">流通量<b>{{ item.circulationCount }}</b></span>
      </div>
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
  }
  &__tag {
    position: absolute; top: 8px; left: 8px;
    font-size: 10px; font-weight: 600; color: #fff;
    background: linear-gradient(135deg, $color-primary, #8B0000);
    padding: 2px 6px; border-radius: 4px;
  }
  &__name {
    margin: 10px 0 6px;
    font-size: 14px; font-weight: 600; color: $color-text-primary;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  }
  &__stats {
    display: flex; align-items: center; gap: 10px;
  }
  &__stat {
    font-size: 10px; color: $color-text-tertiary;
    display: flex; flex-direction: column; gap: 2px;
    b { font-size: 12px; color: $color-text-primary; font-weight: 600; font-family: $font-price; }
  }
  &__footer {
    display: flex; align-items: flex-end; justify-content: space-between;
    margin-top: 10px; padding-top: 10px; border-top: 1px solid $color-border;
  }
  &__price { font-size: 18px; font-weight: 700; color: $color-primary; font-family: $font-price; }
}
</style>
