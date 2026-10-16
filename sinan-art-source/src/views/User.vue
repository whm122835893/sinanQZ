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
const inviteIcon    = computed(() => iconTheme.getFeatureIcon('invite'))

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
      <div class="invite-banner__icon">
        <!-- 五套主题的 invite 走下面这条矢量分支（人形 + 加号，§7.47）；位图分支只留给后台那个与主题无关的自定义上传口。 -->
        <img v-if="inviteIcon?.type === 'image'" :src="inviteIcon.image" class="invite-banner__img" alt="" draggable="false" @contextmenu.prevent />
        <!-- ⚠️ 这里的 color 不能退回 #C00000：这块底板是深色海报上的一层磨砂白，深红线条量出来只有 1.0~1.6:1 = 看不见（§7.44）。 -->
        <AppIcon v-else :name="inviteIcon?.icon || 'invite'" :size="28" color="#FFFFFF" />
      </div>
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
  // 深色海报卡的兜底底色：图没传上去时也不能是「白底白字」的空卡（§7.43）
  background: #1A0D10;
  border: 1px solid rgba(255,255,255,0.10);   // 宽度保持 1px：改了会挪动文字区那 72px 的起点
  display: flex; align-items: center; gap: 12px; cursor: pointer;
  box-shadow: 0 6px 18px rgba(26,13,16,0.28);
  position: relative; overflow: hidden;
  &::before {
    content: ''; position: absolute; inset: 0; z-index: 0;
    // 深色满幅海报（§7.43 推翻了 §7.42 的「浅底 + 白纱」）：不叠白纱、也不降 opacity，
    // 那两层都会把深色图洗成奶昔。可读性由图自己保证 —— 左 2/3 是均匀暗底，
    // 按真实行盒逐像素量：标题最坏 18.46:1、副标最坏 8.25:1（§7.42 旧渲染基线 8.28 / 3.32），
    // 字形足迹右界 x=215，玉璧亮边从 x≈223 才起。
    background: url('/images/invite-banner-bg-v4.webp') center/cover no-repeat;
    pointer-events: none;
  }
  &__icon {
    width: 44px; height: 44px; border-radius: 12px; position: relative; z-index: 1;
    background: rgba(255,255,255,0.16);
    box-shadow: inset 0 0 0 1px rgba(255,255,255,0.28);
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
    backdrop-filter: blur(2px);
  }
  &__img { width: 28px; height: 28px; object-fit: contain; -webkit-user-drag: none; -webkit-touch-callout: none; user-select: none; pointer-events: none; }
  &__text { flex: 1; display: flex; flex-direction: column; gap: 4px; position: relative; z-index: 1; }
  &__title { font-size: 15px; font-weight: 700; color: #FFFFFF; letter-spacing: 0.5px; text-shadow: 0 1px 2px rgba(0,0,0,0.55); }
  &__sub { font-size: 12px; color: rgba(255,255,255,0.85); text-shadow: 0 1px 2px rgba(0,0,0,0.5); }
  &__btn {
    // 透明灰按钮（2026-10-16 §7.44：用户把上一条口径「液态玻璃白 + 深红字」改成「透明灰」）。
    // 基准色 rgb(46,46,52)、α=0.62 都是量出来的：这颗按钮整个压在深色海报上，
    // 底下那片海报亮度跨度 L=0.005~0.797（右端有玉璧高光），白字最坏点：
    //   α=0.58 → 4.51:1、0.62 → 4.98:1、0.65 → 5.37:1（AA 线 4.5）
    // 这三个数是离线 GD 按 CSS 几何合成量的；落位后又用 headless Chrome 走真排版复量（CDP，390x844/dpr2）：
    // 「邀请」两字的行盒（29x16 设备px）上最坏 5.12:1，整块板子外接框上最坏 4.69:1 —— 两个口径都过 4.5。
    // 这里**不写 backdrop-filter**：不糊底就没有"这台机器的 WebView 支不支持 blur"的争议，
    // 上面那串数字即真机逐像素读数。代价是底图细节会透出来一点，这正是"透明灰"要的观感。
    // 字色只能是浅色：同一组合成下 #C00000 深红字的最坏点只有 1.00~1.57:1，等于看不见。
    border: 1px solid rgba(255, 255, 255, 0.32); cursor: pointer;
    background: rgba(46, 46, 52, 0.62); color: #FFFFFF; font-size: 14px;
    // 内边距**四个方向各收 1px**，把新增的 1px 边框补回去：外沿和改之前是同一个盒子
    // （实测 66.45x38.38 设备px，改之前 66.55x38.47，差 0.1px = 亚像素噪声）。
    // 只收左右那一版（padding: 8px 17px）也量过：高出来的 2.6px 就是上下两条边框，
    // 被它盖住的海报区域会往外长一圈，上面那串对比度读数的几何前提就不成立了。
    padding: 7px 17px; border-radius: $radius-pill; position: relative; z-index: 1;
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.18);
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
