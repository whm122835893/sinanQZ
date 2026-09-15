<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import request from '@/utils/request'
import AppNavBar from '@/components/AppNavBar.vue'
import AppIcon from '@/components/AppIcon.vue'
import AppListItem from '@/components/AppListItem.vue'
import AppButton from '@/components/AppButton.vue'
import { showToast, showImagePreview } from 'vant'

const router = useRouter()

// 客服配置（B 端系统参数：service_hotline / service_hours / service_online_url）
const service = ref({ hotline: '', hours: '9:00 - 22:00', onlineUrl: '' })
// 社群（B 端「官方社群」配置，含二维码与 QQ 群号）
const groups = ref([])
const showJoin = ref(false)

// 弹窗展示的社群：优先选择已配置 QQ 群号或二维码的社群
const activeGroup = computed(() => groups.value.find((g) => g.qqGroup || g.qrCode) || groups.value[0] || null)

async function load() {
  try {
    const cfg = await request.get('/config')
    if (cfg?.service) {
      service.value = {
        hotline: cfg.service.hotline || '',
        hours: cfg.service.hours || '9:00 - 22:00',
        onlineUrl: cfg.service.onlineUrl || ''
      }
    }
  } catch { /* 网络异常沿用默认值 */ }

  try {
    const list = await request.get('/community/groups')
    groups.value = (list || []).map((g) => ({
      name: g.name || '官方社群',
      qrCode: g.qrCode || '',
      qqGroup: g.qqGroup || ''
    }))
  } catch { /* 忽略 */ }
}

onMounted(load)

function online() {
  const url = service.value.onlineUrl
  if (url) window.location.href = url
  else showToast('正在接入在线客服…')
}

function openJoin() {
  if (!activeGroup.value) {
    showToast('暂无可加入的社群')
    return
  }
  showJoin.value = true
}

function previewQr() {
  if (activeGroup.value?.qrCode) showImagePreview({ images: [activeGroup.value.qrCode], closeable: true })
}

// 跳转 QQ 加入群：QQ 群号跳转 URL Scheme
function joinGroup() {
  const g = activeGroup.value
  if (!g) return
  const qq = (g.qqGroup || '').trim()
  if (!qq) {
    if (g.qrCode) {
      showImagePreview({ images: [g.qrCode], closeable: true })
      showToast('请使用 QQ 扫码加入')
    } else {
      showToast('管理员尚未配置 QQ 群号')
    }
    return
  }
  window.location.href = `mqqapi://card/show_pslcard?src_type=internal&version=1&uin=${encodeURIComponent(qq)}&card_type=group&source=qrcode`
}
</script>

<template>
  <div class="service page--no-tabbar">
    <AppNavBar title="我的客服" @click-left="$router.back()" />

    <div class="service-banner">
      <AppIcon name="headset" :size="36" color="#C00000" />
      <div class="service-banner__text">
        <span class="service-banner__title">客服在线时间 {{ service.hours }}</span>
        <span class="service-banner__sub">您的问题我们将第一时间响应</span>
      </div>
    </div>

    <div class="service-group">
      <AppListItem title="在线客服" icon="headset" arrow @click="online" />
      <AppListItem title="常见问题" icon="file" arrow border @click="online" />
    </div>

    <div class="service-group" style="margin-top:12px">
      <AppListItem title="加入社群" icon="community" arrow @click="openJoin" />
    </div>

    <div class="service-group" style="margin-top:12px">
      <AppListItem title="返回上一级" icon="back" arrow @click="router.back()" />
    </div>

    <!-- 加入社群弹窗 -->
    <van-popup v-model:show="showJoin" round :style="{ width: '82%' }" class="join-popup">
      <div class="join-popup__head">
        <AppIcon name="community" :size="26" color="#C00000" />
        <span class="join-popup__title">{{ activeGroup?.name || '加入社群' }}</span>
      </div>
      <div class="join-popup__body">
        <img
          v-if="activeGroup?.qrCode"
          class="join-popup__qr"
          :src="activeGroup.qrCode"
          alt="社群二维码"
          draggable="false"
          @click="previewQr"
        />
        <div v-else class="join-popup__qrempty">二维码待配置</div>
        <div class="join-popup__tip">使用 QQ 识别二维码，或点击下方按钮直接加入</div>
      </div>
      <div class="join-popup__footer">
        <AppButton @click="joinGroup">加入社群</AppButton>
        <AppButton type="outline" class="join-popup__cancel" @click="showJoin = false">取消</AppButton>
      </div>
    </van-popup>
  </div>
</template>

<style scoped lang="scss">
.service-banner {
  margin: 12px $page-padding; background: $color-card; border-radius: $radius-lg; padding: 18px;
  display: flex; align-items: center; gap: 14px;
  &__text { display: flex; flex-direction: column; gap: 6px; }
  &__title { font-size: 15px; font-weight: 700; color: $color-text-primary; }
  &__sub { font-size: 12px; color: $color-text-tertiary; }
}
.service-group { margin: 12px $page-padding; border-radius: $radius-lg; overflow: hidden; }

.join-popup {
  padding-bottom: 20px;
  &__head {
    display: flex; align-items: center; gap: 10px;
    padding: 18px 20px 8px;
    border-bottom: 1px solid $color-border;
  }
  &__title { font-size: 16px; font-weight: 700; color: $color-text-primary; }
  &__body {
    display: flex; flex-direction: column; align-items: center; gap: 10px;
    padding: 20px;
  }
  &__qr {
    width: 200px; height: 200px; border-radius: 12px; object-fit: contain;
    border: 1px solid $color-border; background: #fff; cursor: zoom-in;
  }
  &__qrempty {
    width: 200px; height: 200px; border-radius: 12px; border: 1px dashed $color-border;
    display: flex; align-items: center; justify-content: center;
    color: $color-text-tertiary; font-size: 13px; background: $color-bg;
  }
  &__tip { font-size: 12px; color: $color-text-tertiary; text-align: center; line-height: 1.6; }
  &__footer { padding: 6px 20px 0; }
  &__cancel { margin-top: 12px; }
}
</style>