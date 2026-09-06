<script setup lang="ts">
/**
 * 客服工单（ticket:list / ticket:manage）
 *
 * - 搜索：关键词（工单号/标题/UID）、类型、状态、时间区间（后端 keyword / ticket_type / status / startDate/endDate）
 * - 表格：字段与 TicketController::list 返回严格一致
 *   （该控制器未调用 camelize_keys，列表字段为下划线命名，如 ticket_no / status_name / last_reply_at）
 * - 操作（ticket:manage）：详情（抽屉）/ 受理（assignTicket，分配给自己）/ 回复（replyTicket）/ 关闭（changeTicketStatus）
 * - 状态机（与后端一致，5 态）：1待处理 → 2处理中 → 3待用户确认 → 4已解决 / 5已关闭（终态）
 *   注：format.ts 的 TICKET_STATUS 为旧版 4 态枚举，与后端不一致，此处按后端本地维护
 */
import { computed, nextTick, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useListPage } from '@/utils/useListPage'
import type { PageResult } from '@/utils/useListPage'
import { datetime, maskPhone } from '@/utils/format'
import {
  assignTicket,
  changeTicketStatus,
  fetchTicketDetail,
  fetchTickets,
  replyTicket,
} from '@/api/misc'

/** 列表接口实际返回（后端额外附带 summary 概览统计，misc.ts 未声明该字段） */
interface ListResult extends PageResult {
  summary?: Record<string, number>
}

/** 工单类型（与后端 TicketController::TICKET_TYPES 一致） */
const TICKET_TYPES: Record<number, string> = {
  1: '支付异常', 2: '藏品丢失', 3: '盲盒问题', 4: '转赠纠纷', 5: '账号问题', 6: '其他',
}
const typeOptions = Object.entries(TICKET_TYPES).map(([k, v]) => ({ value: Number(k), label: v }))

/** 优先级：1紧急 2高 3中 4低 */
const PRIORITY_MAP: Record<number, { text: string; type: string }> = {
  1: { text: '紧急', type: 'danger' },
  2: { text: '高', type: 'warning' },
  3: { text: '中', type: 'primary' },
  4: { text: '低', type: 'info' },
}

/** 工单状态（与后端 TicketController::STATUSES 一致，5 态） */
const STATUS_MAP: Record<number, { text: string; type: string }> = {
  1: { text: '待处理', type: 'warning' },
  2: { text: '处理中', type: 'primary' },
  3: { text: '待用户确认', type: 'warning' },
  4: { text: '已解决', type: 'success' },
  5: { text: '已关闭', type: 'info' },
}
const statusOptions = Object.entries(STATUS_MAP).map(([k, v]) => ({ value: Number(k), label: v.text }))

/** 回复发送者：1用户 2客服 3系统 */
const SENDER_TYPE: Record<number, { text: string; type: string }> = {
  1: { text: '用户', type: 'info' },
  2: { text: '客服', type: 'primary' },
  3: { text: '系统', type: 'warning' },
}

function priorityTag(priority?: number) {
  return PRIORITY_MAP[Number(priority)] ?? { text: String(priority ?? '-'), type: 'info' }
}

function statusTag(status?: number) {
  return STATUS_MAP[Number(status)] ?? { text: String(status ?? '-'), type: 'info' }
}

function senderTag(type?: number) {
  return SENDER_TYPE[Number(type)] ?? { text: '未知', type: 'info' }
}

/** 时间线节点颜色：用户蓝 / 客服红 / 系统灰 */
function senderColor(type?: number) {
  return { 1: '#1565c0', 2: '#b00000', 3: '#909399' }[Number(type)] ?? '#b00000'
}

interface TicketRow {
  id: number
  ticket_no: string
  user_id: number
  uid: string
  phone: string
  ticket_type: number
  ticket_type_name: string
  priority: number
  priority_name: string
  title: string
  description?: string
  status: number
  status_name: string
  assignee_id?: number
  assignee_name?: string
  last_reply_at?: string
  last_reply_by?: string
  reply_count: number
  created_at: string
  updated_at: string
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
  const res = (await fetchTickets(buildQuery(params))) as ListResult
  summary.value = res?.summary ?? {}
  return res
}, {
  keyword: '',
  ticket_type: '',
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
  { label: '待处理', value: summary.value.pending ?? 0, sub: '等待受理', icon: 'Bell', color: '' },
  { label: '处理中', value: summary.value.handling ?? 0, sub: '跟进中的工单', icon: 'Timer', color: 'blue' },
  { label: '待用户确认', value: summary.value.confirming ?? 0, sub: '等待用户反馈', icon: 'ChatDotRound', color: 'orange' },
  { label: '已解决', value: summary.value.solved ?? 0, sub: '本月累计闭环', icon: 'CircleCheck', color: 'green' },
  { label: '已关闭', value: summary.value.closed ?? 0, sub: '终态工单', icon: 'CircleClose', color: 'gray' },
  { label: '紧急未结', value: summary.value.urgentOpen ?? 0, sub: '紧急且未闭环', icon: 'AlarmClock', color: 'purple' },
])

// ===== 受理（分配给自己，待处理自动进入处理中）=====

async function handleAssign(row: TicketRow) {
  try {
    await ElMessageBox.confirm(
      `确认受理工单 ${row.ticket_no}？受理后工单将分配给当前登录账号，待处理工单自动进入「处理中」状态`,
      '受理工单',
      { confirmButtonText: '确认受理', cancelButtonText: '取消', type: 'info' },
    )
    await assignTicket(row.id, {})
    await pager.done('工单已受理，分配给当前账号')
  } catch {
    /* 弹窗取消或接口失败（错误已全局提示） */
  }
}

// ===== 状态变更（changeTicketStatus：3待用户确认 / 4已解决 / 5已关闭）=====

async function changeStatus(ticket: any, status: number) {
  const label = STATUS_MAP[status]?.text ?? String(status)
  // 关闭走必填原因弹窗
  if (status === 5) return handleClose(ticket)
  try {
    await ElMessageBox.confirm(
      `确认将工单 ${ticket.ticket_no} 状态变更为「${label}」？`,
      '工单状态变更',
      { confirmButtonText: '确认变更', cancelButtonText: '取消', type: 'warning' },
    )
    await changeTicketStatus(ticket.id, { status })
    if (drawerVisible.value && detail.value?.ticket?.id === ticket.id) {
      await loadDetail(ticket.id)
    }
    await pager.done(`工单状态已更新为「${label}」`)
  } catch {
    /* 弹窗取消或接口失败（错误已全局提示） */
  }
}

/** 关闭工单（必填关闭原因；后端 changeStatus 仅读取 status，reason 为冗余传参便于后端留痕扩展） */
async function handleClose(ticket: any) {
  try {
    const { value } = await ElMessageBox.prompt(
      `确认关闭工单 ${ticket.ticket_no}？关闭后工单进入终态，不可再回复或流转`,
      '关闭工单',
      {
        confirmButtonText: '确认关闭',
        cancelButtonText: '取消',
        type: 'warning',
        inputType: 'textarea',
        inputPlaceholder: '请输入关闭原因（必填，将写入审计日志）',
        inputValidator: (v: string) => (v && v.trim() ? true : '请填写关闭原因'),
      },
    )
    await changeTicketStatus(ticket.id, { status: 5, reason: value.trim() })
    if (drawerVisible.value && detail.value?.ticket?.id === ticket.id) {
      await loadDetail(ticket.id)
    }
    await pager.done('工单已关闭')
  } catch {
    /* 弹窗取消或接口失败（错误已全局提示） */
  }
}

// ===== 详情抽屉（工单信息 + 回复时间线 + 回复输入框）=====

const drawerVisible = ref(false)
const detailLoading = ref(false)
const detail = ref<any>(null)
const focusReply = ref(false)
const replyInputRef = ref()

const replyContent = ref('')
const replyInternal = ref(false)
const replySending = ref(false)

async function openDetail(row: TicketRow, focus = false) {
  drawerVisible.value = true
  focusReply.value = focus
  replyContent.value = ''
  replyInternal.value = false
  await loadDetail(row.id)
}

async function loadDetail(id: number) {
  detailLoading.value = true
  detail.value = null
  try {
    detail.value = await fetchTicketDetail(id)
    if (focusReply.value) {
      focusReply.value = false
      await nextTick()
      replyInputRef.value?.focus?.()
    }
  } catch {
    drawerVisible.value = false
  } finally {
    detailLoading.value = false
  }
}

const ticket = computed(() => detail.value?.ticket ?? null)
const replies = computed(() => detail.value?.replies ?? [])

/** 发送回复（replyTicket：content 必填；is_internal=1 时用户不可见） */
async function sendReply() {
  const content = replyContent.value.trim()
  if (!content) {
    ElMessage.warning('请输入回复内容')
    return
  }
  if (!ticket.value) return
  const id = Number(ticket.value.id)
  const isInternal = replyInternal.value
  replySending.value = true
  try {
    await replyTicket(id, {
      content,
      is_internal: isInternal ? 1 : 0,
    })
    replyContent.value = ''
    replyInternal.value = false
    await loadDetail(id)
    await pager.done(isInternal ? '内部备注已添加' : '回复已发送')
  } catch {
    /* 全局提示 */
  } finally {
    replySending.value = false
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
            placeholder="工单号 / 标题 / 用户UID"
            clearable
            style="width: 200px"
            @keyup.enter="pager.search"
            @clear="pager.search"
          />
        </el-form-item>
        <el-form-item label="类型">
          <el-select
            v-model="pager.filters.ticket_type"
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
            <el-option v-for="opt in statusOptions" :key="opt.value" :label="opt.label" :value="opt.value" />
          </el-select>
        </el-form-item>
        <el-form-item label="创建时间">
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

    <!-- 工单表格 -->
    <div class="table-card">
      <div class="table-header">
        <span class="table-title">客服工单</span>
        <span class="table-tip">列表按优先级排序；已分配工单仅处理人或超级管理员可回复</span>
      </div>

      <el-table v-loading="pager.loading" :data="pager.list" border stripe>
        <el-table-column prop="ticket_no" label="工单号" width="165" fixed="left" show-overflow-tooltip>
          <template #default="{ row }">
            <span class="mono">{{ row.ticket_no }}</span>
          </template>
        </el-table-column>
        <el-table-column label="用户" min-width="140">
          <template #default="{ row }">
            <div>{{ row.uid || row.user_id }}</div>
            <div class="cell-sub">{{ maskPhone(row.phone) }}</div>
          </template>
        </el-table-column>
        <el-table-column label="标题 / 摘要" min-width="220">
          <template #default="{ row }">
            <div class="cell-title">{{ row.title || '-' }}</div>
            <div class="cell-sub">{{ row.description || '-' }}</div>
          </template>
        </el-table-column>
        <el-table-column label="类型" width="100" align="center">
          <template #default="{ row }">
            <el-tag size="small" effect="plain">{{ row.ticket_type_name || TICKET_TYPES[Number(row.ticket_type)] || '-' }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="优先级" width="85" align="center">
          <template #default="{ row }">
            <el-tag :type="priorityTag(row.priority).type" size="small">{{ row.priority_name || priorityTag(row.priority).text }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="105" align="center">
          <template #default="{ row }">
            <el-tag :type="statusTag(row.status).type" size="small">{{ row.status_name || statusTag(row.status).text }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="处理人" width="100" show-overflow-tooltip>
          <template #default="{ row }">
            <span v-if="row.assignee_name">{{ row.assignee_name }}</span>
            <el-tag v-else type="info" size="small" effect="plain">未分配</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="最后更新时间" width="185">
          <template #default="{ row }">
            <div>{{ datetime(row.updated_at) }}</div>
            <div class="cell-sub">回复 {{ row.reply_count ?? 0 }} 条<template v-if="row.last_reply_at"> · 最后 {{ datetime(row.last_reply_at).slice(5, 16) }}</template></div>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="215" fixed="right" align="center">
          <template #default="{ row }">
            <el-button link type="primary" size="small" @click="openDetail(row)">详情</el-button>
            <el-button
              v-if="Number(row.status) === 1"
              v-permission="'ticket:manage'"
              link
              type="success"
              size="small"
              @click="handleAssign(row)"
            >受理</el-button>
            <el-button
              v-if="Number(row.status) !== 5"
              v-permission="'ticket:manage'"
              link
              type="primary"
              size="small"
              @click="openDetail(row, true)"
            >回复</el-button>
            <el-button
              v-if="Number(row.status) !== 5"
              v-permission="'ticket:manage'"
              link
              type="danger"
              size="small"
              @click="handleClose(row)"
            >关闭</el-button>
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

    <!-- 工单详情抽屉 -->
    <el-drawer v-model="drawerVisible" title="工单详情" size="62%">
      <div v-loading="detailLoading" class="detail-body">
        <template v-if="ticket">
          <!-- 状态流转 -->
          <div class="flow-bar">
            <span class="flow-label">状态流转：</span>
            <el-button
              v-if="Number(ticket.status) === 2"
              v-permission="'ticket:manage'"
              size="small"
              @click="changeStatus(ticket, 3)"
            >转待用户确认</el-button>
            <el-button
              v-if="[2, 3].includes(Number(ticket.status))"
              v-permission="'ticket:manage'"
              size="small"
              type="success"
              plain
              @click="changeStatus(ticket, 4)"
            >标记已解决</el-button>
            <el-button
              v-if="Number(ticket.status) !== 5"
              v-permission="'ticket:manage'"
              size="small"
              type="danger"
              plain
              @click="handleClose(ticket)"
            >关闭工单</el-button>
          </div>

          <!-- 工单信息 -->
          <div class="detail-section">
            <div class="section-title">工单信息</div>
            <el-descriptions :column="2" border>
              <el-descriptions-item label="工单号">
                <span class="mono">{{ ticket.ticket_no || '-' }}</span>
              </el-descriptions-item>
              <el-descriptions-item label="状态">
                <el-tag :type="statusTag(ticket.status).type" size="small">
                  {{ ticket.status_name || statusTag(ticket.status).text }}
                </el-tag>
              </el-descriptions-item>
              <el-descriptions-item label="类型">
                {{ ticket.ticket_type_name || TICKET_TYPES[Number(ticket.ticket_type)] || '-' }}
              </el-descriptions-item>
              <el-descriptions-item label="优先级">
                <el-tag :type="priorityTag(ticket.priority).type" size="small">
                  {{ ticket.priority_name || priorityTag(ticket.priority).text }}
                </el-tag>
              </el-descriptions-item>
              <el-descriptions-item label="用户UID">{{ ticket.uid || ticket.user_id }}</el-descriptions-item>
              <el-descriptions-item label="用户昵称">{{ ticket.username || '-' }}</el-descriptions-item>
              <el-descriptions-item label="手机号">{{ maskPhone(ticket.phone) }}</el-descriptions-item>
              <el-descriptions-item label="处理人">{{ ticket.assignee_name || '未分配' }}</el-descriptions-item>
              <el-descriptions-item label="创建时间">{{ datetime(ticket.created_at) }}</el-descriptions-item>
              <el-descriptions-item label="更新时间">{{ datetime(ticket.updated_at) }}</el-descriptions-item>
              <el-descriptions-item v-if="ticket.solved_at" label="解决时间">{{ datetime(ticket.solved_at) }}</el-descriptions-item>
              <el-descriptions-item v-if="ticket.closed_at" label="关闭时间">{{ datetime(ticket.closed_at) }}</el-descriptions-item>
              <el-descriptions-item v-if="ticket.related_order_no" label="关联订单">
                <span class="mono">{{ ticket.related_order_no }}</span>
              </el-descriptions-item>
              <el-descriptions-item v-if="ticket.related_collectible_name" label="关联藏品">
                {{ ticket.related_collectible_name }}
              </el-descriptions-item>
              <el-descriptions-item label="标题" :span="2">{{ ticket.title || '-' }}</el-descriptions-item>
              <el-descriptions-item label="问题描述" :span="2">
                <div class="desc-text">{{ ticket.description || '-' }}</div>
              </el-descriptions-item>
            </el-descriptions>
          </div>

          <!-- 回复记录时间线 -->
          <div class="detail-section">
            <div class="section-title">沟通记录（{{ replies.length }} 条）</div>
            <el-empty v-if="!replies.length" description="暂无沟通记录" :image-size="70" />
            <el-timeline v-else>
              <el-timeline-item
                v-for="item in replies"
                :key="item.id"
                :timestamp="datetime(item.created_at)"
                placement="top"
                :color="senderColor(item.sender_type)"
              >
                <div class="reply-card" :class="{ internal: Number(item.is_internal) === 1 }">
                  <div class="reply-head">
                    <span class="reply-sender">{{ item.sender_name || '-' }}</span>
                    <el-tag :type="senderTag(item.sender_type).type" size="small" effect="plain">
                      {{ senderTag(item.sender_type).text }}
                    </el-tag>
                    <el-tag v-if="Number(item.is_internal) === 1" type="warning" size="small" effect="plain">
                      内部备注（用户不可见）
                    </el-tag>
                  </div>
                  <div class="reply-content">{{ item.content }}</div>
                </div>
              </el-timeline-item>
            </el-timeline>
          </div>

          <!-- 回复输入框 -->
          <div class="detail-section reply-section">
            <div class="section-title">回复工单</div>
            <el-alert
              v-if="Number(ticket.status) === 5"
              type="info"
              :closable="false"
              show-icon
              title="工单已关闭（终态），不可再回复"
              style="margin-bottom: 12px"
            />
            <template v-else>
              <el-input
                ref="replyInputRef"
                v-model="replyContent"
                type="textarea"
                :rows="4"
                maxlength="5000"
                show-word-limit
                placeholder="输入回复内容（5000 字以内）；勾选「内部备注」则该回复仅管理端可见"
              />
              <div class="reply-actions">
                <el-checkbox v-model="replyInternal">内部备注（用户不可见，仅协查）</el-checkbox>
                <el-button
                  v-permission="'ticket:manage'"
                  type="primary"
                  :loading="replySending"
                  :disabled="!replyContent.trim()"
                  @click="sendReply"
                >发送回复</el-button>
              </div>
            </template>
          </div>
        </template>
      </div>
    </el-drawer>
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

.detail-body {
  min-height: 200px;
}

.flow-bar {
  display: flex;
  align-items: center;
  gap: 8px;
  background: #fff;
  border: 1px solid var(--sn-border);
  border-radius: 8px;
  padding: 10px 14px;
  margin-bottom: 12px;

  .flow-label {
    font-size: 13px;
    color: var(--sn-text-secondary);
    margin-right: 4px;
  }
}

.desc-text {
  white-space: pre-wrap;
  word-break: break-all;
}

.reply-card {
  border: 1px solid var(--sn-border);
  border-radius: 8px;
  padding: 10px 12px;
  background: #fafafa;

  &.internal {
    background: #fdf6ec;
    border-color: #f3d19e;
  }

  .reply-head {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 6px;

    .reply-sender {
      font-weight: 600;
      font-size: 13px;
    }
  }

  .reply-content {
    font-size: 13px;
    line-height: 1.7;
    white-space: pre-wrap;
    word-break: break-all;
  }
}

.reply-section {
  .reply-actions {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 10px;
  }
}
</style>
