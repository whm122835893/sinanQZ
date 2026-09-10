<script setup>
// 标签：primary（红底白字）/ gray（灰底）
defineProps({
  type: { type: String, default: 'primary' }, // primary | gray | gold
  size: { type: String, default: 'small' }
})
</script>

<template>
  <span class="app-tag" :class="[`app-tag--${type}`, `app-tag--${size}`]">
    <slot />
  </span>
</template>

<style scoped lang="scss">
.app-tag {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 4px;
  line-height: 1;
  font-weight: 500;
  &--small { font-size: 12px; padding: 3px 6px; }
  &--large { font-size: 13px; padding: 4px 8px; }
  &--primary { background: $color-primary; color: #fff; }
  &--gray { background: #BBBBBB; color: #fff; }
  &--gold { background: $color-gold; color: #fff; }
}
</style>
