<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useNoticeStore } from '@/stores/notice'
import AppNavBar from '@/components/AppNavBar.vue'
import AppButton from '@/components/AppButton.vue'

const route = useRoute()
const router = useRouter()
const store = useNoticeStore()

const notice = ref(null)
const loading = ref(true)

onMounted(async () => {
  try {
    notice.value = await store.fetchNotice(route.params.id)
  } catch {
    notice.value = null
  } finally {
    loading.value = false
  }
})

/**
 * 返回：有上一页就正常后退；没有历史（比如直接打开/刷新这条深链）时退回公告列表。
 * 只写 router.back() 的话，无历史时浏览器会原地不动（用户感知就是"返回键点了没用"的另一种）。
 */
function back() {
  if (router.options.history.state.back) router.back()
  else router.push('/notice')
}

/**
 * 类型标签的底色。接口已经把 tagColor 收敛成 #rrggbb（后端 AnnouncementCover::colorFor：
 * 公告自带的 tag_color 优先，否则该子分类主题色），这里再核一次是因为 style 绑定是
 * 往 DOM 里写颜色，脏值最好别进到浏览器 —— 认不下来就用平台主红兜底。
 */
const FALLBACK_COLOR = '#C00000'
const typeStyle = computed(() => {
  const c = String(notice.value?.tagColor || '')
  const color = /^#[0-9a-fA-F]{6}$/.test(c) ? c : FALLBACK_COLOR
  return { backgroundColor: color, borderColor: color }
})
</script>

<template>
  <div class="notice-detail page">
    <!-- @click-left 必须绑：AppNavBar 自己不做任何跳转，只 emit('click-left')，
         早先这里漏绑导致左上角返回箭头点了没反应（正文底部那颗「返回公告列表」是另一条路径） -->
    <AppNavBar title="公告详情" @click-left="back" />

    <template v-if="notice">
      <!-- 一张主卡片从头读到尾：标题 → 类型标签 + 日期 → 头图横幅 → 正文（对齐参考图口径） -->
      <article class="notice-detail__main">
        <h1 class="notice-detail__title">{{ notice.title }}</h1>
        <div class="notice-detail__meta">
          <span class="notice-detail__type" :style="typeStyle">{{ notice.categoryLabel }}</span>
          <span class="notice-detail__time">{{ notice.time }}</span>
        </div>

        <!-- 头图横幅：后端已经把「这条公告自己上传的图 > 该类型默认头图 > 空」算成一个最终地址下发下来，
             空串（该类型默认图也被清空了）就整块不渲染，不留灰坑。
             位置：正文区最上方、卡片内通宽居中（2026-10-14 你的要求「首图放在顶部居中的位置，圆角10px」），
             不再左右出血。真源：后端 AnnouncementCover::coverFor -->
        <img
          v-if="notice.coverImage"
          class="notice-detail__cover"
          :src="notice.coverImage"
          :alt="notice.categoryLabel"
        />

        <div class="notice-detail__body">
          <!-- 开头敬语：接口给的是「这条公告自己填的 > 全局默认」的最终值，纯文本 {{ }} 渲染 -->
          <p v-if="notice.greeting" class="notice-detail__greeting">{{ notice.greeting }}</p>

          <!-- 富文本模式 -->
          <template v-if="notice.richContent">
            <div
              v-for="(block, i) in notice.richContent"
              :key="i"
              class="notice-detail__block"
              :class="`notice-detail__block--${block.type}`"
            >
              <!-- 用 div 承载富文本（管理端编辑器产出，可能含 h2/ul/img 等块级标签） -->
              <div v-if="block.type === 'text'" class="notice-detail__p" v-html="block.value"></div>
              <img v-else-if="block.type === 'image'" class="notice-detail__image" :src="block.value" alt="" />
              <div v-else-if="block.type === 'signature'" class="notice-detail__signature" v-html="block.value"></div>
              <div v-else-if="block.type === 'warning'" class="notice-detail__warning" v-html="block.value"></div>
            </div>
          </template>

          <!-- 普通文本模式 -->
          <template v-else>
            <p v-for="(p, i) in notice.content" :key="i" class="notice-detail__p">{{ p }}</p>
          </template>

          <!-- 落款：署名（可后台逐条改）+ 发布日期（后端按实际生效时间算，运营改不了）；
               写在两个正文分支之外，所以富文本与纯文本公告都有落款 -->
          <div v-if="notice.signature" class="notice-detail__foot">
            <span class="notice-detail__foot-name">{{ notice.signature }}</span>
            <span v-if="notice.signatureDate" class="notice-detail__foot-date">{{ notice.signatureDate }}</span>
          </div>
        </div>

        <!-- 平台风险提示（2026-10-14 起，见 RUNBOOK §7.32）：后端 GET /api/announcements/:id 的
             disclaimer 字段，公告与新闻、富文本与纯文本正文都统一拼在最底部，所以放在上面两个分支之外；
             文案在后台「系统配置 → 全局参数」改。
             2026-10-15 你的要求「公告正文和风险提示共用一个边框」→ 它搬进正文那张卡片里，
             不再自己占一张白卡（§7.39，这条推翻了 §7.32/§7.36 的「分开才像平台在说话」口径）；
             里面仍复用 __warning 这套现成样式（居中标题 + 灰底小字）。 -->
        <div v-if="notice.disclaimer" class="notice-detail__warning" v-html="notice.disclaimer"></div>
      </article>

      <div class="notice-detail__back">
        <AppButton @click="back">返回公告列表</AppButton>
      </div>
    </template>

    <div v-else-if="!loading" class="notice-detail__empty">公告不存在或已下架</div>
  </div>
</template>

<style scoped lang="scss">
.notice-detail {
  padding-bottom: calc(#{$tabbar-height} + env(safe-area-inset-bottom) + 12px);
  background: $color-bg;
  min-height: 100vh;

  // 主卡片：标题 → 类型标签 + 日期 → 头图 → 正文，一张卡从头读到尾（参考图口径）
  &__main {
    margin: 12px $page-padding 0;
    padding: 16px;
    background: $color-card;
    border-radius: $radius-lg;
  }
  &__title {
    margin: 0 0 10px;
    font-size: 19px;
    font-weight: 700;
    color: $color-text-primary;
    line-height: 1.5;
  }
  &__meta {
    display: flex;
    align-items: center;
    gap: 8px;
  }
  // 类型标签：底色是接口算好的（公告自带 tag_color > 该类型主题色），所以不写死颜色类
  &__type {
    display: inline-flex;
    align-items: center;
    height: 22px;
    padding: 0 8px;
    border: 1px solid transparent;
    border-radius: 4px;
    color: #ffffff;
    font-size: 12px;
    line-height: 1;
    white-space: nowrap;
  }
  &__time {
    font-size: 12px;
    color: $color-text-tertiary;
  }
  // 头图横幅：卡片内通宽、水平居中，圆角 10px（2026-10-14 你的要求「首图放在顶部居中的位置，圆角10px」）。
  // 比例跟图本身走，出厂图 1280×495，运营自己传的图按宽度等比缩放、不裁头不裁尾
  &__cover {
    display: block;
    width: 100%;
    margin: 12px auto 0;
    border-radius: 10px;
    object-fit: cover;
  }
  &__body {
    margin-top: 14px;
  }

  // 开头敬语：接口给的是最终值（这条自己填的 > 全局默认 > 出厂值），纯文本渲染，样式跟正文一致
  &__greeting {
    margin: 0 0 10px;
    font-size: 14px;
    color: $color-text-primary;
    line-height: 1.85;
  }

  &__block { margin-bottom: 12px; }
  &__block:last-child { margin-bottom: 0; }

  &__p {
    margin: 0 0 10px;
    font-size: 14px;
    color: $color-text-primary;
    line-height: 1.85;
    text-align: justify;
  }
  &__p:last-child { margin-bottom: 0; }

  // 富文本正文（管理端富文本编辑器产出，v-html 渲染）
  :deep(.notice-detail__p) {
    h2 { font-size: 17px; font-weight: 700; margin: 14px 0 8px; }
    h3 { font-size: 15px; font-weight: 600; margin: 10px 0 6px; }
    ul, ol { padding-left: 20px; margin: 6px 0 10px; }
    li { margin: 3px 0; }
    img { display: block; max-width: 100%; border-radius: $radius-md; margin: 10px auto; }
    a { color: $color-primary; text-decoration: underline; word-break: break-all; }
    blockquote {
      margin: 10px 0; padding: 8px 12px;
      border-left: 3px solid $color-primary;
      background: $color-bg; border-radius: $radius-md;
      color: $color-text-secondary; font-size: 13px;
    }
    strong { font-weight: 700; }
    p { margin: 0 0 10px; font-size: 14px; line-height: 1.85; }
    p:last-child { margin-bottom: 0; }
  }

  :deep(.label) { font-weight: 700; color: $color-text-primary; }
  :deep(.highlight) { color: $color-primary; font-weight: 700; }
  :deep(.section-title) {
    display: block;
    font-weight: 700;
    color: $color-text-primary;
    margin: 14px 0 6px;
  }

  &__image {
    display: block;
    width: 100%;
    border-radius: $radius-md;
    margin: 8px 0;
  }

  &__signature {
    margin-top: 16px;
    text-align: right;
    font-size: 13px;
    color: $color-text-secondary;
    line-height: 1.8;
  }

  // 风险提示共用一套文字样式，盒子按场合分两种：
  //  - 挂在页面上的那条（接口 disclaimer 字段）→ 就在正文卡片最底下，用一条虚线和正文隔开（§7.39）
  //  - 夹在正文块里的（富文本 block.type === 'warning'，现网接口不产出，留着兜历史数据）→ 卡内灰底小块
  &__warning {
    font-size: 12px;
    color: $color-text-secondary;
    line-height: 1.75;
    :deep(p) { margin: 0 0 8px; }
    :deep(p:last-child) { margin-bottom: 0; }
    :deep(.warn-title) {
      font-weight: 700;
      color: $color-text-primary;
      margin-bottom: 6px;
      text-align: center;
    }
  }
  &__main > .notice-detail__warning {
    margin-top: 18px;
    padding-top: 14px;
    border-top: 1px dashed $color-border;
  }
  &__body &__warning {
    margin-top: 18px;
    padding: 14px;
    background: $color-bg;
    border-radius: $radius-md;
  }

  // 落款：署名 + 发布日期，右对齐（2026-10-14 之前这里是写死的「司南文创 · 数字艺术品平台」居中一行）
  &__foot {
    margin-top: 18px; padding-top: 14px;
    border-top: 1px dashed $color-border;
    display: flex; justify-content: flex-end; align-items: baseline; gap: 8px;
    font-size: 13px; color: $color-text-secondary;
    text-align: right;
  }
  &__foot-date {
    font-size: 12px; color: $color-text-tertiary;
  }

  &__back { margin: 16px $page-padding 0; }

  &__empty {
    text-align: center;
    color: $color-text-tertiary;
    font-size: 14px;
    margin-top: 80px;
  }
}
</style>
