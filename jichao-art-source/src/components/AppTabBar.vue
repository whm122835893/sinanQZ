<script setup>
import { useRoute, useRouter } from 'vue-router'
import AppIcon from './AppIcon.vue'

// 全局底部导航：首页 / 市场 / 商城 / 公告 / 我的
// 四个线性标签使用现代矢量图标（AppIcon），商城为圆形平台 logo（半露导航栏外）
const props = defineProps({
  active: { type: Number, default: -1 } // 显式指定；否则按路由自动识别
})

const route = useRoute()
const router = useRouter()

const tabs = [
  { index: 0, name: 'home',   label: '首页', to: '/',                icon: 'home' },
  { index: 1, name: 'market', label: '市场', to: '/market/activity', icon: 'grid' },
  { index: 2, name: 'mall',   label: '商城', to: '/mall',            round: true, hideLabel: true },
  { index: 3, name: 'notice', label: '公告', to: '/notice',          icon: 'bell' },
  { index: 4, name: 'user',   label: '我的', to: '/user',            icon: 'person' }
]

function isActive(tab) {
  if (props.active === tab.index) return true
  if (props.active !== -1) return false
  if (tab.name === 'home')   return route.path === '/'
  if (tab.name === 'market') return route.path.startsWith('/market')
  if (tab.name === 'mall')   return route.path.startsWith('/mall')
  if (tab.name === 'notice') return route.path.startsWith('/notice')
  if (tab.name === 'user')   return route.path.startsWith('/user')
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
      v-for="tab in tabs"
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
      <AppIcon
        v-else
        class="app-tabbar__icon"
        :name="tab.icon"
        :size="30"
      />
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
  // —— 液态玻璃核心：半透明 + 背景模糊 + 饱和增强 ——
  background: rgba(255, 255, 255, 0.7);
  backdrop-filter: saturate(180%) blur(20px);
  -webkit-backdrop-filter: saturate(180%) blur(20px);
  // 顶边细线 + 内高光，营造悬浮玻璃质感
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
  gap: 3px;                         // 图标与文字间距
  color: $color-text-tertiary;      // 未选中：灰 #999
  cursor: pointer;
  position: relative;
  transition: color 0.25s ease, transform 0.25s ease;
  &.is-active {
    color: $color-primary;          // 选中：红色 #C00000
    // 图标上浮 + 轻微放大
    .app-tabbar__icon {
      transform: translateY(-3px) scale(1.1);
    }
    // 顶部红色渐变指示条
    &::after {
      content: '';
      position: absolute;
      top: 0;
      left: 50%;
      transform: translateX(-50%);
      width: 26px;                  // 指示条宽
      height: 3px;                  // 指示条高
      border-radius: 0 0 3px 3px;
      background: linear-gradient(90deg, $color-primary-dark, $color-primary);
      box-shadow: 0 1px 4px rgba(192, 0, 0, 0.4);
    }
  }
  // 商城圆形项不显示顶部指示条
  &.is-round.is-active::after {
    content: none;
  }
}
.app-tabbar__label {
  font-size: 11px;                  // 文字大小
  line-height: 1;
  transition: color 0.25s ease;
}
.app-tabbar__icon {
  width: 30px;                     // 线性图标尺寸
  height: 30px;
  object-fit: contain;
  transition: transform 0.25s ease;
}
.app-tabbar__image {
  width: 60px;                     // 商城圆标尺寸
  height: 60px;
  border-radius: 50%;
  object-fit: cover;
  margin-top: -30px;               // 半露导航栏外（再往上移一点）
  border: 3px solid rgba(255, 255, 255, 0.9);
  box-shadow: 0 6px 16px rgba(192, 0, 0, 0.18), 0 2px 6px rgba(0, 0, 0, 0.12);
  background: #fff;
  transition: transform 0.25s ease;
  user-select: none;
  -webkit-user-drag: none;
  -webkit-touch-callout: none;
  pointer-events: none;
}
// 商城圆形项激活时上浮放大
.app-tabbar__item.is-round.is-active .app-tabbar__image {
  transform: translateY(-4px) scale(1.06);
}
</style>
