<script setup lang="ts">
// 主布局：侧边栏 + 顶栏 + 标签页 + 内容区（文档 3.3）
import Sidebar from './Sidebar.vue'
import Header from './Header.vue'
import TabsNav from './TabsNav.vue'
import { useAppStore } from '@/stores/app'

const appStore = useAppStore()
</script>

<template>
  <el-container class="main-layout">
    <el-aside :width="appStore.sidebarFolded ? '64px' : '220px'" class="layout-aside">
      <Sidebar />
    </el-aside>
    <el-container class="layout-body">
      <el-header class="layout-header" height="56px">
        <Header />
      </el-header>
      <div class="layout-tabs">
        <TabsNav />
      </div>
      <el-main class="layout-main">
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
.main-layout {
  height: 100vh;
  overflow: hidden;
}

.layout-aside {
  background: $sn-card;
  border-right: 1px solid $sn-border;
  transition: width 0.2s ease;
  overflow: hidden;
}

.layout-body {
  min-width: 0;
  display: flex;
  flex-direction: column;
}

.layout-header {
  background: $sn-card;
  border-bottom: 1px solid $sn-border;
  padding: 0 16px;
  z-index: 5;
}

.layout-tabs {
  height: 40px;
  background: $sn-card;
  border-bottom: 1px solid $sn-border;
  padding: 4px 12px 0;
  box-sizing: border-box;
}

.layout-main {
  background: $sn-bg;
  padding: 16px;
  overflow-y: auto;
}
</style>
