<script setup>
import { ref, computed, onBeforeUnmount } from 'vue'
import { useSiteStore } from '@/stores/site'

// ============================================================
// 开屏全屏组件（重构版）
// - B 端装修页配置启用 + 上传图片后生效
// - 图片加载成功后开始倒计时；加载失败自动跳过（且不写"已看"，下次可重试）
// - "已看"标记绑定图片标识（store.splashTargetKey）：换图后同设备自动重新展示
// - 支持点击跳过 / 倒计时自动消失
// ============================================================

const site = useSiteStore()
const imageReady = ref(false)
const dismissed = ref(false)
let timer = null

const duration = computed(() =>
  Math.max(1, Math.min(10, Number(site.splashDuration) || 3))
)
const remaining = ref(duration.value)

function stopTimer() {
  if (timer) clearInterval(timer)
  timer = null
}

function dismiss(markSeen = true) {
  if (dismissed.value) return
  dismissed.value = true
  stopTimer()
  if (markSeen) site.markSplashSeen()
}

function onImageLoad() {
  if (dismissed.value) return
  imageReady.value = true
  remaining.value = duration.value
  timer = setInterval(() => {
    remaining.value -= 1
    if (remaining.value <= 0) dismiss(true)
  }, 1000)
}

function onImageError() {
  // 加载失败：不标记已看，静默退出，下次进入再尝试
  dismiss(false)
}

onBeforeUnmount(stopTimer)
</script>

<template>
  <Transition name="splash-fade">
    <div
      v-if="!dismissed"
      class="splash"
      role="dialog"
      aria-label="开屏"
      @click="dismiss(true)"
    >
      <img
        class="splash__img"
        :src="site.splashImage"
        alt=""
        @load="onImageLoad"
        @error="onImageError"
      />
      <div
        v-if="imageReady"
        class="splash__skip"
        @click.stop="dismiss(true)"
      >跳过 {{ remaining }}s</div>
    </div>
  </Transition>
</template>

<style scoped>
.splash {
  position: fixed;
  inset: 0;
  z-index: 9999;
  background: #000;
  display: flex;
  align-items: center;
  justify-content: center;
}

.splash__img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.splash__skip {
  position: absolute;
  top: 48px;
  right: 20px;
  padding: 6px 14px;
  font-size: 13px;
  color: #fff;
  background: rgba(0, 0, 0, 0.45);
  border-radius: 20px;
  cursor: pointer;
  backdrop-filter: blur(4px);
  user-select: none;
}

.splash-fade-enter-active,
.splash-fade-leave-active {
  transition: opacity 0.35s ease;
}
.splash-fade-enter-from,
.splash-fade-leave-to {
  opacity: 0;
}
</style>