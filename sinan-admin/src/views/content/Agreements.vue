<script setup>
import { ref, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { getAgreements, saveAgreement } from '@/api'
import RichTextEditor from '@/components/RichTextEditor.vue'

const loading = ref(false)
const list = ref([])

const editShow = ref(false)
const editing = ref(null)   // { key, name, content }
const contentDraft = ref('')
const submitting = ref(false)

async function load() {
  loading.value = true
  const res = await getAgreements()
  loading.value = false
  if (res.code === 0) {
    list.value = res.data || []
  } else {
    ElMessage.error(res.message || '加载协议列表失败')
  }
}

function openEdit(row) {
  editing.value = { ...row }
  contentDraft.value = row.content || ''
  editShow.value = true
}

function plainText(html) {
  return String(html || '').replace(/<[^>]*>/g, '').replace(/&nbsp;/g, ' ').trim()
}

async function onSave() {
  if (!editing.value) return
  const plain = plainText(contentDraft.value)
  if (plain === '') {
    ElMessage.warning('协议内容不能为空')
    return
  }
  submitting.value = true
  const res = await saveAgreement(editing.value.key, contentDraft.value)
  submitting.value = false
  if (res.code === 0) {
    ElMessage.success(res.message || '已保存')
    editShow.value = false
    await load()
  } else {
    ElMessage.error(res.message || '保存失败')
  }
}

onMounted(load)
</script>

<template>
  <div class="agreements-page">
    <el-card v-loading="loading" shadow="never">
      <template #header>
        <div class="agreements-header">
          <span class="agreements-title">协议管理</span>
          <span class="agreements-tip">共 3 份，C 端登录/注册页实时读取最新内容</span>
        </div>
      </template>

      <el-table :data="list" border stripe style="width: 100%">
        <el-table-column label="协议名称" min-width="180">
          <template #default="{ row }">
            <div class="agreements-name">{{ row.name }}</div>
            <div class="agreements-key">{{ row.key }}</div>
          </template>
        </el-table-column>
        <el-table-column label="内容预览" min-width="260">
          <template #default="{ row }">
            <span v-if="plainText(row.content)" class="agreements-preview">
              {{ plainText(row.content).slice(0, 80) }}{{ plainText(row.content).length > 80 ? '…' : '' }}
            </span>
            <span v-else class="agreements-empty">未配置</span>
          </template>
        </el-table-column>
        <el-table-column label="上次修改" width="180">
          <template #default="{ row }">
            <span v-if="row.updatedAt">{{ row.updatedAt }}</span>
            <span v-else class="agreements-empty">—</span>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="110">
          <template #default="{ row }">
            <el-tag v-if="plainText(row.content)" type="success" effect="light">已发布</el-tag>
            <el-tag v-else type="info" effect="light">草稿</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="110" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" size="small" @click="openEdit(row)">编辑</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <!-- 编辑弹窗 -->
    <el-dialog
      v-model="editShow"
      :title="editing ? '编辑「' + editing.name + '」' : '编辑协议'"
      width="860px"
      :close-on-click-modal="false"
      top="5vh"
    >
      <div v-if="editing" class="agreements-form">
        <div class="agreements-form-row">
          <span class="agreements-form-label">协议标识</span>
          <span class="agreements-form-key">{{ editing.key }}</span>
        </div>
        <div class="agreements-form-tip">
          内容将直接下发至 C 端登录/注册页的《用户协议》《隐私政策》弹窗，支持富文本排版（标题、加粗、颜色、列表、图片等）。
        </div>
        <RichTextEditor
          v-model="contentDraft"
          :placeholder="'请输入「' + editing.name + '」的完整内容…'"
          :height="420"
          :biz="'agreement-' + editing.key"
        />
      </div>

      <template #footer>
        <el-button @click="editShow = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="onSave">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped lang="scss">
.agreements-page {
  padding: 12px;
}
.agreements-header {
  display: flex;
  align-items: center;
  gap: 12px;
}
.agreements-title {
  font-size: 16px;
  font-weight: 600;
}
.agreements-tip {
  font-size: 12px;
  color: var(--el-text-color-secondary);
}
.agreements-name {
  font-weight: 500;
}
.agreements-key {
  font-size: 12px;
  color: var(--el-text-color-placeholder);
  font-family: Menlo, Consolas, monospace;
}
.agreements-preview {
  font-size: 13px;
  color: var(--el-text-color-regular);
  line-height: 1.6;
}
.agreements-empty {
  color: var(--el-text-color-placeholder);
}
.agreements-form-row {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 10px;
}
.agreements-form-label {
  color: var(--el-text-color-secondary);
  font-size: 13px;
}
.agreements-form-key {
  font-family: Menlo, Consolas, monospace;
  color: var(--el-text-color-primary);
  font-size: 13px;
}
.agreements-form-tip {
  background: var(--el-fill-color-light);
  border-left: 3px solid var(--el-color-primary-light-5);
  padding: 8px 12px;
  font-size: 12px;
  color: var(--el-text-color-secondary);
  margin-bottom: 12px;
  border-radius: 2px;
}
</style>
