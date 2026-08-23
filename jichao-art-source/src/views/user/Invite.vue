<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import AppNavBar from '@/components/AppNavBar.vue'
import AppButton from '@/components/AppButton.vue'
import AppEmpty from '@/components/AppEmpty.vue'
import { showToast } from 'vant'

const router = useRouter()
const inviteCode = ref('jKet17636')
const regUrl = ref('https://h5.quanzhi1.top#/pages/auth/register/index?code=jKet17636')

const stats = [
  { label: '邀请注册', value: 0 },
  { label: '已实名', value: 0 },
  { label: '已开通钱包', value: 0 }
]

function copy(text, msg) {
  if (navigator.clipboard) {
    navigator.clipboard.writeText(text).then(() => showToast(msg)).catch(() => showToast('复制失败'))
  } else {
    showToast('当前环境不支持复制')
  }
}
</script>

<template>
  <div class="invite page--no-tabbar">
    <AppNavBar title="我的好友" @click-left="$router.back()">
      <template #right>
        <span class="invite-top-btn" @click="showToast('邀请规则开发中')">邀请规则</span>
      </template>
    </AppNavBar>

    <!-- 邀请码 -->
    <div class="invite-code">
      <div class="invite-code__main">
        <span class="invite-code__label">我的邀请码</span>
        <span class="invite-code__value">{{ inviteCode }}</span>
      </div>
      <button class="invite-code__poster" @click="showToast('生成海报')">生成邀请海报</button>
    </div>

    <!-- 注册链接 -->
    <div class="invite-link">
      <span class="invite-link__label">注册链接</span>
      <p class="invite-link__url">{{ regUrl }}</p>
      <AppButton @click="copy(inviteCode, '邀请码已复制')">复制邀请码</AppButton>
      <AppButton type="outline" style="margin-top:12px" @click="copy(regUrl, '注册链接已复制')">复制注册链接</AppButton>
    </div>

    <!-- 统计 -->
    <div class="invite-stats">
      <div v-for="s in stats" :key="s.label" class="invite-stats__item">
        <span class="invite-stats__title">{{ s.label }}</span>
        <span class="invite-stats__value">{{ s.value }}</span>
      </div>
    </div>

    <!-- 邀请名单 -->
    <div class="invite-list-title"><span class="red">邀请</span>名单</div>
    <AppEmpty description="空空如也" />
  </div>
</template>

<style scoped lang="scss">
.invite-top-btn { font-size: 14px; color: $color-text-primary; cursor: pointer; }

.invite-code {
  display: flex; align-items: center; justify-content: space-between;
  padding: 16px $page-padding; background: $color-card; margin: 12px $page-padding 0; border-radius: $radius-lg;
  &__main { display: flex; flex-direction: column; gap: 6px; }
  &__label { font-size: 12px; color: $color-text-tertiary; }
  &__value { font-size: 22px; font-weight: 700; color: $color-text-primary; }
  &__poster {
    border: none; cursor: pointer; background: $color-surface; color: $color-text-primary;
    font-size: 13px; padding: 8px 14px; border-radius: $radius-pill;
  }
}

.invite-link {
  margin: 12px $page-padding 0; background: $color-surface; border-radius: $radius-lg; padding: 16px;
  &__label { font-size: 14px; color: $color-text-primary; font-weight: 500; }
  &__url { margin: 8px 0 16px; font-size: 14px; color: $color-text-primary; word-break: break-all; line-height: 1.5; }
}

.invite-stats { display: flex; gap: 12px; padding: 16px $page-padding; }
.invite-stats__item {
  flex: 1; background: $color-card; border-radius: $radius-lg; padding: 14px;
  display: flex; flex-direction: column; align-items: center; gap: 6px;
  &__title { font-size: 12px; color: $color-text-tertiary; }
  &__value { font-size: 16px; font-weight: 700; color: $color-text-primary; }
}

.invite-list-title { font-size: 17px; font-weight: 700; color: $color-text-primary; padding: 16px $page-padding 4px; .red { color: #C00000; } }
</style>
