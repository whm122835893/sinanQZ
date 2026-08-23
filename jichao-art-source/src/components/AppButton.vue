<script setup>
// 按钮：primary（全宽红胶囊）/ outline（描边红）
const props = defineProps({
  type: { type: String, default: 'primary' }, // primary | outline
  size: { type: String, default: 'large' },    // large(全宽) | small(行内)
  disabled: { type: Boolean, default: false },
  round: { type: Boolean, default: true },
  block: { type: Boolean, default: true }
})
const emit = defineEmits(['click'])
</script>

<template>
  <button
    class="app-btn"
    :class="[
      `app-btn--${type}`,
      `app-btn--${size}`,
      { 'is-disabled': disabled, 'is-round': round, 'is-block': block }
    ]"
    :disabled="disabled"
    @click="!disabled && emit('click', $event)"
  >
    <slot />
  </button>
</template>

<style scoped lang="scss">
.app-btn {
  border: none;
  outline: none;
  cursor: pointer;
  font-family: $font-family;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  transition: opacity .15s, background .15s;

  &.is-block { width: 100%; }
  &.is-round { border-radius: $radius-pill; }

  &--large {
    height: 48px;
    font-size: 16px;
    font-weight: 500;
  }
  &--small {
    height: 32px;
    padding: 0 14px;
    font-size: 13px;
    border-radius: $radius-md;
  }

  &--primary {
    color: #fff;
    background: linear-gradient(135deg, #D00000, #B00000);
    &:active { background: $color-primary-dark; }
    &.is-disabled {
      background: $color-primary-disabled;
      color: #fff;
    }
  }

  &--outline {
    background: #fff;
    color: $color-primary;
    border: 1px solid $color-primary;
    &:active { background: $color-primary-light; }
  }
}
</style>
