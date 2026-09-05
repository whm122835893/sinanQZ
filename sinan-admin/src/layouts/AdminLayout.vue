<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useAppStore } from '@/stores/app'
import { useAdminStore } from '@/stores/admin'
import AdminSidebar from './components/AdminSidebar.vue'
import AppHeader from './components/AppHeader.vue'
import AdminTabBar from './components/AdminTabBar.vue'

const app = useAppStore()
const admin = useAdminStore()
const route = useRoute()

const showTabbar = computed(() => route.meta.tab && app.isMobile)
const showBack = computed(() => app.isMobile && !route.meta.tab)
const pageTitle = computed(() => route.meta.title || '')
const avatar = computed(() => admin.info?.avatar || '/images/platform-logo.png')
</script>

<template>
  <div class="admin" :class="{ 'is-mobile': app.isMobile }">
    <!-- 桌面侧边栏 -->
    <AdminSidebar v-if="!app.isMobile" />

    <div class="admin-body">
      <!-- 桌面顶栏 -->
      <AppHeader v-if="!app.isMobile" />

      <!-- 移动端导航栏（C 端同款玻璃质感） -->
      <div v-else class="m-navbar safe-top">
        <div class="m-navbar__side">
          <van-icon v-if="showBack" name="arrow-left" size="20" @click="$router.back()" />
          <img v-else class="m-navbar__logo" :src="avatar" alt="logo" />
        </div>
        <div class="m-navbar__title">{{ pageTitle }}</div>
        <div class="m-navbar__side is-right">
          <router-link to="/mine">
            <img class="m-navbar__avatar" :src="avatar" alt="avatar" />
          </router-link>
        </div>
      </div>

      <main class="admin-content">
        <router-view v-slot="{ Component }">
          <transition name="fade" mode="out-in">
            <component :is="Component" :key="route.path" />
          </transition>
        </router-view>
      </main>
    </div>

    <!-- 移动端底部 TabBar -->
    <AdminTabBar v-if="showTabbar" />
  </div>
</template>

<style scoped lang="scss">
.admin {
  display: flex;
  min-height: 100vh;
  background: $color-bg;

  &.is-mobile {
    display: block;
  }
}

.admin-body {
  flex: 1;
  min-width: 0;
}

.admin-content {
  padding: 14px;
  max-width: $content-max;
  margin: 0 auto;

  :global(.adm-page) {
    padding-bottom: 0;
  }
}

// ---------- 移动端导航栏 ----------
.m-navbar {
  position: sticky;
  top: 0;
  z-index: $z-navbar;
  display: flex;
  align-items: center;
  height: $navbar-height;
  padding: 0 12px;
  @include glass;
  border-bottom: 1px solid rgba(0, 0, 0, 0.05);

  .admin.is-mobile .admin-content {
    padding-top: 10px;
  }
}

.m-navbar__side {
  width: 84px;
  display: flex;
  align-items: center;
  cursor: pointer;
  color: $color-text-primary;
}

.m-navbar__side.is-right {
  justify-content: flex-end;
}

.m-navbar__logo {
  width: 30px;
  height: 30px;
  border-radius: $radius-md;
  object-fit: cover;
}

.m-navbar__avatar {
  width: 30px;
  height: 30px;
  border-radius: 50%;
  object-fit: cover;
  border: 1.5px solid rgba(192, 0, 0, 0.25);
}

.m-navbar__title {
  flex: 1;
  text-align: center;
  font-size: 16px;
  font-weight: 600;
  color: $color-text-primary;
  @include ellipsis;
}

@media (min-width: 769px) {
  .m-navbar { display: none; }
}
</style>
