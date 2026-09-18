import { defineStore } from 'pinia'
import { ref } from 'vue'
import request from '@/utils/request'
import { useUserStore } from './user'

/**
 * 收件箱 store：全局弹窗数据源
 * - 登录后轮询 GET /api/inbox/pending，拿到未读空投/转赠到达
 * - 弹窗关闭时调 POST /api/inbox/:id/read
 */
export const useInboxStore = defineStore('inbox', () => {
  const pending = ref([])
  const pollingTimer = ref(null)

  async function fetchPending() {
    const user = useUserStore()
    if (!user.token) { pending.value = []; return pending.value }
    try {
      const res = await request.get('/inbox/pending')
      pending.value = (res.list || []).map((x) => ({
        id: x.id,
        type: x.type, // airdrop | transfer
        title: x.title,
        name: x.name,
        image: x.image,
        createdAt: x.createdAt,
        extra: x.extra || {},
      }))
    } catch (_) {
      // 未登录时忽略；其他错误不阻断页面
    }
    return pending.value
  }

  async function markRead(id, confirmed = true) {
    try {
      await request.post(`/inbox/${id}/read`, { confirmed: confirmed ? 1 : 0 })
    } catch (_) {}
    pending.value = pending.value.filter((x) => x.id !== id)
  }

  function startPoll() {
    stopPoll()
    fetchPending()
    pollingTimer.value = setInterval(fetchPending, 15000)
  }
  function stopPoll() {
    if (pollingTimer.value) { clearInterval(pollingTimer.value); pollingTimer.value = null }
  }

  return { pending, fetchPending, markRead, startPoll, stopPoll }
})
