<script setup lang="ts">
/**
 * 文物展馆（CMS）
 *
 * - 列表：图片预览 / 名称 / 年代 / 材质 / 描述（故事摘要）/ 创建时间
 * - 搜索：关键词（名称 / 年代模糊匹配）
 * - 新增 / 编辑弹窗：名称、年代、图片地址、材质、时期、尺寸、来源、收藏馆、
 *   级别、图片高度（50~2000）、背景故事
 * - 删除需二次确认（后端软删除）；列表按创建时间倒序（后端 id desc，无独立排序字段）
 *
 * 接口：GET/POST /admin/cms/artifacts、PUT/DELETE /admin/cms/artifacts/:id
 * 权限：cms:artifact
 */
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import { createArtifact, deleteArtifact, fetchArtifacts, updateArtifact } from '@/api/cms'
import { useListPage } from '@/utils/useListPage'
import { datetime } from '@/utils/format'

// ==================== 列表 ====================
const listPage = useListPage(fetchArtifacts, { keyword: '' })

function onPageChange(page: number) {
  listPage.page = page
  listPage.load()
}

function onSizeChange(size: number) {
  listPage.pageSize = size
  listPage.page = 1
  listPage.load()
}

/** 描述摘要：截取背景故事纯文本 */
function storyText(row: any): string {
  const text = String(row.story || '')
    .replace(/<[^>]+>/g, '')
    .replace(/\s+/g, ' ')
    .trim()
  return text.slice(0, 60)
}

// ==================== 新增 / 编辑弹窗 ====================
const dialogVisible = ref(false)
const saving = ref(false)
const formRef = ref<FormInstance>()

const form = reactive({
  id: undefined as number | undefined,
  name: '',
  dynasty: '',
  image: '',
  material: '',
  period: '',
  size: '',
  origin: '',
  museum: '',
  level: '',
  /** 图片高度（px，50~2000，后端默认 150） */
  imgHeight: 150,
  story: '',
})

/** 后端新增必填：name / dynasty / image / material / period / story */
const rules: FormRules = {
  name: [
    { required: true, message: '请输入文物名称', trigger: 'blur' },
    { max: 100, message: '名称不超过 100 字', trigger: 'blur' },
  ],
  dynasty: [{ required: true, message: '请输入年代', trigger: 'blur' }],
  image: [{ required: true, message: '请输入图片地址', trigger: 'blur' }],
  material: [{ required: true, message: '请输入材质', trigger: 'blur' }],
  period: [{ required: true, message: '请输入时期', trigger: 'blur' }],
  story: [{ required: true, message: '请输入背景故事', trigger: 'blur' }],
}

function openCreate() {
  form.id = undefined
  form.name = ''
  form.dynasty = ''
  form.image = ''
  form.material = ''
  form.period = ''
  form.size = ''
  form.origin = ''
  form.museum = ''
  form.level = ''
  form.imgHeight = 150
  form.story = ''
  dialogVisible.value = true
}

function openEdit(row: any) {
  form.id = row.id
  form.name = row.name || ''
  form.dynasty = row.dynasty || ''
  form.image = row.image || ''
  form.material = row.material || ''
  form.period = row.period || ''
  form.size = row.size || ''
  form.origin = row.origin || ''
  form.museum = row.museum || ''
  form.level = row.level || ''
  form.imgHeight = Number(row.imgHeight ?? 150) || 150
  form.story = row.story || ''
  dialogVisible.value = true
}

async function submit() {
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) return

  saving.value = true
  try {
    // 后端入参为 snake_case（img_height），其余字段为单词
    const payload = {
      name: form.name.trim(),
      dynasty: form.dynasty.trim(),
      image: form.image.trim(),
      material: form.material.trim(),
      period: form.period.trim(),
      size: form.size.trim(),
      origin: form.origin.trim(),
      museum: form.museum.trim(),
      level: form.level.trim(),
      img_height: form.imgHeight,
      story: form.story,
    }
    if (form.id) {
      await updateArtifact(form.id, payload)
      ElMessage.success('文物已更新')
    } else {
      await createArtifact(payload)
      ElMessage.success('文物已创建')
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
      `确定删除文物「${row.name}」吗？删除后前台展馆不再展示。`,
      '删除确认',
      { type: 'warning', confirmButtonText: '确认删除', cancelButtonText: '取消' },
    )
  } catch {
    return
  }

  try {
    await deleteArtifact(row.id)
    await listPage.done('文物已删除')
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
        <el-form-item label="关键词">
          <el-input
            v-model="listPage.filters.keyword"
            placeholder="文物名称 / 年代"
            clearable
            style="width: 240px"
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

    <!-- 文物列表 -->
    <div class="table-card">
      <div class="card-header">
        <div>
          <div class="card-title">文物展馆</div>
          <div class="card-sub">C 端文物科普展馆，按创建时间倒序展示</div>
        </div>
        <el-button v-permission="'cms:artifact'" type="primary" @click="openCreate">新增文物</el-button>
      </div>

      <el-table v-loading="listPage.loading" :data="listPage.list">
        <el-table-column label="图片" width="90">
          <template #default="{ row }">
            <el-image
              class="table-img"
              :src="row.image"
              fit="cover"
              :preview-src-list="row.image ? [row.image] : []"
              preview-teleported
              hide-on-click-modal
            >
              <template #error>
                <div class="img-fallback">暂无图片</div>
              </template>
            </el-image>
          </template>
        </el-table-column>
        <el-table-column label="名称" min-width="160">
          <template #default="{ row }">
            <div>{{ row.name }}</div>
            <div class="sub-text">ID：{{ row.id }}<template v-if="row.level"> · {{ row.level }}</template></div>
          </template>
        </el-table-column>
        <el-table-column label="年代" width="100" show-overflow-tooltip>
          <template #default="{ row }">{{ row.dynasty || '-' }}</template>
        </el-table-column>
        <el-table-column label="材质" width="100" show-overflow-tooltip>
          <template #default="{ row }">{{ row.material || '-' }}</template>
        </el-table-column>
        <el-table-column label="描述" min-width="220" show-overflow-tooltip>
          <template #default="{ row }">{{ storyText(row) || '-' }}</template>
        </el-table-column>
        <el-table-column label="创建时间" width="170">
          <template #default="{ row }">{{ datetime(row.createdAt) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="130" fixed="right">
          <template #default="{ row }">
            <el-button v-permission="'cms:artifact'" link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button v-permission="'cms:artifact'" link type="danger" @click="remove(row)">删除</el-button>
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
      :title="form.id ? '编辑文物' : '新增文物'"
      width="720px"
      destroy-on-close
      top="6vh"
    >
      <el-form ref="formRef" :model="form" :rules="rules" label-width="90px">
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="名称" prop="name">
              <el-input v-model="form.name" maxlength="100" show-word-limit placeholder="如：司南（汉代）" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="年代" prop="dynasty">
              <el-input v-model="form.dynasty" maxlength="50" placeholder="如：汉" />
            </el-form-item>
          </el-col>
        </el-row>
        <el-form-item label="图片地址" prop="image">
          <el-input v-model="form.image" maxlength="255" placeholder="http(s):// 或以 / 开头的相对路径" />
        </el-form-item>
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="材质" prop="material">
              <el-input v-model="form.material" maxlength="50" placeholder="如：青铜" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="时期" prop="period">
              <el-input v-model="form.period" maxlength="100" placeholder="如：西汉" />
            </el-form-item>
          </el-col>
        </el-row>
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="尺寸">
              <el-input v-model="form.size" maxlength="100" placeholder="如：通高 15.2cm（选填）" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="来源">
              <el-input v-model="form.origin" maxlength="100" placeholder="出土地 / 传承（选填）" />
            </el-form-item>
          </el-col>
        </el-row>
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="收藏馆">
              <el-input v-model="form.museum" maxlength="100" placeholder="如：故宫博物院（选填）" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="级别">
              <el-input v-model="form.level" maxlength="20" placeholder="如：一级文物（选填）" />
            </el-form-item>
          </el-col>
        </el-row>
        <el-form-item label="图片高度">
          <el-input-number v-model="form.imgHeight" :min="50" :max="2000" :step="10" />
          <span class="form-tip inline">C 端展示高度（px，50~2000，默认 150）</span>
        </el-form-item>
        <el-form-item label="背景故事" prop="story">
          <el-input
            v-model="form.story"
            type="textarea"
            :rows="6"
            placeholder="请输入文物背景故事 / 描述，展示于 C 端文物详情"
          />
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

.sub-text {
  font-size: 12px;
  color: var(--sn-text-secondary);
  margin-top: 2px;
}

.img-fallback {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 11px;
  color: var(--sn-text-secondary);
  background: #f3f4f6;
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
