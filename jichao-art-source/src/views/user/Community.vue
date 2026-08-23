<script setup>
import { useRouter } from 'vue-router'
import AppNavBar from '@/components/AppNavBar.vue'
import AppIcon from '@/components/AppIcon.vue'
import AppButton from '@/components/AppButton.vue'
import { showToast } from 'vant'

const router = useRouter()

const groups = [
  { icon: 'community', name: '司南官方社群', desc: '第一时间获取发售与活动资讯' },
  { icon: 'headset', name: '司南玩家交流群', desc: '藏友互动，分享收藏心得' }
]

function copy() { showToast('群号已复制，请在微信中搜索加入') }
</script>

<template>
  <div class="community page--no-tabbar">
    <AppNavBar title="加入社区" @click-left="$router.back()" />

    <div class="community-banner">
      <AppIcon name="community" :size="40" color="#C00000" />
      <div class="community-banner__text">
        <span class="community-banner__title">同游作伴，潮流好礼不设限</span>
        <span class="community-banner__sub">扫码或搜索群号加入司南官方社群</span>
      </div>
    </div>

    <div class="community-list">
      <div v-for="g in groups" :key="g.name" class="community-item">
        <AppIcon :name="g.icon" :size="28" color="#C00000" />
        <div class="community-item__text">
          <span class="community-item__name">{{ g.name }}</span>
          <span class="community-item__desc">{{ g.desc }}</span>
        </div>
      </div>
    </div>

    <div class="community-action">
      <AppButton @click="copy">复制群号</AppButton>
      <AppButton type="outline" style="margin-top:12px" @click="router.back()">返回</AppButton>
    </div>
  </div>
</template>

<style scoped lang="scss">
.community-banner {
  margin: 12px $page-padding; background: $color-card; border-radius: $radius-lg; padding: 18px;
  display: flex; align-items: center; gap: 14px;
  &__text { display: flex; flex-direction: column; gap: 6px; }
  &__title { font-size: 15px; font-weight: 700; color: $color-text-primary; }
  &__sub { font-size: 12px; color: $color-text-tertiary; }
}
.community-list { padding: 0 $page-padding; }
.community-item {
  display: flex; align-items: center; gap: 14px; background: $color-card; border-radius: $radius-lg;
  padding: 16px; margin-bottom: 12px;
  &__text { display: flex; flex-direction: column; gap: 4px; }
  &__name { font-size: 15px; font-weight: 600; color: $color-text-primary; }
  &__desc { font-size: 12px; color: $color-text-tertiary; }
}
.community-action { padding: 8px $page-padding 0; }
</style>
