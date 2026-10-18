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
    // 「水墨写意·爵」v8（2026-10-18 §7.52：用户口径「把现在正在用的邀请卡片的背景主体往上提一点」「把脚露出来」）。
    // **同一张源图、只换裁带**：源图 = F3（2560x1080，md5 2591c00dbac2f2a868823d5bdeee51b4），
    // v7 从它裁 200:768，v8 裁 **352:920**，都缩到 1280x284。**9,644 B**
    // （md5 08b551351bb49d665a165fa2c319f4db；v7 是 7,554 B / 22b468e8…，v6 21,236 B、v5 21,974 B）。
    //
    // 为什么要换带 —— v7 那版**把爵的三条腿整条切在卡外**：脚底在源图 **900 行**（按 L>0.12 逐行量出来的），
    // 而 200:768 那档的带底只有 768 行 ⇒ 脚尖落在卡片下沿以下约 18 CSS px，画面里根本看不见。
    // 带高不是自由参数：卡片盒 343x76 = 比例 4.513、图必须 4.507 ⇒ 带高被钉死 **568 行**。
    // 爵本体（口沿上那根柱 367 行 → 脚尖 900 行）跨度 **533 行**，装得进 568 行的带，但**总余量只有 35 行**
    // —— 往上那缕烟必须让位、切在画面上沿。352:920 就是把爵摆正的那一档：
    // 柱顶距上沿 15 行（≈2 CSS px）、脚尖距下沿 20 行（≈2.7 CSS px）。
    //
    // ⚠ 顺手订正 §7.51 留在这里的一句错话：原文写「文字区换成干净的浅灰纸」。**实测正相反** ——
    //   文字区是 rgb(19,18,21)，整幅 L 均值 0.0103、最亮处也只 0.54，这张是**暗底亮爵**（爵用亮墨画在黑纸上）。
    //   白字 18:1 成立靠的就是这个近黑底；黑字打在同一块底上只有 **1.07:1**。判字色别照注释读，要照数读。
    //
    // **这一层现在只剩图，罩摘掉了**（§7.51 的决定，v8 没动它）—— 为什么前两轮必须有罩、这一轮能摘：
    //   卡片**没有任何横向裁剪手段**（`center/cover` 把整条 2560 宽的图正好压进卡宽，只有纵向那条带能选），
    //   所以主体只要落在源列 2%~73.3%（= 文字区那一段）里，它就一定压在字底下。v5 那只鼎、v6 那只爵都摆在
    //   画面正中，亮部又是一大片弥散的雾 ⇒ 当时 15 档带全扫一遍「没有任何一条能同时让两行字达标」，只能加 CSS 罩。
    //   这张把主体收到**右侧**、字底下是一整块近黑底，裸图自己就够：带顶 160..440 那 15 档
    //   **每一档四块全过**（最差的也还有 标题 15.05 / 副标 7.77 / 按钮 15.21 / 图标 3.89）—— 那 15 档是
    //   §7.51 在**旧带**上量的，当时的判据是「四块同时最好」所以选了 200:768；v8 的判据换成了
    //   「**先把脚露出来，四块再同时不红**」，所以带挪到 352:920（代价见下面那一段，两格都掉了但都还在线上）。
    //
    // 读数（口径与 §7.48 起一致：离线只量设计值、不含 text-shadow 增益；取最坏点不是平均值；
    //   背景板 = 把四个前景节点藏掉再拍一张；按钮/图标先与半透明板逐点合成再比）：
    //   qa 脚本尺 A（DSF=3，压缩前那张 1280x284）  标题 18.05 / 副标 8.40 / 按钮 15.21 / 图标 6.41
    //   尺 B（**落盘后的 webp 字节**，同一段代码）  标题 18.24 / 副标 8.76 / 按钮 15.45 / 图标 6.75
    //     （Lossy 把最坏那几点压平了，所以真字节反而略好；两套都贴在这，spec 钉的是落盘字节那一套。
    //      同一把尺拿 v7 落盘字节复量 = 18.41 / 13.30 / 15.45 / 9.59，与 §7.51 记的逐位相同 ⇒ 尺子没动）
    //   尺 C 真机 CDP 十档 320/360/375/390/412/430/480/600/768/820（E:/tmp/v52/cdp52.js + contrast52.py）
    //     各档取最坏、min(底最坏, 笔画核心)：       标题 15.04 / 副标 7.93 / 按钮 12.62 / 图标 6.82
    //     只看"底最坏"那一列（拿来与尺 B 对表）：    标题 18.24 / 副标 9.12 / 按钮 15.45 / 图标 6.82
    //     十档自证：每档"藏掉四个前景再拍"那张与首张**逐位一致**=true；叠回 §7.50 那副罩只让四块**往上抬**
    //     （标题 16.15 / 副标 11.23 / 按钮 12.62 / 图标 9.61）⇒ 这轮的绿不是罩在扛，也不是尺子松了。
    //   线：标题、图标按非文字 3.0，副标、按钮按正文 4.5。未达标列：标题 无、副标 无。
    //
    // 换带的**代价**（这两格是往下挪带子换来的，都还在各自的线上，但别当成没掉）：
    //   图标 9.59 → **6.75**（带底从 768 推到 920，源图底部那片亮地气进了图标那一格）；
    //   副标 13.30 → **8.76**（同一原因，字底下变亮）。两格离 3.0 / 4.5 线仍各有 2~4 倍。
    //   标题 18.41 → 18.24、按钮 15.45 → 15.45 —— 基本没动。
    //
    // 三组控制组（不红就说明量具分不出好坏，上面那串数不算证据）：
    //   ① **只摘罩不换图** = 上一版 v6 裸图拿同一把尺：标题 1.77 / 副标 1.80 ⇒ 两行字都会融进雾里。
    //      这条才是"摘罩"真正的反证：那轮赢的是**构图**，不是尺子松了。
    //   ② v6 叠回 §7.50 那副罩：6.12 / 5.35 / 9.21 / 12.66 —— 和 §7.50 记的离线数（6.20/5.46/9.08/12.66）
    //      只差压缩那一点点 ⇒ 尺子从头到尾没动。（① ② 都是 §7.51 在旧带 200:768 上量的历史证据，
    //      v8 只换带、没动罩也没动字色，所以照旧引用、不重算；要重算得先造 v6 那张旧图。）
    //   ③ 退回 §7.42 那套旧色（**拿 v8 这块底、同一只墨迹盒当场重量**，三把尺一起给）：
    //      尺 A 深红标题 2.18 / 棕副标 1.00 / 灰板红字 2.35；尺 B 2.20 / 1.00 / 2.38；
    //      真机十档 2.20 / 1.01 / 2.38 —— 三档都远低于各自要求（3.0 / 4.5 / 4.5）。
    //      （含义都是"迟早有一片字完全融进底里"，别把这些数当读数。）
    //
    // 文案上界（v8 当场重量的右界）：标题行第一个跌破 3.0 的列在 **x=206**、副标行跌破 4.5 的列在
    // **x=225**（旧带是 206 / 220 —— 往下挪带子反而把副标右界推出去 5 列）⇒ 上限仍是 **标题 8 字 / 副标 12 字**，
    // 而副标"同游作伴，潮流好礼不设限"正好 12 字 = **贴线，一个字都不能再加**（spec 有这条上限钉）。
    // 叠回罩（反证，不落盘）能推到 247 / 241。
    background: url('/images/invite-banner-bg-v8.webp') center/cover no-repeat;
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
    // 离线板上白字最坏点：尺 B（落盘字节）**15.45:1**、尺 A（压缩前）15.21 —— 过 AA 线 4.5 得很宽。
    // 要加厚就抬 α？这张图上**抬不动了**，而且六档读数和 v7 **逐位相同**（352:920 那条带子没动过按钮那块底）：
    //   尺 A（压缩前，两档带同值）0.55→15.48、0.58→15.41、0.62→15.21、0.68→14.88、0.75→14.67、0.82→14.28
    //   尺 B（落盘字节，v7/v8 同值）0.55→15.66、0.58→15.46、0.62→15.45、0.68→15.06、0.75→14.67、0.82→14.28
    //   （§7.51 那条注释只写了一串数、没标是哪把尺 —— 当场复量证明它是**尺 A**。本轮两把都标上。）
    //   前两轮是"抬 α 就往上走"（那颗坐在亮雾上，灰板压亮雾才有效）；这一轮底图本身就是深色，
    //   卡住读数的是**灰板自己的本色**（纯白对 rgb(46,46,52) = 13.49:1 是天花板），α 越高越靠近天花板。
    //   所以 0.62 保持：它现在比 §7.49 那轮（4.65）、§7.50 那轮（9.08）都厚。
    // 真机 CDP 十档复量（E:/tmp/v52/cdp52.js + contrast52.py）：底最坏那列 **15.45**，与尺 B 逐位相同；
    //   十档最坏落在「笔画核心」那档 = 12.62。这颗按钮不吃罩：反证档把罩注回去还是 12.62。
    //   十档合并的板中位色 rgb(36,36,41)（v7 是 rgb(36,36,40)，§7.50 有罩那版 rgb(51,50,49)）⇒ 白字对它 15.45:1。
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
