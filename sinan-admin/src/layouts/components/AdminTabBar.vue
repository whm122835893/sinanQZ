<script setup>
import { useRoute, useRouter } from 'vue-router'
import { mainTabs } from '@/router/menu'

// 全局底部导航（C 端同款：液态玻璃 + 红色指示条 + 中央圆形 logo）
const route = useRoute()
const router = useRouter()

function isActive(tab) {
  if (tab.name === 'dashboard') return route.path === '/dashboard'
  if (tab.name === 'marketing') return route.path.startsWith('/marketing')
  if (tab.name === 'collectible') return route.path.startsWith('/collectible') || route.path.startsWith('/blindbox')
  if (tab.name === 'order') return route.path.startsWith('/order') || route.path.startsWith('/resale') || route.path.startsWith('/transfer')
  if (tab.name === 'mine') return route.path.startsWith('/mine')
  return false
}

function go(tab) {
  if (isActive(tab)) return
  router.push(tab.to)
}
</script>

<template>
  <nav class="app-tabbar safe-bottom">
    <div
      v-for="tab in mainTabs"
      :key="tab.name"
      class="app-tabbar__item"
      :class="{ 'is-active': isActive(tab), 'is-round': tab.round }"
      @click="go(tab)"
    >
      <img
        v-if="tab.round"
        class="app-tabbar__image"
        src="/images/platform-logo.png"
        alt=""
        draggable="false"
        @click.prevent
        @contextmenu.prevent
      />
      <van-icon v-else class="app-tabbar__icon" :name="tab.icon" size="24" />
      <span v-if="!tab.hideLabel" class="app-tabbar__label">{{ tab.label }}</span>
    </div>
  </nav>
</template>

<style scoped lang="scss">
.app-tabbar {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  height: $tabbar-height;
  display: flex;
  // —— 液态玻璃核心（与 C 端一致）——
  background: rgba(255, 255, 255, 0.7);
  backdrop-filter: saturate(180%) blur(20px);
  -webkit-backdrop-filter: saturate(180%) blur(20px);
  border-top: 1px solid rgba(0, 0, 0, 0.05);
  box-shadow:
    inset 0 1px 0 rgba(255, 255, 255, 0.6),
    0 -6px 20px rgba(0, 0, 0, 0.06);
  z-index: $z-tabbar;
}

.app-tabbar__item {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 3px;
  color: $color-text-tertiary;
  cursor: pointer;
  position: relative;
  transition: color 0.1s ease, transform 0.1s ease;

  &.is-active {
    color: $color-primary;

    .app-tabbar__icon {
      transform: translateY(-3px) scale(1.08);
    }

    &::after {
      content: '';
      position: absolute;
      top: 0;
      left: 50%;
      transform: translateX(-50%);
      width: 26px;
      height: 3px;
      border-radius: 0 0 3px 3px;
      background: linear-gradient(90deg, $color-primary-dark, $color-primary);
      box-shadow: 0 1px 4px rgba(192, 0, 0, 0.4);
    }
  }

  &.is-round.is-active::after {
    content: none;
  }
}

.app-tabbar__label {
  font-size: 11px;
  line-height: 1;
}

.app-tabbar__icon {
  transition: transform 0.1s ease;
}

.app-tabbar__image {
  width: 54px;
  height: 54px;
  border-radius: 50%;
  object-fit: cover;
  margin-top: -26px;
  border: 3px solid rgba(255, 255, 255, 0.9);
  box-shadow: 0 6px 16px rgba(192, 0, 0, 0.18), 0 2px 6px rgba(0, 0, 0, 0.12);
  background: #fff;
  transition: transform 0.25s ease;
  user-select: none;
  -webkit-user-drag: none;
  -webkit-touch-callout: none;
  pointer-events: none;
}

.app-tabbar__item.is-round.is-active .app-tabbar__image {
  transform: translateY(-4px) scale(1.06);
}
</style>
