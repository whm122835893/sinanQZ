<script setup lang="ts">
/**
 * 侧边栏：司南 LOGO + 权限过滤后的菜单
 */
import type { MenuNode } from '../config/menu'

defineProps<{
  menus: MenuNode[]
  collapse: boolean
  activePath: string
  expandedKeys: string[]
}>()
</script>

<template>
  <el-aside class="sidebar" :width="collapse ? 'var(--sn-sidebar-collapsed-width)' : 'var(--sn-sidebar-width)'">
    <!-- LOGO -->
    <div class="logo-wrap">
      <div class="logo-badge">司</div>
      <transition name="fade">
        <div v-if="!collapse" class="logo-text">
          <div class="title">司南数字藏品</div>
          <div class="subtitle">管理后台</div>
        </div>
      </transition>
    </div>

    <!-- 菜单 -->
    <el-scrollbar class="menu-scroll">
      <el-menu
        class="sidebar-menu"
        :default-active="activePath"
        :default-openeds="expandedKeys"
        :collapse="collapse"
        :collapse-transition="false"
        background-color="#1c1c20"
        text-color="#a3a4a8"
        active-text-color="#ffffff"
        router
      >
        <template v-for="menu in menus" :key="menu.path">
          <!-- 分组 -->
          <el-sub-menu v-if="menu.children?.length" :index="menu.path">
            <template #title>
              <el-icon><component :is="menu.icon" /></el-icon>
              <span>{{ menu.title }}</span>
            </template>
            <el-menu-item v-for="child in menu.children" :key="child.path" :index="child.path">
              <el-icon><component :is="child.icon" /></el-icon>
              <template #title>{{ child.title }}</template>
            </el-menu-item>
          </el-sub-menu>

          <!-- 单项 -->
          <el-menu-item v-else :index="menu.path">
            <el-icon><component :is="menu.icon" /></el-icon>
            <template #title>{{ menu.title }}</template>
          </el-menu-item>
        </template>
      </el-menu>
    </el-scrollbar>
  </el-aside>
</template>

<style scoped lang="scss">
.sidebar {
  background: #1c1c20;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  transition: width 0.2s ease;
  flex-shrink: 0;
}

.logo-wrap {
  height: var(--sn-header-height);
  display: flex;
  align-items: center;
  padding: 0 14px;
  gap: 10px;
  border-bottom: 1px solid rgba(255, 255, 255, 0.06);
  flex-shrink: 0;
}

.logo-badge {
  width: 32px;
  height: 32px;
  border-radius: 8px;
  background: linear-gradient(135deg, #b00000, #d5342c);
  color: #fff;
  font-size: 16px;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  box-shadow: 0 2px 8px rgba(176, 0, 0, 0.35);
}

.logo-text {
  overflow: hidden;
  white-space: nowrap;

  .title {
    color: #fff;
    font-size: 14px;
    font-weight: 600;
    line-height: 1.3;
  }
  .subtitle {
    color: #7c7d82;
    font-size: 11px;
    line-height: 1.3;
  }
}

.menu-scroll {
  flex: 1;
}

:deep(.sidebar-menu) {
  border-right: none;

  .el-menu-item.is-active {
    background: var(--sn-red) !important;
    color: #fff !important;
  }

  .el-menu-item:hover,
  .el-sub-menu__title:hover {
    background: rgba(255, 255, 255, 0.06) !important;
  }

  .el-menu-item,
  .el-sub-menu__title {
    height: 44px;
    line-height: 44px;
  }
}

.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.15s;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
