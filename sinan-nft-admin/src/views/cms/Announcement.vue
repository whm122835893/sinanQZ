<script setup lang="ts">
/**
 * 公告管理（CMS）
 *
 * - 列表：标题（置顶标记）/ 类型标签（notice 公告 / news 新闻）/ 内容摘要 /
 *        置顶状态 / 发布时间
 * - 操作：编辑 / 删除（二次确认）/ 置顶切换（toggle-top，置顶优先展示）
 * - 搜索：类型（notice/news）+ 标题关键词
 * - 新增 / 编辑弹窗：标题、类型、摘要（选填）、内容（textarea，支持富文本）、置顶开关
 *
 * 接口：GET/POST /admin/cms/announcements、PUT/DELETE /admin/cms/announcements/:id、
 *      POST /admin/cms/announcements/:id/toggle-top
 * 权限：cms:announcement
 */
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import {
  createAnnouncement,
  deleteAnnouncement,
  fetchAnnouncements,
  toggleAnnouncementTop,
  updateAnnouncement,
} from '@/api/cms'
import { useListPage } from '@/utils/useListPage'
import { datetime } from '@/utils/format'

/** 公告类型（后端 type：notice 公告 / news 新闻） */
const TYPES: Record<string, { text: string; type: 'warning' | 'primary' }> = {
  notice: { text: '公告', type: 'warning' },
  news: { text: '新闻', type: 'primary' },
}

/** 新闻子类（后端 subtype：activity 活动 / compose 合成 / operation 运营） */
const SUBTYPES: Record<string, string> = {
  activity: '活动',
  compose: '合成',
  operation: '运营',
}

// ==================== 列表 ====================
const listPage = useListPage(fetchAnnouncements, { type: '', keyword: '' })

function onPageChange(page: number) {
  listPage.page = page
  listPage.load()
}

function onSizeChange(size: number) {
  listPage.pageSize = size
  listPage.page = 1
  listPage.load()
}

/** 内容摘要：优先 summary，否则截取正文纯文本 */
function summaryText(row: any): string {
  const summary = String(row.summary || '').trim()
  if (summary) return summary
  const text = String(row.content || '')
    .replace(/<[^>]+>/g, '')
    .replace(/\s+/g, ' ')
    .trim()
  return text.slice(0, 60)
}

// ==================== 置顶切换 ====================
const togglingId = ref<number | null>(null)

async function onToggleTop(row: any) {
  togglingId.value = row.id
  try {
    await toggleAnnouncementTop(row.id)
    await listPage.refresh()
  } catch {
    /* 错误已全局提示 */
  } finally {
    togglingId.value = null
  }
}

// ==================== 新增 / 编辑弹窗 ====================
const dialogVisible = ref(false)
const saving = ref(false)
const formRef = ref<FormInstance>()

const form = reactive({
  id: undefined as number | undefined,
  title: '',
  type: 'notice',
  summary: '',
  content: '',
  isTop: false,
})

const rules: FormRules = {
  title: [
    { required: true, message: '请输入标题', trigger: 'blur' },
    { min: 2, max: 200, message: '标题长度需为 2~200 字', trigger: 'blur' },
  ],
  type: [{ required: true, message: '请选择类型', trigger: 'change' }],
  content: [{ required: true, message: '请输入内容', trigger: 'blur' }],
}

function openCreate() {
  form.id = undefined
  form.title = ''
  form.type = 'notice'
  form.summary = ''
  form.content = ''
  form.isTop = false
  dialogVisible.value = true
}

function openEdit(row: any) {
  form.id = row.id
  form.title = row.title || ''
  form.type = row.type === 'news' ? 'news' : 'notice'
  form.summary = row.summary || ''
  form.content = row.content || ''
  form.isTop = Number(row.isTop) === 1
  dialogVisible.value = true
}

async function submit() {
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) return

  saving.value = true
  try {
    // 后端入参为 snake_case：title / type / summary / content / is_top
    const payload = {
      title: form.title.trim(),
      type: form.type,
      summary: form.summary.trim(),
      content: form.content,
      is_top: form.isTop ? 1 : 0,
    }
    if (form.id) {
      await updateAnnouncement(form.id, payload)
      ElMessage.success('公告已更新')
    } else {
      await createAnnouncement(payload)
      ElMessage.success('公告已发布')
    }
    dialogVisible.value = false
    await listPage.refresh()
  } catch {
    /* 错误已全局提示 */
  } finally {
    saving.value = false
  }
}

// ==================== 删除（二次确认） ====================
async function remove(row: any) {
  try {
    await ElMessageBox.confirm(
      `确定删除公告「${row.title}」吗？删除后 C 端不再展示。`,
      '删除确认',
      { type: 'warning', confirmButtonText: '确认删除', cancelButtonText: '取消' },
    )
  } catch {
    return
  }

  try {
    await deleteAnnouncement(row.id)
    await listPage.done('公告已删除')
  } catch {
    /* 错误已全局提示 */
  }
}

onMounted(() => listPage.load())
</script>

<template>
  <div class="page-container">
    <!-- 搜索栏 -->
    <div class="search-bar">
      <el-form inline @submit.prevent="listPage.search">
        <el-form-item label="类型">
          <el-select
            v-model="listPage.filters.type"
            placeholder="全部类型"
            clearable
            style="width: 140px"
            @change="listPage.search"
          >
            <el-option v-for="(t, key) in TYPES" :key="key" :label="t.text" :value="key" />
          </el-select>
        </el-form-item>
        <el-form-item label="关键词">
          <el-input
            v-model="listPage.filters.keyword"
            placeholder="标题关键词"
            clearable
            style="width: 220px"
            @keyup.enter="listPage.search"
            @clear="listPage.search"
          />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="listPage.search">查询</el-button>
          <el-button @click="listPage.reset">重置</el-button>
        </el-form-item>
      </el-form>
    </div>

    <!-- 公告列表 -->
    <div class="table-card">
      <div class="card-header">
        <div>
          <div class="card-title">公告管理</div>
          <div class="card-sub">notice 公告与 news 新闻统一维护，置顶内容优先展示</div>
        </div>
        <el-button v-permission="'cms:announcement'" type="primary" @click="openCreate">发布公告</el-button>
      </div>

      <el-table v-loading="listPage.loading" :data="listPage.list">
        <el-table-column label="标题" min-width="240">
          <template #default="{ row }">
            <div class="title-cell">
              <el-tag v-if="Number(row.isTop) === 1" type="danger" size="small" effect="light">置顶</el-tag>
              <span class="title-text">{{ row.title }}</span>
            </div>
            <div class="sub-text">ID：{{ row.id }}</div>
          </template>
        </el-table-column>
        <el-table-column label="类型" width="120" align="center">
          <template #default="{ row }">
            <el-tag :type="TYPES[row.type]?.type ?? 'info'" size="small">
              {{ TYPES[row.type]?.text ?? row.type }}
            </el-tag>
            <div v-if="row.type === 'news' && row.subtype" class="sub-text">
              {{ SUBTYPES[row.subtype] ?? row.subtype }}
            </div>
          </template>
        </el-table-column>
        <el-table-column label="内容摘要" min-width="220" show-overflow-tooltip>
          <template #default="{ row }">{{ summaryText(row) || '-' }}</template>
        </el-table-column>
        <el-table-column label="状态" width="90" align="center">
          <template #default="{ row }">
            <el-tag :type="Number(row.isTop) === 1 ? 'warning' : 'info'" size="small">
              {{ Number(row.isTop) === 1 ? '已置顶' : '正常' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="发布时间" width="170">
          <template #default="{ row }">{{ datetime(row.createdAt) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="200" fixed="right">
          <template #default="{ row }">
            <el-button v-permission="'cms:announcement'" link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button
              v-permission="'cms:announcement'"
              link
              :type="Number(row.isTop) === 1 ? 'info' : 'warning'"
              :loading="togglingId === row.id"
              @click="onToggleTop(row)"
            >
              {{ Number(row.isTop) === 1 ? '取消置顶' : '置顶' }}
            </el-button>
            <el-button v-permission="'cms:announcement'" link type="danger" @click="remove(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>

      <div class="pagination-wrap">
        <el-pagination
          :current-page="listPage.page"
          :page-size="listPage.pageSize"
          :total="listPage.total"
          :page-sizes="[10, 20, 50, 100]"
          layout="total, sizes, prev, pager, next, jumper"
          @current-change="onPageChange"
          @size-change="onSizeChange"
        />
      </div>
    </div>

    <!-- 新增 / 编辑弹窗 -->
    <el-dialog
      v-model="dialogVisible"
      :title="form.id ? '编辑公告' : '发布公告'"
      width="680px"
      destroy-on-close
      top="6vh"
    >
      <el-form ref="formRef" :model="form" :rules="rules" label-width="80px">
        <el-form-item label="标题" prop="title">
          <el-input v-model="form.title" maxlength="200" show-word-limit placeholder="请输入公告标题" />
        </el-form-item>
        <el-form-item label="类型" prop="type">
          <el-radio-group v-model="form.type">
            <el-radio v-for="(t, key) in TYPES" :key="key" :value="key">{{ t.text }}</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="摘要" prop="summary">
          <el-input
            v-model="form.summary"
            maxlength="500"
            show-word-limit
            placeholder="列表摘要（选填，留空自动截取正文）"
          />
        </el-form-item>
        <el-form-item label="内容" prop="content">
          <el-input
            v-model="form.content"
            type="textarea"
            :rows="8"
            placeholder="请输入公告内容，支持 HTML 富文本（服务端自动过滤 script/iframe 等危险标签）"
          />
        </el-form-item>
        <el-form-item label="置顶" prop="isTop">
          <el-switch v-model="form.isTop" />
          <span class="form-tip inline">置顶后列表优先展示（同时影响 C 端排序）</span>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="submit">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped lang="scss">
.card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 14px;

  .card-title {
    font-size: 15px;
    font-weight: 600;
  }
  .card-sub {
    margin-top: 4px;
    font-size: 12px;
    color: var(--sn-text-secondary);
  }
}

.title-cell {
  display: flex;
  align-items: center;
  gap: 6px;

  .title-text {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
}

.sub-text {
  font-size: 12px;
  color: var(--sn-text-secondary);
  margin-top: 2px;
}

.form-tip {
  font-size: 12px;
  color: var(--sn-text-secondary);
  line-height: 1.7;
  margin-top: 4px;

  &.inline {
    display: inline;
    margin-left: 10px;
  }
}
</style>
