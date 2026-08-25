<script setup>
import { useRoute, useRouter } from 'vue-router'
import AppIcon from '@/components/AppIcon.vue'

// 全局底部导航：首页 / 市场 / 商城 / 公告 / 我的
// 商城为圆形大图（半露导航栏外），其余四个为统一 30px SVG 矢量图标
const props = defineProps({
  active: { type: Number, default: -1 } // 显式指定；否则按路由自动识别
})

const route = useRoute()
const router = useRouter()

const tabs = [
  { index: 0, name: 'home', label: '首页', to: '/', icon: 'home' },
  { index: 1, name: 'market', label: '市场', to: '/market/activity', icon: 'bag' },
  { index: 2, name: 'mall', label: '商城', to: '/mall', image: '/images/platform-logo.png', round: true, hideLabel: true },
  { index: 3, name: 'notice', label: '公告', to: '/notice', icon: 'bell' },
  { index: 4, name: 'user', label: '我的', to: '/user', icon: 'person' }
]

function isActive(tab) {
  if (props.active === tab.index) return true
  if (props.active !== -1) return false
  if (tab.name === 'home') return route.path === '/'
  if (tab.name === 'market') return route.path.startsWith('/market')
  if (tab.name === 'mall') return route.path.startsWith('/mall')
  if (tab.name === 'notice') return route.path.startsWith('/notice')
  if (tab.name === 'user') return route.path.startsWith('/user')
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
      :class="{ 'is-active': isActive(tab) }"
      @click="go(tab)"
    >
      <img
        v-if="tab.round"
        class="app-tabbar__image"
        :src="tab.image"
        alt=""
        draggable="false"
        @click.prevent
        @contextmenu.prevent
      />
      <AppIcon
        v-else
        :name="tab.icon"
        :size="30"
        :color="isActive(tab) ? '#C00000' : '#999'"
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
  background: $color-card;
  border-top: 1px solid $color-border;
  z-index: $z-tabbar;
}
.app-tabbar__item {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding-top: 15px;
  gap: 4px;
  color: $color-text-tertiary;
  cursor: pointer;
  &.is-active {
    color: $color-primary;
  }
}
.app-tabbar__label {
  font-size: 12px;
  line-height: 1;
}
.app-tabbar__image {
  width: 70px;
  height: 70px;
  border-radius: 50%;
  object-fit: cover;
  margin-top: -30px;
  border: 2px solid #fff;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.18);
  user-select: none;
  -webkit-user-drag: none;
  -webkit-touch-callout: none;
  pointer-events: none;
}
</style>
