<script setup>
import { ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import { getAnnouncements, saveAnnouncement, removeAnnouncement } from '@/api'
import AdminTablePage from '@/components/AdminTablePage.vue'
import StatusTag from '@/components/StatusTag.vue'
import RichTextEditor from '@/components/RichTextEditor.vue'
import { NOTICE_TYPE } from '@/utils/maps'

const filters = [
  {
    field: 'type',
    label: '类型',
    options: [
      { value: 'activity', label: '活动公告' },
      { value: 'compose', label: '合成公告' },
      { value: 'operation', label: '运营公告' }
    ]
  },
  {
    field: 'status',
    label: '状态',
    options: [
      { value: 'published', label: '已发布' },
      { value: 'draft', label: '草稿' }
    ]
  }
]

const listRef = ref(null)
const editShow = ref(false)
const editing = ref(null)
const submitting = ref(false)
const form = ref(emptyForm())

function emptyForm() {
  return {
    title: '',
    type: 'operation',
    summary: '',
    content: '',
    // 发布方式：now 立即发布 / schedule 定时发布 / draft 存草稿
    mode: 'now',
    publishTime: ''
  }
}

// 展示状态：草稿 / 定时中（未到时间）/ 已发布
function displayStatus(a) {
  if (a.status === 'draft') return 'draft'
  if (a.publishTime && new Date(a.publishTime.replace(/-/g, '/')).getTime() > Date.now()) return 'scheduled'
  return 'published'
}

const STATUS_MAP = {
  draft: { label: '草稿', type: 'info' },
  scheduled: { label: '定时中', type: 'warning' },
  published: { label: '已发布', type: 'success' }
}

// 纯文本内容（校验非空用）
function plainContent(html) {
  return String(html || '').replace(/<[^>]*>/g, '').replace(/&nbsp;/g, ' ').trim()
}

function openCreate() {
  editing.value = null
  form.value = emptyForm()
  editShow.value = true
}

function openEdit(a) {
  editing.value = a
  const scheduled = a.status === 'published' && a.publishTime
    && new Date(a.publishTime.replace(/-/g, '/')).getTime() > Date.now()
  form.value = {
    title: a.title,
    type: a.type,
    summary: a.summary || '',
    content: a.content || '',
    mode: a.status === 'draft' ? 'draft' : scheduled ? 'schedule' : 'now',
    publishTime: scheduled ? a.publishTime : ''
  }
  editShow.value = true
}

async function onSave() {
  const f = form.value
  if (!f.title.trim()) return ElMessage.warning('请输入公告标题')
  if (!plainContent(f.content) && !f.content.includes('<img')) {
    return ElMessage.warning('请输入公告内容')
  }
  if (f.mode === 'schedule' && !f.publishTime) {
    return ElMessage.warning('请选择定时发布时间')
  }

  // 摘要为空时取正文前 100 字
  const summary = f.summary.trim() || plainContent(f.content).slice(0, 100)

  submitting.value = true
  const res = await saveAnnouncement({
    id: editing.value?.id,
    title: f.title.trim(),
    type: f.type,
    summary,
    content: f.content,
    status: f.mode === 'draft' ? 'draft' : 'published',
    publishTime: f.mode === 'schedule' ? f.publishTime : ''
  })
  submitting.value = false
  if (res.code === 0) {
    ElMessage.success(
      editing.value
        ? '已更新'
        : f.mode === 'draft' ? '已存草稿' : f.mode === 'schedule' ? '已设置定时发布' : '已发布'
    )
    editShow.value = false
    listRef.value?.refresh()
  }
}

async function onRemove(a) {
  await ElMessageBox.confirm(`确认删除「${a.title}」？删除后不可恢复。`, '删除公告', { type: 'warning' })
  const res = await removeAnnouncement(a.id)
  if (res.code === 0) {
    ElMessage.success('已删除')
    listRef.value?.refresh()
  }
}
</script>

<template>
  <div class="adm-page an">
    <AdminTablePage ref="listRef" :fetch="getAnnouncements" :filters="filters" search-placeholder="搜索公告标题">
      <template #extra>
        <el-button type="primary" :icon="Plus" @click="openCreate">发布公告</el-button>
      </template>

      <template #default="{ items }">
        <el-table-column label="公告标题" min-width="240" fixed="left" show-overflow-tooltip>
          <template #default="{ row }">{{ row.title }}</template>
        </el-table-column>
        <el-table-column label="类型" width="110">
          <template #default="{ row }">
            <StatusTag :value="row.type" :map="NOTICE_TYPE" />
          </template>
        </el-table-column>
        <el-table-column label="状态" width="100">
          <template #default="{ row }">
            <StatusTag :value="displayStatus(row)" :map="STATUS_MAP" />
          </template>
        </el-table-column>
        <el-table-column label="发布时间" width="170">
          <template #default="{ row }">
            <template v-if="displayStatus(row) === 'scheduled'">
              <div>{{ row.publishTime }}</div>
              <div class="t-tertiary" style="font-size: 12px">定时发布</div>
            </template>
            <template v-else>{{ row.publishTime || row.createdAt || '—' }}</template>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="130" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" size="small" @click="openEdit(row)">编辑</el-button>
            <el-button link type="danger" size="small" @click="onRemove(row)">删除</el-button>
          </template>
        </el-table-column>
      </template>
    </AdminTablePage>

    <!-- 编辑弹窗 -->
    <el-dialog
      v-model="editShow"
      :title="editing ? '编辑公告' : '发布公告'"
      width="760px"
      :close-on-click-modal="false"
      top="6vh"
    >
      <el-form label-width="90px">
        <el-form-item label="公告标题">
          <el-input v-model="form.title" placeholder="请输入标题" maxlength="60" show-word-limit />
        </el-form-item>
        <el-form-item label="公告类型">
          <el-radio-group v-model="form.type">
            <el-radio value="activity">活动</el-radio>
            <el-radio value="compose">合成</el-radio>
            <el-radio value="operation">运营</el-radio>
          </el-radio-group>
          <div class="t-tertiary" style="font-size: 12px; width: 100%">C 端公告列表按此分类展示（活动/合成/运营 Tab）</div>
        </el-form-item>
        <el-form-item label="摘要">
          <el-input
            v-model="form.summary"
            type="textarea"
            :rows="2"
            maxlength="200"
            show-word-limit
            placeholder="列表页展示的摘要（留空自动截取正文前 100 字）"
          />
        </el-form-item>
        <el-form-item label="公告内容">
          <RichTextEditor
            v-model="form.content"
            placeholder="公告正文，支持标题/加粗/颜色/列表/图片等（C 端公告详情展示）"
            :height="280"
          />
        </el-form-item>
        <el-form-item label="发布方式">
          <el-radio-group v-model="form.mode">
            <el-radio value="now">立即发布</el-radio>
            <el-radio value="schedule">定时发布</el-radio>
            <el-radio value="draft">存草稿</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item v-if="form.mode === 'schedule'" label="发布时间">
          <el-date-picker
            v-model="form.publishTime"
            type="datetime"
            placeholder="选择定时发布时间"
            format="YYYY-MM-DD HH:mm:ss"
            value-format="YYYY-MM-DD HH:mm:ss"
            :disabled-date="(d) => d.getTime() < Date.now() - 86400000"
            style="width: 240px"
          />
          <div class="t-tertiary" style="font-size: 12px; margin-top: 4px; width: 100%">
            到达该时间后公告自动在 C 端可见，无需再次操作
          </div>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="editShow = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="onSave">
          {{ form.mode === 'draft' ? '存草稿' : form.mode === 'schedule' ? '设置定时发布' : '立即发布' }}
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>
