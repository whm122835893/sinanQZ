<script setup lang="ts">
// 操作日志：管理员操作审计流水（文档 8.16 P0 子集）
import { onMounted, reactive, ref } from 'vue'
import { fetchOperationLogs } from '@/api/permission'
import type { OperationLogRow, PageData } from '@/types/api'

// ---------------- 检索 ----------------
const query = reactive({
  adminId: '',
  module: '',
  action: '',
  createdAtRange: null as [string, string] | null
})

const MODULE_OPTIONS = [
  'auth', 'user', 'realname', 'collectible', 'blindbox', 'order', 'refund',
  'market', 'transfer', 'marketing', 'wallet', 'cms', 'system', 'permission',
  'security', 'ticket', 'report', 'platform'
]

function buildParams(): Record<string, unknown> {
  const params: Record<string, unknown> = { page: page.value, pageSize: pageSize.value }
  if (query.adminId.trim()) params.adminId = query.adminId.trim()
  if (query.module !== '') params.module = query.module
  if (query.action.trim()) params.action = query.action.trim()
  if (query.createdAtRange && query.createdAtRange.length === 2) {
    params.createdAtStart = query.createdAtRange[0]
    params.createdAtEnd = query.createdAtRange[1]
  }
  return params
}

// ---------------- 列表 ----------------
const loading = ref(false)
const list = ref<OperationLogRow[]>([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(20)

async function load(): Promise<void> {
  loading.value = true
  try {
    const data = await fetchOperationLogs(buildParams())
    const pageData = data as PageData<OperationLogRow>
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
  query.adminId = ''
  query.module = ''
  query.action = ''
  query.createdAtRange = null
  handleSearch()
}

/** 模块名映射为中文 */
const MODULE_LABELS: Record<string, string> = {
  auth: '认证', user: '用户', realname: '实名', collectible: '藏品', blindbox: '盲盒',
  order: '订单', refund: '退款', market: '市场', transfer: '转赠', marketing: '营销',
  wallet: '钱包', cms: '内容', system: '系统', permission: '权限', security: '风控',
  ticket: '工单', report: '报表', platform: '平台'
}

function moduleLabel(module: string): string {
  return MODULE_LABELS[module] ?? module
}

onMounted(load)
</script>

<template>
  <div class="page-container">
    <!-- 检索区 -->
    <div class="sn-card">
      <el-form inline class="query-form" @submit.prevent="handleSearch">
        <el-form-item label="管理员ID">
          <el-input v-model="query.adminId" placeholder="精确匹配" clearable style="width: 130px" />
        </el-form-item>
        <el-form-item label="模块">
          <el-select v-model="query.module" placeholder="全部" clearable filterable style="width: 140px">
            <el-option v-for="m in MODULE_OPTIONS" :key="m" :label="`${moduleLabel(m)}（${m}）`" :value="m" />
          </el-select>
        </el-form-item>
        <el-form-item label="操作">
          <el-input v-model="query.action" placeholder="如 user.freeze" clearable style="width: 160px" />
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
        <span class="toolbar-title">操作日志</span>
        <span class="toolbar-sub">所有写操作均记录审计流水，保留 180 天</span>
      </div>

      <el-table v-loading="loading" :data="list" stripe>
        <el-table-column type="expand">
          <template #default="{ row }">
            <div class="log-expand">
              <div class="expand-row">
                <span class="expand-label">操作人：</span>
                <span>{{ row.adminName }}（ID: {{ row.adminId }}）</span>
              </div>
              <div class="expand-row">
                <span class="expand-label">操作对象：</span>
                <span>{{ row.targetType ? `${row.targetType} #${row.targetId ?? '—'}` : '—' }} {{ row.targetDesc || '' }}</span>
              </div>
              <div class="expand-row">
                <span class="expand-label">操作原因：</span>
                <span>{{ row.reason || '—' }}</span>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="id" label="ID" width="80" />
        <el-table-column prop="adminName" label="操作人" width="120" />
        <el-table-column label="模块" width="90" align="center">
          <template #default="{ row }">
            <el-tag size="small" effect="plain">{{ moduleLabel(row.module) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="action" label="操作" width="180" show-overflow-tooltip>
          <template #default="{ row }">
            <span class="din action-code">{{ row.action }}</span>
          </template>
        </el-table-column>
        <el-table-column label="对象" min-width="200" show-overflow-tooltip>
          <template #default="{ row }">
            <template v-if="row.targetType">
              {{ row.targetType }}#{{ row.targetId }} {{ row.targetDesc ? `· ${row.targetDesc}` : '' }}
            </template>
            <span v-else>—</span>
          </template>
        </el-table-column>
        <el-table-column prop="reason" label="原因" min-width="140" show-overflow-tooltip>
          <template #default="{ row }">{{ row.reason || '—' }}</template>
        </el-table-column>
        <el-table-column prop="ip" label="IP" width="130" />
        <el-table-column prop="createdAt" label="操作时间" width="165" fixed="right" />
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

.action-code {
  color: $sn-primary-dark;
}

.log-expand {
  padding: 4px 12px;

  .expand-row {
    line-height: 24px;
    font-size: 13px;
    color: $sn-text-sub;
  }

  .expand-label {
    color: $sn-text-muted;
  }
}
</style>
