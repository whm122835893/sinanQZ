<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useAppStore } from '@/stores/app'
import { useAdminStore } from '@/stores/admin'
import { menuGroups } from '@/router/menu'

// ============================================================
// 浅色侧边栏（Soybean Admin 风格）：白底 + 右侧细边框 + 分组标题
// 按管理员权限码过滤菜单；抽屉模式下不显示折叠按钮
// ============================================================

defineProps({
  inDrawer: { type: Boolean, default: false }
})

const app = useAppStore()
const admin = useAdminStore()
const route = useRoute()

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
    <!-- Logo 区 -->
    <div class="sidebar__brand">
      <img class="sidebar__logo" src="/images/platform-logo.png" alt="logo" />
      <transition name="fade">
        <div v-if="!app.sidebarCollapsed || inDrawer" class="sidebar__title">
          <span class="calligraphy">司南</span>珍藏
        </div>
      </transition>
    </div>

    <!-- 菜单 -->
    <el-scrollbar class="sidebar__scroll thin-scrollbar">
      <el-menu
        :default-active="activeMenu"
        :collapse="app.sidebarCollapsed && !inDrawer"
        :collapse-transition="false"
        router
        class="sidebar__menu"
        @select="inDrawer && app.toggleDrawer(false)"
      >
        <template v-for="g in groups" :key="g.group">
          <div v-if="!app.sidebarCollapsed || inDrawer" class="sidebar__group">{{ g.group }}</div>
          <el-menu-item v-for="item in g.items" :key="item.path" :index="item.path">
            <el-icon><component :is="item.icon" /></el-icon>
            <template #title>{{ item.title }}</template>
          </el-menu-item>
        </template>
      </el-menu>
    </el-scrollbar>

    <!-- 底部折叠按钮（仅桌面侧栏） -->
    <div v-if="!inDrawer" class="sidebar__footer" @click="app.toggleSidebar()">
      <el-icon :size="16">
        <Expand v-if="app.sidebarCollapsed" />
        <Fold v-else />
      </el-icon>
      <span v-if="!app.sidebarCollapsed" class="sidebar__fold-text">收起菜单</span>
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

  .calligraphy { color: $color-primary; }
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
}

.sidebar__footer {
  height: 44px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  border-top: 1px solid $color-border;
  cursor: pointer;
  color: $color-text-secondary;
  flex-shrink: 0;

  &:hover { color: $color-primary; }
}

.sidebar__fold-text {
  font-size: 12px;
  white-space: nowrap;
}

// 抽屉模式（移动端）铺满
:global(.el-drawer__body) {
  padding: 0;
}
</style>
