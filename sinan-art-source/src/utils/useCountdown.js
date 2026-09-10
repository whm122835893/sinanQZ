import { ref, onUnmounted } from 'vue'

// 验证码倒计时：点击发送后 60s 不可重复点击
export function useCountdown(seconds = 60) {
  const counting = ref(false)
  const remain = ref(0)
  let timer = null

  function start() {
    if (counting.value) return
    counting.value = true
    remain.value = seconds
    timer = setInterval(() => {
      remain.value -= 1
      if (remain.value <= 0) stop()
    }, 1000)
  }

  function stop() {
    counting.value = false
    remain.value = 0
    if (timer) {
      clearInterval(timer)
      timer = null
    }
  }

  onUnmounted(stop)

  return { counting, remain, start, stop }
}
