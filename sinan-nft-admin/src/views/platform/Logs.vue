<script setup lang="ts">
/**
 * 平台运维（platform:log / platform:cleanup）
 *
 * - 顶部统计卡：清库次数 / 成败 / 影响面（fetchCleanupLogs 汇总）
 * - 一键清库危险操作区（platform:cleanup，红色警示卡）三步确认：
 *   1) 查看清库预览（fetchCleanupPreview 展示将清理的表与行数）
 *   2) 发送验证码（sendCleanupCode，发送至当前管理员绑定手机）
 *   3) 输入验证码 + 清库原因（后端必填 ≥5 字）+ 确认勾选后 executeCleanup({ code, reason })
 * - 下方清库历史日志表格：字段与 PlatformController::cleanupLogs 返回严格一致
 *   （该控制器未调用 camelize_keys，列表字段为下划线命名，如 admin_name / execution_time）
 */
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useListPage } from '@/utils/useListPage'
import { datetime, maskPhone } from '@/utils/format'
import {
  executeCleanup,
  fetchCleanupLogs,
  fetchCleanupPreview,
  sendCleanupCode,
} from '@/api/misc'

interface CleanupLogRow {
  id: number
  admin_id: number
  admin_name: string
  admin_phone: string
  ip: string
  reason: string
  backup_path: string
  affected_users: number
  affected_orders: number
  execution_time: number
  status: number
  error_message?: string
  created_at: string
}

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

const pager = useListPage((params: Record<string, any>) => fetchCleanupLogs(buildQuery(params)), {
  admin_name: '',
  dateRange: [],
})

onMounted(() => {
  pager.load()
  loadStats()
  loadPreview()
})

function handlePageChange() {
  pager.load()
}

function handleSizeChange() {
  pager.page = 1
  pager.load()
}

// ===== 顶部统计（fetchCleanupLogs 汇总，取最近 100 条）=====

const stats = ref({
  total: 0,
  success: 0,
  failed: 0,
  affectedUsers: 0,
  affectedOrders: 0,
  lastTime: '',
})

async function loadStats() {
  try {
    const res = await fetchCleanupLogs({ page: 1, pageSize: 100 })
    const rows: CleanupLogRow[] = res?.list ?? []
    stats.value = {
      total: res?.total ?? rows.length,
      success: rows.filter((r) => Number(r.status) === 1).length,
      failed: rows.filter((r) => Number(r.status) === 2).length,
      affectedUsers: rows.reduce((s, r) => s + Number(r.affected_users ?? 0), 0),
      affectedOrders: rows.reduce((s, r) => s + Number(r.affected_orders ?? 0), 0),
      lastTime: rows[0]?.created_at ?? '',
    }
  } catch {
    /* 全局提示 */
  }
}

const statCards = computed(() => [
  { label: '清库总次数', value: stats.value.total, sub: '历史执行记录总数', icon: 'Histogram', color: '' },
  { label: '执行成功', value: stats.value.success, sub: '近 100 条中成功次数', icon: 'CircleCheck', color: 'green' },
  { label: '执行失败', value: stats.value.failed, sub: '近 100 条中失败次数', icon: 'CircleClose', color: 'orange' },
  { label: '累计影响用户', value: stats.value.affectedUsers, sub: '近 100 条清库影响用户数', icon: 'User', color: 'blue' },
  { label: '累计影响订单', value: stats.value.affectedOrders, sub: '近 100 条清库影响订单数', icon: 'ShoppingCart', color: 'purple' },
  { label: '最近执行', value: stats.value.lastTime ? datetime(stats.value.lastTime).slice(5, 16) : '-', sub: '最近一次清库时间', icon: 'Timer', color: 'gray' },
])

// ===== 清库预览（弹窗第一步数据源，同时用于危险区概览与「清理表数」列）=====

const previewInfo = ref<any>(null)
const previewLoading = ref(false)

async function loadPreview() {
  previewLoading.value = true
  try {
    previewInfo.value = await fetchCleanupPreview()
  } catch {
    /* 全局提示；预览也可在弹窗中重试 */
  } finally {
    previewLoading.value = false
  }
}

const previewTables = computed(() => previewInfo.value?.tables ?? [])

// ===== 一键清库三步确认弹窗 =====

const cleanupVisible = ref(false)
/** 步骤：0 影响面预览 → 1 短信验证 → 2 确认执行 */
const cleanupStep = ref(0)
const sendingCode = ref(false)
const sentPhone = ref('')
const executing = ref(false)
const cleanupForm = reactive({
  code: '',
  reason: '',
})
const cleanupConfirmed = ref(false)

async function openCleanup() {
  cleanupStep.value = 0
  sentPhone.value = ''
  cleanupForm.code = ''
  cleanupForm.reason = ''
  cleanupConfirmed.value = false
  cleanupVisible.value = true
  if (!previewInfo.value) await loadPreview()
}

/** 第一步 → 第二步：发送短信验证码（发送至当前管理员绑定手机） */
async function sendCode() {
  sendingCode.value = true
  try {
    const res = await sendCleanupCode()
    sentPhone.value = res?.phone ?? ''
    cleanupStep.value = 1
  } catch {
    /* 全局提示 */
  } finally {
    sendingCode.value = false
  }
}

function prevStep() {
  if (cleanupStep.value > 0) cleanupStep.value -= 1
}

/** 第三步执行条件：验证码 + 原因（≥5 字）+ 确认勾选 */
const canExecute = computed(
  () =>
    !!cleanupForm.code.trim() &&
    cleanupForm.reason.trim().length >= 5 &&
    cleanupConfirmed.value,
)

async function execute() {
  if (!canExecute.value) return
  executing.value = true
  try {
    const res = await executeCleanup({
      code: cleanupForm.code.trim(),
      reason: cleanupForm.reason.trim(),
    })
    cleanupVisible.value = false
    await loadStats()
    await pager.refresh()
    loadPreview()
    ElMessageBox.alert(
      `清库执行完成：影响用户 ${res?.affectedUsers ?? 0} 人、订单 ${res?.affectedOrders ?? 0} 单，耗时 ${res?.executionTime ?? 0} 秒。` +
        `备份文件：${res?.backupPath ?? '-'}（请异地妥善保管）`,
      '清库执行结果',
      { confirmButtonText: '知道了', type: 'success' },
    ).catch(() => {})
  } catch {
    /* 全局提示 */
  } finally {
    executing.value = false
  }
}

/** 短信校验是否开启（system_configs.cleanup_sms_required，关闭时后端跳过校验但仍要求 code 参数非空） */
const smsRequired = computed(() => previewInfo.value?.smsRequired !== false)
</script>

<template>
  <div class="page-container">
    <!-- 顶部统计卡 -->
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

    <!-- 一键清库危险操作区（platform:cleanup） -->
    <div v-permission="'platform:cleanup'" class="danger-card" v-loading="previewLoading">
      <div class="danger-head">
        <el-icon :size="20" color="var(--sn-red)"><WarningFilled /></el-icon>
        <span class="danger-title">一键清库（极高风险操作）</span>
      </div>
      <div class="danger-desc">
        将清空全部业务数据（交易、资产、盲盒、营销、工单、黑名单等白名单表，含全部用户账号），执行前系统自动全量备份（mysqldump 落盘）。
        操作需短信验证码二次确认，全程写入审计日志；管理员/角色/权限、支付渠道、系统参数等配置表不会清理。
      </div>
      <div class="danger-meta">
        <template v-if="previewInfo">
          待清理 <b>{{ previewTables.length }}</b> 张表 · 共 <b>{{ previewInfo.totalRows ?? 0 }}</b> 行数据
          <el-divider direction="vertical" />
          短信校验：{{ smsRequired ? '已开启' : '已关闭' }}
        </template>
        <template v-else>清库影响面预览加载中…</template>
      </div>
      <div class="danger-actions">
        <el-button type="danger" @click="openCleanup">查看清库预览</el-button>
      </div>
    </div>

    <!-- 搜索栏 -->
    <div class="search-bar">
      <el-form inline @submit.prevent>
        <el-form-item label="操作人">
          <el-input
            v-model="pager.filters.admin_name"
            placeholder="操作人姓名模糊搜索"
            clearable
            style="width: 200px"
            @keyup.enter="pager.search"
            @clear="pager.search"
          />
        </el-form-item>
        <el-form-item label="执行时间">
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

    <!-- 清库历史日志表格 -->
    <div class="table-card">
      <div class="table-header">
        <span class="table-title">清库历史日志</span>
        <span class="table-tip">每次清库无论成败均记录日志（含自动备份文件路径），日志表本身不会被清空</span>
      </div>

      <el-table v-loading="pager.loading" :data="pager.list" border stripe>
        <el-table-column label="时间" width="170">
          <template #default="{ row }">{{ datetime(row.created_at) }}</template>
        </el-table-column>
        <el-table-column label="操作人" min-width="130">
          <template #default="{ row }">
            <div>{{ row.admin_name || '-' }}</div>
            <div class="cell-sub">{{ maskPhone(row.admin_phone) }}</div>
          </template>
        </el-table-column>
        <el-table-column label="清库原因" min-width="160" show-overflow-tooltip>
          <template #default="{ row }">{{ row.reason || '-' }}</template>
        </el-table-column>
        <el-table-column label="清理表数" width="90" align="center">
          <template #default="{ row }">
            <span v-if="previewTables.length">{{ previewTables.length }}</span>
            <span v-else class="cell-sub">-</span>
          </template>
        </el-table-column>
        <el-table-column label="影响用户" width="95" align="right">
          <template #default="{ row }">{{ row.affected_users ?? 0 }}</template>
        </el-table-column>
        <el-table-column label="影响订单" width="95" align="right">
          <template #default="{ row }">{{ row.affected_orders ?? 0 }}</template>
        </el-table-column>
        <el-table-column label="结果" width="90" align="center">
          <template #default="{ row }">
            <el-tooltip v-if="Number(row.status) === 2 && row.error_message" :content="row.error_message" placement="top">
              <el-tag type="danger" size="small">失败</el-tag>
            </el-tooltip>
            <el-tag v-else :type="Number(row.status) === 1 ? 'success' : 'danger'" size="small">
              {{ Number(row.status) === 1 ? '成功' : '失败' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="耗时" width="90" align="right">
          <template #default="{ row }">{{ row.execution_time ?? 0 }} 秒</template>
        </el-table-column>
        <el-table-column label="备份文件" min-width="200" show-overflow-tooltip>
          <template #default="{ row }">
            <span class="mono">{{ row.backup_path || '-' }}</span>
          </template>
        </el-table-column>
        <el-table-column label="操作IP" width="130">
          <template #default="{ row }">
            <span class="mono">{{ row.ip || '-' }}</span>
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

    <!-- 一键清库三步确认弹窗 -->
    <el-dialog v-model="cleanupVisible" title="一键清库" width="760px" destroy-on-close :close-on-click-modal="false">
      <el-steps :active="cleanupStep" finish-status="success" align-center style="margin-bottom: 20px">
        <el-step title="影响面预览" />
        <el-step title="短信验证" />
        <el-step title="确认执行" />
      </el-steps>

      <!-- 第一步：清库预览 -->
      <template v-if="cleanupStep === 0">
        <el-alert
          type="error"
          :closable="false"
          show-icon
          title="以下业务数据表将被全部清空（TRUNCATE），操作不可恢复！执行前系统将自动全量备份。"
          style="margin-bottom: 14px"
        />
        <div v-loading="previewLoading" class="preview-box">
          <el-table :data="previewTables" border size="small" max-height="320">
            <el-table-column prop="table" label="数据表" min-width="240">
              <template #default="{ row }">
                <span class="mono">{{ row.table }}</span>
              </template>
            </el-table-column>
            <el-table-column prop="rows" label="当前行数" width="120" align="right" />
          </el-table>
          <div class="preview-total">
            共 <b>{{ previewTables.length }}</b> 张表 · <b>{{ previewInfo?.totalRows ?? 0 }}</b> 行数据将被清空
          </div>
          <el-divider content-position="left">受保护配置（不会清理）</el-divider>
          <div class="protected-tags">
            <el-tag v-for="p in previewInfo?.protected ?? []" :key="p" type="success" size="small" effect="plain">
              {{ p }}
            </el-tag>
          </div>
        </div>
      </template>

      <!-- 第二步：短信验证 + 清库原因 -->
      <template v-else-if="cleanupStep === 1">
        <el-alert
          type="success"
          :closable="false"
          show-icon
          :title="`验证码已发送至当前管理员绑定手机 ${sentPhone || '（脱敏号）'}，请查收短信`"
          style="margin-bottom: 16px"
        />
        <el-alert
          v-if="!smsRequired"
          type="info"
          :closable="false"
          show-icon
          title="当前环境已关闭短信校验（cleanup_sms_required=0），可输入任意 6 位数字验证码"
          style="margin-bottom: 16px"
        />
        <el-form label-width="90px">
          <el-form-item label="短信验证码" required>
            <div class="code-row">
              <el-input
                v-model="cleanupForm.code"
                placeholder="请输入 6 位验证码"
                maxlength="6"
                style="width: 200px"
              />
              <el-button link type="primary" :loading="sendingCode" @click="sendCode">重新发送验证码</el-button>
            </div>
          </el-form-item>
          <el-form-item label="清库原因" required>
            <el-input
              v-model="cleanupForm.reason"
              type="textarea"
              :rows="3"
              maxlength="255"
              show-word-limit
              placeholder="必填，不少于 5 字（如：联调环境重置数据，准备验收演示），将写入清库日志与审计记录"
            />
          </el-form-item>
        </el-form>
      </template>

      <!-- 第三步：最终确认 -->
      <template v-else>
        <el-alert
          type="error"
          :closable="false"
          show-icon
          title="最终确认：即将清空全部业务数据（含全部用户账号），执行后不可恢复！"
          style="margin-bottom: 16px"
        />
        <el-descriptions :column="1" border>
          <el-descriptions-item label="清理范围">
            {{ previewTables.length }} 张业务表 · 共 {{ previewInfo?.totalRows ?? 0 }} 行数据（另含全部用户账号）
          </el-descriptions-item>
          <el-descriptions-item label="自动备份">
            执行前 mysqldump 全量备份至 runtime/backup/
          </el-descriptions-item>
          <el-descriptions-item label="清库原因">{{ cleanupForm.reason }}</el-descriptions-item>
        </el-descriptions>
        <el-checkbox v-model="cleanupConfirmed" class="confirm-check">
          我已知晓该操作将清空全部业务数据（含全部用户账号与交易资产），且执行后不可恢复
        </el-checkbox>
      </template>

      <template #footer>
        <el-button @click="cleanupVisible = false">取消</el-button>
        <el-button v-if="cleanupStep === 1" @click="prevStep">上一步</el-button>
        <el-button v-if="cleanupStep === 2" @click="prevStep">上一步</el-button>
        <el-button
          v-if="cleanupStep === 0"
          type="danger"
          :loading="sendingCode || previewLoading"
          :disabled="!previewTables.length"
          @click="sendCode"
        >已确认影响面，发送验证码</el-button>
        <el-button
          v-if="cleanupStep === 1"
          type="danger"
          :disabled="!cleanupForm.code.trim() || cleanupForm.reason.trim().length < 5"
          @click="cleanupStep = 2"
        >下一步</el-button>
        <el-button
          v-if="cleanupStep === 2"
          type="danger"
          :loading="executing"
          :disabled="!canExecute"
          @click="execute"
        >确认执行清库</el-button>
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

.cell-sub {
  font-size: 12px;
  color: var(--sn-text-secondary);
}

.mono {
  font-family: 'JetBrains Mono', Menlo, Consolas, monospace;
  font-size: 12px;
}

// 危险操作区（红色警示卡片）
.danger-card {
  background: var(--sn-red-bg);
  border: 1px solid rgba(176, 0, 0, 0.35);
  border-radius: 8px;
  padding: 16px;
  margin-bottom: 12px;

  .danger-head {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 10px;

    .danger-title {
      font-size: 15px;
      font-weight: 600;
      color: var(--sn-red-dark);
    }
  }

  .danger-desc {
    font-size: 13px;
    line-height: 1.8;
    color: var(--sn-text);
    margin-bottom: 10px;
  }

  .danger-meta {
    font-size: 12px;
    color: var(--sn-text-secondary);
    margin-bottom: 12px;

    b {
      color: var(--sn-red);
      font-size: 14px;
    }
  }

  .danger-actions {
    display: flex;
    justify-content: flex-end;
  }
}

.preview-box {
  min-height: 120px;

  .preview-total {
    margin-top: 10px;
    font-size: 13px;
    text-align: right;

    b {
      color: var(--sn-red);
    }
  }

  .protected-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
  }
}

.code-row {
  display: flex;
  align-items: center;
  gap: 12px;
}

.confirm-check {
  margin-top: 14px;

  :deep(.el-checkbox__label) {
    font-size: 13px;
    color: var(--sn-red-dark);
  }
}
</style>
