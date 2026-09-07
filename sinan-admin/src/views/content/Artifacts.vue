<script setup>
import { ref } from 'vue'
import { ElMessage } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import { getArtifacts, saveArtifact, uploadImage } from '@/api'
import AdminTablePage from '@/components/AdminTablePage.vue'

const filters = [
  {
    field: 'status',
    label: '状态',
    options: [
      { value: 1, label: '展示中' },
      { value: 0, label: '已隐藏' }
    ]
  },
  {
    field: 'dynasty',
    label: '朝代',
    options: [
      { value: '战国', label: '战国' },
      { value: '西周', label: '西周' },
      { value: '东汉', label: '东汉' }
    ]
  }
]

const listRef = ref(null)
const editShow = ref(false)
const editing = ref(null)
const uploading = ref(false)
const form = ref(emptyForm())

const dynasties = ['新石器时代', '商', '西周', '春秋', '战国', '秦', '西汉', '东汉', '唐', '宋', '元', '明', '清']

function emptyForm() {
  return {
    name: '',
    dynasty: '战国',
    museum: '',
    level: '国家一级文物',
    material: '',
    image: '',
    imgHeight: 150,
    status: 1
  }
}

function openCreate() {
  editing.value = null
  form.value = emptyForm()
  editShow.value = true
}

function openEdit(a) {
  editing.value = a
  form.value = {
    name: a.name,
    dynasty: a.dynasty,
    museum: a.museum,
    level: a.level,
    material: a.material,
    image: a.image || '',
    imgHeight: a.imgHeight || 150,
    status: a.status
  }
  editShow.value = true
}

// ---- 文物图上传（JPG/PNG/WEBP/GIF，≤5MB）----
async function onUploadImage({ file }) {
  if (!file) return
  if (file.size > 5 * 1024 * 1024) {
    return ElMessage.warning('图片大小不能超过 5MB')
  }
  uploading.value = true
  const res = await uploadImage(file, 'artifact')
  uploading.value = false
  if (res.code === 0 && res.data?.url) {
    form.value.image = res.data.url
    ElMessage.success('文物图已上传')
  } else {
    ElMessage.error(res.message || '上传失败，请重试')
  }
}

async function onSave() {
  const f = form.value
  if (!f.name.trim()) return ElMessage.warning('请输入文物名称')
  if (!f.museum.trim()) return ElMessage.warning('请输入收藏博物馆')
  if (!f.image) return ElMessage.warning('请上传文物图片')
  const res = await saveArtifact({
    id: editing.value?.id,
    ...f,
    img_height: f.imgHeight
  })
  if (res.code === 0) {
    ElMessage.success('已保存')
    editShow.value = false
    listRef.value?.refresh()
  }
}
</script>

<template>
  <div class="adm-page af">
    <AdminTablePage ref="listRef" :fetch="getArtifacts" :filters="filters" search-placeholder="搜索文物名称 / 博物馆">
      <template #extra>
        <el-button type="primary" :icon="Plus" @click="openCreate">新增文物</el-button>
      </template>

      <template #default="{ items }">
        <el-table-column label="文物" min-width="240" fixed="left">
          <template #default="{ row }">
            <div class="af__cell">
              <img class="af__img" :src="row.image" :alt="row.name" />
              <div>
                <div class="af__name">{{ row.name }}</div>
                <div class="t-tertiary" style="font-size: 12px">{{ row.museum }}</div>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="朝代" width="100" align="center">
          <template #default="{ row }">
            <el-tag type="primary" effect="plain" size="small">{{ row.dynasty }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="材质" min-width="110">
          <template #default="{ row }">
            <span class="t-secondary">{{ row.material }}</span>
          </template>
        </el-table-column>
        <el-table-column label="文物等级" min-width="120">
          <template #default="{ row }">
            <span class="t-gold">{{ row.level }}</span>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="90" align="center">
          <template #default="{ row }">
            <el-tag :type="row.status === 1 ? 'success' : 'info'" effect="plain" size="small">
              {{ row.status === 1 ? '展示中' : '已隐藏' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="90" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" size="small" @click="openEdit(row)">编辑</el-button>
          </template>
        </el-table-column>
      </template>
    </AdminTablePage>

    <!-- 编辑弹窗 -->
    <el-dialog v-model="editShow" :title="editing ? '编辑文物' : '新增文物'" width="520px" :close-on-click-modal="false">
      <el-form label-width="100px">
        <el-form-item label="文物图片" required>
          <div class="af__upload">
            <div v-if="form.image" class="af__preview">
              <img :src="form.image" :alt="form.name" />
              <div class="af__preview-ops">
                <el-upload
                  :show-file-list="false"
                  :http-request="onUploadImage"
                  accept="image/jpeg,image/png,image/webp,image/gif"
                >
                  <el-button link type="primary" size="small" :loading="uploading">重新上传</el-button>
                </el-upload>
                <el-button link type="danger" size="small" @click="form.image = ''">删除</el-button>
              </div>
            </div>
            <el-upload
              v-else
              class="af__uploader"
              drag
              :show-file-list="false"
              :http-request="onUploadImage"
              accept="image/jpeg,image/png,image/webp,image/gif"
            >
              <div class="af__uploader-box">
                <el-icon :size="26"><Plus /></el-icon>
                <div class="t-tertiary" style="font-size: 12px">{{ uploading ? '上传中…' : '上传文物图片' }}</div>
                <div class="t-tertiary" style="font-size: 11px; opacity: 0.7">JPG / PNG / WEBP / GIF，≤5MB</div>
              </div>
            </el-upload>
          </div>
        </el-form-item>
        <el-form-item label="文物名称" required>
          <el-input v-model="form.name" placeholder="文物全名" maxlength="30" show-word-limit />
        </el-form-item>
        <el-form-item label="收藏博物馆" required>
          <el-input v-model="form.museum" placeholder="如：河北博物院" />
        </el-form-item>
        <el-form-item label="朝代">
          <el-select v-model="form.dynasty">
            <el-option v-for="d in dynasties" :key="d" :label="d" :value="d" />
          </el-select>
        </el-form-item>
        <el-form-item label="材质">
          <el-input v-model="form.material" placeholder="如：青铜 / 铜鎏金 / 绢本" />
        </el-form-item>
        <el-form-item label="文物等级">
          <el-input v-model="form.level" placeholder="如：国家一级文物" />
        </el-form-item>
        <el-form-item label="图片高度">
          <el-input-number v-model="form.imgHeight" :min="50" :max="2000" :step="10" style="width: 140px" />
          <span class="t-tertiary" style="font-size: 12px; margin-left: 8px">px · C 端瀑布流展示高度</span>
        </el-form-item>
        <el-form-item label="展示状态">
          <el-switch v-model="form.status" :active-value="1" :inactive-value="0" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="editShow = false">取消</el-button>
        <el-button type="primary" @click="onSave">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped lang="scss">
.af__cell {
  display: flex;
  align-items: center;
  gap: 10px;
}

.af__img {
  width: 48px;
  height: 48px;
  border-radius: 8px;
  object-fit: cover;
  background: $color-surface;
  flex-shrink: 0;
}

.af__name {
  font-weight: 600;
  color: $color-text-primary;
}

.af__upload { width: 100%; }

.af__preview {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 6px;

  img {
    width: 120px;
    height: 120px;
    border-radius: 8px;
    object-fit: cover;
    background: $color-surface;
  }
}

.af__preview-ops { display: flex; gap: 4px; }

.af__uploader {
  width: 100%;

  :deep(.el-upload-dragger) {
    padding: 18px 0;
    border-radius: 8px;
  }
}

.af__uploader-box {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
  color: var(--el-text-color-secondary);
}
</style>
