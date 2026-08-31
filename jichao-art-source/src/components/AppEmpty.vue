<script setup>
// 全局空状态：与首页发售日历、抽奖活动同风格图标
defineProps({
  description: { type: String, default: '暂无数据' },
  image: { type: String, default: 'box' }
})
</script>

<template>
  <div class="app-empty">
    <img class="app-empty__img" src="/images/tab/empty-carton.png" alt="" draggable="false" @contextmenu.prevent />
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
