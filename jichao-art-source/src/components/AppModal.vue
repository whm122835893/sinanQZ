<script setup>
import AppIcon from './AppIcon.vue'

// 通用弹窗（如登录弹窗）：底部弹出 / 居中面板
defineProps({
  show: { type: Boolean, default: false },
  closable: { type: Boolean, default: true },
  title: { type: String, default: '' }
})
const emit = defineEmits(['update:show', 'close'])
</script>

<template>
  <Teleport to="body">
    <Transition name="modal-fade">
      <div v-if="show" class="app-modal" @click.self="closable && emit('update:show', false)">
        <div class="app-modal__panel">
          <div class="app-modal__header">
            <span class="app-modal__title">{{ title }}</span>
            <AppIcon
              v-if="closable"
              name="close"
              :size="22"
              color="#333"
              class="app-modal__close"
              @click="emit('update:show', false); emit('close')"
            />
          </div>
          <div class="app-modal__body">
            <slot />
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped lang="scss">
.app-modal {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.5);
  z-index: $z-modal;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 24px;
}
.app-modal__panel {
  width: 100%;
  max-width: 340px;
  background: #fff;
  border-radius: $radius-lg;
  padding: 20px 16px 16px;
  position: relative;
}
.app-modal__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 8px;
}
.app-modal__title { font-size: 18px; font-weight: 600; color: $color-text-primary; }
.app-modal__close { cursor: pointer; }
.app-modal__body { margin-top: 4px; }

.modal-fade-enter-active, .modal-fade-leave-active { transition: opacity .2s; }
.modal-fade-enter-from, .modal-fade-leave-to { opacity: 0; }
</style>
