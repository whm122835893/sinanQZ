<script setup>
// 标签：primary（红底白字）/ gray（灰底）/ gold（金底）
// color：可选的自定义底色（十六进制），传了就在 primary 之上覆盖背景，文字仍是白的。
//        公告的类型标签用它 —— 底色由接口算好（见 utils/tagColor.js），不能写死成平台红。
defineProps({
  type: { type: String, default: 'primary' }, // primary | gray | gold
  size: { type: String, default: 'small' },
  color: { type: String, default: '' }
})
</script>

<template>
  <span class="app-tag" :class="[`app-tag--${type}`, `app-tag--${size}`]" :style="color ? { backgroundColor: color } : null">
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
