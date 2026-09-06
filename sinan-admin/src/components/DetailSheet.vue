<script setup>
// 底部详情抽屉（C 端 AppModal 同款视觉：圆角弹层 + 头部标题）
defineProps({
  title: { type: String, default: '' }
})

const visible = defineModel('show', { type: Boolean, default: false })
</script>

<template>
  <van-popup
    v-model:show="visible"
    position="bottom"
    round
    closeable
    :style="{ maxHeight: '82%' }"
    class="dsheet"
  >
    <div class="dsheet__title">{{ title }}</div>
    <div class="dsheet__body">
      <slot />
    </div>
    <div v-if="$slots.actions" class="dsheet__footer">
      <slot name="actions" />
    </div>
  </van-popup>
</template>

<style scoped lang="scss">
.dsheet__title {
  text-align: center;
  font-size: 15px;
  font-weight: 600;
  padding: 16px 40px 10px;
  color: $color-text-primary;
}

.dsheet__body {
  padding: 0 16px 16px;
  overflow-y: auto;
  max-height: 56vh;
}

.dsheet__footer {
  padding: 10px 16px calc(14px + env(safe-area-inset-bottom));
  display: flex;
  gap: 10px;
  border-top: 1px solid $color-border;
}
</style>
