<script setup>
import { useRouter } from 'vue-router'
import { showConfirmDialog } from 'vant'
import { useAdminStore } from '@/stores/admin'
import { logout } from '@/api'
import { menuGroups } from '@/router/menu'
import { ROLE_MAP } from '@/utils/maps'

const router = useRouter()
const admin = useAdminStore()

const groups = menuGroups.filter((g) => g.group !== '总览')
const overview = menuGroups.find((g) => g.group === '总览')

async function onLogout() {
  await showConfirmDialog({ title: '退出登录', message: '确认退出管理后台？' })
  await logout()
  admin.clearSession()
  router.replace('/login')
}
</script>

<template>
  <div class="adm-page mine">
    <!-- 个人卡片 -->
    <div class="mine__card adm-card">
      <img class="mine__avatar" :src="admin.info?.avatar || '/images/platform-logo.png'" alt="avatar" />
      <div class="mine__info">
        <div class="mine__name">{{ admin.displayName }}</div>
        <div class="mine__role">
          <van-tag :type="ROLE_MAP[admin.info?.role]?.type || 'primary'" plain round size="medium">
            {{ admin.roleLabel }}
          </van-tag>
        </div>
      </div>
      <van-icon name="setting-o" size="20" color="#999" @click="router.push('/system/config')" />
    </div>

    <!-- 看板入口 -->
    <div v-if="overview" class="adm-card">
      <div class="adm-card__title">{{ overview.group }}</div>
      <div class="mine__menu">
        <div
          v-for="m in overview.items"
          :key="m.path"
          class="mine__menu-item"
          @click="router.push(m.path)"
        >
          <van-icon :name="m.icon" size="20" color="var(--color-primary)" />
          <span>{{ m.title }}</span>
          <van-icon name="arrow" size="12" color="#c8c9cc" />
        </div>
      </div>
    </div>

    <!-- 全部模块 -->
    <div v-for="g in groups" :key="g.group" class="adm-card">
      <div class="adm-card__title">{{ g.group }}</div>
      <div class="mine__menu">
        <div
          v-for="m in g.items"
          :key="m.path"
          class="mine__menu-item"
          @click="router.push(m.path)"
        >
          <van-icon :name="m.icon" size="20" color="var(--color-primary)" />
          <span>{{ m.title }}</span>
          <van-icon name="arrow" size="12" color="#c8c9cc" />
        </div>
      </div>
    </div>

    <div class="mine__logout">
      <van-button block round plain type="danger" icon="revoke" @click="onLogout">退出登录</van-button>
    </div>

    <div class="mine__version t-tertiary">
      司南珍藏管理后台 · v0.1.0（Mock 演示，未联调后端）
    </div>
  </div>
</template>

<style scoped lang="scss">
.mine__card {
  display: flex;
  align-items: center;
  gap: 14px;
}

.mine__avatar {
  width: 56px;
  height: 56px;
  border-radius: 50%;
  object-fit: cover;
  border: 2px solid rgba(192, 0, 0, 0.2);
  flex-shrink: 0;
}

.mine__info { flex: 1; }

.mine__name {
  font-size: 17px;
  font-weight: 700;
}

.mine__role { margin-top: 6px; }

.mine__menu {
  display: grid;
  grid-template-columns: 1fr;
  gap: 2px;
}

.mine__menu-item {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 11px 4px;
  font-size: 14px;
  border-radius: $radius-md;
  cursor: pointer;

  span { flex: 1; }

  &:active { background: rgba(0, 0, 0, 0.03); }
}

@media (min-width: 769px) {
  .mine__menu { grid-template-columns: repeat(2, 1fr); }
}

.mine__logout { padding: 6px 0; }

.mine__version {
  text-align: center;
  font-size: 11px;
  padding: 8px 0 4px;
}
</style>
