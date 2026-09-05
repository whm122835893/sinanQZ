<script setup lang="ts">
// 管理员账号管理：列表 + 创建管理员（仅超管可建，文档 8.16 P0 子集）
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import { fetchAdmins, createAdmin } from '@/api/permission'
import type { AdminRow, PageData } from '@/types/api'

// 角色选项（与 nft_admin_roles 一致）
const ROLE_OPTIONS = [
  { value: 1, label: '超级管理员' },
  { value: 2, label: '运营' },
  { value: 3, label: '财务' },
  { value: 4, label: '风控' },
  { value: 5, label: '客服' }
]

// ---------------- 检索 ----------------
const query = reactive({
  username: '',
  role: '',
  status: ''
})

function buildParams(): Record<string, unknown> {
  const params: Record<string, unknown> = { page: page.value, pageSize: pageSize.value }
  if (query.username.trim()) params.username = query.username.trim()
  if (query.role !== '') params.role = query.role
  if (query.status !== '') params.status = query.status
  return params
}

// ---------------- 列表 ----------------
const loading = ref(false)
const list = ref<AdminRow[]>([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(20)

async function load(): Promise<void> {
  loading.value = true
  try {
    const data = await fetchAdmins(buildParams())
    const pageData = data as PageData<AdminRow>
    list.value = pageData.list
    total.value = pageData.total
  } catch {
    // 拦截器已提示
  } finally {
    loading.value = false
  }
}

function handleSearch(): void {
  page.value = 1
  load()
}

function resetSearch(): void {
  query.username = ''
  query.role = ''
  query.status = ''
  handleSearch()
}

// ---------------- 创建管理员 ----------------
const dialogVisible = ref(false)
const submitting = ref(false)
const formRef = ref<FormInstance>()
const form = reactive({
  username: '',
  password: '',
  realName: '',
  role: 2,
  phone: '',
  email: ''
})

const rules: FormRules = {
  username: [
    { required: true, message: '请输入用户名', trigger: 'blur' },
    { pattern: /^[a-zA-Z0-9_]{3,50}$/, message: '3-50位字母数字下划线', trigger: 'blur' }
  ],
  password: [
    { required: true, message: '请输入密码', trigger: 'blur' },
    { min: 8, message: '密码长度至少8位', trigger: 'blur' },
    { pattern: /^(?=.*[A-Za-z])(?=.*\d).+$/, message: '密码必须同时包含字母和数字', trigger: 'blur' }
  ],
  realName: [{ required: true, message: '请输入真实姓名', trigger: 'blur' }],
  role: [{ required: true, message: '请选择角色', trigger: 'change' }],
  phone: [{ pattern: /^1\d{10}$/, message: '手机号格式不正确', trigger: 'blur' }],
  email: [{ type: 'email', message: '邮箱格式不正确', trigger: 'blur' }]
}

function openCreate(): void {
  form.username = ''
  form.password = ''
  form.realName = ''
  form.role = 2
  form.phone = ''
  form.email = ''
  dialogVisible.value = true
}

async function submitCreate(): Promise<void> {
  const formEl = formRef.value
  if (!formEl) return
  const valid = await formEl.validate().catch(() => false)
  if (!valid) return

  submitting.value = true
  try {
    await createAdmin({
      username: form.username.trim(),
      password: form.password,
      realName: form.realName.trim(),
      role: form.role,
      phone: form.phone.trim() || undefined,
      email: form.email.trim() || undefined
    })
    ElMessage.success('管理员已创建，初始密码需在首次登录时修改')
    dialogVisible.value = false
    load()
  } catch {
    // 拦截器已提示
  } finally {
    submitting.value = false
  }
}

function roleTagType(role: number): 'danger' | 'primary' | 'warning' | 'info' | 'success' {
  switch (role) {
    case 1: return 'danger'
    case 2: return 'primary'
    case 3: return 'warning'
    case 4: return 'success'
    default: return 'info'
  }
}

onMounted(load)
</script>

<template>
  <div class="page-container">
    <!-- 检索区 -->
    <div class="sn-card">
      <el-form inline class="query-form" @submit.prevent="handleSearch">
        <el-form-item label="用户名">
          <el-input v-model="query.username" placeholder="支持模糊搜索" clearable style="width: 180px" />
        </el-form-item>
        <el-form-item label="角色">
          <el-select v-model="query.role" placeholder="全部" clearable style="width: 140px">
            <el-option v-for="opt in ROLE_OPTIONS" :key="opt.value" :label="opt.label" :value="String(opt.value)" />
          </el-select>
        </el-form-item>
        <el-form-item label="状态">
          <el-select v-model="query.status" placeholder="全部" clearable style="width: 120px">
            <el-option label="启用" value="1" />
            <el-option label="禁用" value="0" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" :icon="'Search'" @click="handleSearch">查询</el-button>
          <el-button :icon="'RefreshLeft'" @click="resetSearch">重置</el-button>
        </el-form-item>
      </el-form>
    </div>

    <!-- 列表区 -->
    <div class="sn-card">
      <div class="table-toolbar">
        <span class="toolbar-title">管理员账号</span>
        <el-button
          v-permission="'permission:admin:create'"
          type="primary"
          :icon="'Plus'"
          @click="openCreate"
        >
          新建管理员
        </el-button>
      </div>

      <el-table v-loading="loading" :data="list" stripe>
        <el-table-column prop="id" label="ID" width="60" />
        <el-table-column prop="username" label="用户名" width="140" />
        <el-table-column prop="realName" label="真实姓名" width="120" />
        <el-table-column label="角色" width="110">
          <template #default="{ row }">
            <el-tag :type="roleTagType(row.role)" size="small">{{ row.roleName }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="phone" label="手机号" width="130">
          <template #default="{ row }">{{ row.phone || '—' }}</template>
        </el-table-column>
        <el-table-column prop="email" label="邮箱" min-width="180" show-overflow-tooltip>
          <template #default="{ row }">{{ row.email || '—' }}</template>
        </el-table-column>
        <el-table-column label="状态" width="80" align="center">
          <template #default="{ row }">
            <el-tag :type="row.status === 1 ? 'success' : 'danger'" size="small">
              {{ row.status === 1 ? '启用' : '禁用' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="lastLoginAt" label="最后登录" width="160">
          <template #default="{ row }">{{ row.lastLoginAt || '—' }}</template>
        </el-table-column>
        <el-table-column prop="lastActionAt" label="最后操作" width="160">
          <template #default="{ row }">{{ row.lastActionAt || '—' }}</template>
        </el-table-column>
        <el-table-column prop="createdAt" label="创建时间" width="160" />
      </el-table>

      <div class="table-footer">
        <el-pagination
          v-model:current-page="page"
          v-model:page-size="pageSize"
          :total="total"
          :page-sizes="[10, 20, 50]"
          layout="total, sizes, prev, pager, next, jumper"
          @current-change="load"
          @size-change="handleSearch"
        />
      </div>
    </div>

    <!-- 创建管理员弹窗 -->
    <el-dialog
      v-model="dialogVisible"
      title="新建管理员"
      width="480px"
      :close-on-click-modal="false"
      append-to-body
    >
      <el-form ref="formRef" :model="form" :rules="rules" label-width="82px" @submit.prevent>
        <el-form-item label="用户名" prop="username">
          <el-input v-model="form.username" placeholder="3-50位字母数字下划线" maxlength="50" />
        </el-form-item>
        <el-form-item label="初始密码" prop="password">
          <el-input
            v-model="form.password"
            type="password"
            show-password
            placeholder="至少8位，必须包含字母和数字"
          />
        </el-form-item>
        <el-form-item label="真实姓名" prop="realName">
          <el-input v-model="form.realName" placeholder="用于审计日志展示" maxlength="30" />
        </el-form-item>
        <el-form-item label="角色" prop="role">
          <el-select v-model="form.role" style="width: 100%">
            <el-option v-for="opt in ROLE_OPTIONS" :key="opt.value" :label="opt.label" :value="opt.value" />
          </el-select>
        </el-form-item>
        <el-form-item label="手机号" prop="phone">
          <el-input v-model="form.phone" placeholder="选填" maxlength="11" />
        </el-form-item>
        <el-form-item label="邮箱" prop="email">
          <el-input v-model="form.email" placeholder="选填" maxlength="100" />
        </el-form-item>
      </el-form>
      <el-alert
        type="info"
        :closable="false"
        show-icon
        title="新账号首次登录将被要求修改密码；操作权限由角色决定"
        style="margin-top: 4px"
      />
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="submitCreate">创建</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped lang="scss">
.query-form {
  :deep(.el-form-item) {
    margin-bottom: 4px;
    margin-right: 16px;
  }
}

.table-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;

  .toolbar-title {
    font-size: 15px;
    font-weight: 600;
    color: $sn-text-main;
  }
}
</style>
