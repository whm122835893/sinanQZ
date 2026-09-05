<script setup lang="ts">
// 用户列表：多条件检索 + 冻结/解冻（文档 8.4 P0 子集）
import { onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import type { FormInstance } from 'element-plus'
import { fetchUsers, freezeUser, unfreezeUser } from '@/api/user'
import type { PageData, UserRow } from '@/types/api'

const router = useRouter()

// ---------------- 检索 ----------------
const queryFormRef = ref<FormInstance>()
const query = reactive({
  phone: '',
  username: '',
  uid: '',
  status: '',
  isRealname: '',
  createdAtRange: null as [string, string] | null
})

function buildParams(): Record<string, unknown> {
  const params: Record<string, unknown> = {
    page: page.value,
    pageSize: pageSize.value
  }
  if (query.phone.trim()) params.phone = query.phone.trim()
  if (query.username.trim()) params.username = query.username.trim()
  if (query.uid.trim()) params.uid = query.uid.trim()
  if (query.status !== '') params.status = query.status
  if (query.isRealname !== '') params.isRealname = query.isRealname
  if (query.createdAtRange && query.createdAtRange.length === 2) {
    params.createdAtStart = query.createdAtRange[0]
    params.createdAtEnd = query.createdAtRange[1]
  }
  return params
}

// ---------------- 列表 ----------------
const loading = ref(false)
const list = ref<UserRow[]>([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(20)

async function load(): Promise<void> {
  loading.value = true
  try {
    const data = await fetchUsers(buildParams())
    const pageData = data as PageData<UserRow>
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
  query.phone = ''
  query.username = ''
  query.uid = ''
  query.status = ''
  query.isRealname = ''
  query.createdAtRange = null
  handleSearch()
}

// ---------------- 冻结 / 解冻 ----------------
const freezeDialogVisible = ref(false)
const freezeSubmitting = ref(false)
const freezeTarget = ref<UserRow | null>(null)
const freezeFormRef = ref<FormInstance>()
const freezeForm = reactive({ reason: '' })

function openFreeze(row: UserRow): void {
  freezeTarget.value = row
  freezeForm.reason = ''
  freezeDialogVisible.value = true
}

async function submitFreeze(): Promise<void> {
  const target = freezeTarget.value
  if (!target) return
  const formEl = freezeFormRef.value
  if (!formEl) return
  const valid = await formEl.validate().catch(() => false)
  if (!valid) return

  freezeSubmitting.value = true
  try {
    await freezeUser(target.id, freezeForm.reason.trim())
    ElMessage.success(`账号 ${target.username} 已冻结`)
    freezeDialogVisible.value = false
    load()
  } catch {
    // 拦截器已提示
  } finally {
    freezeSubmitting.value = false
  }
}

async function handleUnfreeze(row: UserRow): Promise<void> {
  try {
    await unfreezeUser(row.id)
    ElMessage.success(`账号 ${row.username} 已解冻`)
    load()
  } catch {
    // 拦截器已提示
  }
}

function gotoDetail(row: UserRow): void {
  router.push(`/user/${row.id}`)
}

onMounted(load)
</script>

<template>
  <div class="page-container">
    <!-- 检索区 -->
    <div class="sn-card">
      <el-form ref="queryFormRef" :model="query" inline class="query-form" @submit.prevent="handleSearch">
        <el-form-item label="手机号">
          <el-input v-model="query.phone" placeholder="支持模糊搜索" clearable style="width: 180px" />
        </el-form-item>
        <el-form-item label="用户名">
          <el-input v-model="query.username" placeholder="支持模糊搜索" clearable style="width: 160px" />
        </el-form-item>
        <el-form-item label="UID">
          <el-input v-model="query.uid" placeholder="精确匹配" clearable style="width: 150px" />
        </el-form-item>
        <el-form-item label="状态">
          <el-select v-model="query.status" placeholder="全部" clearable style="width: 120px">
            <el-option label="正常" value="1" />
            <el-option label="冻结" value="0" />
          </el-select>
        </el-form-item>
        <el-form-item label="实名">
          <el-select v-model="query.isRealname" placeholder="全部" clearable style="width: 120px">
            <el-option label="已实名" value="1" />
            <el-option label="未实名" value="0" />
          </el-select>
        </el-form-item>
        <el-form-item label="注册时间">
          <el-date-picker
            v-model="query.createdAtRange"
            type="daterange"
            value-format="YYYY-MM-DD"
            range-separator="至"
            start-placeholder="开始日期"
            end-placeholder="结束日期"
            style="width: 260px"
          />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" :icon="'Search'" @click="handleSearch">查询</el-button>
          <el-button :icon="'RefreshLeft'" @click="resetSearch">重置</el-button>
        </el-form-item>
      </el-form>
    </div>

    <!-- 列表区 -->
    <div class="sn-card">
      <el-table v-loading="loading" :data="list" stripe>
        <el-table-column prop="uid" label="UID" width="170" show-overflow-tooltip>
          <template #default="{ row }">
            <el-link type="primary" @click="gotoDetail(row)">{{ row.uid }}</el-link>
          </template>
        </el-table-column>
        <el-table-column label="用户" min-width="160">
          <template #default="{ row }">
            <div class="user-cell">
              <el-avatar :size="28" :src="row.avatar || undefined">
                {{ row.username.slice(0, 1).toUpperCase() }}
              </el-avatar>
              <span>{{ row.username }}</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="phone" label="手机号" width="130">
          <template #default="{ row }">{{ row.phone || '—' }}</template>
        </el-table-column>
        <el-table-column label="实名" width="80" align="center">
          <template #default="{ row }">
            <el-tag :type="row.isRealname ? 'success' : 'info'" size="small" effect="light">
              {{ row.isRealname ? '已实名' : '未实名' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="80" align="center">
          <template #default="{ row }">
            <el-tag :type="row.status === 1 ? 'success' : 'danger'" size="small">
              {{ row.status === 1 ? '正常' : '冻结' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="loginCount" label="登录次数" width="90" align="center" />
        <el-table-column prop="lastLoginAt" label="最后登录" width="160">
          <template #default="{ row }">{{ row.lastLoginAt || '—' }}</template>
        </el-table-column>
        <el-table-column prop="createdAt" label="注册时间" width="160" />
        <el-table-column label="操作" width="180" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" size="small" @click="gotoDetail(row)">详情</el-button>
            <el-button
              v-if="row.status === 1"
              v-permission="'user:freeze'"
              link
              type="danger"
              size="small"
              @click="openFreeze(row)"
            >
              冻结
            </el-button>
            <el-button
              v-else
              v-permission="'user:freeze'"
              link
              type="success"
              size="small"
              @click="handleUnfreeze(row)"
            >
              解冻
            </el-button>
          </template>
        </el-table-column>
      </el-table>

      <div class="table-footer">
        <el-pagination
          v-model:current-page="page"
          v-model:page-size="pageSize"
          :total="total"
          :page-sizes="[10, 20, 50, 100]"
          layout="total, sizes, prev, pager, next, jumper"
          @current-change="load"
          @size-change="handleSearch"
        />
      </div>
    </div>

    <!-- 冻结弹窗 -->
    <el-dialog
      v-model="freezeDialogVisible"
      title="冻结账号"
      width="440px"
      :close-on-click-modal="false"
      append-to-body
    >
      <el-alert
        type="warning"
        :closable="false"
        show-icon
        :title="`确认冻结账号「${freezeTarget?.username ?? ''}」？冻结后该用户无法登录与交易`"
        style="margin-bottom: 16px"
      />
      <el-form ref="freezeFormRef" :model="freezeForm" @submit.prevent>
        <el-form-item
          label="冻结原因"
          prop="reason"
          :rules="[{ required: true, message: '冻结原因不能为空', trigger: 'blur' }]"
          label-width="82px"
        >
          <el-input
            v-model="freezeForm.reason"
            type="textarea"
            :rows="3"
            maxlength="200"
            show-word-limit
            placeholder="将写入操作审计日志"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="freezeDialogVisible = false">取消</el-button>
        <el-button type="danger" :loading="freezeSubmitting" @click="submitFreeze">确认冻结</el-button>
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

.user-cell {
  display: flex;
  align-items: center;
  gap: 8px;

  span {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
}
</style>
