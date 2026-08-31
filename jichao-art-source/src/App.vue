<script setup>
import { ref } from 'vue'
import { useRoute } from 'vue-router'
import AppTabBar from '@/components/AppTabBar.vue'
import AppLoginModal from '@/components/AppLoginModal.vue'

const route = useRoute()
const refreshing = ref(false)

function onRefresh() {
  setTimeout(() => {
    refreshing.value = false
  }, 1000)
}
</script>

<template>
  <van-pull-refresh v-model="refreshing" @refresh="onRefresh" class="app-refresh">
    <router-view v-slot="{ Component }">
      <Transition name="page">
        <component :is="Component" :key="route.fullPath" />
      </Transition>
    </router-view>
  </van-pull-refresh>

  <AppTabBar v-if="route.meta.tabbar" />

  <!-- 全局登录提示弹窗 -->
  <AppLoginModal />
</template>

<style lang="scss">
// 原生 H5 风格的页面切换：淡入淡出 + 轻微滑动
.page-enter-active,
.page-leave-active {
  transition: opacity 0.1s ease;
}

.page-enter-from,
.page-leave-to {
  opacity: 0;
}
</style>
