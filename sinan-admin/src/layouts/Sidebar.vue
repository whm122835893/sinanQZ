<script setup lang="ts">
// 侧边栏：品牌区 + 权限菜单（折叠态仅显示图标）
import { computed } from 'vue'
import { useRoute, type RouteRecordRaw } from 'vue-router'
import { useAppStore } from '@/stores/app'
import { usePermissionStore } from '@/stores/permission'

const route = useRoute()
const appStore = useAppStore()
const permissionStore = usePermissionStore()

interface MenuItem {
  path: string
  title: string
  icon?: string
  children?: MenuItem[]
}

/** 拼接父子相对路径为绝对路径 */
function joinPath(parentPath: string, childPath: string): string {
  if (childPath.startsWith('/')) return childPath
  return parentPath === '/' ? `/${childPath}` : `${parentPath}/${childPath}`
}

/** 路由记录 → 菜单项（相对 path 归一化为绝对 path） */
function toMenu(item: RouteRecordRaw, parentPath: string): MenuItem {
  const meta = (item.meta ?? {}) as { title?: string; icon?: string }
  const path = joinPath(parentPath, item.path)
  const children = (item.children ?? [])
    .filter((c) => !(c.meta as { hidden?: boolean })?.hidden)
    .map((c) => toMenu(c, path))
  return {
    path,
    title: meta.title ?? '',
    icon: meta.icon,
    children: children.length > 0 ? children : undefined
  }
}

const menuItems = computed<MenuItem[]>(() =>
  permissionStore.menus.map((r) => toMenu(r, '/'))
)

const activePath = computed(() => route.path)
</script>

<template>
  <div class="sidebar">
    <!-- 品牌区 -->
    <div class="sidebar-brand" :class="{ folded: appStore.sidebarFolded }">
      <div class="brand-mark">
        <el-icon :size="22"><Compass /></el-icon>
      </div>
      <transition name="fade-slide">
        <div v-if="!appStore.sidebarFolded" class="brand-text">
          <span class="brand-name">司南管理后台</span>
          <span class="brand-sub">数字藏品运营平台</span>
        </div>
      </transition>
    </div>

    <!-- 权限菜单 -->
    <el-scrollbar class="sidebar-menu-wrap">
      <el-menu
        :default-active="activePath"
        :collapse="appStore.sidebarFolded"
        :collapse-transition="false"
        router
        class="sidebar-menu"
      >
        <template v-for="item in menuItems" :key="item.path">
          <!-- 有子菜单：分组 -->
          <el-sub-menu v-if="item.children" :index="item.path">
            <template #title>
              <el-icon v-if="item.icon"><component :is="item.icon" /></el-icon>
              <span>{{ item.title }}</span>
            </template>
            <el-menu-item v-for="child in item.children" :key="child.path" :index="child.path">
              <el-icon v-if="child.icon"><component :is="child.icon" /></el-icon>
              <span>{{ child.title }}</span>
            </el-menu-item>
          </el-sub-menu>

          <!-- 普通菜单项 -->
          <el-menu-item v-else :index="item.path">
            <el-icon v-if="item.icon"><component :is="item.icon" /></el-icon>
            <template #title>{{ item.title }}</template>
          </el-menu-item>
        </template>
      </el-menu>
    </el-scrollbar>

    <!-- 底部版本号 -->
    <div v-if="!appStore.sidebarFolded" class="sidebar-footer">P0 · v0.1.0</div>
  </div>
</template>

<style scoped lang="scss">
.sidebar {
  height: 100%;
  display: flex;
  flex-direction: column;
}

.sidebar-brand {
  height: 56px;
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 0 16px;
  border-bottom: 1px solid $sn-border;
  flex-shrink: 0;
  overflow: hidden;

  &.folded {
    justify-content: center;
    padding: 0;
  }

  .brand-mark {
    width: 34px;
    height: 34px;
    border-radius: 10px;
    background: $sn-gradient-primary;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    box-shadow: 0 4px 10px rgba(192, 0, 0, 0.28);
  }

  .brand-text {
    display: flex;
    flex-direction: column;
    line-height: 1.2;
    white-space: nowrap;

    .brand-name {
      font-size: 15px;
      font-weight: 600;
      color: $sn-text-main;
    }

    .brand-sub {
      font-size: 11px;
      color: $sn-text-muted;
      margin-top: 2px;
    }
  }
}

.sidebar-menu-wrap {
  flex: 1;
  min-height: 0;
}

.sidebar-menu {
  border-right: none;
  padding: 8px;

  &:not(.el-menu--collapse) {
    width: 100%;
  }

  :deep(.el-menu-item),
  :deep(.el-sub-menu__title) {
    height: 44px;
    line-height: 44px;
    border-radius: 8px;
    margin-bottom: 2px;
    color: $sn-text-sub;

    .el-icon {
      color: $sn-text-sub;
    }

    &:hover {
      background: $sn-surface;
      color: $sn-primary;

      .el-icon {
        color: $sn-primary;
      }
    }
  }

  :deep(.el-menu-item.is-active) {
    background: $sn-primary;
    color: #fff;

    .el-icon {
      color: #fff;
    }
  }

  :deep(.el-sub-menu .el-menu-item) {
    min-width: 0;
  }
}

.sidebar-footer {
  padding: 10px 16px;
  font-size: 11px;
  color: $sn-text-muted;
  border-top: 1px solid $sn-border;
  flex-shrink: 0;
}
</style>
