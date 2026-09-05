<script setup>
import { ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import { getAnnouncements, saveAnnouncement, removeAnnouncement } from '@/api'
import AdminTablePage from '@/components/AdminTablePage.vue'
import StatusTag from '@/components/StatusTag.vue'
import { NOTICE_TYPE, CONTENT_STATUS } from '@/utils/maps'
import { fmtNumber } from '@/utils/format'

const filters = [
  {
    field: 'type',
    label: '类型',
    options: [
      { value: 'system', label: '系统公告' },
      { value: 'activity', label: '活动公告' },
      { value: 'maintenance', label: '维护公告' }
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
const form = ref({ title: '', type: 'system', content: '' })

function openCreate() {
  editing.value = null
  form.value = { title: '', type: 'system', content: '' }
  editShow.value = true
}

function openEdit(a) {
  editing.value = a
  form.value = { title: a.title, type: a.type, content: a.content || '' }
  editShow.value = true
}

async function onSave() {
  if (!form.value.title.trim()) return ElMessage.warning('请输入公告标题')
  const res = await saveAnnouncement({
    id: editing.value?.id,
    title: form.value.title.trim(),
    type: form.value.type,
    content: form.value.content,
    status: 'published'
  })
  if (res.code === 0) {
    ElMessage.success(editing.value ? '已更新' : '已发布')
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
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <StatusTag :value="row.status" :map="CONTENT_STATUS" />
          </template>
        </el-table-column>
        <el-table-column label="浏览量" width="100" align="right">
          <template #default="{ row }">{{ fmtNumber(row.views) }}</template>
        </el-table-column>
        <el-table-column label="发布时间" prop="publishTime" width="160" />
        <el-table-column label="操作" width="130" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" size="small" @click="openEdit(row)">编辑</el-button>
            <el-button link type="danger" size="small" @click="onRemove(row)">删除</el-button>
          </template>
        </el-table-column>
      </template>
    </AdminTablePage>

    <!-- 编辑弹窗 -->
    <el-dialog v-model="editShow" :title="editing ? '编辑公告' : '发布公告'" width="520px" :close-on-click-modal="false">
      <el-form label-width="90px">
        <el-form-item label="公告标题">
          <el-input v-model="form.title" placeholder="请输入标题" maxlength="60" show-word-limit />
        </el-form-item>
        <el-form-item label="公告类型">
          <el-radio-group v-model="form.type">
            <el-radio value="system">系统</el-radio>
            <el-radio value="activity">活动</el-radio>
            <el-radio value="maintenance">维护</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="公告内容">
          <el-input
            v-model="form.content"
            type="textarea"
            :rows="5"
            maxlength="500"
            show-word-limit
            placeholder="公告正文（C 端公告详情展示）"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="editShow = false">取消</el-button>
        <el-button type="primary" @click="onSave">{{ editing ? '保存修改' : '立即发布' }}</el-button>
      </template>
    </el-dialog>
  </div>
</template>
