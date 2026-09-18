<script setup>
/**
 * 收件箱弹窗组件：空投 / 转赠到达时弹出
 * - 每次只弹一条（按 created_at 顺序），关闭后自动取下一条
 * - 空投：红色圆角 8px 的"我知道了"按钮
 * - 转赠：红色圆角 8px 的"去查看"按钮，点击进入转赠记录页
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
  <van-dialog
    v-if="current"
    :show-confirm-button="true"
    :show-cancel-button="false"
    :close-on-click-overlay="false"
    :close-on-click-esc="false"
    :allow-html="false"
    class="inbox-popup"
    teleport="body"
  >
    <div class="inbox-body">
      <div class="inbox-title">
        🎁 {{ current.title }}
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

<style lang="scss" scoped>
.inbox-popup {
  :deep(.van-dialog) {
    width: 80% !important;
    max-width: 340px !important;
    border-radius: 16px !important;
    padding: 20px 20px 16px !important;
  }
  :deep(.van-dialog__header),
  :deep(.van-dialog__content) { display: none; }
}

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
