import { defineStore } from 'pinia'
import { ref } from 'vue'
import request from '@/utils/request'
import { useUserStore } from './user'

/**
 * 收件箱 store：全局弹窗数据源
 * - 登录后轮询 GET /api/inbox/pending，拿到未读的到账通知
 * - 通知类型（后端 nft_inbox.type）：airdrop 空投 / transfer 转赠 /
 *   blindbox 开盒中奖 / reward 活动奖励 / lottery 抽奖中奖
 *   其中开盒与抽奖虽然也会写记录，但接口把 inboxId 回传给了发起方，前端当场标记已读，
 *   所以实际会弹出来的只有"人不在发放现场"的那几类（空投/转赠/活动奖励）
 * - 弹窗关闭时调 POST /api/inbox/:id/read
 *
 * 轮询节奏（2026-10-11 用户定的口径：不缩间隔，怕请求量翻三倍）：
 * 保持 15 秒一次，另外在「页面重新回到前台 / 窗口重新获得焦点」时立刻补拉一次。
 * 之所以要补这一下：手机浏览器会把后台标签页的 setInterval 限流到几十秒甚至暂停，
 * 用户切走再切回来时，界面上的到账弹窗可能要干等好几分钟才冒出来。
 */
export const useInboxStore = defineStore('inbox', () => {
  const pending = ref([])
  const pollingTimer = ref(null)

  // 前台补拉的去抖窗口：visibilitychange、focus、定时器经常在几百毫秒内连着触发，
  // 不去抖就会为同一批通知打两次 /inbox/pending。
  const POLL_INTERVAL_MS = 15000
  const RESUME_MIN_GAP_MS = 3000
  let lastFetchAt = 0
  let resumeBound = false

  /**
   * @param {boolean} force 强制拉（startPoll 的首帧、切回前台），否则受去抖窗口约束
   */
  async function fetchPending(force = false) {
    const user = useUserStore()
    if (!user.token) { pending.value = []; return pending.value }
    const nowTs = Date.now()
    if (!force && nowTs - lastFetchAt < RESUME_MIN_GAP_MS) return pending.value
    lastFetchAt = nowTs
    try {
      const res = await request.get('/inbox/pending')
      pending.value = (res.list || []).map((x) => ({
        id: x.id,
        type: x.type, // airdrop | transfer | blindbox | reward | lottery
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

  // 切回前台/窗口重新聚焦：立刻补拉一次，不等下一个 15 秒
  function onResume() {
    if (typeof document === 'undefined') return
    if (document.visibilityState && document.visibilityState !== 'visible') return
    fetchPending(true)
  }

  function startPoll() {
    stopPoll()
    fetchPending(true)
    pollingTimer.value = setInterval(fetchPending, POLL_INTERVAL_MS)
    if (typeof document !== 'undefined' && typeof window !== 'undefined') {
      document.addEventListener('visibilitychange', onResume)
      window.addEventListener('focus', onResume)
      resumeBound = true
    }
  }
  function stopPoll() {
    if (pollingTimer.value) { clearInterval(pollingTimer.value); pollingTimer.value = null }
    if (resumeBound && typeof document !== 'undefined') {
      document.removeEventListener('visibilitychange', onResume)
      window.removeEventListener('focus', onResume)
      resumeBound = false
    }
  }

  return { pending, fetchPending, markRead, startPoll, stopPoll }
})
