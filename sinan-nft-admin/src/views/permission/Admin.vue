<script setup lang="ts">
/**
 * 权限管理 - 管理员管理
 *
 * - 搜索：账号/姓名关键词、角色下拉（fetchRoles）、状态
 * - 表格：账号、姓名、角色、状态、最后登录时间、锁定状态；操作：编辑 / 重置密码 / 解锁 / 删除
 * - 新增/编辑弹窗（账号/姓名/手机/邮箱/角色/状态）、重置密码弹窗（8~64 位含字母与数字）
 * - 敏感操作 ElMessageBox.confirm；不可删除当前登录账号（后端二次校验）
 *
 * 接口：GET/POST /admin/permission/admins、PUT/DELETE /admin/permission/admins/:id、
 *       POST /admin/permission/admins/:id/reset-password、POST /admin/permission/admins/:id/unlock
 * 权限：permission:admin
 */
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import { useListPage } from '@/utils/useListPage'
import {
  fetchAdmins,
  createAdmin,
  updateAdmin,
  resetAdminPassword,
  unlockAdmin,
  deleteAdmin,
  fetchRoles,
} from '@/api/permission'
import { datetime } from '@/utils/format'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()

/** 后端管理员列表直接返回 DB 行（snake_case 键），统一转 camelCase 视图模型使用 */
function camelizeKeys<T = any>(data: any): T {
  if (Array.isArray(data)) return data.map(camelizeKeys) as any
  if (data && typeof data === 'object') {
    const out: Record<string, any> = {}
    Object.keys(data).forEach((k) => {
      const nk = k.replace(/_([a-z0-9])/g, (_, c: string) => c.toUpperCase())
      out[nk] = camelizeKeys(data[k])
    })
    return out as any
  }
  return data
}

const pager = useListPage(
  async (params: Record<string, any>) => {
    const res = await fetchAdmins(params)
    return { ...res, list: (res?.list ?? []).map(camelizeKeys) }
  },
  { keyword: '', role_id: '', status: '' },
)

onMounted(() => {
  pager.load()
  loadRoles()
})

function onPageChange() {
  pager.load()
}

function onSizeChange() {
  pager.page = 1
  pager.load()
}

// ===== 角色下拉（搜索栏 + 表单共用） =====

const roleOptions = ref<any[]>([])

async function loadRoles() {
  try {
    roleOptions.value = ((await fetchRoles()) ?? []).map(camelizeKeys)
  } catch {
    /* 错误已全局提示 */
  }
}

// ===== 新增 / 编辑弹窗 =====

const dialogVisible = ref(false)
const isCreate = ref(true)
const submitting = ref(false)
const formRef = ref<FormInstance>()

const form = reactive({
  id: 0,
  username: '',
  password: '',
  realName: '',
  phone: '',
  email: '',
  roleId: undefined as number | undefined,
  status: 1,
})

/** 密码强度（与后端 validatePassword 一致：8~64 位且同时包含字母与数字） */
function validatePassword(_rule: any, value: string, callback: (e?: Error) => void) {
  if (!value) {
    callback(new Error('请输入登录密码'))
    return
  }
  if (value.length < 8 || value.length > 64) {
    callback(new Error('密码长度需为 8~64 位'))
    return
  }
  if (!/[a-zA-Z]/.test(value) || !/\d/.test(value)) {
    callback(new Error('密码需同时包含字母和数字'))
    return
  }
  callback()
}

const rules: FormRules = {
  username: [
    { required: true, message: '请输入登录账号', trigger: 'blur' },
    { pattern: /^[a-zA-Z][a-zA-Z0-9_]{2,49}$/, message: '字母开头的 3~50 位字母/数字/下划线', trigger: 'blur' },
  ],
  password: [{ required: true, validator: validatePassword, trigger: 'blur' }],
  realName: [
    { required: true, message: '请输入真实姓名', trigger: 'blur' },
    { min: 2, max: 50, message: '真实姓名需为 2~50 字', trigger: 'blur' },
  ],
  roleId: [{ required: true, message: '请选择角色', trigger: 'change' }],
  phone: [{ pattern: /^1[3-9]\d{9}$/, message: '手机号格式不正确', trigger: 'blur' }],
  email: [{ type: 'email', message: '邮箱格式不正确', trigger: 'blur' }],
}

function openCreate() {
  isCreate.value = true
  form.id = 0
  form.username = ''
  form.password = ''
  form.realName = ''
  form.phone = ''
  form.email = ''
  form.roleId = undefined
  form.status = 1
  dialogVisible.value = true
}

function openEdit(row: any) {
  isCreate.value = false
  form.id = Number(row.id)
  form.username = String(row.username ?? '')
  form.password = ''
  form.realName = String(row.realName ?? '')
  form.phone = String(row.phone ?? '')
  form.email = String(row.email ?? '')
  form.roleId = row.roleId ? Number(row.roleId) : undefined
  form.status = Number(row.status) === 1 ? 1 : 0
  dialogVisible.value = true
}

async function submitForm() {
  const valid = await formRef.value?.validate().then(() => true).catch(() => false)
  if (formRef.value && !valid) return
  submitting.value = true
  try {
    if (isCreate.value) {
      await createAdmin({
        username: form.username.trim(),
        password: form.password,
        real_name: form.realName.trim(),
        role_id: form.roleId,
        phone: form.phone.trim(),
        email: form.email.trim(),
      })
      ElMessage.success('管理员已创建')
    } else {
      await updateAdmin(form.id, {
        real_name: form.realName.trim(),
        role_id: form.roleId,
        phone: form.phone.trim(),
        email: form.email.trim(),
        status: form.status,
      })
      ElMessage.success('管理员信息已更新')
    }
    dialogVisible.value = false
    pager.load()
  } catch {
    /* 校验失败或业务错误（已全局提示） */
  } finally {
    submitting.value = false
  }
}

// ===== 重置密码弹窗 =====

const resetVisible = ref(false)
const resetSubmitting = ref(false)
const resetFormRef = ref<FormInstance>()
const resetForm = reactive({ id: 0, username: '', newPassword: '' })

const resetRules: FormRules = {
  newPassword: [{ required: true, validator: validatePassword, trigger: 'blur' }],
}

function openReset(row: any) {
  ElMessageBox.confirm(
    `确认重置管理员「${row.username}」的登录密码？重置后原密码立即失效`,
    '重置密码',
    { confirmButtonText: '继续', cancelButtonText: '取消', type: 'warning' },
  )
    .then(() => {
      resetForm.id = Number(row.id)
      resetForm.username = String(row.username ?? '')
      resetForm.newPassword = ''
      resetVisible.value = true
    })
    .catch(() => {
      /* 取消重置 */
    })
}

async function submitReset() {
  const valid = await resetFormRef.value?.validate().then(() => true).catch(() => false)
  if (resetFormRef.value && !valid) return
  resetSubmitting.value = true
  try {
    await resetAdminPassword(resetForm.id, { new_password: resetForm.newPassword })
    ElMessage.success(`管理员「${resetForm.username}」密码已重置`)
    resetVisible.value = false
  } catch {
    /* 业务错误已全局提示 */
  } finally {
    resetSubmitting.value = false
  }
}

// ===== 解锁 / 删除 =====

/** 当前登录管理员 ID（删除保护：不可删除自己） */
const currentAdminId = computed(() => Number(auth.adminInfo?.id ?? 0))

async function onUnlock(row: any) {
  try {
    await ElMessageBox.confirm(
      `确认解除管理员「${row.username}」的登录锁定？解锁后将立即恢复登录能力`,
      '解除锁定',
      { confirmButtonText: '确认解锁', cancelButtonText: '取消', type: 'warning' },
    )
  } catch {
    return
  }
  try {
    await unlockAdmin(row.id)
    await pager.done('账号已解锁')
  } catch {
    /* 业务错误已全局提示 */
  }
}

async function onDelete(row: any) {
  try {
    await ElMessageBox.confirm(
      `确认删除管理员「${row.username}」（${row.realName || '-'}）？删除为软删除且不可恢复，请谨慎操作`,
      '删除管理员',
      { confirmButtonText: '确认删除', cancelButtonText: '取消', type: 'warning' },
    )
  } catch {
    return
  }
  try {
    await deleteAdmin(row.id)
    await pager.done('管理员已删除')
  } catch {
    /* 业务错误已全局提示（不能删除自己 / 最后超管保护等） */
  }
}
</script>

<template>
  <div class="page-container">
    <!-- 搜索栏 -->
    <el-form class="search-bar" inline @submit.prevent>
      <el-form-item label="关键词">
        <el-input
          v-model="pager.filters.keyword"
          placeholder="账号 / 姓名"
          clearable
          style="width: 200px"
          @keyup.enter="pager.search"
          @clear="pager.search"
        />
      </el-form-item>
      <el-form-item label="角色">
        <el-select
          v-model="pager.filters.role_id"
          placeholder="全部角色"
          clearable
          style="width: 180px"
          @change="pager.search"
        >
          <el-option v-for="r in roleOptions" :key="r.id" :label="r.name" :value="r.id" />
        </el-select>
      </el-form-item>
      <el-form-item label="状态">
        <el-select
          v-model="pager.filters.status"
          placeholder="全部状态"
          clearable
          style="width: 130px"
          @change="pager.search"
        >
          <el-option label="启用" :value="1" />
          <el-option label="禁用" :value="0" />
        </el-select>
      </el-form-item>
      <el-form-item>
        <el-button type="primary" @click="pager.search">查询</el-button>
        <el-button @click="pager.reset">重置</el-button>
      </el-form-item>
    </el-form>

    <!-- 管理员表格 -->
    <div class="table-card">
      <div class="table-header">
        <div>
          <span class="table-title">管理员列表</span>
          <span class="table-tip">账号增删改、重置密码、解锁均写入操作审计日志；系统保留至少一名启用状态的超级管理员</span>
        </div>
        <el-button v-permission="'permission:admin'" type="primary" :icon="Plus" @click="openCreate">新增管理员</el-button>
      </div>

      <el-table v-loading="pager.loading" :data="pager.list" border stripe row-key="id">
        <el-table-column prop="username" label="账号" width="150" fixed="left">
          <template #default="{ row }">
            <span class="mono">{{ row.username }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="realName" label="姓名" min-width="110">
          <template #default="{ row }">{{ row.realName || '-' }}</template>
        </el-table-column>
        <el-table-column label="角色" min-width="140">
          <template #default="{ row }">
            <el-tag :type="row.roleCode === 'super_admin' ? 'danger' : 'primary'" size="small" disable-transitions>
              {{ row.roleName || '-' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="90" align="center">
          <template #default="{ row }">
            <el-tag :type="Number(row.status) === 1 ? 'success' : 'info'" size="small" disable-transitions>
              {{ Number(row.status) === 1 ? '启用' : '禁用' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="最后登录时间" width="190">
          <template #default="{ row }">
            <div>{{ datetime(row.lastLoginAt) }}</div>
            <div class="cell-sub">{{ row.lastLoginIp || '-' }}</div>
          </template>
        </el-table-column>
        <el-table-column label="锁定状态" width="180">
          <template #default="{ row }">
            <template v-if="Number(row.isLocked) === 1">
              <el-tag type="danger" size="small" disable-transitions>已锁定</el-tag>
              <span class="lock-until">{{ datetime(row.lockedUntil) }} 解锁</span>
            </template>
            <el-tag v-else type="success" size="small" effect="plain" disable-transitions>正常</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="250" fixed="right">
          <template #default="{ row }">
            <el-button v-permission="'permission:admin'" link type="primary" size="small" @click="openEdit(row)">
              编辑
            </el-button>
            <el-button v-permission="'permission:admin'" link type="warning" size="small" @click="openReset(row)">
              重置密码
            </el-button>
            <el-button
              v-if="Number(row.isLocked) === 1"
              v-permission="'permission:admin'"
              link
              type="success"
              size="small"
              @click="onUnlock(row)"
            >
              解锁
            </el-button>
            <el-button
              v-if="Number(row.id) !== currentAdminId"
              v-permission="'permission:admin'"
              link
              type="danger"
              size="small"
              @click="onDelete(row)"
            >
              删除
            </el-button>
          </template>
        </el-table-column>
      </el-table>

      <div class="pagination-wrap">
        <el-pagination
          v-model:current-page="pager.page"
          v-model:page-size="pager.pageSize"
          :total="pager.total"
          :page-sizes="[10, 20, 50, 100]"
          layout="total, sizes, prev, pager, next, jumper"
          @current-change="onPageChange"
          @size-change="onSizeChange"
        />
      </div>
    </div>

    <!-- 新增 / 编辑弹窗 -->
    <el-dialog
      v-model="dialogVisible"
      :title="isCreate ? '新增管理员' : `编辑管理员 · ${form.username}`"
      width="520px"
      destroy-on-close
    >
      <el-form ref="formRef" :model="form" :rules="rules" label-width="90px" @submit.prevent>
        <el-form-item label="账号" prop="username">
          <el-input
            v-model="form.username"
            :disabled="!isCreate"
            maxlength="50"
            placeholder="字母开头的 3~50 位字母/数字/下划线"
          />
          <div v-if="!isCreate" class="form-tip">账号创建后不可修改</div>
        </el-form-item>
        <el-form-item v-if="isCreate" label="登录密码" prop="password">
          <el-input v-model="form.password" type="password" show-password autocomplete="new-password" placeholder="8~64 位，需同时包含字母和数字" />
        </el-form-item>
        <el-form-item label="姓名" prop="realName">
          <el-input v-model="form.realName" maxlength="50" placeholder="真实姓名（2~50 字）" />
        </el-form-item>
        <el-form-item label="手机" prop="phone">
          <el-input v-model="form.phone" maxlength="11" placeholder="选填，11 位手机号" />
        </el-form-item>
        <el-form-item label="邮箱" prop="email">
          <el-input v-model="form.email" maxlength="100" placeholder="选填" />
        </el-form-item>
        <el-form-item label="角色" prop="roleId">
          <el-select v-model="form.roleId" placeholder="请选择角色" style="width: 100%">
            <el-option v-for="r in roleOptions" :key="r.id" :label="r.name" :value="r.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="状态">
          <el-switch
            v-model="form.status"
            :active-value="1"
            :inactive-value="0"
            :disabled="isCreate"
            active-text="启用"
            inactive-text="禁用"
          />
          <div v-if="isCreate" class="form-tip">新增账号默认启用</div>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="submitForm">{{ isCreate ? '确认新增' : '保存修改' }}</el-button>
      </template>
    </el-dialog>

    <!-- 重置密码弹窗 -->
    <el-dialog v-model="resetVisible" :title="`重置密码 · ${resetForm.username}`" width="440px" destroy-on-close>
      <el-alert
        type="warning"
        :closable="false"
        show-icon
        title="重置后原密码立即失效，请将新密码安全告知对应管理员"
        style="margin-bottom: 14px"
      />
      <el-form ref="resetFormRef" :model="resetForm" :rules="resetRules" label-width="90px" @submit.prevent>
        <el-form-item label="新密码" prop="newPassword">
          <el-input
            v-model="resetForm.newPassword"
            type="password"
            show-password
            autocomplete="new-password"
            placeholder="8~64 位，需同时包含字母和数字"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="resetVisible = false">取消</el-button>
        <el-button type="primary" :loading="resetSubmitting" @click="submitReset">确认重置</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped lang="scss">
.table-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 14px;

  .table-title {
    font-size: 15px;
    font-weight: 600;
  }

  .table-tip {
    display: block;
    margin-top: 4px;
    font-size: 12px;
    color: var(--sn-text-secondary);
  }
}

.mono {
  font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
  font-size: 13px;
}

.cell-sub {
  font-size: 12px;
  color: var(--sn-text-secondary);
}

.lock-until {
  display: block;
  margin-top: 2px;
  font-size: 12px;
  color: var(--sn-text-secondary);
}

.form-tip {
  font-size: 12px;
  color: var(--sn-text-secondary);
  line-height: 1.4;
  margin-top: 2px;
}
</style>
