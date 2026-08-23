<script setup>
import AppIcon from './AppIcon.vue'

// 列表项：白色卡片，圆角，项间 1px 分割线
defineProps({
  title: { type: String, default: '' },
  value: { type: String, default: '' },
  arrow: { type: Boolean, default: false },
  icon: { type: String, default: '' },
  border: { type: Boolean, default: true },
  iconColor: { type: String, default: '#333' }
})
</script>

<template>
  <div class="app-list-item" :class="{ 'no-border': !border }">
    <slot name="icon">
      <AppIcon v-if="icon" :name="icon" :size="22" :color="iconColor" class="app-list-item__icon" />
    </slot>
    <span class="app-list-item__title">{{ title }}</span>
    <span class="app-list-item__value"><slot name="value">{{ value }}</slot></span>
    <AppIcon v-if="arrow" name="arrowRight" :size="18" color="#999" />
  </div>
</template>

<style scoped lang="scss">
.app-list-item {
  display: flex;
  align-items: center;
  min-height: $list-item-min-height;
  padding: 0 $page-padding;
  background: $color-card;
  border-bottom: 1px solid $color-border;
  &.no-border { border-bottom: none; }
  &__icon { margin-right: 12px; flex-shrink: 0; }
  &__title {
    font-size: 14px;
    color: $color-text-primary;
    flex-shrink: 0;
  }
  &__value {
    flex: 1;
    text-align: right;
    font-size: 14px;
    color: $color-text-tertiary;
    @include ellipsis;
    margin-right: 6px;
    padding-left: 8px;
  }
}
</style>
