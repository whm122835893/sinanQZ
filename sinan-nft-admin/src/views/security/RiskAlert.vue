<script setup lang="ts">
/**
 * 风控安全 - 风控告警（security:alert）
 *
 * - 搜索：告警类型 / 告警等级 / 状态 / 时间区间（后端 alert_type / alert_level / status / startDate/endDate）
 * - 表格：字段与 SecurityController::riskAlerts 返回严格一致
 *   （该控制器未调用 camelize_keys，列表字段为下划线命名，如 alert_type_name / created_at）
 * - 操作：处理（弹窗选择处理方式 + 必填处理结果，security:alert）
 *   状态机：1未处理 → 2处理中 → 3已处理 / 4已忽略（3、4 为终态不可再流转）
 */
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { useListPage } from '@/utils/useListPage'
import type { PageResult } from '@/utils/useListPage'
import { datetime, maskPhone } from '@/utils/format'
import { fetchRiskAlerts, handleRiskAlert } from '@/api/misc'

/** 列表接口实际返回（后端额外附带 summary 概览统计，misc.ts 未声明该字段） */
interface ListResult extends PageResult {
  summary?: Record<string, number>
}

/** 告警类型（与后端 SecurityController::ALERT_TYPES 一致） */
const ALERT_TYPES: Record<number, string> = {
  1: '大额充值', 2: '频繁小额充值', 3: '余额突变', 4: '高频API',
  5: '异常时间操作', 6: '异地登录', 7: '批量注册', 8: '异常价格', 9: '其他',
}
const typeOptions = Object.entries(ALERT_TYPES).map(([k, v]) => ({ value: Number(k), label: v }))

/** 告警等级：1低 2中 3高 4紧急 */
const LEVEL_MAP: Record<number, { text: string; type: string }> = {
  1: { text: '低', type: 'info' },
  2: { text: '中', type: 'warning' },
  3: { text: '高', type: 'danger' },
  4: { text: '紧急', type: 'danger' },
}
const levelOptions = Object.entries(LEVEL_MAP).map(([k, v]) => ({ value: Number(k), label: `${v.text}级` }))

/** 告警状态：1未处理 2处理中 3已处理 4已忽略 */
const STATUS_MAP: Record<number, { text: string; type: string }> = {
  1: { text: '未处理', type: 'danger' },
  2: { text: '处理中', type: 'primary' },
  3: { text: '已处理', type: 'success' },
  4: { text: '已忽略', type: 'info' },
}
const statusOptions = Object.entries(STATUS_MAP).map(([k, v]) => ({ value: Number(k), label: v.text }))

function levelTag(level?: number) {
  return LEVEL_MAP[Number(level)] ?? { text: String(level ?? '-'), type: 'info' }
}

function statusTag(status?: number) {
  return STATUS_MAP[Number(status)] ?? { text: String(status ?? '-'), type: 'info' }
}

interface AlertRow {
  id: number
  alert_type: number
  alert_type_name: string
  alert_level: number
  user_id: number
  uid: string
  phone: string
  title: string
  description?: string
  status: number
  handler_name?: string
  handled_at?: string
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
  const res = (await fetchRiskAlerts(buildQuery(params))) as ListResult
  summary.value = res?.summary ?? {}
  return res
}, {
  alert_type: '',
  alert_level: '',
  status: '',
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
  { label: '待处理', value: summary.value.pending ?? 0, sub: '未处理告警', icon: 'Bell', color: '' },
  { label: '处理中', value: summary.value.handling ?? 0, sub: '跟进中的告警', icon: 'Timer', color: 'blue' },
  { label: '已处理', value: summary.value.handled ?? 0, sub: '已完成闭环', icon: 'CircleCheck', color: 'green' },
  { label: '已忽略', value: summary.value.ignored ?? 0, sub: '判定误报等', icon: 'InfoFilled', color: 'gray' },
  { label: '高危未结', value: summary.value.highLevel ?? 0, sub: '高/紧急等级未闭环', icon: 'Warning', color: 'orange' },
])

// ===== 处理告警弹窗（必填处理结果）=====

const handleVisible = ref(false)
const handleSubmitting = ref(false)
const handleForm = reactive({
  id: 0,
  title: '',
  uid: '',
  status: 3 as 2 | 3 | 4,
  handle_comment: '',
})

/** 处理方式选项（后端仅允许 2处理中/3已处理/4已忽略） */
const HANDLE_STATUS_OPTIONS = [
  { value: 3, label: '已处理（闭环）' },
  { value: 2, label: '处理中（跟进）' },
  { value: 4, label: '已忽略（误报）' },
]

function openHandle(row: AlertRow) {
  handleForm.id = row.id
  handleForm.title = row.title
  handleForm.uid = row.uid || String(row.user_id ?? '')
  handleForm.status = 3
  handleForm.handle_comment = ''
  handleVisible.value = true
}

async function submitHandle() {
  const comment = handleForm.handle_comment.trim()
  if (!comment) {
    ElMessage.warning('请填写处理结果')
    return
  }
  handleSubmitting.value = true
  try {
    await handleRiskAlert(handleForm.id, {
      status: handleForm.status,
      handle_comment: comment,
    })
    handleVisible.value = false
    await pager.done('告警状态已更新')
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
        <el-form-item label="告警类型">
          <el-select
            v-model="pager.filters.alert_type"
            placeholder="全部类型"
            clearable
            filterable
            style="width: 150px"
            @change="pager.search"
          >
            <el-option v-for="opt in typeOptions" :key="opt.value" :label="opt.label" :value="opt.value" />
          </el-select>
        </el-form-item>
        <el-form-item label="等级">
          <el-select
            v-model="pager.filters.alert_level"
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
        <el-form-item label="告警时间">
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

    <!-- 告警表格 -->
    <div class="table-card">
      <div class="table-header">
        <span class="table-title">风控告警</span>
        <span class="table-tip">列表按等级优先排序；已处理 / 已忽略为终态，不可再次流转</span>
      </div>

      <el-table v-loading="pager.loading" :data="pager.list" border stripe>
        <el-table-column prop="id" label="ID" width="70" align="center" />
        <el-table-column label="告警类型" width="120" align="center">
          <template #default="{ row }">{{ row.alert_type_name || ALERT_TYPES[Number(row.alert_type)] || '-' }}</template>
        </el-table-column>
        <el-table-column label="等级" width="80" align="center">
          <template #default="{ row }">
            <el-tag :type="levelTag(row.alert_level).type" size="small" effect="dark">
              {{ levelTag(row.alert_level).text }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="告警内容" min-width="240">
          <template #default="{ row }">
            <div class="cell-title">{{ row.title || '-' }}</div>
            <div class="cell-sub">{{ row.description || '-' }}</div>
          </template>
        </el-table-column>
        <el-table-column label="用户" min-width="140">
          <template #default="{ row }">
            <template v-if="row.uid || row.user_id">
              <div>{{ row.uid || row.user_id }}</div>
              <div class="cell-sub">{{ maskPhone(row.phone) }}</div>
            </template>
            <span v-else class="cell-sub">平台级</span>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="95" align="center">
          <template #default="{ row }">
            <el-tooltip
              v-if="row.handle_comment"
              :content="`处理意见：${row.handle_comment}（${row.handler_name || '-'} ${datetime(row.handled_at)}）`"
              placement="top"
            >
              <el-tag :type="statusTag(row.status).type" size="small">{{ statusTag(row.status).text }}</el-tag>
            </el-tooltip>
            <el-tag v-else :type="statusTag(row.status).type" size="small">{{ statusTag(row.status).text }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="处理人" width="150">
          <template #default="{ row }">
            <template v-if="row.handler_name">
              <div>{{ row.handler_name }}</div>
              <div class="cell-sub">{{ datetime(row.handled_at) }}</div>
            </template>
            <span v-else class="cell-sub">-</span>
          </template>
        </el-table-column>
        <el-table-column label="告警时间" width="170">
          <template #default="{ row }">{{ datetime(row.created_at) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="90" fixed="right" align="center">
          <template #default="{ row }">
            <el-button
              v-if="[1, 2].includes(Number(row.status))"
              v-permission="'security:alert'"
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

    <!-- 处理告警弹窗 -->
    <el-dialog v-model="handleVisible" title="处理风控告警" width="560px" destroy-on-close>
      <el-alert
        type="info"
        :closable="false"
        show-icon
        :title="`告警：${handleForm.title || '-'}${handleForm.uid ? ' · 用户 ' + handleForm.uid : ''}`"
        style="margin-bottom: 16px"
      />
      <el-form label-width="90px">
        <el-form-item label="处理方式">
          <el-radio-group v-model="handleForm.status">
            <el-radio v-for="opt in HANDLE_STATUS_OPTIONS" :key="opt.value" :value="opt.value">{{ opt.label }}</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="处理结果" required>
          <el-input
            v-model="handleForm.handle_comment"
            type="textarea"
            :rows="4"
            maxlength="255"
            show-word-limit
            placeholder="必填，填写核查结论 / 处置动作（如：已核实为本人操作，解除限制），将写入处理记录与审计日志"
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
</style>
