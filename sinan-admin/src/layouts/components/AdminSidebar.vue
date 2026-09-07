<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useAppStore } from '@/stores/app'
import { useAdminStore } from '@/stores/admin'
import { useSiteStore } from '@/stores/site'
import { menuGroups } from '@/router/menu'

// ============================================================
// 浅色侧边栏（Soybean Admin 风格）：白底 + 右侧细边框
// 菜单两种展示模式（底部按钮切换，仅手动触发、本地持久化）：
// - flat    平铺：分组标题 + 菜单项全部直接可见
// - submenu 二级：项数 ≥3 的大组折叠为一级可展开菜单，小组保持平铺
// 按管理员权限码过滤；抽屉模式下不显示折叠按钮
// 品牌区（Logo/站点名）读取站点装修配置，与 C 端同步
// ============================================================

defineProps({
  inDrawer: { type: Boolean, default: false }
})

const app = useAppStore()
const admin = useAdminStore()
const site = useSiteStore()
const route = useRoute()

// 二级菜单模式下折叠渲染的最小分组项数（小于此值保持平铺）
const SUBMENU_MIN_ITEMS = 3

const useSubmenu = computed(() => app.menuMode === 'submenu')

// 按权限过滤菜单组
const groups = computed(() =>
  menuGroups
    .map((g) => ({
      ...g,
      items: g.items.filter((i) => admin.hasPermission(i.perm))
    }))
    .filter((g) => g.items.length)
)

const activeMenu = computed(() => route.path)
</script>

<template>
  <aside class="sidebar" :class="{ 'is-collapsed': app.sidebarCollapsed && !inDrawer }">
    <!-- Logo 区（站点装修配置同步：头像 + 站点名） -->
    <div class="sidebar__brand">
      <img class="sidebar__logo" :src="site.brandLogo" alt="logo" />
      <transition name="fade">
        <div v-if="!app.sidebarCollapsed || inDrawer" class="sidebar__title">
          {{ site.siteName }}
        </div>
      </transition>
    </div>

    <!-- 菜单 -->
    <el-scrollbar class="sidebar__scroll thin-scrollbar">
      <el-menu
        :default-active="activeMenu"
        :collapse="app.sidebarCollapsed && !inDrawer"
        :collapse-transition="false"
        unique-opened
        router
        class="sidebar__menu"
        @select="inDrawer && app.toggleDrawer(false)"
      >
        <template v-for="g in groups" :key="g.group">
          <!-- 二级菜单模式 · 大组：折叠为可展开一级菜单 -->
          <el-sub-menu v-if="useSubmenu && g.items.length >= SUBMENU_MIN_ITEMS" :index="g.group">
            <template #title>
              <el-icon><component :is="g.icon" /></el-icon>
              <span>{{ g.group }}</span>
            </template>
            <el-menu-item v-for="item in g.items" :key="item.path" :index="item.path">
              <el-icon><component :is="item.icon" /></el-icon>
              <template #title>{{ item.title }}</template>
            </el-menu-item>
          </el-sub-menu>

          <!-- 平铺模式 / 二级模式下的小组：分组标题 + 一级菜单项 -->
          <template v-else>
            <div v-if="!app.sidebarCollapsed || inDrawer" class="sidebar__group">{{ g.group }}</div>
            <el-menu-item v-for="item in g.items" :key="item.path" :index="item.path">
              <el-icon><component :is="item.icon" /></el-icon>
              <template #title>{{ item.title }}</template>
            </el-menu-item>
          </template>
        </template>
      </el-menu>
    </el-scrollbar>

    <!-- 底部操作区：菜单模式切换（始终显示）+ 侧栏折叠（仅桌面侧栏） -->
    <div class="sidebar__footer" :class="{ 'is-drawer': inDrawer }">
      <button
        class="sidebar__footer-btn"
        type="button"
        :title="useSubmenu ? '切换为平铺菜单' : '切换为二级菜单'"
        @click="app.toggleMenuMode()"
      >
        <el-icon :size="16"><Menu /></el-icon>
        <span v-if="!app.sidebarCollapsed || inDrawer">{{ useSubmenu ? '平铺菜单' : '二级菜单' }}</span>
      </button>
      <button
        v-if="!inDrawer"
        class="sidebar__footer-btn"
        type="button"
        title="收起/展开侧栏"
        @click="app.toggleSidebar()"
      >
        <el-icon :size="16">
          <Expand v-if="app.sidebarCollapsed" />
          <Fold v-else />
        </el-icon>
        <span v-if="!app.sidebarCollapsed">收起菜单</span>
      </button>
    </div>
  </aside>
</template>

<style scoped lang="scss">
.sidebar {
  position: fixed;
  left: 0;
  top: 0;
  bottom: 0;
  width: var(--sidebar-width, 224px);
  display: flex;
  flex-direction: column;
  background: $color-card;
  border-right: 1px solid #ebeef5;
  z-index: 100;
  transition: width 0.2s ease;

  &.is-collapsed {
    width: var(--sidebar-collapsed, 64px);

    :deep(.el-menu) {
      width: 64px;
    }
  }
}

.sidebar__brand {
  height: 56px;
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 0 16px;
  border-bottom: 1px solid $color-border;
  flex-shrink: 0;
  overflow: hidden;
}

.sidebar__logo {
  width: 30px;
  height: 30px;
  border-radius: 8px;
  flex-shrink: 0;
}

.sidebar__title {
  font-size: 15px;
  font-weight: 700;
  color: $color-text-primary;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.sidebar__scroll {
  flex: 1;
}

.sidebar__group {
  padding: 14px 16px 6px;
  font-size: 11px;
  color: $color-text-tertiary;
  letter-spacing: 1px;
}

.sidebar__menu {
  border-right: none;

  &:not(.el-menu--collapse) {
    width: 100%;
  }

  :deep(.el-menu-item) {
    height: 44px;
    line-height: 44px;
    margin: 2px 8px;
    border-radius: 6px;
    color: $color-text-secondary;

    .el-icon {
      color: $color-text-secondary;
    }

    &:hover {
      background: $color-bg;
      color: $color-primary;

      .el-icon { color: $color-primary; }
    }

    &.is-active {
      background: var(--color-primary-bg);
      color: $color-primary;
      font-weight: 600;

      .el-icon { color: $color-primary; }
    }
  }

  // 二级菜单模式：折叠大组的一级标题（点击展开/收起）
  :deep(.el-sub-menu) {
    .el-sub-menu__title {
      height: 44px;
      line-height: 44px;
      margin: 2px 8px;
      border-radius: 6px;
      color: $color-text-secondary;
      font-weight: 600;

      .el-icon {
        color: $color-text-secondary;
      }

      &:hover {
        background: $color-bg;
        color: $color-primary;

        .el-icon { color: $color-primary; }
      }
    }

    // 展开 / 含激活项：标题与箭头着主题色
    &.is-opened > .el-sub-menu__title,
    &.is-active > .el-sub-menu__title {
      color: $color-primary;

      .el-icon,
      .el-sub-menu__icon-arrow { color: $color-primary; }
    }

    // 二级嵌套菜单容器：透明背景贴合侧栏
    .el-menu {
      background: transparent;
    }

    // 二级菜单项：缩进收窄，视觉上从属于一级
    .el-menu-item {
      min-width: 0;
      padding-left: 52px !important;
    }
  }
}

.sidebar__footer {
  height: 44px;
  display: flex;
  border-top: 1px solid $color-border;
  flex-shrink: 0;

  // 抽屉模式：单个切换按钮通栏显示
  &.is-drawer .sidebar__footer-btn {
    flex: none;
    width: 100%;
  }
}

.sidebar__footer-btn {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  cursor: pointer;
  color: $color-text-secondary;
  background: transparent;
  border: none;
  padding: 0;
  font-size: 12px;
  font-family: inherit;
  white-space: nowrap;

  &:hover { color: $color-primary; }

  & + .sidebar__footer-btn {
    border-left: 1px solid $color-border;
  }
}

// 抽屉模式（移动端）铺满
:global(.el-drawer__body) {
  padding: 0;
}
</style>
