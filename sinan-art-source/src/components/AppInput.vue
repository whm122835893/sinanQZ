<script setup>
import { ref, computed } from 'vue'

// 表单输入框：高度 48px，背景 --color-surface，圆角 8px
const props = defineProps({
  modelValue: { type: [String, Number], default: '' },
  label: { type: String, default: '' },
  placeholder: { type: String, default: '请输入' },
  type: { type: String, default: 'text' }, // text | password | tel | number
  maxlength: { type: [Number, String], default: 100 },
  readonly: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
  passwordToggle: { type: Boolean, default: false }, // 密码“显示/隐藏”切换
  error: { type: String, default: '' }               // 校验错误文案（为空不显示）
})
const emit = defineEmits(['update:modelValue'])

const showPwd = ref(false)
const inputType = computed(() => {
  if (props.type === 'password' && showPwd.value) return 'text'
  return props.type
})

function onInput(e) {
  emit('update:modelValue', e.target.value)
}
function toggle() {
  showPwd.value = !showPwd.value
}
</script>

<template>
  <div class="app-input">
    <label v-if="label" class="app-input__label">{{ label }}</label>
    <div class="app-input__field" :class="{ 'is-readonly': readonly, 'is-disabled': disabled, 'is-error': !!error }">
      <input
        class="app-input__native"
        :type="inputType"
        :value="modelValue"
        :placeholder="placeholder"
        :maxlength="maxlength"
        :readonly="readonly"
        :disabled="disabled"
        @input="onInput"
      />
      <span v-if="passwordToggle && type === 'password'" class="app-input__toggle" @click="toggle">
        {{ showPwd ? '隐藏' : '显示' }}
      </span>
      <slot name="suffix" />
    </div>
    <p v-if="error" class="app-input__error">{{ error }}</p>
  </div>
</template>

<style scoped lang="scss">
.app-input {
  &__label {
    display: block;
    font-size: 14px;
    color: $color-text-primary;
    margin-bottom: 8px;
    font-weight: 500;
  }
  &__field {
    display: flex;
    align-items: center;
    height: 48px;
    background: $color-surface;
    border-radius: $radius-md;
    padding: 0 14px;
  }
  &__native {
    flex: 1;
    height: 100%;
    border: none;
    outline: none;
    background: transparent;
    font-size: 16px; /* ≥16px，防止 iOS 聚焦输入框时自动放大页面 */
    color: $color-text-primary;
    &::placeholder { color: $color-text-tertiary; }
    &:disabled, &[readonly] { color: $color-text-secondary; }
  }
  &__toggle {
    font-size: 12px;
    color: $color-text-secondary;
    margin-left: 10px;
    cursor: pointer;
    flex-shrink: 0;
  }
  &.is-readonly &__field, .is-readonly {
    background: $color-surface;
  }
  &__error {
    margin: 6px 0 0; font-size: 12px; color: $color-primary;
  }
}
.is-readonly.app-input__field, .app-input__field.is-readonly { background: $color-surface; }
.is-disabled.app-input__field, .app-input__field.is-disabled { opacity: .7; }
.app-input__field.is-error { border: 1px solid $color-primary; background: $color-primary-bg; }
</style>
