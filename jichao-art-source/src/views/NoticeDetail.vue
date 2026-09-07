<script setup>
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useNoticeStore } from '@/stores/notice'
import AppNavBar from '@/components/AppNavBar.vue'
import AppTag from '@/components/AppTag.vue'
import AppButton from '@/components/AppButton.vue'

const route = useRoute()
const router = useRouter()
const store = useNoticeStore()

// MOCK_REPLACED: 原为从本地 mock 数组查找，现走后端详情接口（GET /api/announcements/:id）
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

function back() { router.back() }
</script>

<template>
  <div class="notice-detail page">
    <AppNavBar title="公告详情" />

    <template v-if="notice">
      <!-- 标题区：保留，无渐变 -->
      <header class="notice-detail__head">
        <h1 class="notice-detail__title">{{ notice.title }}</h1>
        <div class="notice-detail__meta">
          <AppTag type="primary">{{ notice.categoryLabel }}</AppTag>
          <span class="notice-detail__time">{{ notice.time }}</span>
        </div>
      </header>

      <!-- 正文卡片 -->
      <article class="notice-detail__content">
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
          <div class="notice-detail__foot">
            <span>司南文创 · 数字艺术品平台</span>
          </div>
        </template>
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

  &__head {
    margin: 12px $page-padding 0;
    padding: 16px;
    background: $color-card;
    border-radius: $radius-lg;
  }
  &__time {
    font-size: 12px;
    color: $color-text-tertiary;
  }
  &__title {
    margin: 0 0 12px;
    font-size: 18px;
    font-weight: 700;
    color: $color-text-primary;
    line-height: 1.55;
  }
  &__meta {
    display: flex;
    align-items: center;
    justify-content: space-between;
  }

  &__content {
    margin: 12px $page-padding 0;
    padding: 18px 16px 20px;
    background: $color-card;
    border-radius: $radius-lg;
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

  &__warning {
    margin-top: 18px;
    padding: 14px;
    background: $color-bg;
    border-radius: $radius-md;
    font-size: 12px;
    color: $color-text-secondary;
    line-height: 1.75;
    :deep(p) { margin: 0 0 8px; }
    :deep(p:last-child) { margin-bottom: 0; }
    :deep(.warn-title) {
      font-weight: 700;
      color: $color-text-primary;
      margin-bottom: 6px;
    }
  }

  &__foot {
    margin-top: 18px; padding-top: 14px;
    border-top: 1px dashed $color-border;
    font-size: 12px; color: $color-text-tertiary;
    text-align: center;
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
