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
    // 「水墨写意·爵」（2026-10-18 §7.51：用户口径「换个风格，主体小一点」→ 定「B 水墨写意」+「摘掉罩」，
    // 并明确「主体收到右侧，文字区全干净」）。底图 = qa/make_invite_banner_fog.py 从 2560x1080 原图
    // 裁 200:768 缩到 1280x284，**7,554 B**（md5 22b468e82aa492cf45ed5ef7ccb3a8cf；v6 是 21,236 B、
    // §7.49 的 v5 是 21,974 B —— 这张不但更轻，落在卡里的亮部面积也从 §7.50 那版的 36.6% 掉到 6.2%）。
    //
    // **这一层现在只剩图，罩摘掉了** —— 为什么前两轮必须有罩、这一轮能摘：
    //   卡片**没有任何横向裁剪手段**（`center/cover` 把整条 2560 宽的图正好压进卡宽，只有纵向那条带能选），
    //   所以主体只要落在源列 2%~73.3%（= 文字区那一段）里，它就一定压在字底下。v5 那只鼎、v6 那只爵都摆在
    //   画面正中，亮部又是一大片弥散的雾 ⇒ 当时 15 档带全扫一遍「没有任何一条能同时让两行字达标」，只能加 CSS 罩。
    //   这张把主体收到**右侧**、文字区换成干净的浅灰纸，裸图自己就够：带顶 160..440 那 15 档
    //   **每一档四块全过**（最差的也还有 标题 15.05 / 副标 7.77 / 按钮 15.21 / 图标 3.89），
    //   所以带**不是按对比度选的**，是取「四块同时最好」的那一档 200:768。
    //
    // 读数（口径与 §7.48 起一致：离线只量设计值、不含 text-shadow 增益；取最坏点不是平均值；
    //   背景板 = 把四个前景节点藏掉再拍一张；按钮/图标先与半透明板逐点合成再比）：
    //   qa 脚本（DSF=3，压缩前那张 1280x284）  标题 18.20 / 副标 13.16 / 按钮 15.21 / 图标 9.52
    //   拿**落盘后的 webp 字节**复量            标题 18.41 / 副标 13.30 / 按钮 15.45 / 图标 9.59
    //     （Lossy 把最坏那几点压平了，所以真字节反而略好；两套都贴在这，spec 钉的是落盘字节那一套）
    //   真机 CDP 十档 320~820（E:/tmp/v51/cdp51.js + contrast51.py，十档各自最坏）：
    //     min(底最坏, 笔画核心) = 标题 15.11 / 副标 10.90 / 按钮 12.62 / 图标 9.37，十档未达标列全为「无」；
    //     只看「底最坏」那一列是 18.42 / 13.30 / 15.27 / 9.59 —— 与上面拿落盘字节复量那套差 ≤0.18，同一把尺。
    //   反证档（脚本临时把 §7.50 那副罩注回去）十档最坏 16.16 / 11.59 / 12.62 / 10.62：只往上抬；
    //     背景板亮度每档都是「裸图 > 叠罩」（375 档 0.0142 vs 0.0102）⇒ 摘掉的是一层多余的，不是放宽判据。
    //   线：标题、图标按非文字 3.0，副标、按钮按正文 4.5。未达标列：标题 无、副标 无。
    //
    // 三组控制组（不红就说明量具分不出好坏，上面那串数不算证据）：
    //   ① **只摘罩不换图** = 上一版 v6 裸图拿同一把尺：标题 1.77 / 副标 1.80 ⇒ 两行字都会融进雾里。
    //      这条才是"摘罩"真正的反证：这轮赢的是**构图**，不是尺子松了。
    //   ② v6 叠回 §7.50 那副罩：6.12 / 5.35 / 9.21 / 12.66 —— 和 §7.50 记的离线数（6.20/5.46/9.08/12.66）
    //      只差压缩那一点点 ⇒ 尺子从头到尾没动。
    //   ③ 退回 §7.42 那套旧色（同一块底、同一只墨迹盒）：深红标题 2.20 / 棕副标 1.26 / 灰板红字 2.35，
    //      三档都远低于各自要求。（旧色在 v5/v6 那种"从近黑到亮雾"的连续底上会近乎 1.00，在这张浅纸底上能到
    //      2.2 —— 含义都是"迟早有一片字完全融进底里"，别把这个数当读数。）
    //
    // 摘罩的**代价**写在这：罩本来还顺手把两行字的右界往外推。裸图标题第一个跌破 3.0 的列在 **x=206**、
    // 副标跌破 4.5 的列在 **x=220** ⇒ 文案上限收到 **标题 8 字 / 副标 12 字**，而现在副标"同游作伴，
    // 潮流好礼不设限"正好 12 字 = **贴线，一个字都不能再加**（spec 有这条上限钉）。叠回罩能推到 247 / 228。
    background: url('/images/invite-banner-bg-v7.webp') center/cover no-repeat;
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
    // 基准色 rgb(46,46,52)、α=0.62 是那一轮量出来的。§7.49 / §7.50 / §7.51 三次换图这颗都没跟着动）。
    // §7.51 起**罩摘掉了**，所以这颗不再有"在不在罩外"的问题 —— 它坐在**爵身那块深色墨**上：
    //   盒 x=263..327（左沿离副标墨迹右界 x=216 有 47 CSS px，两件事互不干扰）。
    // 离线（qa 脚本，落盘字节）板上白字最坏点 **15.45:1**，压缩前那张 15.21 —— 过 AA 线 4.5 得很宽。
    // 要加厚就抬 α？这张图上**抬不动了**：0.55→15.48、0.58→15.41、0.62→15.21、0.68→14.88、
    //   0.75→14.67、0.82→14.28 —— 前两轮是"抬 α 就往上走"（那颗坐在亮雾上，灰板压亮雾才有效），
    //   这一轮底图本身就是深色，卡住读数的是**灰板自己的本色**（纯白对 rgb(46,46,52) = 13.49:1 是天花板），
    //   α 越高越靠近这个天花板。所以 0.62 保持：它现在比 §7.49 那轮（4.65）、§7.50 那轮（9.08）都厚。
    // 真机 CDP 十档复量（E:/tmp/v51/contrast51.py）：底最坏那列 15.27，与上面落盘字节那套 15.45 同档；
    //   十档最坏落在「笔画核心」那档 = 12.62（各档 12.62~12.64）。这颗按钮不吃罩：反证档把罩注回去还是 12.62。
    //   十档合并的板中位色 rgb(36,36,40)（§7.50 有罩那版是 rgb(51,50,49)）⇒ 白字对它 15.46:1。
    // 这里**不写 backdrop-filter**：不糊底就没有"这台机器的 WebView 支不支持 blur"的争议，
    //   上面那串数字即真机逐像素读数。代价是底图细节会透出来一点，这正是"透明灰"要的观感。
    // 字色只能是浅色：同一块板上 #C00000 红字离线最坏 2.35:1（逐像素找得到和字同色的底 = 看不见）。
    border: 1px solid rgba(255, 255, 255, 0.32); cursor: pointer;
    background: rgba(46, 46, 52, 0.62); color: #FFFFFF; font-size: 14px;
    // 内边距**四个方向各收 1px**，把新增的 1px 边框补回去：外沿和改之前是同一个盒子
    // （390 档 getBoundingClientRect 实测 66.45x38.38 CSS px，改之前 66.55x38.47，差 0.1px = 亚像素噪声）。
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
