<script setup>
import { ref, computed } from 'vue'
import AppIcon from '@/components/AppIcon.vue'
import AppEmpty from '@/components/AppEmpty.vue'

const categories = ['全部', '青铜', '陶瓷', '书画', '玉器']
const activeCategory = ref('全部')

// mock 文物数据
const exhibits = [
  { id: 1, name: '西周青铜鼎', char: '鼎', dynasty: '西周', material: '青铜', category: '青铜', desc: '饕餮纹方鼎，庄重威严，礼器之重' },
  { id: 2, name: '战国谷纹玉璧', char: '璧', dynasty: '战国', material: '玉石', category: '玉器', desc: '苍璧礼天，温润有方，谷纹精美' },
  { id: 3, name: '元青花缠枝罐', char: '青', dynasty: '元', material: '陶瓷', category: '陶瓷', desc: '釉下青花，缠枝莲纹，发色浓艳' },
  { id: 4, name: '宋水墨山水卷', char: '山', dynasty: '宋', material: '书画', category: '书画', desc: '水墨晕染，意境悠远，咫尺千里' },
  { id: 5, name: '汉彩绘陶俑', char: '俑', dynasty: '汉', material: '陶瓷', category: '陶瓷', desc: '彩绘陶俑，衣袂翩然，生动传神' },
  { id: 6, name: '商玉龙形佩', char: '龙', dynasty: '商', material: '玉石', category: '玉器', desc: '苍龙曲身，琢工古拙，神韵天成' },
  { id: 7, name: '唐鎏金铜镜', char: '镜', dynasty: '唐', material: '青铜', category: '青铜', desc: '瑞兽葡萄，鎏金辉煌，照影千年' },
  { id: 8, name: '明文征明行书', char: '书', dynasty: '明', material: '书画', category: '书画', desc: '行云流水，法度谨严，文气盎然' }
]

const filtered = computed(() =>
  activeCategory.value === '全部'
    ? exhibits
    : exhibits.filter(e => e.category === activeCategory.value)
)
</script>

<template>
  <div class="exhibit page">
    <!-- 顶部头图 -->
    <header class="exhibit-header safe-top">
      <span class="exhibit-header__wm calligraphy">RELIC EXHIBITION</span>
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
      <div v-for="item in filtered" :key="item.id" class="exhibit-card">
        <div class="exhibit-card__cover">
          <span class="exhibit-card__char calligraphy">{{ item.char }}</span>
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
    radial-gradient(120% 80% at 80% 0%, rgba(212,165,116,0.18), transparent 60%),
    linear-gradient(160deg, #1f1f1f, #0c0c0c);
  display: flex; flex-direction: column; justify-content: center; padding: 0 $page-padding;
  &__wm {
    position: absolute; right: -8px; top: 16px; font-size: 30px; color: rgba(255,255,255,0.06);
    letter-spacing: 2px; transform: rotate(-6deg); pointer-events: none;
  }
  &__title { position: relative; font-size: 26px; font-weight: 700; color: #fff; margin: 0; }
  &__sub { position: relative; margin: 6px 0 0; font-size: 13px; color: rgba(212,165,116,0.9); letter-spacing: 1px; }
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
  background: $color-card; border-radius: $radius-lg; overflow: hidden;
  box-shadow: 0 2px 8px rgba(0,0,0,0.04);
  &__cover {
    position: relative; height: 116px; display: flex; align-items: center; justify-content: center;
    background: radial-gradient(120% 120% at 50% 0%, #2a2a2a, #121212);
  }
  &__char { font-size: 56px; color: #D4A574; text-shadow: 0 2px 6px rgba(0,0,0,0.5); }
  &__cat {
    position: absolute; top: 8px; left: 8px; font-size: 11px; color: #f3e3c4;
    background: rgba(176,141,85,0.35); padding: 2px 8px; border-radius: 10px;
  }
  &__body { padding: 10px 12px 12px; }
  &__name { font-size: 14px; font-weight: 700; color: $color-text-primary; }
  &__meta { margin-top: 4px; font-size: 12px; color: $color-text-secondary; display: flex; align-items: center; gap: 4px; }
  &__dyn { color: $color-primary; font-weight: 600; }
  &__sep { color: $color-text-tertiary; }
  &__desc { margin-top: 6px; font-size: 12px; color: $color-text-tertiary; line-height: 1.5;
    display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
}
</style>
