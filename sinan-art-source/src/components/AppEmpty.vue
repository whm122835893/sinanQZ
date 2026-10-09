<script setup>
// 全局空状态：铜鼎实物图（背景与脚下投影已抠掉，做法见 deploy/RUNBOOK.md 7.5）
// ?v= 是刻意加的：public/ 下的图路径不变时 nginx 走启发式缓存，换图后老访客会一直看到旧图
defineProps({
  description: { type: String, default: '暂无数据' },
  image: { type: String, default: 'box' }
})
</script>

<template>
  <div class="app-empty">
    <img class="app-empty__img" src="/images/tab/empty-ding.png?v=20261009" alt="" draggable="false" @contextmenu.prevent />
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
  object-fit: contain;
  -webkit-user-drag: none;
  -webkit-touch-callout: none;
  user-select: none;
  pointer-events: none;
}
.app-empty__text {
  margin: 12px 0 0;
  font-size: 14px;
  color: $color-text-secondary;
}
</style>
