<script setup>
import { useLoginGate } from '@/utils/loginGate'
import AppIcon from './AppIcon.vue'

// 全局登录提示弹窗：未登录点击需登录操作时统一弹出
const { visible, closeLoginModal, goLogin } = useLoginGate()
</script>

<template>
  <Teleport to="body">
    <Transition name="modal-fade">
      <div v-if="visible" class="login-modal" @click.self="closeLoginModal">
        <div class="login-modal__panel">
          <div class="login-modal__icon">
            <AppIcon name="person" :size="30" color="#fff" />
          </div>
          <p class="login-modal__title">请先登录</p>
          <p class="login-modal__desc">登录后可继续操作，享受完整的司南艺术体验</p>
          <div class="login-modal__btns">
            <button class="login-modal__btn login-modal__btn--ghost" @click="closeLoginModal">暂不</button>
            <button class="login-modal__btn login-modal__btn--primary" @click="goLogin">去登录</button>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped lang="scss">
.login-modal {
  position: fixed; inset: 0; background: rgba(0, 0, 0, 0.5);
  z-index: $z-modal; display: flex; align-items: center; justify-content: center; padding: 24px;
}
.login-modal__panel {
  width: 100%; max-width: 300px; background: #fff; border-radius: $radius-lg;
  padding: 24px 20px 20px; text-align: center;
}
.login-modal__icon {
  width: 60px; height: 60px; margin: 0 auto 12px; border-radius: 50%;
  background: linear-gradient(135deg, #C00000, #E0483B);
  display: flex; align-items: center; justify-content: center;
  box-shadow: 0 6px 16px rgba(192, 0, 0, 0.25);
}
.login-modal__title { margin: 0 0 6px; font-size: 18px; font-weight: 700; color: $color-text-primary; }
.login-modal__desc { margin: 0 0 20px; font-size: 13px; color: $color-text-tertiary; line-height: 1.5; }
.login-modal__btns { display: flex; gap: 12px; }
.login-modal__btn {
  flex: 1; height: 42px; border-radius: $radius-pill; font-size: 15px; cursor: pointer;
  border: none; transition: opacity 0.2s;
  &:active { opacity: 0.8; }
}
.login-modal__btn--ghost { background: $color-surface; color: $color-text-secondary; }
.login-modal__btn--primary { background: $color-primary; color: #fff; font-weight: 600; }
.modal-fade-enter-active, .modal-fade-leave-active { transition: opacity .2s; }
.modal-fade-enter-from, .modal-fade-leave-to { opacity: 0; }
</style>
