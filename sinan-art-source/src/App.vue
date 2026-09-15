<script setup>
import { ref, computed } from 'vue'
import { useRoute } from 'vue-router'
import AppTabBar from '@/components/AppTabBar.vue'
import AppLoginModal from '@/components/AppLoginModal.vue'
import Splash from '@/components/Splash.vue'
import { useSiteStore } from '@/stores/site'

const route = useRoute()
const refreshing = ref(false)
const site = useSiteStore()

// 是否展示开屏：B 端启用 + 有图 + 本设备还没看过
// 条件计算放在模板里配合组件内部 dismiss 即可
const showSplash = computed(() => {
  if (!site.splashEnabled || !site.splashImage) return false
  try {
    return !localStorage.getItem('jc_splash_seen')
  } catch {
    return true
  }
})

function onRefresh() {
  setTimeout(() => {
    refreshing.value = false
  }, 1000)
}
</script>

<template>
  <!-- 开屏全屏：优先级最高，遮罩所有路由内容 -->
  <Splash v-if="showSplash" />

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
