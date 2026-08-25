<script setup>
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppIcon from '@/components/AppIcon.vue'
import { useIconThemeStore } from '@/stores/iconTheme'

// 全局底部导航：首页 / 市场 / 商城 / 公告 / 我的
// 商城为圆形大图（半露导航栏外），其余四个 Tab 的图标风格由
// iconTheme store 统一管理，后期管理后台调用 setTheme() 即可一键切换。
const props = defineProps({
  active: { type: Number, default: -1 } // 显式指定；否则按路由自动识别
})

const route = useRoute()
const router = useRouter()
const iconTheme = useIconThemeStore()

// 静态 Tab 基础信息（不含图标），与主题无关
const BASE_TABS = [
  { index: 0, name: 'home',   label: '首页', to: '/' },
  { index: 1, name: 'market', label: '市场', to: '/market/activity' },
  { index: 2, name: 'mall',   label: '商城', to: '/mall', image: '/images/platform-logo.png', round: true, hideLabel: true },
  { index: 3, name: 'notice',  label: '公告', to: '/notice' },
  { index: 4, name: 'user',    label: '我的', to: '/user' }
]

// 合并主题图标配置到 Tab 定义（home/market/notice/user 读 store，mall 固定）
const tabs = computed(() =>
  BASE_TABS.map(tab => {
    if (tab.round) return tab
    const iconCfg = iconTheme.getTabIcon(tab.name)
    return { ...tab, ...iconCfg }
  })
)

// 当前主题渲染方式：'svg' | 'image'
const renderType = computed(() => iconTheme.current.type)

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
      <!-- 商城：圆形大图，固定不变 -->
      <img
        v-if="tab.round"
        class="app-tabbar__image"
        :src="tab.image"
        alt=""
        draggable="false"
        @click.prevent
        @contextmenu.prevent
      />
      <!-- SVG 矢量主题 -->
      <AppIcon
        v-else-if="renderType === 'svg'"
        :name="tab.icon"
        :size="30"
        :color="isActive(tab) ? '#C00000' : '#999'"
      />
      <!-- 位图主题（3D 宝石等） -->
      <img
        v-else
        class="app-tabbar__icon"
        :src="isActive(tab) ? tab.imageActive : tab.image"
        alt=""
        draggable="false"
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
.app-tabbar__icon {
  width: 38px;
  height: 38px;
  object-fit: contain;
  -webkit-user-drag: none;
  user-select: none;
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
