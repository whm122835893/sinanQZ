<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import request from '@/utils/request'
import AppNavBar from '@/components/AppNavBar.vue'
import AppIcon from '@/components/AppIcon.vue'
import AppButton from '@/components/AppButton.vue'
import AppEmpty from '@/components/AppEmpty.vue'
import { showToast, showImagePreview } from 'vant'

const router = useRouter()

const groups = ref([])
const loading = ref(false)

// MOCK_REPLACED: 原为内联 mock 社群数组（司南官方社群/玩家交流群），
// 现从后端拉取：GET /api/community/groups（nft_community_groups 配置）
async function fetchGroups() {
  loading.value = true
  try {
    const list = await request.get('/community/groups')
    groups.value = (list || []).map((g) => ({
      id: g.id,
      icon: g.icon || '',
      name: g.name,
      desc: g.description || '',
      qrCode: g.qrCode || ''
    }))
  } catch (e) {
    showToast(e.message || '加载失败')
  } finally {
    loading.value = false
  }
}

onMounted(fetchGroups)

// 图标：后端配置为图片 URL 时用 <img>，否则回退内置图标
const isImg = (v) => !!v && (/^(https?:)?\/\//.test(v) || v.startsWith('/'))

function previewQr(url) {
  if (url) showImagePreview({ images: [url], closeable: true })
}

function copy(text, msg) {
  if (navigator.clipboard) {
    navigator.clipboard.writeText(text).then(() => showToast(msg)).catch(() => showToast('复制失败'))
  } else {
    showToast('当前环境不支持复制')
  }
}

// 点击社群卡片：复制群名称，微信中搜索加入
function onGroupClick(g) {
  copy(g.name, `「${g.name}」已复制，请在微信中搜索加入`)
}
</script>

<template>
  <div class="community page--no-tabbar">
    <AppNavBar title="加入社区" @click-left="$router.back()" />

    <div class="community-banner">
      <AppIcon name="community" :size="40" color="#C00000" />
      <div class="community-banner__text">
        <span class="community-banner__title">同游作伴，潮流好礼不设限</span>
        <span class="community-banner__sub">扫码或搜索群名称加入司南官方社群</span>
      </div>
    </div>

    <div v-if="groups.length" class="community-list">
      <div v-for="g in groups" :key="g.id" class="community-item" @click="onGroupClick(g)">
        <img v-if="isImg(g.icon)" class="community-item__img" :src="g.icon" alt="" draggable="false" />
        <AppIcon v-else name="community" :size="28" color="#C00000" />
        <div class="community-item__text">
          <span class="community-item__name">{{ g.name }}</span>
          <span class="community-item__desc">{{ g.desc }}</span>
        </div>
        <img
          v-if="isImg(g.qrCode)"
          class="community-item__qr"
          :src="g.qrCode"
          alt="群二维码"
          title="查看二维码"
          draggable="false"
          @click.stop="previewQr(g.qrCode)"
        />
      </div>
    </div>
    <div v-else-if="loading" class="community-list__loading">加载中...</div>
    <AppEmpty v-else description="暂无社群，敬请期待" />

    <div class="community-action">
      <AppButton
        :disabled="!groups.length"
        @click="copy(groups.map((g) => g.name).join('、'), '群名称已复制，请在微信中搜索加入')"
      >复制群名称</AppButton>
      <AppButton type="outline" style="margin-top:12px" @click="router.back()">返回</AppButton>
    </div>
  </div>
</template>

<style scoped lang="scss">
.community-banner {
  margin: 12px $page-padding; background: $color-card; border-radius: $radius-lg; padding: 18px;
  display: flex; align-items: center; gap: 14px;
  &__text { display: flex; flex-direction: column; gap: 6px; }
  &__title { font-size: 15px; font-weight: 700; color: $color-text-primary; }
  &__sub { font-size: 12px; color: $color-text-tertiary; }
}
.community-list { padding: 0 $page-padding; }
.community-list__loading { padding: 32px 0; text-align: center; font-size: 13px; color: $color-text-tertiary; }
.community-item {
  display: flex; align-items: center; gap: 14px; background: $color-card; border-radius: $radius-lg;
  padding: 16px; margin-bottom: 12px; cursor: pointer;
  &__text { display: flex; flex-direction: column; gap: 4px; flex: 1; min-width: 0; }
  &__name { font-size: 15px; font-weight: 600; color: $color-text-primary; }
  &__desc { font-size: 12px; color: $color-text-tertiary; @include ellipsis; }
  &__img {
    width: 28px; height: 28px; border-radius: $radius-md; object-fit: cover; flex-shrink: 0;
    pointer-events: none; user-select: none; -webkit-touch-callout: none;
  }
  &__qr {
    width: 56px; height: 56px; border-radius: $radius-md; object-fit: cover; flex-shrink: 0;
    border: 1px solid $color-border; background: #fff; cursor: zoom-in;
    -webkit-touch-callout: none;
  }
}
.community-action { padding: 8px $page-padding 0; }
</style>
