<script setup>
import { ref } from 'vue'
import { ElDialog, ElForm, ElFormItem, ElInput, ElButton, ElMessage } from 'element-plus'
import { verifyAdminPassword } from '@/api'

// ============================================================
// 管理员密码验证弹窗（敏感操作前置校验）
// 用法：
//   <PasswordVerify v-model="show" title="确认空投" @verified="onVerified" />
//   通过 @verified 回调继续业务操作，失败则提示
// ============================================================

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  title: { type: String, default: '敏感操作验证' },
  tip: { type: String, default: '该操作影响用户资产，请输入管理员密码验证' }
})
const emit = defineEmits(['update:modelValue', 'verified'])

const password = ref('')
const loading = ref(false)

function close() {
  password.value = ''
  emit('update:modelValue', false)
}

async function onVerify() {
  if (!password.value) return ElMessage.warning('请输入管理员密码')
  loading.value = true
  const res = await verifyAdminPassword(password.value)
  loading.value = false
  if (res.code === 0 && res.data) {
    close()
    emit('verified')
  } else {
    ElMessage.error(res.message || '密码验证失败')
  }
}
</script>

<template>
  <el-dialog
    :model-value="modelValue"
    :title="title"
    width="400px"
    :close-on-click-modal="false"
    append-to-body
    @update:model-value="close"
  >
    <div class="danger-note" style="margin-bottom: 14px">{{ tip }}</div>
    <el-form @submit.prevent>
      <el-form-item label="管理员密码">
        <el-input
          v-model="password"
          type="password"
          show-password
          placeholder="请输入当前登录管理员密码"
          @keyup.enter="onVerify"
        />
      </el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="close">取消</el-button>
      <el-button type="primary" :loading="loading" @click="onVerify">验证并继续</el-button>
    </template>
  </el-dialog>
</template>
