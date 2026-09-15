<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'
import { useSiteStore } from '@/stores/site'

// ============================================================
// 开屏全屏组件
// - B 端装修页配置启用 + 上传图片后生效
// - 用户看过一次后写 localStorage，同设备不再重复展示（除非后台关闭/重新启用）
// - 支持点击跳过 / 倒计时自动消失
// ============================================================

const site = useSiteStore()
const remaining = ref(0)
const dismissed = ref(false)
let timer = null

const duration = Math.max(1, Math.min(10, Number(site.splashDuration) || 3))
remaining.value = duration

function dismiss() {
  if (dismissed.value) return
  dismissed.value = true
  if (timer) clearInterval(timer)
  // 标记"已看过开屏"，下次不再展示
  try {
    localStorage.setItem('jc_splash_seen', String(Date.now()))
  } catch { /* ignore */ }
}

onMounted(() => {
  timer = setInterval(() => {
    remaining.value -= 1
    if (remaining.value <= 0) dismiss()
  }, 1000)
})

onBeforeUnmount(() => {
  if (timer) clearInterval(timer)
})
</script>

<template>
  <Transition name="splash-fade">
    <div
      v-if="site.splashEnabled && site.splashImage && !dismissed"
      class="splash"
      @click="dismiss"
    >
      <img class="splash__img" :src="site.splashImage" alt="" />
      <div class="splash__skip" @click.stop="dismiss">跳过 {{ remaining }}s</div>
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
