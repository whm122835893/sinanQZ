<script setup>
import AppIcon from './AppIcon.vue'

// 全局顶部导航栏
const props = defineProps({
  title: { type: String, default: '' },
  leftArrow: { type: Boolean, default: true },
  rightText: { type: String, default: '' },
  rightIcon: { type: String, default: '' },
  fixed: { type: Boolean, default: false },
  placeholder: { type: Boolean, default: false }, // 占位撑高，避免内容被固定导航遮挡
  border: { type: Boolean, default: true },
  transparent: { type: Boolean, default: false }
})

const emit = defineEmits(['click-left', 'click-right'])
</script>

<template>
  <div class="app-navbar-wrap">
    <header
      class="app-navbar"
      :class="{ 'is-fixed': fixed, 'no-border': !border, 'is-transparent': transparent }"
    >
      <div class="app-navbar__inner safe-top">
        <div class="app-navbar__left" @click="emit('click-left')">
          <AppIcon v-if="leftArrow" name="back" :size="24" color="#333" />
        </div>
        <div class="app-navbar__title">{{ title }}</div>
        <div class="app-navbar__right" @click="emit('click-right')">
          <slot name="right">
            <AppIcon v-if="rightIcon" :name="rightIcon" :size="22" color="#333" />
            <span v-else-if="rightText" class="app-navbar__right-text">{{ rightText }}</span>
          </slot>
        </div>
      </div>
    </header>
    <div v-if="fixed && placeholder" class="app-navbar__placeholder safe-top"></div>
  </div>
</template>

<style scoped lang="scss">
.app-navbar-wrap {
  position: relative;
  z-index: $z-navbar;
}
.app-navbar {
  background: $color-card;
  &.is-fixed {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    z-index: $z-navbar;
  }
  &.no-border .app-navbar__inner {
    border-bottom: none;
  }
  &.is-transparent {
    background: transparent;
    .app-navbar__inner { border-bottom: none; }
  }
}
.app-navbar__inner {
  height: $navbar-height;
  display: flex;
  align-items: center;
  position: relative;
  border-bottom: 1px solid $color-border;
}
.app-navbar__left {
  position: absolute;
  left: 0;
  height: 100%;
  width: 48px;
  display: flex;
  align-items: center;
  justify-content: center;
}
.app-navbar__title {
  flex: 1;
  text-align: center;
  font-size: 16px;
  font-weight: 600;
  color: $color-text-primary;
  @include ellipsis;
  padding: 0 48px;
}
.app-navbar__right {
  position: absolute;
  right: 0;
  height: 100%;
  min-width: 48px;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0 12px;
}
.app-navbar__right-text {
  font-size: 14px;
  color: $color-primary;
}
.app-navbar__placeholder {
  height: $navbar-height;
}
</style>
