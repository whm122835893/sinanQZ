<script setup>
import { ref, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import { getBanners, saveBanner, deleteBanner, toggleBanner, uploadImage } from '@/api'
import { fmtNumber } from '@/utils/format'

const loading = ref(true)
const banners = ref([])
const uploading = ref(false)

const editShow = ref(false)
const editing = ref(null)
const form = ref({ title: '', image: '', sort: 1, status: 1 })

onMounted(load)

async function load() {
  loading.value = true
  const res = await getBanners()
  if (res.code === 0) banners.value = res.data
  loading.value = false
}

function openCreate() {
  editing.value = null
  form.value = { title: '', image: '', sort: banners.value.length + 1, status: 1 }
  editShow.value = true
}

function openEdit(b) {
  editing.value = b
  form.value = { title: b.title, image: b.image, sort: b.sort, status: b.status }
  editShow.value = true
}

// ---- 轮播图上传（JPG/PNG/WEBP/GIF，≤5MB；存 /uploads/content/年月/随机名）----
async function onUploadImage({ file }) {
  if (!file) return
  if (file.size > 5 * 1024 * 1024) {
    return ElMessage.warning('图片大小不能超过 5MB')
  }
  uploading.value = true
  const res = await uploadImage(file, 'content')
  uploading.value = false
  if (res.code === 0 && res.data?.url) {
    form.value.image = res.data.url
    ElMessage.success('轮播图已上传')
  } else {
    ElMessage.error(res.message || '上传失败，请重试')
  }
}

async function onSave() {
  const f = form.value
  if (!f.image) return ElMessage.warning('请上传轮播图')
  const res = await saveBanner({ id: editing.value?.id, ...f })
  if (res.code === 0) {
    ElMessage.success(editing.value ? '已保存' : '已新增')
    editShow.value = false
    load()
  } else {
    ElMessage.error(res.message || '保存失败')
  }
}

async function onToggle(b) {
  const enabling = b.status !== 1
  const res = await toggleBanner(b.id)
  if (res.code === 0) {
    b.status = res.data?.is_active !== undefined ? Number(res.data.is_active) : (enabling ? 1 : 0)
    ElMessage.success(b.status === 1 ? '已上架' : '已下架')
  } else {
    ElMessage.error(res.message || '操作失败')
  }
}

async function onDelete(b) {
  try {
    await ElMessageBox.confirm(
      `确定删除这张轮播图吗？删除后首页立即不再展示，可在「系统 → 回收站」恢复。`,
      '删除轮播图',
      { type: 'warning', confirmButtonText: '删除', cancelButtonText: '取消' }
    )
  } catch {
    return // 用户取消
  }
  const res = await deleteBanner(b.id)
  if (res.code === 0) {
    ElMessage.success('轮播图已删除')
    load()
  } else {
    ElMessage.error(res.message || '删除失败')
  }
}
</script>

<template>
  <div class="adm-page bn">
    <el-skeleton v-if="loading" :rows="6" animated style="padding: 20px" />

    <div v-else class="adm-card">
      <div class="adm-card__title">
        首页轮播图（{{ fmtNumber(banners.length) }} 张，按排序升序展示）
        <div class="bn__extra">
          <el-button type="primary" :icon="Plus" @click="openCreate">新增轮播</el-button>
        </div>
      </div>
      <div class="bn__tip">
        上架 1 张时首页静止展示、不轮播；2 张起才自动轮播（3.5 秒/张，可左右滑动）。
        图片尺寸不限，前台轮播区大小固定，超出部分自动居中裁切。<br />
        另：在「藏品管理 → 市场 / 推荐」列打开某件藏品的「播」开关，它的封面图会插到这些轮播图前面，
        左上角带「推荐藏品」角标、点击跳到市场里该藏品的寄售页；关掉即从首页轮播撤下（这里的张数与它合并计算）。
      </div>

      <el-table :data="banners">
        <el-table-column label="预览" width="170">
          <template #default="{ row }">
            <img class="bn__img" :src="row.image" :alt="row.title" />
          </template>
        </el-table-column>
        <el-table-column label="备注标题" min-width="160" show-overflow-tooltip>
          <template #default="{ row }">
            <span :class="row.title ? '' : 't-tertiary'">{{ row.title || '（未填写）' }}</span>
          </template>
        </el-table-column>
        <el-table-column label="图片地址" min-width="220" show-overflow-tooltip>
          <template #default="{ row }">
            <span class="t-tertiary" style="font-size: 12px">{{ row.image }}</span>
          </template>
        </el-table-column>
        <el-table-column label="排序" width="80" align="center">
          <template #default="{ row }">#{{ row.sort }}</template>
        </el-table-column>
        <el-table-column label="上架状态" width="110" align="center">
          <template #default="{ row }">
            <el-tag :type="row.status === 1 ? 'success' : 'info'" effect="plain" size="small">
              {{ row.status === 1 ? '上架中' : '已下架' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="快捷启停" width="100" align="center">
          <template #default="{ row }">
            <el-switch :model-value="row.status === 1" @change="onToggle(row)" />
          </template>
        </el-table-column>
        <el-table-column label="操作" width="130" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" size="small" @click="openEdit(row)">编辑</el-button>
            <el-button link type="danger" size="small" @click="onDelete(row)">删除</el-button>
          </template>
        </el-table-column>
        <template #empty>
          <div class="t-tertiary" style="padding: 18px 0">还没有轮播图，点右上「新增轮播」上传第一张</div>
        </template>
      </el-table>
    </div>

    <!-- 编辑弹窗 -->
    <el-dialog v-model="editShow" :title="editing ? '编辑轮播图' : '新增轮播图'" width="520px" :close-on-click-modal="false">
      <el-form label-width="90px">
        <el-form-item label="轮播图" required>
          <div class="bn__upload">
            <div v-if="form.image" class="bn__preview">
              <img :src="form.image" alt="轮播图预览" />
              <div class="bn__preview-ops">
                <el-upload
                  :show-file-list="false"
                  :http-request="onUploadImage"
                  accept="image/jpeg,image/png,image/webp,image/gif"
                >
                  <el-button link type="primary" size="small" :loading="uploading">重新上传</el-button>
                </el-upload>
                <el-button link type="danger" size="small" @click="form.image = ''">移除</el-button>
              </div>
            </div>
            <el-upload
              v-else
              class="bn__uploader"
              drag
              :show-file-list="false"
              :http-request="onUploadImage"
              accept="image/jpeg,image/png,image/webp,image/gif"
            >
              <div class="bn__uploader-box">
                <el-icon :size="26"><Plus /></el-icon>
                <div class="t-tertiary" style="font-size: 12px">{{ uploading ? '上传中…' : '点击或拖拽图片到此处上传' }}</div>
                <div class="t-tertiary" style="font-size: 11px; opacity: 0.7">JPG / PNG / WEBP / GIF，≤5MB</div>
              </div>
            </el-upload>
            <div class="t-tertiary" style="font-size: 11px; margin-top: 6px">
              尺寸不限，前台轮播区大小固定，图片自动居中裁切铺满（建议竖图，如 1170×1650）。
            </div>
          </div>
        </el-form-item>
        <el-form-item label="备注标题">
          <el-input v-model="form.title" placeholder="仅后台备忘用，首页不显示（选填）" maxlength="30" show-word-limit />
        </el-form-item>
        <el-form-item label="排序">
          <el-input-number v-model="form.sort" :min="1" :max="99" />
          <span class="t-tertiary" style="margin-left: 10px; font-size: 12px">数字越小越靠前</span>
        </el-form-item>
        <el-form-item label="上架状态">
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
.bn__extra {
  margin-left: auto;
}

.bn__tip {
  font-size: 12px;
  color: $color-text-tertiary;
  padding: 0 0 12px;
}

.bn__img {
  width: 130px;
  height: 68px;
  border-radius: 6px;
  object-fit: cover;
  background: $color-surface;
}

.bn__upload {
  width: 100%;
}

.bn__preview {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 6px;

  img {
    width: 150px;
    height: 150px;
    border-radius: 8px;
    object-fit: cover;
    background: $color-surface;
  }
}

.bn__preview-ops {
  display: flex;
  gap: 4px;
}

.bn__uploader {
  width: 100%;

  :deep(.el-upload-dragger) {
    padding: 18px 0;
    border-radius: 8px;
  }
}

.bn__uploader-box {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
  color: var(--el-text-color-secondary);
}
</style>
