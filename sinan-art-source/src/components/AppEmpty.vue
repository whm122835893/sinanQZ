<script setup>
// 全局空状态：简笔画「开箱」+ 朱红回纹（内联 SVG，不再用位图）
//
// 为什么是内联 SVG 而不是 public/ 下的图片：
//   1) 换图不用再手动加 ?v= 绕 nginx 启发式缓存（老访客必然看到新图，因为 JS 分片名带 hash）；
//   2) 回纹颜色写的是 var(--color-primary)，后台改主题色时图标跟着变，位图做不到；
//   3) 120×120 的实物图 50KB，这套线条约 1KB，且任意屏幕密度都不糊。
//
// 图形口径（改之前先读）：等轴视角的开口箱，两片后翻盖支起，箱身下沿绕一圈回纹
// —— 回纹取自平台文物图样的那圈纹饰，用来贴合「数字藏品」这个网站的特点。
// 线条走次级文字色、回纹走主题色，全站只有这一个空状态组件（14 个页面引用它）。
defineProps({
  description: { type: String, default: '暂无数据' }
})
</script>

<template>
  <div class="app-empty">
    <svg class="app-empty__img" viewBox="0 0 120 120" fill="none" aria-hidden="true" focusable="false">
      <g class="app-empty__box" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
        <path d="M28 46 L60 30 L92 46 L60 62 Z" />
        <path d="M28 46 L60 55 L92 46" />
        <path d="M28 46 L24 31 L56 15 L60 30" />
        <path d="M92 46 L96 31 L64 15 L60 30" />
        <path d="M28 46 L28 80 L60 96 L60 62" />
        <path d="M92 46 L92 80 L60 96 L60 62" />
      </g>
      <g class="app-empty__motif" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
        <g transform="translate(28 46) skewY(26.57)">
          <path d="M0 11 L0 0 L13 0 L13 11 L3.5 11 L3.5 3.5 L9.5 3.5 L9.5 7 L6.5 7" transform="translate(2 20)" />
          <path d="M0 11 L0 0 L13 0 L13 11 L3.5 11 L3.5 3.5 L9.5 3.5 L9.5 7 L6.5 7" transform="translate(17 20)" />
        </g>
        <g transform="translate(60 62) skewY(-26.57)">
          <path d="M0 11 L0 0 L13 0 L13 11 L3.5 11 L3.5 3.5 L9.5 3.5 L9.5 7 L6.5 7" transform="translate(2 20)" />
          <path d="M0 11 L0 0 L13 0 L13 11 L3.5 11 L3.5 3.5 L9.5 3.5 L9.5 7 L6.5 7" transform="translate(17 20)" />
        </g>
      </g>
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
  // 网格容器内占满整行，避免空状态落到第一列而靠左
  grid-column: 1 / -1;
}
.app-empty__img {
  width: 120px;
  height: 120px;
  display: block;
  -webkit-touch-callout: none;
  user-select: none;
  pointer-events: none;
}
// 箱体线条：次级文字色压淡，只做「有个箱子」，不抢文字
.app-empty__box {
  stroke: $color-text-secondary;
  opacity: 0.55;
}
// 回纹：跟随后台主题色（site.js 运行时写 --color-primary）
.app-empty__motif {
  stroke: $color-primary;
  opacity: 0.85;
}
.app-empty__text {
  margin: 12px 0 0;
  font-size: 14px;
  color: $color-text-secondary;
}
</style>
