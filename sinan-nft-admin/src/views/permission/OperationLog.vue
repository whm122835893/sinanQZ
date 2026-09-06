<script setup lang="ts">
/**
 * 权限管理 - 操作/登录日志
 *
 * - el-tabs 两个页签：操作日志 / 登录日志（各自独立搜索 + 分页，切换时懒加载）
 * - 操作日志：模块下拉（fetchLogModules）/ 管理员或描述关键词 / 时间区间；
 *   表格：管理员、模块、动作、描述、IP、时间
 * - 登录日志：账号关键词 / 登录状态（1 成功 2 失败）/ IP / 时间区间；
 *   表格：管理员、账号、状态、原因、IP、user_agent、时间
 *
 * 接口：GET /admin/permission/operation-logs、GET /admin/permission/log-modules、
 *       GET /admin/permission/login-logs
 * 权限：permission:log
 */
import { onMounted, ref } from 'vue'
import { useListPage } from '@/utils/useListPage'
import { fetchOperationLogs, fetchLogModules, fetchLoginLogs } from '@/api/permission'
import { datetime } from '@/utils/format'

/** 后端日志接口直接返回 DB 行（snake_case 键），统一转 camelCase 视图模型使用 */
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

/** 时间区间数组 → 后端 startDate/endDate，并剔除空参数 */
function withDateRange(fetcher: (p: Record<string, any>) => Promise<any>) {
  return (params: Record<string, any>) => {
    const { dateRange, ...rest } = params
    const query: Record<string, any> = {}
    Object.keys(rest).forEach((k) => {
      if (rest[k] !== '' && rest[k] !== null && rest[k] !== undefined) query[k] = rest[k]
    })
    if (Array.isArray(dateRange)) {
      if (dateRange[0]) query.startDate = dateRange[0]
      if (dateRange[1]) query.endDate = dateRange[1]
    }
    return fetcher(query)
  }
}

/** 分页结果行 camelCase 化 */
function camelizePage(res: any) {
  return { ...res, list: (res?.list ?? []).map(camelizeKeys) }
}

// ===== Tab 切换 =====

const activeTab = ref('operation')

// ===== 操作日志 =====

const opPager = useListPage(
  withDateRange(async (params: Record<string, any>) => camelizePage(await fetchOperationLogs(params))),
  { keyword: '', module: '', dateRange: [] },
)

/** 模块筛选项（后端 distinct 下发） */
const moduleOptions = ref<string[]>([])

async function loadModules() {
  try {
    moduleOptions.value = (await fetchLogModules()) ?? []
  } catch {
    /* 错误已全局提示 */
  }
}

// ===== 登录日志 =====

const LOGIN_STATUS: Record<number, { text: string; type: 'success' | 'danger' }> = {
  1: { text: '成功', type: 'success' },
  2: { text: '失败', type: 'danger' },
}

const loginPager = useListPage(
  withDateRange(async (params: Record<string, any>) => camelizePage(await fetchLoginLogs(params))),
  { keyword: '', status: '', ip: '', dateRange: [] },
)

/** 登录日志页签懒加载：首次切入时拉取 */
let loginLoaded = false

function onTabChange(name: string | number) {
  if (name === 'login' && !loginLoaded) {
    loginLoaded = true
    loginPager.load()
  }
}

onMounted(() => {
  opPager.load()
  loadModules()
})

function onPageChange(pager: any) {
  pager.load()
}

function onSizeChange(pager: any) {
  pager.page = 1
  pager.load()
}

function loginStatusTag(status: any) {
  return LOGIN_STATUS[Number(status)] ?? { text: status ?? '-', type: 'info' as const }
}
</script>

<template>
  <div class="page-container">
    <div class="table-card">
      <el-tabs v-model="activeTab" @tab-change="onTabChange">
        <!-- ==================== 操作日志 ==================== -->
        <el-tab-pane label="操作日志" name="operation">
          <el-form class="search-bar inner-search" inline @submit.prevent>
            <el-form-item label="模块">
              <el-select
                v-model="opPager.filters.module"
                placeholder="全部模块"
                clearable
                style="width: 160px"
                @change="opPager.search"
              >
                <el-option v-for="m in moduleOptions" :key="m" :label="m" :value="m" />
              </el-select>
            </el-form-item>
            <el-form-item label="关键词">
              <el-input
                v-model="opPager.filters.keyword"
                placeholder="管理员 / 操作描述"
                clearable
                style="width: 220px"
                @keyup.enter="opPager.search"
                @clear="opPager.search"
              />
            </el-form-item>
            <el-form-item label="时间区间">
              <el-date-picker
                v-model="opPager.filters.dateRange"
                type="daterange"
                value-format="YYYY-MM-DD"
                range-separator="至"
                start-placeholder="开始日期"
                end-placeholder="结束日期"
                style="width: 250px"
              />
            </el-form-item>
            <el-form-item>
              <el-button type="primary" @click="opPager.search">查询</el-button>
              <el-button @click="opPager.reset">重置</el-button>
            </el-form-item>
          </el-form>

          <el-table v-loading="opPager.loading" :data="opPager.list" border stripe>
            <el-table-column prop="adminName" label="管理员" width="140" fixed="left">
              <template #default="{ row }">
                <span class="mono">{{ row.adminName || '系统' }}</span>
              </template>
            </el-table-column>
            <el-table-column prop="module" label="模块" width="110" align="center">
              <template #default="{ row }">
                <el-tag size="small" type="info" disable-transitions>{{ row.module || '-' }}</el-tag>
              </template>
            </el-table-column>
            <el-table-column prop="action" label="动作" width="150">
              <template #default="{ row }">
                <span class="mono">{{ row.action || '-' }}</span>
              </template>
            </el-table-column>
            <el-table-column prop="actionDesc" label="描述" min-width="240" show-overflow-tooltip>
              <template #default="{ row }">{{ row.actionDesc || '-' }}</template>
            </el-table-column>
            <el-table-column prop="ip" label="IP" width="140">
              <template #default="{ row }">{{ row.ip || '-' }}</template>
            </el-table-column>
            <el-table-column label="时间" width="180" fixed="right">
              <template #default="{ row }">{{ datetime(row.createdAt) }}</template>
            </el-table-column>
          </el-table>

          <div class="pagination-wrap">
            <el-pagination
              v-model:current-page="opPager.page"
              v-model:page-size="opPager.pageSize"
              :total="opPager.total"
              :page-sizes="[10, 20, 50, 100]"
              layout="total, sizes, prev, pager, next, jumper"
              @current-change="onPageChange(opPager)"
              @size-change="onSizeChange(opPager)"
            />
          </div>
        </el-tab-pane>

        <!-- ==================== 登录日志 ==================== -->
        <el-tab-pane label="登录日志" name="login">
          <el-form class="search-bar inner-search" inline @submit.prevent>
            <el-form-item label="账号">
              <el-input
                v-model="loginPager.filters.keyword"
                placeholder="登录账号模糊搜索"
                clearable
                style="width: 200px"
                @keyup.enter="loginPager.search"
                @clear="loginPager.search"
              />
            </el-form-item>
            <el-form-item label="状态">
              <el-select
                v-model="loginPager.filters.status"
                placeholder="全部状态"
                clearable
                style="width: 130px"
                @change="loginPager.search"
              >
                <el-option label="成功" :value="1" />
                <el-option label="失败" :value="2" />
              </el-select>
            </el-form-item>
            <el-form-item label="IP">
              <el-input
                v-model="loginPager.filters.ip"
                placeholder="IP 模糊搜索"
                clearable
                style="width: 170px"
                @keyup.enter="loginPager.search"
                @clear="loginPager.search"
              />
            </el-form-item>
            <el-form-item label="时间区间">
              <el-date-picker
                v-model="loginPager.filters.dateRange"
                type="daterange"
                value-format="YYYY-MM-DD"
                range-separator="至"
                start-placeholder="开始日期"
                end-placeholder="结束日期"
                style="width: 250px"
              />
            </el-form-item>
            <el-form-item>
              <el-button type="primary" @click="loginPager.search">查询</el-button>
              <el-button @click="loginPager.reset">重置</el-button>
            </el-form-item>
          </el-form>

          <el-table v-loading="loginPager.loading" :data="loginPager.list" border stripe>
            <el-table-column label="管理员" width="110" align="center">
              <template #default="{ row }">
                <span v-if="row.adminId">#{{ row.adminId }}</span>
                <span v-else>-</span>
              </template>
            </el-table-column>
            <el-table-column prop="username" label="账号" width="160" fixed="left">
              <template #default="{ row }">
                <span class="mono">{{ row.username || '-' }}</span>
              </template>
            </el-table-column>
            <el-table-column label="状态" width="100" align="center">
              <template #default="{ row }">
                <el-tag :type="loginStatusTag(row.status).type" size="small" disable-transitions>
                  {{ loginStatusTag(row.status).text }}
                </el-tag>
              </template>
            </el-table-column>
            <el-table-column label="失败原因" min-width="140">
              <template #default="{ row }">{{ row.reason || '-' }}</template>
            </el-table-column>
            <el-table-column prop="ip" label="IP" width="140">
              <template #default="{ row }">{{ row.ip || '-' }}</template>
            </el-table-column>
            <el-table-column prop="userAgent" label="User-Agent" min-width="220" show-overflow-tooltip>
              <template #default="{ row }">{{ row.userAgent || '-' }}</template>
            </el-table-column>
            <el-table-column label="时间" width="180" fixed="right">
              <template #default="{ row }">{{ datetime(row.createdAt) }}</template>
            </el-table-column>
          </el-table>

          <div class="pagination-wrap">
            <el-pagination
              v-model:current-page="loginPager.page"
              v-model:page-size="loginPager.pageSize"
              :total="loginPager.total"
              :page-sizes="[10, 20, 50, 100]"
              layout="total, sizes, prev, pager, next, jumper"
              @current-change="onPageChange(loginPager)"
              @size-change="onSizeChange(loginPager)"
            />
          </div>
        </el-tab-pane>
      </el-tabs>
    </div>
  </div>
</template>

<style scoped lang="scss">
/* tab 内搜索栏：去掉外层卡片已提供的留白，保留白底圆角 */
.inner-search {
  margin-bottom: 12px;
  padding: 14px 14px 0;
}

.mono {
  font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
  font-size: 13px;
}
</style>
