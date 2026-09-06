<script setup lang="ts">
/**
 * 管理后台主布局：深色侧边栏 + 顶栏 + 内容区
 */
import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { menuConfig, type MenuNode } from './config/menu'
import Sidebar from './components/Sidebar.vue'
import Navbar from './components/Navbar.vue'

const route = useRoute()
const auth = useAuthStore()

const isCollapse = ref(false)

// 按服务端权限过滤菜单
const visibleMenus = computed<MenuNode[]>(() => {
  const filterNode = (nodes: MenuNode[]): MenuNode[] =>
    nodes
      .map((n) => {
        if (n.children?.length) {
          const children = filterNode(n.children)
          if (!children.length) return null
          return { ...n, children }
        }
        return auth.hasPermission(n.code) ? n : null
      })
      .filter(Boolean) as MenuNode[]
  return filterNode(menuConfig)
})

// 当前激活菜单（详情页高亮父级列表：/user/3 → /user）
const activePath = computed(() => {
  const path = route.path
  const m = path.match(/^\/(user|collectible|blindbox|order)\/\d+/)
  return m ? `/${m[1]}` : path
})

// 展开的分组
const expandedKeys = ref<string[]>([])
watch(
  visibleMenus,
  (menus) => {
    for (const m of menus) {
      if (m.children?.some((c) => c.path === activePath.value)) {
        expandedKeys.value = [m.path]
      }
    }
  },
  { immediate: true },
)

function toggleCollapse() {
  isCollapse.value = !isCollapse.value
}

defineExpose({ toggleCollapse })
</script>

<template>
  <el-container class="admin-layout">
    <Sidebar :menus="visibleMenus" :collapse="isCollapse" :active-path="activePath" :expanded-keys="expandedKeys" />
    <el-container class="main-side">
      <Navbar :collapse="isCollapse" @toggle-collapse="toggleCollapse" />
      <el-main class="page-main">
        <router-view v-slot="{ Component }">
          <transition name="fade-slide" mode="out-in">
            <component :is="Component" />
          </transition>
        </router-view>
      </el-main>
    </el-container>
  </el-container>
</template>

<style scoped lang="scss">
.admin-layout {
  height: 100%;
}

.main-side {
  flex-direction: column;
  min-width: 0;
}

.page-main {
  background: var(--sn-bg);
  padding: 0;
  overflow-y: auto;
}

.fade-slide-enter-active,
.fade-slide-leave-active {
  transition: opacity 0.18s ease, transform 0.18s ease;
}
.fade-slide-enter-from {
  opacity: 0;
  transform: translateY(6px);
}
.fade-slide-leave-to {
  opacity: 0;
  transform: translateY(-6px);
}
</style>
