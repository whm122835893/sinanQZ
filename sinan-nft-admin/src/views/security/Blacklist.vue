<script setup lang="ts">
/**
 * 风控安全 - 黑名单管理（security:blacklist）
 *
 * - 搜索：关键词（匹配 UID / 目标值 / 原因）、类型（1用户级/2IP级/3设备级）、状态、加入时间区间
 * - 表格：字段与 SecurityController::blacklist 返回严格一致
 *   （该控制器未调用 camelize_keys，列表字段为下划线命名，如 blacklist_type_name / created_at）
 * - 操作：解除（必填解除原因）；顶部「加入黑名单」弹窗（用户ID + 类型 + 原因必填）
 */
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import { post } from '@/api/http'
import { useListPage } from '@/utils/useListPage'
import type { PageResult } from '@/utils/useListPage'
import { datetime, maskPhone } from '@/utils/format'
import { addBlacklist, fetchBlacklist } from '@/api/misc'

/** 列表接口实际返回（后端额外附带 summary 概览统计，misc.ts 未声明该字段） */
interface ListResult extends PageResult {
  summary?: Record<string, number>
}

/** 黑名单类型（与后端 SecurityController::BLACKLIST_TYPES 一致） */
const BLACKLIST_TYPES: Record<number, string> = { 1: '用户级', 2: 'IP级', 3: '设备级' }
const typeOptions = Object.entries(BLACKLIST_TYPES).map(([k, v]) => ({ value: Number(k), label: v }))

const TYPE_TAG: Record<number, string> = { 1: 'danger', 2: 'warning', 3: 'info' }

const STATUS_OPTIONS = [
  { value: 1, label: '生效中' },
  { value: 0, label: '已解除' },
]

interface BlacklistRow {
  id: number
  user_id: number
  uid: string
  username: string
  phone: string
  blacklist_type: number
  blacklist_type_name: string
  target_value: string
  reason: string
  evidence?: string
  admin_id: number
  admin_name: string
  status: number
  lifted_at?: string
  lifted_by?: number
  lifted_reason?: string
  expires_at?: string
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
  const res = (await fetchBlacklist(buildQuery(params))) as ListResult
  summary.value = res?.summary ?? {}
  return res
}, {
  keyword: '',
  blacklist_type: '',
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
  { label: '生效中', value: summary.value.active ?? 0, sub: '拦截中的黑名单记录', icon: 'Lock', color: '' },
  { label: '用户级生效', value: summary.value.user ?? 0, sub: '用户级黑名单', icon: 'User', color: 'blue' },
  { label: 'IP级生效', value: summary.value.ip ?? 0, sub: 'IP级黑名单', icon: 'Connection', color: 'orange' },
  { label: '设备级生效', value: summary.value.device ?? 0, sub: '设备级黑名单', icon: 'Cellphone', color: 'purple' },
  { label: '已解除', value: summary.value.lifted ?? 0, sub: '历史解除记录', icon: 'Unlock', color: 'gray' },
])

// ===== 解除黑名单（必填原因）=====

/**
 * 后端 SecurityController::blacklistLift 读取字段 lift_reason；
 * misc.ts 的 liftBlacklist 发送的是 { reason }，与后端字段不一致，
 * 按「字段名以后端 PHP 控制器为准」约定，此处直接调用 post 并传 lift_reason。
 */
async function handleLift(row: BlacklistRow) {
  try {
    const { value } = await ElMessageBox.prompt(
      `确认解除用户 ${row.uid || row.user_id} 的${row.blacklist_type_name}黑名单？用户级黑名单解除后用户即刻恢复访问（原因将写入审计日志）`,
      '解除黑名单',
      {
        confirmButtonText: '确认解除',
        cancelButtonText: '取消',
        type: 'warning',
        inputType: 'textarea',
        inputPlaceholder: '请输入解除原因（必填，不少于 2 字）',
        inputValidator: (v: string) => (v && v.trim().length >= 2 ? true : '解除原因不能少于 2 字'),
      },
    )
    await post(`/security/blacklist/${row.id}/lift`, { lift_reason: value.trim() })
    await pager.done('黑名单已解除')
  } catch {
    /* 弹窗取消或接口失败（错误已全局提示） */
  }
}

// ===== 加入黑名单弹窗 =====

const addVisible = ref(false)
const addSubmitting = ref(false)
const addFormRef = ref<FormInstance>()
const addForm = reactive({
  user_id: undefined as number | undefined,
  blacklist_type: 1,
  target_value: '',
  reason: '',
  evidence: '',
  expires_at: '',
})

const addRules: FormRules = {
  user_id: [{ required: true, message: '请输入用户ID（数字）', trigger: 'blur' }],
  blacklist_type: [{ required: true, message: '请选择黑名单类型', trigger: 'change' }],
  reason: [
    { required: true, message: '请输入拉黑原因', trigger: 'blur' },
    { min: 2, max: 255, message: '拉黑原因需为 2~255 字', trigger: 'blur' },
  ],
}

function openAdd() {
  addForm.user_id = undefined
  addForm.blacklist_type = 1
  addForm.target_value = ''
  addForm.reason = ''
  addForm.evidence = ''
  addForm.expires_at = ''
  addVisible.value = true
}

async function submitAdd() {
  const valid = await addFormRef.value?.validate().catch(() => false)
  if (!valid) return

  const data: Record<string, any> = {
    user_id: addForm.user_id,
    blacklist_type: addForm.blacklist_type,
    reason: addForm.reason.trim(),
  }
  if (addForm.target_value.trim()) data.target_value = addForm.target_value.trim()
  if (addForm.evidence.trim()) data.evidence = addForm.evidence.trim()
  if (addForm.expires_at) data.expires_at = addForm.expires_at

  addSubmitting.value = true
  try {
    await addBlacklist(data)
    addVisible.value = false
    await pager.done('已加入黑名单')
  } catch {
    /* 全局提示 */
  } finally {
    addSubmitting.value = false
  }
}
</script>

<template>
  <div class="page-container">
    <!-- 搜索栏 -->
    <div class="search-bar">
      <el-form inline @submit.prevent>
        <el-form-item label="关键词">
          <el-input
            v-model="pager.filters.keyword"
            placeholder="UID / IP / 设备号 / 原因"
            clearable
            style="width: 220px"
            @keyup.enter="pager.search"
            @clear="pager.search"
          />
        </el-form-item>
        <el-form-item label="类型">
          <el-select
            v-model="pager.filters.blacklist_type"
            placeholder="全部类型"
            clearable
            style="width: 130px"
            @change="pager.search"
          >
            <el-option v-for="opt in typeOptions" :key="opt.value" :label="opt.label" :value="opt.value" />
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
            <el-option v-for="opt in STATUS_OPTIONS" :key="opt.value" :label="opt.label" :value="opt.value" />
          </el-select>
        </el-form-item>
        <el-form-item label="加入时间">
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

    <!-- 黑名单表格 -->
    <div class="table-card">
      <div class="table-header">
        <div class="table-head-left">
          <span class="table-title">黑名单记录</span>
          <span class="table-tip">用户级黑名单同步用户 is_blacklisted 标记（C 端实时拦截），解除需填写原因并写审计日志</span>
        </div>
        <el-button v-permission="'security:blacklist'" type="primary" @click="openAdd">加入黑名单</el-button>
      </div>

      <el-table v-loading="pager.loading" :data="pager.list" border stripe>
        <el-table-column prop="id" label="ID" width="70" align="center" />
        <el-table-column label="用户" min-width="150">
          <template #default="{ row }">
            <div>{{ row.uid || row.user_id }}</div>
            <div class="cell-sub">{{ row.username || '-' }} · {{ maskPhone(row.phone) }}</div>
          </template>
        </el-table-column>
        <el-table-column label="类型" width="90" align="center">
          <template #default="{ row }">
            <el-tag :type="TYPE_TAG[Number(row.blacklist_type)] ?? 'info'" size="small">
              {{ row.blacklist_type_name || BLACKLIST_TYPES[Number(row.blacklist_type)] || '-' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="target_value" label="目标值" min-width="130" show-overflow-tooltip>
          <template #default="{ row }">
            <span class="mono">{{ row.target_value || '-' }}</span>
          </template>
        </el-table-column>
        <el-table-column label="拉黑原因" min-width="180" show-overflow-tooltip>
          <template #default="{ row }">{{ row.reason || '-' }}</template>
        </el-table-column>
        <el-table-column prop="admin_name" label="操作人" width="100" show-overflow-tooltip />
        <el-table-column label="加入时间" width="170">
          <template #default="{ row }">{{ datetime(row.created_at) }}</template>
        </el-table-column>
        <el-table-column label="到期时间" width="170">
          <template #default="{ row }">{{ row.expires_at ? datetime(row.expires_at) : '永久' }}</template>
        </el-table-column>
        <el-table-column label="状态" width="100" align="center">
          <template #default="{ row }">
            <el-tooltip
              v-if="Number(row.status) !== 1 && row.lifted_reason"
              :content="`解除原因：${row.lifted_reason}（${datetime(row.lifted_at)}）`"
              placement="top"
            >
              <el-tag type="info" size="small">已解除</el-tag>
            </el-tooltip>
            <el-tag v-else :type="Number(row.status) === 1 ? 'danger' : 'info'" size="small">
              {{ Number(row.status) === 1 ? '生效中' : '已解除' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="90" fixed="right" align="center">
          <template #default="{ row }">
            <el-button
              v-if="Number(row.status) === 1"
              v-permission="'security:blacklist'"
              link
              type="success"
              size="small"
              @click="handleLift(row)"
            >解除</el-button>
            <span v-else class="cell-sub">-</span>
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

    <!-- 加入黑名单弹窗 -->
    <el-dialog v-model="addVisible" title="加入黑名单" width="560px" destroy-on-close>
      <el-alert
        type="warning"
        :closable="false"
        show-icon
        title="用户级黑名单加入后该用户即刻被 C 端拦截；同用户同类型仅允许一条生效记录（重复加入幂等复用）"
        style="margin-bottom: 16px"
      />
      <el-form ref="addFormRef" :model="addForm" :rules="addRules" label-width="90px">
        <el-form-item label="用户ID" prop="user_id">
          <el-input-number
            v-model="addForm.user_id"
            :min="1"
            :precision="0"
            controls-position="right"
            placeholder="用户数字ID（用户列表/详情中的 ID）"
            style="width: 100%"
          />
        </el-form-item>
        <el-form-item label="黑名单类型" prop="blacklist_type">
          <el-radio-group v-model="addForm.blacklist_type">
            <el-radio v-for="opt in typeOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="目标值">
          <el-input
            v-model="addForm.target_value"
            placeholder="选填，默认取用户UID（IP级默认取当前请求IP）"
            maxlength="255"
          />
        </el-form-item>
        <el-form-item label="拉黑原因" prop="reason">
          <el-input
            v-model="addForm.reason"
            type="textarea"
            :rows="3"
            maxlength="255"
            show-word-limit
            placeholder="必填，2~255 字，将写入审计日志"
          />
        </el-form-item>
        <el-form-item label="证据描述">
          <el-input
            v-model="addForm.evidence"
            type="textarea"
            :rows="2"
            maxlength="65535"
            placeholder="选填，证据/截图说明等"
          />
        </el-form-item>
        <el-form-item label="过期时间">
          <el-date-picker
            v-model="addForm.expires_at"
            type="datetime"
            value-format="YYYY-MM-DD HH:mm:ss"
            placeholder="选填，留空为永久生效（须晚于当前时间）"
            style="width: 100%"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="addVisible = false">取消</el-button>
        <el-button type="danger" :loading="addSubmitting" @click="submitAdd">确认拉黑</el-button>
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

  .table-head-left {
    display: flex;
    align-items: baseline;
    gap: 12px;
    min-width: 0;
  }

  .table-title {
    font-size: 15px;
    font-weight: 600;
    white-space: nowrap;
  }

  .table-tip {
    font-size: 12px;
    color: var(--sn-text-secondary);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
}

.cell-sub {
  font-size: 12px;
  color: var(--sn-text-secondary);
}

.mono {
  font-family: 'JetBrains Mono', Menlo, Consolas, monospace;
  font-size: 12px;
}
</style>
