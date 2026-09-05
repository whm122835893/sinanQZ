<script setup>
import { computed, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useAppStore } from '@/stores/app'
import AdminSidebar from './components/AdminSidebar.vue'
import AppHeader from './components/AppHeader.vue'
import TagsView from './components/TagsView.vue'

const app = useAppStore()
const route = useRoute()

// 路由切换时登记多标签页
watch(
  () => route.path,
  () => app.addTag(route),
  { immediate: true }
)

const sidebarWidth = computed(() =>
  app.sidebarCollapsed ? 'var(--sidebar-collapsed)' : 'var(--sidebar-width)'
)
</script>

<template>
  <div class="admin-layout">
    <!-- 桌面侧栏 -->
    <AdminSidebar v-if="!app.isMobile" />

    <!-- 移动端抽屉侧栏 -->
    <el-drawer
      v-else
      :model-value="app.drawerOpen"
      direction="ltr"
      :size="220"
      :with-header="false"
      @update:model-value="app.toggleDrawer(false)"
    >
      <AdminSidebar in-drawer />
    </el-drawer>

    <div class="admin-layout__body" :style="{ '--sidebar-w': sidebarWidth }">
      <AppHeader />
      <TagsView />
      <main class="admin-layout__main">
        <router-view v-slot="{ Component }">
          <transition name="fade" mode="out-in">
            <component :is="Component" :key="route.path" />
          </transition>
        </router-view>
      </main>
    </div>
  </div>
</template>

<style scoped lang="scss">
.admin-layout {
  display: flex;
  height: 100vh;
  background: $color-bg;
  --sidebar-width: 224px;
  --sidebar-collapsed: 64px;
}

.admin-layout__body {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  margin-left: var(--sidebar-w);
  transition: margin-left 0.2s ease;

  @media (max-width: 768px) {
    margin-left: 0;
  }
}

.admin-layout__main {
  flex: 1;
  overflow-y: auto;
  padding: 16px;
  max-width: 1440px;
  width: 100%;
  margin: 0 auto;
}
</style>
