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
    // 「雾中逆光」深青铜鼎（2026-10-17 §7.49，用户点名要 §7.48 那三张候选里的 C）。
    // 与 §7.43 的「深色图自己扛」相比多了一层**左侧深色渐隐罩**，这不是把当年删掉的白纱请回来：
    // 白纱是浅色、会把海报洗成奶昔；这层是黑色、只压文字那一侧，卡宽 76.2% 处归零（58.6% 前满值 α=0.35）。
    // 375 档实测：罩在卡内 x≈261 CSS px 归零，「邀请」按钮左沿在 x≈262 —— 按钮一点罩都没吃到，
    // 这一点有实测自证：摘掉罩再量那颗按钮，读数和有罩**逐位相同**（4.67/4.67、4.72/4.72）。
    // 罩的边界用百分比写，卡（含罩）随视口等比缩放，所以"按钮在罩之外"这个关系不随视口变（320 档量到 76.2%）。
    // 为什么必须有：这张是逆光雾景，把带顶从 160 到 440 每 20 行全扫了一遍（qa/make_invite_banner_fog.py
    // --sweep-bands），**没有一条带能让两行字同时达标** —— 带取低亮雾压在标题上（最坏 1.87~2.78:1），
    // 取高压在副标尾巴上（340 档副标 3.40:1）；副标就算从 85% 白改成纯白也只有 4.03/5.01，两头仍一头沉。
    // 两把尺子都对过同一套口径（背景板 = 藏掉四个前景节点再拍，按钮/图标先合成半透明板再比）：
    //   离线 qa/make_invite_banner_fog.py（DSF=3）  标题 5.80 / 副标 6.55 / 按钮 4.56 / 图标 6.16
    //   真实渲染 CDP 十档视口 320~820            标题 5.76 / 副标 5.98 / 按钮 4.65 / 图标 5.72（各档最坏）
    //   线：标题、图标按大图 3.0，副标、按钮按正文 4.5 —— 两边都过，且离线那只没比真机乐观。
    // 百分比是相对 ::before 那层（= 卡片 padding box 341x76）算的，postcss 不动百分号。
    background: linear-gradient(90deg, rgba(0, 0, 0, 0.35) 0 58.6%, rgba(0, 0, 0, 0) 76.2%),
      url('/images/invite-banner-bg-v5.webp') center/cover no-repeat;
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
    // 透明灰按钮（2026-10-16 §7.44：用户把上一条口径「液态玻璃白 + 深红字」改成「透明灰」，
    // 基准色 rgb(46,46,52)、α=0.62 是那一轮量出来的。§7.49 换了底图，这颗没跟着动 ——
    // 它整个在罩的右端之外（实测：罩在卡内 x≈261 归零、按钮左沿 x≈262，见上面 ::before 那段），仍按透明灰自己扛）。
    // 换图后用 headless Chrome 真排版复量（2026-10-17，十档视口 320/360/375/390/412/430/480/600/768/820，
    // E:\tmp\v49\cdp49*.js + contrast49*.py）：板上白字最坏点
    //   **十档最差 4.65:1**（820x1180），390 档 4.67、离线那只 4.56 —— 都过 AA 线 4.5，但余量薄。
    // 要加厚就抬 α：离线扫过 0.55→3.80、0.58→4.08、0.62→4.56、0.68→5.36、0.75→6.55。
    // 本轮**没抬**，因为 α 是用户上一轮点名定的值；读数留在这儿，等哪张底图把 4.5 压穿了再提请改。
    // 这里**不写 backdrop-filter**：不糊底就没有"这台机器的 WebView 支不支持 blur"的争议，
    // 上面那串数字即真机逐像素读数。代价是底图细节会透出来一点，这正是"透明灰"要的观感。
    // 字色只能是浅色：同一块板上 #C00000 红字的最坏点是 1.00:1（逐像素找得到和字同色的底 = 看不见）。
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
