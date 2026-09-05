<script setup>
import { ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { getContentAudits, auditContent } from '@/api'
import AdminTablePage from '@/components/AdminTablePage.vue'
import StatusTag from '@/components/StatusTag.vue'
import { AUDIT_STATUS, CONTENT_AUDIT_TYPE } from '@/utils/maps'
import { maskPhone } from '@/utils/format'

const filters = [
  {
    field: 'status',
    label: '状态',
    options: [
      { value: 'pending', label: '待审核' },
      { value: 'approved', label: '已通过' },
      { value: 'rejected', label: '已驳回' }
    ]
  },
  {
    field: 'type',
    label: '类型',
    options: [
      { value: 'ugc_collectible', label: '用户自建藏品' },
      { value: 'community_post', label: '社区帖子' }
    ]
  }
]

const listRef = ref(null)

// ---- 审核详情 ----
const detailShow = ref(false)
const detail = ref(null)

function openDetail(a) {
  detail.value = a
  detailShow.value = true
}

// ---- 通过 ----
async function onApprove(a) {
  await ElMessageBox.confirm(
    `确认通过「${a.title}」的审核？通过后内容将在 C 端展示。`,
    '审核通过',
    { type: 'info' }
  )
  const res = await auditContent({ id: a.id, action: 'approve' })
  if (res.code === 0) {
    ElMessage.success('已通过，审核日志已记录')
    listRef.value?.refresh()
  }
}

// ---- 驳回（需填写原因） ----
async function onReject(a) {
  const { value } = await ElMessageBox.prompt('请填写驳回原因（用户可见）', '驳回内容', {
    type: 'warning',
    inputPlaceholder: '如：图片涉及版权风险，请补充原创声明',
    inputValidator: (v) => (v && v.trim() ? true : '驳回原因不能为空')
  })
  const res = await auditContent({ id: a.id, action: 'reject', reason: value.trim() })
  if (res.code === 0) {
    ElMessage.success('已驳回，审核日志已记录')
    listRef.value?.refresh()
  }
}
</script>

<template>
  <div class="adm-page ca">
    <AdminTablePage
      ref="listRef"
      :fetch="getContentAudits"
      :filters="filters"
      :defaults="{ status: 'pending' }"
      search-placeholder="搜索用户 / 标题 / 内容"
    >
      <template #default="{ items }">
        <el-table-column label="内容标题" min-width="180" fixed="left" show-overflow-tooltip>
          <template #default="{ row }">
            <div class="ca__title" @click="openDetail(row)">{{ row.title }}</div>
          </template>
        </el-table-column>
        <el-table-column label="类型" width="130">
          <template #default="{ row }">
            <el-tag type="info" effect="plain" size="small">{{ CONTENT_AUDIT_TYPE[row.type] || row.type }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="提交用户" min-width="140">
          <template #default="{ row }">
            <div>{{ row.userName }}</div>
            <div class="t-tertiary" style="font-size: 12px">{{ maskPhone(row.userPhone) }}</div>
          </template>
        </el-table-column>
        <el-table-column label="内容摘要" min-width="220" show-overflow-tooltip>
          <template #default="{ row }">
            <span class="t-secondary">{{ row.content }}</span>
          </template>
        </el-table-column>
        <el-table-column label="版权证明" width="100" align="center">
          <template #default="{ row }">
            <el-tag v-if="row.copyright" type="success" effect="plain" size="small">已提交</el-tag>
            <el-tag v-else type="info" effect="plain" size="small">无</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="90" align="center">
          <template #default="{ row }">
            <StatusTag :value="row.status" :map="AUDIT_STATUS" />
          </template>
        </el-table-column>
        <el-table-column label="提交时间" prop="submitTime" width="160" />
        <el-table-column label="操作" width="140" fixed="right">
          <template #default="{ row }">
            <template v-if="row.status === 'pending'">
              <el-button link type="primary" size="small" @click="onApprove(row)">通过</el-button>
              <el-button link type="danger" size="small" @click="onReject(row)">驳回</el-button>
            </template>
            <el-button v-else link type="primary" size="small" @click="openDetail(row)">查看</el-button>
          </template>
        </el-table-column>
      </template>
    </AdminTablePage>

    <!-- 审核详情 -->
    <el-dialog v-model="detailShow" :title="detail ? `审核详情 · ${detail.title}` : ''" width="560px">
      <template v-if="detail">
        <img v-if="detail.cover" class="ca__cover" :src="detail.cover" :alt="detail.title" />
        <div class="ca__kv"><span class="k">类型</span><span class="v">{{ CONTENT_AUDIT_TYPE[detail.type] || detail.type }}</span></div>
        <div class="ca__kv"><span class="k">提交用户</span><span class="v">{{ detail.userName }}（{{ maskPhone(detail.userPhone) }}）</span></div>
        <div class="ca__kv"><span class="k">内容描述</span><span class="v">{{ detail.content }}</span></div>
        <div class="ca__kv"><span class="k">版权证明</span><span class="v">{{ detail.copyright || '未提交' }}</span></div>
        <div class="ca__kv"><span class="k">提交时间</span><span class="v">{{ detail.submitTime }}</span></div>
        <div class="ca__kv"><span class="k">当前状态</span><span class="v"><StatusTag :value="detail.status" :map="AUDIT_STATUS" /></span></div>
        <div v-if="detail.reason" class="ca__kv"><span class="k">驳回原因</span><span class="v" style="color: var(--color-primary)">{{ detail.reason }}</span></div>
      </template>
      <template v-if="detail && detail.status === 'pending'" #footer>
        <el-button @click="detailShow = false">取消</el-button>
        <el-button type="danger" @click="onReject(detail); detailShow = false">驳回</el-button>
        <el-button type="primary" @click="onApprove(detail); detailShow = false">通过</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped lang="scss">
.ca__title {
  color: var(--color-primary);
  cursor: pointer;
  font-weight: 500;

  &:hover { text-decoration: underline; }
}

.ca__cover {
  width: 100%;
  max-height: 220px;
  object-fit: cover;
  border-radius: 8px;
  margin-bottom: 14px;
}

.ca__kv {
  display: flex;
  gap: 12px;
  padding: 7px 0;
  border-bottom: 1px dashed $color-border;
  font-size: 13px;

  .k {
    color: $color-text-tertiary;
    width: 70px;
    flex-shrink: 0;
  }

  .v {
    color: $color-text-primary;
    word-break: break-all;
  }
}
</style>
