<script setup>
// 卡片容器
defineProps({
  padding: { type: [String, Number], default: '16px' }, // 支持数字(px)或字符串
  radius: { type: [String, Number], default: '12px' },
  bgColor: { type: String, default: 'var(--color-card)' }
})
</script>

<template>
  <div
    class="app-card"
    :style="{
      padding: typeof padding === 'number' ? padding + 'px' : padding,
      borderRadius: typeof radius === 'number' ? radius + 'px' : radius,
      background: bgColor
    }"
  >
    <slot />
  </div>
</template>

<style scoped lang="scss">
.app-card {
  background: $color-card;
  box-shadow: 0 1px 8px rgba(0, 0, 0, 0.04);
}
</style>
