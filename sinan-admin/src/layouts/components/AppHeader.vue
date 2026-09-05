<script setup>
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { showConfirmDialog, showToast } from 'vant'
import { useAdminStore } from '@/stores/admin'
import { menuGroups } from '@/router/menu'

const route = useRoute()
const router = useRouter()
const admin = useAdminStore()
const showUser = ref(false)

const crumb = computed(() => {
  for (const g of menuGroups) {
    const hit = g.items.find((i) => route.path === i.path || route.path.startsWith(i.path + '/'))
    if (hit) return { group: g.group, title: route.meta.title || hit.title }
  }
  return { group: '', title: route.meta.title || '' }
})

const displayName = computed(() => admin.displayName)
const roleLabel = computed(() => admin.roleLabel)

async function onLogout() {
  try {
    await showConfirmDialog({ title: '提示', message: '确定退出登录吗？' })
    admin.clearSession()
    showToast('已退出登录')
    router.replace('/login')
  } catch { /* 取消 */ }
}
</script>

<template>
  <header class="header">
    <div class="header__crumb">
      <span class="g">{{ crumb.group }}</span>
      <van-icon name="arrow" size="12" color="#999" />
      <span class="t">{{ crumb.title }}</span>
    </div>

    <div class="header__right">
      <div class="header__env">
        <van-tag plain color="#D4A574">演示数据 · 未联调</van-tag>
      </div>
      <div class="header__user" @click="showUser = !showUser">
        <img class="avatar" :src="admin.info?.avatar || '/images/platform-logo.png'" alt="avatar" />
        <span class="name">{{ displayName }}</span>
        <van-icon name="arrow-down" size="12" color="#999" />
      </div>

      <transition name="fade">
        <div v-if="showUser" class="header__dropdown" @click.stop>
          <div class="header__dropdown-head">
            <div class="row1">{{ displayName }}</div>
            <div class="row2">{{ roleLabel }} · {{ admin.info?.username }}</div>
          </div>
          <div class="header__dropdown-item" @click="$router.push('/mine')">
            <van-icon name="user-o" size="15" /> 个人中心
          </div>
          <div class="header__dropdown-item is-danger" @click="onLogout">
            <van-icon name="replay" size="15" /> 退出登录
          </div>
        </div>
      </transition>
    </div>
  </header>
</template>

<style scoped lang="scss">
.header {
  position: sticky;
  top: 0;
  z-index: 90;
  height: $header-height;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 20px;
  background: rgba(255, 255, 255, 0.86);
  backdrop-filter: saturate(180%) blur(20px);
  -webkit-backdrop-filter: saturate(180%) blur(20px);
  border-bottom: 1px solid $color-border;
}

.header__crumb {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;

  .g { color: $color-text-tertiary; }
  .t { color: $color-text-primary; font-weight: 600; font-size: 14px; }
}

.header__right {
  display: flex;
  align-items: center;
  gap: 16px;
  position: relative;
}

.header__user {
  display: flex;
  align-items: center;
  gap: 8px;
  cursor: pointer;

  .avatar {
    width: 30px;
    height: 30px;
    border-radius: 50%;
    object-fit: cover;
    border: 1.5px solid rgba(192, 0, 0, 0.2);
  }
  .name { font-size: 13px; color: $color-text-primary; }
}

.header__dropdown {
  position: absolute;
  top: 42px;
  right: 0;
  width: 200px;
  background: #fff;
  border-radius: $radius-lg;
  box-shadow: 0 8px 28px rgba(26, 26, 26, 0.12);
  overflow: hidden;
}

.header__dropdown-head {
  padding: 12px 14px;
  background: linear-gradient(135deg, rgba(192, 0, 0, 0.06), rgba(212, 165, 116, 0.08));

  .row1 { font-size: 14px; font-weight: 600; }
  .row2 { font-size: 11px; color: $color-text-tertiary; margin-top: 2px; }
}

.header__dropdown-item {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 11px 14px;
  font-size: 13px;
  cursor: pointer;

  &:hover { background: $color-surface; }
  &.is-danger { color: $color-primary; }
}
</style>
