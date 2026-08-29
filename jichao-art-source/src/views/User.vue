<script setup>
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useUserStore } from '@/stores/user'
import { useIconThemeStore } from '@/stores/iconTheme'
import { useLoginGate } from '@/utils/loginGate'
import AppIcon from '@/components/AppIcon.vue'
import AppListItem from '@/components/AppListItem.vue'
import { showConfirmDialog, showToast } from 'vant'

const route = useRoute()
const router = useRouter()
const user = useUserStore()
const iconTheme = useIconThemeStore()
const { requireLogin } = useLoginGate()

// 功能入口图标（跟随当前主题动态切换）
const inventoryIcon = computed(() => iconTheme.getFeatureIcon('inventory'))
const walletIcon    = computed(() => iconTheme.getFeatureIcon('wallet'))

// 需登录才能访问的私有入口（未登录时统一弹全局登录提示）
const needAuth = ['/user/invite', '/user/collections', '/user/wallet', '/user/orders', '/user/purchase', '/user/profile', '/user/security', '/user/realname']
function go(path) {
  if (needAuth.includes(path) && !requireLogin(route.fullPath)) return
  router.push(path)
}

function logout() {
  showConfirmDialog({ title: '提示', message: '确定退出登录吗？' })
    .then(() => {
      user.logout()
      showToast('已退出登录')
      router.push('/auth/login')
    })
    .catch(() => {})
}
</script>

<template>
  <div class="user page">
    <!-- 用户信息 -->
    <div class="user-info safe-top" @click="!user.isLoggedIn && router.push('/auth/login')">
      <div class="user-info__main">
        <span class="user-info__name">{{ user.isLoggedIn ? user.userInfo.nickname : '未登录' }}</span>
        <span class="user-info__addr">{{ user.isLoggedIn ? '钱包地址:实名后获得' : '点击登录账号' }}</span>
      </div>
      <img class="user-info__avatar" src="/images/platform-logo.png" alt="" />
    </div>

    <!-- 邀请横幅 -->
    <div class="invite-banner" @click="go('/user/invite')">
      <div class="invite-banner__icon"><AppIcon name="invite" :size="28" color="#C00000" /></div>
      <div class="invite-banner__text">
        <span class="invite-banner__title">司南·邀新玩法</span>
        <span class="invite-banner__sub">同游作伴，潮流好礼不设限</span>
      </div>
      <button class="invite-banner__btn">邀请</button>
    </div>

    <!-- 资产入口 -->
    <div class="asset-grid">
      <div class="asset-card" @click="go('/user/collections')">
        <img v-if="inventoryIcon?.type === 'image'" :src="inventoryIcon.image" class="asset-card__icon" alt="" draggable="false" @contextmenu.prevent />
        <AppIcon v-else-if="inventoryIcon?.type === 'svg'" :name="inventoryIcon.icon" :size="30" color="#C00000" />
        <div class="asset-card__text">
          <span class="asset-card__title">我的库存</span>
          <span class="asset-card__sub">藏品轻松管理</span>
        </div>
      </div>
      <div class="asset-card" @click="go('/user/wallet')">
        <img v-if="walletIcon?.type === 'image'" :src="walletIcon.image" class="asset-card__icon" alt="" draggable="false" @contextmenu.prevent />
        <AppIcon v-else-if="walletIcon?.type === 'svg'" :name="walletIcon.icon" :size="30" color="#1A1A1A" />
        <div class="asset-card__text">
          <span class="asset-card__title">我的钱包</span>
          <span class="asset-card__sub">资产安全无忧</span>
        </div>
      </div>
    </div>

    <!-- 订单管理 -->
    <div class="group-title">订单管理</div>
    <div class="group-card">
      <AppListItem title="我的订单" icon="clipboard" arrow @click="go('/user/orders')" />
      <AppListItem title="转赠记录" icon="transfer" arrow border @click="go('/user/purchase')" />
    </div>

    <!-- 账户设置 -->
    <div class="group-title">账户设置</div>
    <div class="group-card">
      <AppListItem title="个人信息" icon="person" arrow @click="go('/user/profile')" />
      <AppListItem title="账户安全" icon="shield" arrow border @click="go('/user/security')" />
      <AppListItem title="实名认证" icon="idcard" arrow border @click="go('/user/realname')" />
    </div>

    <!-- 帮助与支持 -->
    <div class="group-title">帮助与支持</div>
    <div class="group-card">
      <AppListItem title="加入社区" icon="community" arrow @click="go('/user/community')" />
      <AppListItem title="我的客服" icon="headset" arrow border @click="go('/user/service')" />
    </div>

    <div class="user-logout" v-if="user.isLoggedIn" @click="logout">退出登录</div>
  </div>
</template>

<style scoped lang="scss">
.user { padding-bottom: calc(#{$tabbar-height} + env(safe-area-inset-bottom) + 24px); }

.user-info {
  display: flex; align-items: center; gap: 12px; padding: 20px $page-padding;
  background: transparent;
  &__logo { font-size: 30px; color: #C00000; }
  &__main { flex: 1; display: flex; flex-direction: column; gap: 4px; }
  &__name { font-size: 16px; font-weight: 700; color: $color-text-primary; }
  &__addr { font-size: 12px; color: $color-text-tertiary; }
  &__avatar {
    width: 58px; height: 58px; border-radius: 50%; object-fit: cover;
    background: #fff; border: 1px solid #eee;
    -webkit-user-drag: none; -webkit-touch-callout: none; user-select: none; pointer-events: none;
  }
}

.invite-banner {
  margin: 12px $page-padding; border-radius: $radius-lg; padding: 16px;
  background:
    linear-gradient(135deg, rgba(255,255,255,0.92) 0%, rgba(255,255,255,0.86) 100%),
    url('/images/invite-banner-bg.jpg') center/cover no-repeat;
  border: 1px solid rgba(192,0,0,0.1);
  display: flex; align-items: center; gap: 12px; cursor: pointer;
  box-shadow: 0 2px 12px rgba(192,0,0,0.08);
  position: relative; overflow: hidden;
  &__icon {
    width: 44px; height: 44px; border-radius: 12px;
    background: linear-gradient(135deg, rgba(192,0,0,0.12), rgba(210,140,50,0.1));
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
    backdrop-filter: blur(2px);
  }
  &__text { flex: 1; display: flex; flex-direction: column; gap: 4px; }
  &__title { font-size: 15px; font-weight: 700; color: #9A1A1A; letter-spacing: 0.5px; }
  &__sub { font-size: 12px; color: rgba(80,50,20,0.6); }
  &__btn {
    border: none; cursor: pointer; background: #C00000; color: #fff; font-size: 14px;
    padding: 8px 18px; border-radius: $radius-pill;
  }
}

.asset-grid { display: flex; gap: 12px; padding: 0 $page-padding; }
.asset-card {
  flex: 1; background: $color-card; border-radius: $radius-lg; padding: 10px;
  display: flex; align-items: center; gap: 12px; cursor: pointer;
  &__icon { width: 48px; height: 48px; flex: none; object-fit: contain; margin: -4px 0; -webkit-user-drag: none; -webkit-touch-callout: none; user-select: none; pointer-events: none; }
  &__text { display: flex; flex-direction: column; gap: 4px; }
  &__title { font-size: 15px; font-weight: 600; color: $color-text-primary; }
  &__sub { font-size: 12px; color: $color-text-tertiary; }
}

.group-card { margin: 0 $page-padding; border-radius: $radius-lg; overflow: hidden; }

.user-logout {
  margin: 24px $page-padding; text-align: center; font-size: 16px; color: $color-primary; cursor: pointer;
}
</style>
