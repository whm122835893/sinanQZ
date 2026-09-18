<script setup>
import { ref, onMounted, onBeforeUnmount, watch } from 'vue'
import { useRoute } from 'vue-router'
import AppTabBar from '@/components/AppTabBar.vue'
import AppLoginModal from '@/components/AppLoginModal.vue'
import Splash from '@/components/Splash.vue'
import InboxPopup from '@/components/InboxPopup.vue'
import { useSiteStore } from '@/stores/site'
import { useUserStore } from '@/stores/user'
import { useInboxStore } from '@/stores/inbox'

const route = useRoute()
const refreshing = ref(false)
const site = useSiteStore()
const user = useUserStore()
const inbox = useInboxStore()

function onRefresh() {
  setTimeout(() => {
    refreshing.value = false
  }, 1000)
}

// 登录后轮询收件箱，登出时停
watch(
  () => user.isLoggedIn,
  (logged) => {
    if (logged) inbox.startPoll()
    else inbox.stopPoll()
  },
  { immediate: true }
)

onMounted(() => {
  if (user.isLoggedIn) inbox.startPoll()
})
onBeforeUnmount(() => inbox.stopPoll())
</script>

<template>
  <!-- 开屏全屏：优先级最高，遮罩所有路由内容 -->
  <Splash v-if="site.shouldShowSplash" />

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

  <!-- 收件箱弹窗（空投/转赠到达） -->
  <InboxPopup />
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
