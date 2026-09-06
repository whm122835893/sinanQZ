<script setup lang="ts">
/**
 * 风控安全 - 安全事件（security:event）
 *
 * - 搜索：事件类型 / 等级 / 状态 / IP / 时间区间（后端 event_type / event_level / status / ip / startDate/endDate）
 * - 表格：字段与 SecurityController::securityEvents 返回严格一致
 *   （该控制器未调用 camelize_keys，列表字段为下划线命名，如 event_type_name / created_at）
 * - 操作：处理（弹窗选择处理方式 + 必填处理备注，security:event）
 *   状态机：1未处理 → 2已确认 → 3已处理（终态）/ 4误报（3 为终态不可再流转）
 */
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { useListPage } from '@/utils/useListPage'
import type { PageResult } from '@/utils/useListPage'
import { datetime } from '@/utils/format'
import { fetchSecurityEvents, handleSecurityEvent } from '@/api/misc'

/** 列表接口实际返回（后端额外附带 summary 概览统计，misc.ts 未声明该字段） */
interface ListResult extends PageResult {
  summary?: Record<string, number>
}

/** 事件类型（与后端 SecurityController::EVENT_TYPES 一致） */
const EVENT_TYPES: Record<number, string> = {
  1: '越权尝试', 2: '支付回调异常', 3: 'Token异常', 4: '暴力破解', 5: '其他',
}
const typeOptions = Object.entries(EVENT_TYPES).map(([k, v]) => ({ value: Number(k), label: v }))

/** 事件等级：1低 2中 3高 4紧急 */
const LEVEL_MAP: Record<number, { text: string; type: string }> = {
  1: { text: '低', type: 'info' },
  2: { text: '中', type: 'warning' },
  3: { text: '高', type: 'danger' },
  4: { text: '紧急', type: 'danger' },
}
const levelOptions = Object.entries(LEVEL_MAP).map(([k, v]) => ({ value: Number(k), label: `${v.text}级` }))

/** 事件状态：1未处理 2已确认 3已处理 4误报 */
const STATUS_MAP: Record<number, { text: string; type: string }> = {
  1: { text: '未处理', type: 'danger' },
  2: { text: '已确认', type: 'primary' },
  3: { text: '已处理', type: 'success' },
  4: { text: '误报', type: 'info' },
}
const statusOptions = Object.entries(STATUS_MAP).map(([k, v]) => ({ value: Number(k), label: v.text }))

function levelTag(level?: number) {
  return LEVEL_MAP[Number(level)] ?? { text: String(level ?? '-'), type: 'info' }
}

function statusTag(status?: number) {
  return STATUS_MAP[Number(status)] ?? { text: String(status ?? '-'), type: 'info' }
}

interface EventRow {
  id: number
  event_type: number
  event_type_name: string
  event_level: number
  admin_id?: number
  user_id?: number
  ip?: string
  user_agent?: string
  request_path?: string
  request_method?: string
  response_status?: number
  description?: string
  status: number
  handle_comment?: string
  created_at: string
}

/** 概览统计（随列表接口返回 summary） */
const summary = ref<Record<string, number>>({})

/** 时间区间数组 → 后端 startDate/endDate，并剔除空参数 */
function buildQuery(params: Record<string, any>) {
  const { dateRange, ...rest } = params
  const query: Record<string, any> = {}
  Object.keys(rest).forEach((k) => {
    const v = rest[k]
    if (v !== '' && v !== null && v !== undefined) query[k] = v
  })
  if (Array.isArray(dateRange)) {
    if (dateRange[0]) query.startDate = dateRange[0]
    if (dateRange[1]) query.endDate = dateRange[1]
  }
  return query
}

const pager = useListPage(async (params: Record<string, any>) => {
  const res = (await fetchSecurityEvents(buildQuery(params))) as ListResult
  summary.value = res?.summary ?? {}
  return res
}, {
  event_type: '',
  event_level: '',
  status: '',
  ip: '',
  dateRange: [],
})

onMounted(() => {
  pager.load()
})

function handlePageChange() {
  pager.load()
}

function handleSizeChange() {
  pager.page = 1
  pager.load()
}

const statCards = computed(() => [
  { label: '未处理', value: summary.value.pending ?? 0, sub: '待确认的安全事件', icon: 'Warning', color: '' },
  { label: '已确认', value: summary.value.confirmed ?? 0, sub: '确认属实的跟进中', icon: 'View', color: 'blue' },
  { label: '已处理', value: summary.value.handled ?? 0, sub: '已完成处置闭环', icon: 'CircleCheck', color: 'green' },
  { label: '误报', value: summary.value.falsePositive ?? 0, sub: '判定误报的事件', icon: 'CircleClose', color: 'gray' },
])

// ===== 处理事件弹窗（必填处理备注）=====

const handleVisible = ref(false)
const handleSubmitting = ref(false)
const handleForm = reactive({
  id: 0,
  description: '',
  status: 3 as 2 | 3 | 4,
  handle_comment: '',
})

/** 处理方式选项（后端仅允许 2已确认/3已处理/4误报） */
const HANDLE_STATUS_OPTIONS = [
  { value: 3, label: '已处理（闭环）' },
  { value: 2, label: '已确认（跟进）' },
  { value: 4, label: '误报' },
]

function openHandle(row: EventRow) {
  handleForm.id = row.id
  handleForm.description = row.description || row.event_type_name || ''
  handleForm.status = 3
  handleForm.handle_comment = ''
  handleVisible.value = true
}

async function submitHandle() {
  const comment = handleForm.handle_comment.trim()
  if (!comment) {
    ElMessage.warning('请填写处理备注')
    return
  }
  handleSubmitting.value = true
  try {
    await handleSecurityEvent(handleForm.id, {
      status: handleForm.status,
      handle_comment: comment,
    })
    handleVisible.value = false
    await pager.done('安全事件状态已更新')
  } catch {
    /* 全局提示 */
  } finally {
    handleSubmitting.value = false
  }
}
</script>

<template>
  <div class="page-container">
    <!-- 搜索栏 -->
    <div class="search-bar">
      <el-form inline @submit.prevent>
        <el-form-item label="事件类型">
          <el-select
            v-model="pager.filters.event_type"
            placeholder="全部类型"
            clearable
            style="width: 150px"
            @change="pager.search"
          >
            <el-option v-for="opt in typeOptions" :key="opt.value" :label="opt.label" :value="opt.value" />
          </el-select>
        </el-form-item>
        <el-form-item label="等级">
          <el-select
            v-model="pager.filters.event_level"
            placeholder="全部等级"
            clearable
            style="width: 120px"
            @change="pager.search"
          >
            <el-option v-for="opt in levelOptions" :key="opt.value" :label="opt.label" :value="opt.value" />
          </el-select>
        </el-form-item>
        <el-form-item label="状态">
          <el-select
            v-model="pager.filters.status"
            placeholder="全部状态"
            clearable
            style="width: 120px"
            @change="pager.search"
          >
            <el-option v-for="opt in statusOptions" :key="opt.value" :label="opt.label" :value="opt.value" />
          </el-select>
        </el-form-item>
        <el-form-item label="IP">
          <el-input
            v-model="pager.filters.ip"
            placeholder="IP 模糊搜索"
            clearable
            style="width: 160px"
            @keyup.enter="pager.search"
            @clear="pager.search"
          />
        </el-form-item>
        <el-form-item label="发生时间">
          <el-date-picker
            v-model="pager.filters.dateRange"
            type="daterange"
            value-format="YYYY-MM-DD"
            range-separator="至"
            start-placeholder="开始日期"
            end-placeholder="结束日期"
            style="width: 240px"
          />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="pager.search">查询</el-button>
          <el-button @click="pager.reset">重置</el-button>
        </el-form-item>
      </el-form>
    </div>

    <!-- 概览统计 -->
    <div class="stat-grid">
      <div v-for="card in statCards" :key="card.label" class="stat-card">
        <div class="stat-info">
          <div class="stat-label">{{ card.label }}</div>
          <div class="stat-value">{{ card.value }}</div>
          <div class="stat-sub">{{ card.sub }}</div>
        </div>
        <div class="stat-icon" :class="card.color">
          <el-icon :size="22"><component :is="card.icon" /></el-icon>
        </div>
      </div>
    </div>

    <!-- 事件表格 -->
    <div class="table-card">
      <div class="table-header">
        <span class="table-title">安全事件</span>
        <span class="table-tip">记录越权尝试 / 支付回调异常 / Token 异常 / 暴力破解等平台侧安全威胁；已处理为终态</span>
      </div>

      <el-table v-loading="pager.loading" :data="pager.list" border stripe>
        <el-table-column prop="id" label="ID" width="70" align="center" />
        <el-table-column label="事件类型" width="125" align="center">
          <template #default="{ row }">{{ row.event_type_name || EVENT_TYPES[Number(row.event_type)] || '-' }}</template>
        </el-table-column>
        <el-table-column label="等级" width="80" align="center">
          <template #default="{ row }">
            <el-tag :type="levelTag(row.event_level).type" size="small" effect="dark">
              {{ levelTag(row.event_level).text }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="用户" width="110" align="center">
          <template #default="{ row }">
            <span v-if="row.user_id">{{ row.user_id }}</span>
            <span v-else-if="row.admin_id" class="cell-sub">管理员#{{ row.admin_id }}</span>
            <span v-else class="cell-sub">平台级</span>
          </template>
        </el-table-column>
        <el-table-column label="事件描述" min-width="240">
          <template #default="{ row }">
            <div class="cell-title">{{ row.description || '-' }}</div>
            <div v-if="row.request_path" class="cell-sub">
              {{ row.request_method }} {{ row.request_path }}
              <template v-if="row.response_status">· HTTP {{ row.response_status }}</template>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="来源IP" width="140">
          <template #default="{ row }">
            <span class="mono">{{ row.ip || '-' }}</span>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="95" align="center">
          <template #default="{ row }">
            <el-tooltip
              v-if="row.handle_comment"
              :content="`处理备注：${row.handle_comment}`"
              placement="top"
            >
              <el-tag :type="statusTag(row.status).type" size="small">{{ statusTag(row.status).text }}</el-tag>
            </el-tooltip>
            <el-tag v-else :type="statusTag(row.status).type" size="small">{{ statusTag(row.status).text }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="发生时间" width="170">
          <template #default="{ row }">{{ datetime(row.created_at) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="90" fixed="right" align="center">
          <template #default="{ row }">
            <el-button
              v-if="Number(row.status) !== 3"
              v-permission="'security:event'"
              link
              type="primary"
              size="small"
              @click="openHandle(row)"
            >处理</el-button>
            <span v-else class="cell-sub">已闭环</span>
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
          @current-change="handlePageChange"
          @size-change="handleSizeChange"
        />
      </div>
    </div>

    <!-- 处理事件弹窗 -->
    <el-dialog v-model="handleVisible" title="处理安全事件" width="560px" destroy-on-close>
      <el-alert
        type="info"
        :closable="false"
        show-icon
        :title="`事件：${handleForm.description || '-'}`"
        style="margin-bottom: 16px"
      />
      <el-form label-width="90px">
        <el-form-item label="处理方式">
          <el-radio-group v-model="handleForm.status">
            <el-radio v-for="opt in HANDLE_STATUS_OPTIONS" :key="opt.value" :value="opt.value">{{ opt.label }}</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="处理备注" required>
          <el-input
            v-model="handleForm.handle_comment"
            type="textarea"
            :rows="4"
            maxlength="255"
            show-word-limit
            placeholder="必填，填写处置动作（如：已封禁来源IP并加固接口鉴权），将写入处理记录与审计日志"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="handleVisible = false">取消</el-button>
        <el-button type="primary" :loading="handleSubmitting" @click="submitHandle">确认处理</el-button>
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
    font-size: 12px;
    color: var(--sn-text-secondary);
  }
}

.cell-title {
  font-weight: 500;
}

.cell-sub {
  font-size: 12px;
  color: var(--sn-text-secondary);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.mono {
  font-family: 'JetBrains Mono', Menlo, Consolas, monospace;
  font-size: 12px;
}
</style>
