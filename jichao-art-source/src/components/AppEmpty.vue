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
      <!-- 飘出的 3 个红色爱心 / 花瓣 -->
      <path d="M52 40c-3-5-12-3-12 3 0 4 7 9 12 13 5-4 12-9 12-13 0-6-9-8-12-3z" fill="#C00000"/>
      <path d="M86 26c-2.4-4-9.6-2.4-9.6 2.4 0 3.2 5.6 7.2 9.6 10.4 4-3.2 9.6-7.2 9.6-10.4 0-4.8-7.2-6.4-9.6-2.4z" fill="#E08080"/>
      <path d="M116 44c-2-3.4-8-2-8 2 0 2.8 4.8 6 8 8.8 3.2-2.8 8-6 8-8.8 0-4-6-5.4-8-2z" fill="#C00000"/>
      <!-- 箱身 -->
      <path d="M34 78 L126 78 L116 128 L44 128 Z" fill="#EAD7B7" stroke="#C9A66B" stroke-width="2.5" stroke-linejoin="round"/>
      <!-- 箱内阴影 -->
      <path d="M44 78 L116 78 L112 92 L48 92 Z" fill="#DCC49A"/>
      <!-- 左右打开的箱盖 -->
      <path d="M34 78 L50 60 L78 78 Z" fill="#F0E2C6" stroke="#C9A66B" stroke-width="2.5" stroke-linejoin="round"/>
      <path d="M126 78 L110 60 L82 78 Z" fill="#F0E2C6" stroke="#C9A66B" stroke-width="2.5" stroke-linejoin="round"/>
      <!-- 箱体竖纹 -->
      <path d="M80 78 L80 128" stroke="#D8BE8E" stroke-width="2"/>
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
