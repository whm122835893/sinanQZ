<script setup>
// 全局空状态：统一“打开的牛皮纸箱 + 顶部飘出 3 个红色爱心”插画
defineProps({
  description: { type: String, default: '暂无数据' },
  image: { type: String, default: 'box' }
})
</script>

<template>
  <div class="app-empty">
    <svg class="app-empty__img" viewBox="0 0 160 150" fill="none" xmlns="http://www.w3.org/2000/svg">
      <!-- 地面投影 -->
      <ellipse cx="80" cy="138" rx="46" ry="5" fill="#E8D5B7" opacity="0.6"/>
      <!-- 展柜底座 -->
      <path d="M36 126 L124 126 L118 134 L42 134 Z" fill="#C9A66B" opacity="0.8"/>
      <rect x="40" y="122" width="80" height="6" rx="2" fill="#E8D5B7" stroke="#C9A66B" stroke-width="1.5"/>
      <!-- 玻璃罩主体 -->
      <path d="M46 122 L46 54 Q46 44 56 44 L104 44 Q114 44 114 54 L114 122" fill="#F5F9FC" stroke="#B8C8D6" stroke-width="2" stroke-linejoin="round"/>
      <!-- 玻璃高光 -->
      <path d="M52 60 L52 110" stroke="#D4E4EF" stroke-width="3" stroke-linecap="round" opacity="0.7"/>
      <path d="M108 64 L108 100" stroke="#D4E4EF" stroke-width="2" stroke-linecap="round" opacity="0.5"/>
      <!-- 玻璃顶部 -->
      <ellipse cx="80" cy="44" rx="28" ry="6" fill="#EBF2F7" stroke="#B8C8D6" stroke-width="2"/>
      <path d="M52 44 Q52 38 80 38 Q108 38 108 44" fill="none" stroke="#B8C8D6" stroke-width="2"/>
      <!-- 内部空空：一颗小小的灰钻石 -->
      <path d="M74 108 L86 108 L92 102 L80 94 L68 102 Z" fill="#D0D8DE" opacity="0.5"/>
      <path d="M80 108 L80 116" stroke="#B8C8D6" stroke-width="1.5"/>
      <!-- 漂浮的星星 -->
      <path d="M44 32 l2 4.5 l4.5 .7 l-3.4 3.2 l1 4.6 l-4.1 -2.3 l-4.1 2.3 l1 -4.6 l-3.4 -3.2 l4.5 -.7 z" fill="#E8A33D"/>
      <path d="M128 26 l1.5 3.5 l3.5 .5 l-2.6 2.5 l.8 3.6 l-3.2 -1.8 l-3.2 1.8 l.8 -3.6 l-2.6 -2.5 l3.5 -.5 z" fill="#C00000"/>
      <circle cx="32" cy="72" r="2.2" fill="#E8A33D"/>
      <circle cx="134" cy="68" r="1.8" fill="#C00000"/>
      <circle cx="58" cy="20" r="1.5" fill="#E8A33D"/>
      <circle cx="102" cy="16" r="1.2" fill="#C00000"/>
    </svg>
    <p class="app-empty__text">{{ description }}</p>
  </div>
</template>

<style scoped lang="scss">
.app-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 48px 0;
  width: 100%;
}
.app-empty__img {
  width: 120px;
  height: 112px;
}
.app-empty__text {
  margin: 12px 0 0;
  font-size: 14px;
  color: $color-text-secondary;
}
</style>
