<script setup lang="ts">
// 登录日志：管理员登录流水（成功/失败、锁定、白名单拦截）
import { onMounted, reactive, ref } from 'vue'
import { fetchLoginLogs } from '@/api/permission'
import type { LoginLogRow, PageData } from '@/types/api'

// ---------------- 检索 ----------------
const query = reactive({
  username: '',
  success: '',
  ip: '',
  createdAtRange: null as [string, string] | null
})

function buildParams(): Record<string, unknown> {
  const params: Record<string, unknown> = { page: page.value, pageSize: pageSize.value }
  if (query.username.trim()) params.username = query.username.trim()
  if (query.success !== '') params.success = query.success
  if (query.ip.trim()) params.ip = query.ip.trim()
  if (query.createdAtRange && query.createdAtRange.length === 2) {
    params.createdAtStart = query.createdAtRange[0]
    params.createdAtEnd = query.createdAtRange[1]
  }
  return params
}

// ---------------- 列表 ----------------
const loading = ref(false)
const list = ref<LoginLogRow[]>([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(20)

async function load(): Promise<void> {
  loading.value = true
  try {
    const data = await fetchLoginLogs(buildParams())
    const pageData = data as PageData<LoginLogRow>
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
  query.success = ''
  query.ip = ''
  query.createdAtRange = null
  handleSearch()
}

/** 失败原因标签化 */
function failLabel(row: LoginLogRow): string {
  return row.failReason || '—'
}

onMounted(load)
</script>

<template>
  <div class="page-container">
    <!-- 检索区 -->
    <div class="sn-card">
      <el-form inline class="query-form" @submit.prevent="handleSearch">
        <el-form-item label="用户名">
          <el-input v-model="query.username" placeholder="支持模糊搜索" clearable style="width: 160px" />
        </el-form-item>
        <el-form-item label="结果">
          <el-select v-model="query.success" placeholder="全部" clearable style="width: 110px">
            <el-option label="成功" value="1" />
            <el-option label="失败" value="0" />
          </el-select>
        </el-form-item>
        <el-form-item label="IP">
          <el-input v-model="query.ip" placeholder="支持模糊搜索" clearable style="width: 150px" />
        </el-form-item>
        <el-form-item label="时间范围">
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
      <div class="table-toolbar">
        <span class="toolbar-title">登录日志</span>
        <span class="toolbar-sub">连续失败 5 次将锁定账号 30 分钟</span>
      </div>

      <el-table v-loading="loading" :data="list" stripe>
        <el-table-column prop="id" label="ID" width="80" />
        <el-table-column prop="username" label="用户名" width="150" />
        <el-table-column label="结果" width="80" align="center">
          <template #default="{ row }">
            <el-tag :type="row.success ? 'success' : 'danger'" size="small">
              {{ row.success ? '成功' : '失败' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="失败原因" width="150">
          <template #default="{ row }">
            <span :class="row.failReason ? 'fail-reason' : 'muted'">{{ failLabel(row) }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="ip" label="IP 地址" width="140" />
        <el-table-column label="浏览器指纹" min-width="240" show-overflow-tooltip>
          <template #default="{ row }">
            <span class="muted">{{ row.userAgent || '—' }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="adminId" label="管理员ID" width="100" align="center">
          <template #default="{ row }">{{ row.adminId ?? '—' }}</template>
        </el-table-column>
        <el-table-column prop="createdAt" label="登录时间" width="165" fixed="right" />
      </el-table>

      <div class="table-footer">
        <el-pagination
          v-model:current-page="page"
          v-model:page-size="pageSize"
          :total="total"
          :page-sizes="[20, 50, 100]"
          layout="total, sizes, prev, pager, next, jumper"
          @current-change="load"
          @size-change="handleSearch"
        />
      </div>
    </div>
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
  align-items: baseline;
  justify-content: space-between;
  margin-bottom: 12px;

  .toolbar-title {
    font-size: 15px;
    font-weight: 600;
    color: $sn-text-main;
  }

  .toolbar-sub {
    font-size: 12px;
    color: $sn-text-muted;
  }
}

.fail-reason {
  color: $sn-danger;
  font-size: 12px;
}

.muted {
  color: $sn-text-muted;
  font-size: 12px;
}
</style>
