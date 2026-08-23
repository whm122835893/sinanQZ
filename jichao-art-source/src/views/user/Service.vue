<script setup>
import { useRouter } from 'vue-router'
import AppNavBar from '@/components/AppNavBar.vue'
import AppIcon from '@/components/AppIcon.vue'
import AppListItem from '@/components/AppListItem.vue'
import { showToast } from 'vant'

const router = useRouter()

function online() { showToast('正在接入在线客服…') }
</script>

<template>
  <div class="service page--no-tabbar">
    <AppNavBar title="我的客服" @click-left="$router.back()" />

    <div class="service-banner">
      <AppIcon name="headset" :size="36" color="#C00000" />
      <div class="service-banner__text">
        <span class="service-banner__title">客服在线时间 9:00 - 22:00</span>
        <span class="service-banner__sub">您的问题我们将第一时间响应</span>
      </div>
    </div>

    <div class="service-group">
      <AppListItem title="在线客服" icon="headset" arrow @click="online" />
      <AppListItem title="客服热线" value="400-888-0000" icon="horn" arrow border @click="online" />
      <AppListItem title="常见问题" icon="file" arrow border @click="online" />
    </div>

    <div class="service-group" style="margin-top:12px">
      <AppListItem title="返回上一级" icon="back" arrow @click="router.back()" />
    </div>
  </div>
</template>

<style scoped lang="scss">
.service-banner {
  margin: 12px $page-padding; background: $color-card; border-radius: $radius-lg; padding: 18px;
  display: flex; align-items: center; gap: 14px;
  &__text { display: flex; flex-direction: column; gap: 6px; }
  &__title { font-size: 15px; font-weight: 700; color: $color-text-primary; }
  &__sub { font-size: 12px; color: $color-text-tertiary; }
}
.service-group { margin: 12px $page-padding; border-radius: $radius-lg; overflow: hidden; }
</style>
