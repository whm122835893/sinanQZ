<script setup lang="ts">
/**
 * 个人中心：账号信息 + 修改密码
 */
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import { useAuthStore } from '@/stores/auth'
import { changePassword } from '@/api/auth'

const router = useRouter()
const auth = useAuthStore()
const formRef = ref<FormInstance>()
const submitting = ref(false)

const form = reactive({
  old_password: '',
  new_password: '',
  confirm_password: '',
})

const rules: FormRules = {
  old_password: [{ required: true, message: '请输入原密码', trigger: 'blur' }],
  new_password: [
    { required: true, message: '请输入新密码', trigger: 'blur' },
    { min: 8, message: '新密码至少 8 位', trigger: 'blur' },
    {
      validator: (_r, v, cb) => {
        if (v && v === form.old_password) cb(new Error('新密码不能与原密码相同'))
        else cb()
      },
      trigger: 'blur',
    },
  ],
  confirm_password: [
    { required: true, message: '请再次输入新密码', trigger: 'blur' },
    {
      validator: (_r, v, cb) => {
        if (v !== form.new_password) cb(new Error('两次输入的密码不一致'))
        else cb()
      },
      trigger: 'blur',
    },
  ],
}

async function submit() {
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) return
  submitting.value = true
  try {
    await changePassword(form.old_password, form.new_password, form.confirm_password)
    ElMessage.success('密码修改成功，请重新登录')
    await auth.logout(false)
    router.push('/login')
  } catch {
    /* 全局提示 */
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="page-container">
    <div class="detail-section">
      <div class="section-title">账号信息</div>
      <el-descriptions :column="2" border>
        <el-descriptions-item label="账号">{{ auth.adminInfo?.username }}</el-descriptions-item>
        <el-descriptions-item label="姓名">{{ auth.adminInfo?.realName }}</el-descriptions-item>
        <el-descriptions-item label="角色">{{ auth.adminInfo?.roleName }}</el-descriptions-item>
        <el-descriptions-item label="权限数">{{ auth.isSuper ? '全部权限（超级管理员）' : auth.permissions.length + ' 项' }}</el-descriptions-item>
      </el-descriptions>
    </div>

    <div class="detail-section">
      <div class="section-title">修改密码</div>
      <el-form ref="formRef" :model="form" :rules="rules" label-width="100px" style="max-width: 480px">
        <el-form-item label="原密码" prop="old_password">
          <el-input v-model="form.old_password" type="password" show-password />
        </el-form-item>
        <el-form-item label="新密码" prop="new_password">
          <el-input v-model="form.new_password" type="password" show-password placeholder="至少 8 位" />
        </el-form-item>
        <el-form-item label="确认新密码" prop="confirm_password">
          <el-input v-model="form.confirm_password" type="password" show-password />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" :loading="submitting" @click="submit">保存并重新登录</el-button>
        </el-form-item>
      </el-form>
    </div>
  </div>
</template>
