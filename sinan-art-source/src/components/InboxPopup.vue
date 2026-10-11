<script setup>
/**
 * 收件箱弹窗组件：藏品到账时弹出（空投 / 转赠 / 活动奖励；开盒与抽奖当场已读、不会走到这里）
 * - 每次只弹一条（按 created_at 顺序），关闭后自动取下一条
 * - 转赠：红色圆角 8px 的"去查看"按钮，点击进入转赠记录页
 * - 其余类型：红色圆角 8px 的"我知道了"，留在当前页
 * - 标题由后端下发（nft_inbox.title），新增类型不需要改这个组件
 */
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { showToast } from 'vant'
import { useInboxStore } from '@/stores/inbox'

const router = useRouter()
const inbox = useInboxStore()

// 只弹第一条
const current = computed(() => inbox.pending[0] || null)

function handleConfirm() {
  if (!current.value) return
  const item = current.value
  inbox.markRead(item.id, true)
  if (item.type === 'transfer') {
    router.push('/user/purchase')
  } else {
    // airdrop：就在当前页面
  }
}
</script>

<template>
  <!--
    :show 是必需的：van-dialog 继承 Popup 的 `show: Boolean`（默认 false），
    只写 v-if 而不绑 show 时，Popup 内部 v-show="show" 恒为隐藏，弹窗永远不出现
    （空投与转赠到账通知都因此从未弹过）。
    这里用单向 :show 而不是 v-model:show：关闭动作由下面那颗自定义红按钮走 markRead，
    pending 清空后 current 变 null，v-if 直接把节点摘掉，不需要 Dialog 自己关。
    :show-confirm-button="false"：正文里已有"我知道了/去查看"，Dialog 自带那颗确认键再留着就是两个按钮。
  -->
  <van-dialog
    v-if="current"
    :show="!!current"
    :show-confirm-button="false"
    :show-cancel-button="false"
    :close-on-click-overlay="false"
    :close-on-click-esc="false"
    :allow-html="false"
    class="inbox-popup"
    teleport="body"
  >
    <div class="inbox-body">
      <div class="inbox-title">
        {{ current.title }}
      </div>
      <div class="inbox-card">
        <img
          v-if="current.image"
          class="inbox-img"
          :src="current.image"
          :alt="current.name"
          mode="aspectFill"
          lazy-load
        />
        <div v-else class="inbox-img inbox-img--fallback">🎨</div>
        <div class="inbox-name">{{ current.name }}</div>
      </div>
      <button class="inbox-btn" @click="handleConfirm">
        {{ current.type === 'transfer' ? '去查看' : '我知道了' }}
      </button>
    </div>
  </van-dialog>
</template>

<style lang="scss">
/*
  这一块刻意不加 scoped。
  弹窗挂了 teleport="body"，节点被搬到 body 下面；原来写成
  `.inbox-popup { :deep(.van-dialog) { … } }`，编译出来是
  「.inbox-popup[data-v-xxx] 里面的后代 .van-dialog」——可 inbox-popup 这个类
  本来就挂在 van-dialog 那颗根节点自己身上，它没有这样的后代，于是四条覆盖
  一条都没命中，样式全部退回 Vant 默认（固定宽度 + overflow:hidden），
  标题和「我知道了」就被裁掉一截（2026-10-11 用户报的现象）。
  改成全局块并用 .inbox-popup 前缀锁死作用域，不会波及其他弹窗。
*/
.inbox-popup.van-dialog {
  width: 80%;
  max-width: 340px;
  border-radius: 16px;
  padding: 20px 20px 16px;
  /* 背景按内容长高，不再裁字 */
  overflow: visible;
}
.inbox-popup .van-dialog__content {
  /* 内边距统一由上面那颗根节点给，避免两层 padding 叠加把内容顶出去 */
  padding: 0;
  overflow: visible;
  max-height: none;
}
</style>

<style lang="scss" scoped>
.inbox-body {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 14px;
}
.inbox-title {
  font-size: 16px;
  font-weight: 600;
  color: #1a1a1a;
  text-align: center;
}
.inbox-card {
  width: 100%;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
}
.inbox-img {
  width: 160px;
  height: 160px;
  border-radius: 12px;
  object-fit: cover;
  background: #f5f5f5;
}
.inbox-img--fallback {
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 60px;
}
.inbox-name {
  font-size: 14px;
  color: #333;
  max-width: 100%;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.inbox-btn {
  width: 160px;
  height: 40px;
  background: #d32f2f;
  color: #fff;
  border: none;
  border-radius: 8px;
  font-size: 15px;
  font-weight: 500;
  cursor: pointer;
  transition: opacity 0.15s;

  &:active { opacity: 0.85; }
}
</style>
