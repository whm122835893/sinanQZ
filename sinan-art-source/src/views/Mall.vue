<script setup>
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useCollectionStore } from '@/stores/collection'
import AppEmpty from '@/components/AppEmpty.vue'

const router = useRouter()
const store = useCollectionStore()

const categories = ['全部', '青铜', '陶瓷', '书画', '玉器']
const activeCategory = ref('全部')

const filtered = computed(() => {
  const list = store.exhibits
  return activeCategory.value === '全部'
    ? list
    : list.filter(e => e.category === activeCategory.value)
})

function openDetail(item) {
  router.push('/mall/' + item.id)
}
</script>

<template>
  <div class="exhibit page">
    <!-- 顶部头图 -->
    <header class="exhibit-header safe-top">
      <h1 class="exhibit-header__title">文物展览区</h1>
      <p class="exhibit-header__sub">司南 · 数字文物典藏</p>
    </header>

    <!-- 分类 Tab -->
    <div class="exhibit-cats">
      <div
        v-for="cat in categories"
        :key="cat"
        class="exhibit-cats__item"
        :class="{ active: activeCategory === cat }"
        @click="activeCategory = cat"
      >
        <span class="exhibit-cats__label">{{ cat }}</span>
        <span class="exhibit-cats__bar"></span>
      </div>
    </div>

    <!-- 展品网格 -->
    <div v-if="filtered.length" class="exhibit-grid">
      <div v-for="item in filtered" :key="item.id" class="exhibit-card" @click="openDetail(item)">
        <div class="exhibit-card__cover">
          <img class="exhibit-card__img" :src="item.image" :alt="item.name" draggable="false" @contextmenu.prevent />
          <span class="exhibit-card__cat">{{ item.category }}</span>
        </div>
        <div class="exhibit-card__body">
          <div class="exhibit-card__name">{{ item.name }}</div>
          <div class="exhibit-card__meta">
            <span class="exhibit-card__dyn">{{ item.dynasty }}</span>
            <span class="exhibit-card__sep">·</span>
            <span>{{ item.material }}</span>
          </div>
          <div class="exhibit-card__desc">{{ item.desc }}</div>
        </div>
      </div>
    </div>

    <AppEmpty v-else description="暂无展品" />
  </div>
</template>

<style scoped lang="scss">
.exhibit { padding-bottom: calc(#{$tabbar-height} + env(safe-area-inset-bottom) + 12px); }

.exhibit-header {
  position: relative; height: 150px; overflow: hidden;
  background:
    linear-gradient(180deg, rgba(0,0,0,0.08) 0%, rgba(0,0,0,0.55) 100%),
    url('/images/exhibits/exhibit-header-ancient.jpg') center/cover no-repeat;
  display: flex; flex-direction: column; justify-content: center; padding: 0 $page-padding;
  &__title { position: relative; font-size: 28px; font-weight: 700; color: #2c1d0f; margin: 0; text-shadow: 0 1px 6px rgba(255,245,215,0.6); letter-spacing: 2px; }
  &__sub { position: relative; margin: 6px 0 0; font-size: 13px; color: #5a3a1f; letter-spacing: 1px; font-weight: 500; text-shadow: 0 1px 4px rgba(255,245,215,0.5); }
}

.exhibit-cats {
  display: flex; gap: 24px; padding: 14px $page-padding 6px; overflow-x: auto;
  background: $color-card; border-bottom: 1px solid $color-border;
  &__item { display: flex; flex-direction: column; align-items: center; cursor: pointer; white-space: nowrap; }
  &__label { font-size: 14px; color: $color-text-secondary; font-weight: 500; }
  &__bar { margin-top: 6px; width: 18px; height: 3px; border-radius: 2px; background: transparent; }
  &__item.active &__label { color: $color-text-primary; font-weight: 700; }
  &__item.active &__bar { background: $color-primary; }
}

.exhibit-grid {
  display: grid; grid-template-columns: 1fr 1fr; gap: 12px; padding: 12px $page-padding;
}

.exhibit-card {
  background: $color-card; border-radius: $radius-lg; overflow: hidden; cursor: pointer;
  box-shadow: 0 2px 8px rgba(0,0,0,0.04);
  &__cover {
    position: relative; width: 100%; aspect-ratio: 1 / 1; overflow: hidden;
    background: radial-gradient(120% 120% at 50% 0%, #2a2a2a, #121212);
  }
  &__img {
    width: 100%; height: 100%; object-fit: cover; display: block;
    pointer-events: none; -webkit-user-drag: none; -webkit-touch-callout: none; user-select: none;
    -webkit-user-select: none; user-drag: none;
  }
  &__cat {
    position: absolute; top: 8px; left: 8px; font-size: 11px; color: #f3e3c4;
    background: rgba(176,141,85,0.45); padding: 2px 8px; border-radius: 10px; backdrop-filter: blur(6px);
  }
  &__body { padding: 10px 12px 12px; }
  &__name { font-size: 14px; font-weight: 700; color: $color-text-primary; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  &__meta { margin-top: 4px; font-size: 12px; color: $color-text-secondary; display: flex; align-items: center; gap: 4px; }
  &__dyn { color: $color-primary; font-weight: 600; }
  &__sep { color: $color-text-tertiary; }
  &__desc { margin-top: 6px; font-size: 12px; color: $color-text-tertiary; line-height: 1.5;
    display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
}
</style>
