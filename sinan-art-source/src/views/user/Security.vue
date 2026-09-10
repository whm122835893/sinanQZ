<script setup>
import { useRouter } from 'vue-router'
import { useUserStore } from '@/stores/user'
import AppNavBar from '@/components/AppNavBar.vue'
import AppListItem from '@/components/AppListItem.vue'

const router = useRouter()
const user = useUserStore()
</script>

<template>
  <div class="security page--no-tabbar">
    <AppNavBar title="账户安全" @click-left="$router.back()" />

    <div class="security-group">
      <AppListItem title="实名认证" :arrow="true" icon="idcard" @click="router.push('/user/realname')">
        <template #value>
          <span :class="user.userInfo.isRealName ? 'sec-ok' : 'sec-warn'">
            {{ user.userInfo.isRealName ? '已认证' : '未认证' }}
          </span>
        </template>
      </AppListItem>
      <AppListItem title="登录密码" value="已设置" icon="shield" arrow border @click="router.push('/auth/change-pwd')" />
      <AppListItem title="操作密码" value="未设置" icon="lock" arrow border @click="router.push('/auth/op-pwd')" />
      <AppListItem title="找回密码" icon="key" arrow border @click="router.push('/auth/forgot')" />
    </div>

    <div class="security-group" style="margin-top:12px">
      <AppListItem title="注销账号" icon="close" arrow @click="router.push('/auth/cancel')" />
    </div>
  </div>
</template>

<style scoped lang="scss">
.security-group { margin: 12px $page-padding; border-radius: $radius-lg; overflow: hidden; }
:deep(.app-list-item__value) { color: $color-text-tertiary; }
.sec-ok { color: $color-primary !important; }
.sec-warn { color: $color-text-tertiary; }
</style>
