<template>
  <el-dialog
    v-model="visible"
    :title="title"
    width="420px"
    :close-on-click-modal="false"
    append-to-body
    class="pwd-verify-dialog"
    @closed="onClosed"
  >
    <!-- 警示通栏 -->
    <div class="pv-alert">
      <el-icon><WarningFilled /></el-icon>
      <span>{{ hint }}</span>
    </div>

    <el-form ref="formRef" :model="form" :rules="rules" label-position="top" @submit.prevent>
      <el-form-item v-if="requireReason" :label="reasonLabel" prop="reason">
        <el-input
          v-model="form.reason"
          type="textarea"
          :rows="2"
          maxlength="200"
          show-word-limit
          :placeholder="`请输入${reasonLabel}（必填，将写入审计日志）`"
        />
      </el-form-item>
      <el-form-item label="管理员密码" prop="password">
        <el-input
          v-model="form.password"
          type="password"
          show-password
          placeholder="请输入当前登录管理员的密码"
          @keyup.enter="onConfirm"
        />
      </el-form-item>
    </el-form>

    <template #footer>
      <div class="pv-footer">
        <el-button class="pv-cancel" @click="onCancel">取 消</el-button>
        <el-button class="pv-confirm" :loading="submitting" @click="onConfirm">确 认</el-button>
      </div>
    </template>
  </el-dialog>
</template>

<script setup lang="ts">
// ============================================================================
// 高风险操作统一密码验证弹窗（文档 9.1 / 11.1 / 3.3 视觉规范）
// 用法：
//   const pwdRef = ref<InstanceType<typeof PasswordVerify>>()
//   const ok = await pwdRef.value?.open({ title: '强制售罄', requireReason: true })
//   if (!ok) return
//   await api(params, ok.password, ok.reason)
// ============================================================================
import { reactive, ref } from 'vue'
import type { FormInstance, FormRules } from 'element-plus'

export interface PasswordVerifyOptions {
  /** 弹窗标题（操作名） */
  title: string
  /** 警示文案，默认「该操作为高风险操作，需要管理员密码确认」 */
  hint?: string
  /** 是否必填原因（写入审计日志），默认 true */
  requireReason?: boolean
  /** 原因字段标签 */
  reasonLabel?: string
}

export interface PasswordVerifyResult {
  password: string
  reason: string
}

const visible = ref(false)
const submitting = ref(false)
const title = ref('高风险操作确认')
const hint = ref('该操作为高风险操作，需要管理员密码确认')
const requireReason = ref(true)
const reasonLabel = ref('操作原因')

const formRef = ref<FormInstance>()
const form = reactive({ reason: '', password: '' })

const rules: FormRules = {
  reason: [{ required: true, message: '操作原因不能为空', trigger: 'blur' }],
  password: [
    { required: true, message: '管理员密码不能为空', trigger: 'blur' },
    { min: 6, message: '密码长度不能少于 6 位', trigger: 'blur' }
  ]
}

let resolver: ((value: PasswordVerifyResult | null) => void) | null = null

/** 打开弹窗，resolve(null)=取消，resolve({password, reason})=确认 */
function open(options: PasswordVerifyOptions): Promise<PasswordVerifyResult | null> {
  title.value = options.title
  hint.value = options.hint ?? '该操作为高风险操作，需要管理员密码确认'
  requireReason.value = options.requireReason ?? true
  reasonLabel.value = options.reasonLabel ?? '操作原因'
  form.reason = ''
  form.password = ''
  visible.value = true
  return new Promise((resolve) => {
    resolver = resolve
  })
}

async function onConfirm(): Promise<void> {
  if (!formRef.value) return
  const valid = await formRef.value.validate().catch(() => false)
  if (!valid) return
  submitting.value = true
  try {
    // 密码正确性由后端校验（403 拦截），此处仅收集
    resolver?.({ password: form.password, reason: form.reason })
    visible.value = false
  } finally {
    submitting.value = false
  }
}

function onCancel(): void {
  resolver?.(null)
  visible.value = false
}

function onClosed(): void {
  resolver = null
  formRef.value?.resetFields()
}

defineExpose({ open })
</script>

<style scoped lang="scss">
.pv-alert {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 14px;
  margin-bottom: 16px;
  background: #fff5f5;
  border: 1px solid #ffd6d6;
  border-radius: 8px;
  color: $sn-primary;
  font-size: 13px;
  line-height: 1.5;

  .el-icon {
    flex-shrink: 0;
    font-size: 16px;
  }
}

.pv-footer {
  display: flex;
  justify-content: flex-end;
  gap: 12px;

  .pv-cancel {
    border: 1px solid $sn-primary;
    color: $sn-primary;
    border-radius: 8px;

    &:hover {
      background: #fff5f5;
    }
  }

  .pv-confirm {
    background: $sn-gradient-primary;
    color: #fff;
    border: none;
    border-radius: 24px;
    padding: 8px 26px;

    &:hover {
      opacity: 0.9;
    }
  }
}
</style>

<style lang="scss">
/* 弹窗级样式（Element Plus 嵌套层级，不能 scoped） */
.pwd-verify-dialog {
  border-radius: 12px;

  .el-dialog__title {
    font-size: 16px;
    font-weight: 600;
    position: relative;
    padding-left: 12px;

    &::before {
      content: '';
      position: absolute;
      left: 0;
      top: 50%;
      transform: translateY(-50%);
      width: 3px;
      height: 16px;
      border-radius: 2px;
      background: $sn-primary;
    }
  }
}
</style>
