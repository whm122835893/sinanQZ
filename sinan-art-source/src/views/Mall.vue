<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useCollectionStore } from '@/stores/collection'
import AppEmpty from '@/components/AppEmpty.vue'

const router = useRouter()
const store = useCollectionStore()

// 文物展览分类（真实接口：GET /api/collections/categories?scene=artifact，
// 管理端「内容 → 分类管理」维护；失败兜底静态默认）
const FALLBACK_CATS = [
  { id: 0, name: '全部', code: 'all' },
  { id: 1, name: '青铜', code: 'bronze' },
  { id: 2, name: '陶瓷', code: 'ceramics' },
  { id: 3, name: '书画', code: 'calligraphy' },
  { id: 4, name: '玉器', code: 'jade' }
]
const categories = computed(() =>
  store.artifactCategories.length ? store.artifactCategories : FALLBACK_CATS
)
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

onMounted(() => {
  // 分类动态化 + 首次进入展览区拉取展品（此前无入口触发，列表恒为空）
  store.fetchCategories('artifact').catch(() => {})
  store.fetchExhibits().catch(() => {})
})
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
        :key="cat.code"
        class="exhibit-cats__item"
        :class="{ active: activeCategory === cat.name }"
        @click="activeCategory = cat.name"
      >
        <span class="exhibit-cats__label">{{ cat.name }}</span>
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
  // §7.48：头图从「浅米黄青绿山水 + 松鹤」换成「深暗底青铜方鼎」。
  // 图是 qa/make_exhibit_header_bronze.py 从 AI 原图裁出来的（裁掉右下角「Qoder AI生成」水印带 + 缩到 1280 宽），
  // 52,972 B / md5 d09524b2…，比旧图小一个数量级。**换图必须连文字色一起换**：旧深棕字压在新暗底上只有 1.25:1 = 看不见。
  background:
    linear-gradient(180deg, rgba(0,0,0,0.08) 0%, rgba(0,0,0,0.55) 100%),
    url('/images/exhibits/exhibit-header-bronze-ding-v2.webp') center/cover no-repeat;
  display: flex; flex-direction: column; justify-content: center; padding: 0 $page-padding;
  // 暗底 + 白字：28px 粗体按 WCAG 属大字号，要求 ≥3:1；离线设计值最坏点 20.37:1、真渲染四档最坏 12.96:1
  // （两套数差这么多是 postcss 把 px 折成 vw，两把尺量的盒子不一样，细节写在 tests/exhibit-header.spec.js 文件头）；
  // 原来的 text-shadow 是一圈暖白辉光（给浅底深字用的），在暗底上它会糊在笔画边上，换成压深的投影。
  &__title { position: relative; font-size: 28px; font-weight: 700; color: #FFFFFF; margin: 0; text-shadow: 0 1px 6px rgba(0,0,0,0.65); letter-spacing: 2px; }
  // 副标 13px 是正文字号，要求 ≥4.5:1，离线 14.52:1 / 真渲染四档最坏 11.49:1（85% 白，量具已把半透明和底色合成后再算）。
  &__sub { position: relative; margin: 6px 0 0; font-size: 13px; color: rgba(255,255,255,0.85); letter-spacing: 1px; font-weight: 500; text-shadow: 0 1px 4px rgba(0,0,0,0.55); }
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
    position: absolute; top: 8px; left: 8px; z-index: 2;
    font-size: 10px; font-weight: 600; color: #fff;
    background: linear-gradient(135deg, $color-primary, #8B0000);
    padding: 2px 6px; border-radius: 4px;
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
