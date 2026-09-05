<script setup>
import { ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { getTickets, replyTicket, closeTicket } from '@/api'
import AdminTablePage from '@/components/AdminTablePage.vue'
import StatusTag from '@/components/StatusTag.vue'
import { TICKET_STATUS, TICKET_PRIORITY, TICKET_TYPE } from '@/utils/maps'
import { maskPhone } from '@/utils/format'

const filters = [
  {
    field: 'status',
    label: '状态',
    options: [
      { value: 'pending', label: '待处理' },
      { value: 'processing', label: '处理中' },
      { value: 'closed', label: '已关闭' }
    ]
  },
  {
    field: 'type',
    label: '类型',
    options: Object.entries(TICKET_TYPE).map(([value, label]) => ({ value, label }))
  },
  {
    field: 'priority',
    label: '优先级',
    options: [
      { value: 'urgent', label: '紧急' },
      { value: 'high', label: '高' },
      { value: 'normal', label: '普通' }
    ]
  }
]

const listRef = ref(null)

// ---- 工单详情 + 回复 ----
const detailShow = ref(false)
const detail = ref(null)
const replyContent = ref('')

function openDetail(t) {
  detail.value = t
  replyContent.value = ''
  detailShow.value = true
}

async function onReply() {
  if (!replyContent.value.trim()) return ElMessage.warning('请输入回复内容')
  const res = await replyTicket({ id: detail.value.id, content: replyContent.value.trim() })
  if (res.code === 0) {
    ElMessage.success('已回复')
    replyContent.value = ''
    if (detail.value.status === 'pending') detail.value.status = 'processing'
    listRef.value?.refresh()
  }
}

// ---- 关闭工单 ----
async function onClose(t) {
  await ElMessageBox.confirm(
    `确认关闭工单「${t.ticketNo}」？关闭后用户将收到工单已完结通知。`,
    '关闭工单',
    { type: 'warning' }
  )
  const res = await closeTicket(t.id)
  if (res.code === 0) {
    ElMessage.success('已关闭')
    detailShow.value = false
    listRef.value?.refresh()
  }
}
</script>

<template>
  <div class="adm-page tk">
    <AdminTablePage
      ref="listRef"
      :fetch="getTickets"
      :filters="filters"
      :defaults="{ status: 'pending' }"
      search-placeholder="搜索工单号 / 用户 / 标题"
    >
      <template #default="{ items }">
        <el-table-column label="工单号" width="150" fixed="left">
          <template #default="{ row }">
            <div class="tk__no" @click="openDetail(row)">{{ row.ticketNo }}</div>
          </template>
        </el-table-column>
        <el-table-column label="用户" min-width="130">
          <template #default="{ row }">
            <div>{{ row.userName }}</div>
            <div class="t-tertiary" style="font-size: 12px">{{ maskPhone(row.userPhone) }}</div>
          </template>
        </el-table-column>
        <el-table-column label="类型" width="100" align="center">
          <template #default="{ row }">{{ TICKET_TYPE[row.type] || row.type }}</template>
        </el-table-column>
        <el-table-column label="优先级" width="90" align="center">
          <template #default="{ row }">
            <StatusTag :value="row.priority" :map="TICKET_PRIORITY" />
          </template>
        </el-table-column>
        <el-table-column label="标题" min-width="180" show-overflow-tooltip>
          <template #default="{ row }">{{ row.title }}</template>
        </el-table-column>
        <el-table-column label="状态" width="90" align="center">
          <template #default="{ row }">
            <StatusTag :value="row.status" :map="TICKET_STATUS" />
          </template>
        </el-table-column>
        <el-table-column label="创建时间" prop="createTime" width="160" />
        <el-table-column label="操作" width="150" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" size="small" @click="openDetail(row)">详情 / 回复</el-button>
            <el-button
              v-if="row.status !== 'closed'"
              link
              type="danger"
              size="small"
              @click="onClose(row)"
            >关闭</el-button>
          </template>
        </el-table-column>
      </template>
    </AdminTablePage>

    <!-- 工单详情抽屉 -->
    <el-drawer v-model="detailShow" :title="detail ? `工单 · ${detail.ticketNo}` : ''" size="480px">
      <template v-if="detail">
        <div class="tk__kv"><span class="k">用户</span><span class="v">{{ detail.userName }}（{{ maskPhone(detail.userPhone) }}）</span></div>
        <div class="tk__kv"><span class="k">类型</span><span class="v">{{ TICKET_TYPE[detail.type] || detail.type }}</span></div>
        <div class="tk__kv"><span class="k">优先级</span><span class="v"><StatusTag :value="detail.priority" :map="TICKET_PRIORITY" /></span></div>
        <div class="tk__kv"><span class="k">状态</span><span class="v"><StatusTag :value="detail.status" :map="TICKET_STATUS" /></span></div>
        <div class="tk__kv"><span class="k">创建时间</span><span class="v">{{ detail.createTime }}</span></div>
        <div v-if="detail.closeTime" class="tk__kv"><span class="k">关闭时间</span><span class="v">{{ detail.closeTime }}</span></div>

        <div class="tk__section">问题描述</div>
        <div class="tk__content">{{ detail.content }}</div>

        <div class="tk__section">沟通记录（{{ detail.replies.length }} 条）</div>
        <div class="tk__replies">
          <div v-if="!detail.replies.length" class="t-tertiary" style="font-size: 12px; padding: 4px 0">暂无回复</div>
          <div v-for="r in detail.replies" :key="r.id" class="tk__reply">
            <div class="tk__reply-head">
              <b :class="{ 'tk__reply-admin': r.author !== detail.userName }">{{ r.author }}</b>
              <span class="t-tertiary">{{ r.time }}</span>
            </div>
            <div class="tk__reply-body">{{ r.content }}</div>
          </div>
        </div>

        <!-- 回复区 -->
        <div v-if="detail.status !== 'closed'" class="tk__reply-box">
          <el-input
            v-model="replyContent"
            type="textarea"
            :rows="3"
            maxlength="300"
            show-word-limit
            placeholder="输入回复内容…"
          />
          <div class="tk__reply-actions">
            <el-button size="small" @click="onClose(detail)">关闭工单</el-button>
            <el-button type="primary" size="small" @click="onReply">发送回复</el-button>
          </div>
        </div>
      </template>
    </el-drawer>
  </div>
</template>

<style scoped lang="scss">
.tk__no {
  color: var(--color-primary);
  cursor: pointer;
  font-weight: 500;

  &:hover { text-decoration: underline; }
}

.tk__kv {
  display: flex;
  gap: 12px;
  padding: 6px 0;
  border-bottom: 1px dashed $color-border;
  font-size: 13px;

  .k { color: $color-text-tertiary; width: 70px; flex-shrink: 0; }
  .v { color: $color-text-primary; }
}

.tk__section {
  font-weight: 600;
  color: $color-text-primary;
  margin: 16px 0 8px;
  font-size: 13px;
}

.tk__content {
  background: $color-surface;
  border-radius: 8px;
  padding: 12px;
  font-size: 13px;
  color: $color-text-secondary;
  line-height: 1.6;
}

.tk__reply {
  background: $color-bg;
  border-radius: 8px;
  padding: 10px 12px;
  margin-bottom: 8px;

  &-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 12px;
    margin-bottom: 4px;
  }

  &-admin { color: var(--color-primary); }

  &-body {
    font-size: 13px;
    color: $color-text-secondary;
    line-height: 1.6;
  }
}

.tk__reply-box {
  margin-top: 16px;
  display: flex;
  flex-direction: column;
  gap: 8px;

  &-actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
  }
}
</style>
