<script setup>
import { ref, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import { getBanners, saveBanner } from '@/api'
import { fmtNumber } from '@/utils/format'

const loading = ref(true)
const banners = ref([])

const editShow = ref(false)
const editing = ref(null)
const form = ref({ title: '', image: '', link: '', sort: 1, status: 1 })

const imageOptions = [
  '/images/hero/slide-1.jpg',
  '/images/hero/slide-2.jpg',
  '/images/hero/slide-3.jpg'
]

onMounted(load)

async function load() {
  loading.value = true
  const res = await getBanners()
  banners.value = res.data
  loading.value = false
}

function openCreate() {
  editing.value = null
  form.value = { title: '', image: imageOptions[0], link: '', sort: banners.value.length + 1, status: 1 }
  editShow.value = true
}

function openEdit(b) {
  editing.value = b
  form.value = { title: b.title, image: b.image, link: b.link, sort: b.sort, status: b.status }
  editShow.value = true
}

async function onSave() {
  const f = form.value
  if (!f.title.trim()) return ElMessage.warning('请输入标题')
  if (!f.image) return ElMessage.warning('请选择轮播图')
  const res = await saveBanner({ id: editing.value?.id, ...f })
  if (res.code === 0) {
    ElMessage.success('已保存')
    editShow.value = false
    load()
  }
}

async function onToggle(b) {
  const enabling = b.status !== 1
  const res = await saveBanner({ id: b.id, status: enabling ? 1 : 0 })
  if (res.code === 0) {
    b.status = enabling ? 1 : 0
    ElMessage.success(enabling ? '已上架' : '已下架')
  }
}
</script>

<template>
  <div class="adm-page bn">
    <el-skeleton v-if="loading" :rows="6" animated style="padding: 20px" />

    <div v-else class="adm-card">
      <div class="adm-card__title">
        首页轮播图（{{ fmtNumber(banners.length) }} 张，按排序升序播放）
        <div class="bn__extra">
          <el-button type="primary" :icon="Plus" @click="openCreate">新增轮播</el-button>
        </div>
      </div>

      <el-table :data="banners">
        <el-table-column label="预览" width="150">
          <template #default="{ row }">
            <img class="bn__img" :src="row.image" :alt="row.title" />
          </template>
        </el-table-column>
        <el-table-column label="标题" prop="title" min-width="160" show-overflow-tooltip />
        <el-table-column label="跳转链接" min-width="180" show-overflow-tooltip>
          <template #default="{ row }">
            <span class="t-secondary">{{ row.link || '-' }}</span>
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
        <el-table-column label="操作" width="90" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" size="small" @click="openEdit(row)">编辑</el-button>
          </template>
        </el-table-column>
      </el-table>
    </div>

    <!-- 编辑弹窗 -->
    <el-dialog v-model="editShow" :title="editing ? '编辑轮播图' : '新增轮播图'" width="520px" :close-on-click-modal="false">
      <el-form label-width="90px">
        <el-form-item label="标题" required>
          <el-input v-model="form.title" placeholder="轮播图标题" maxlength="30" show-word-limit />
        </el-form-item>
        <el-form-item label="跳转链接">
          <el-input v-model="form.link" placeholder="如 /collection/9001 或 /lottery" />
        </el-form-item>
        <el-form-item label="排序">
          <el-input-number v-model="form.sort" :min="1" :max="99" />
          <span class="t-tertiary" style="margin-left: 10px; font-size: 12px">数字越小越靠前</span>
        </el-form-item>
        <el-form-item label="轮播图">
          <div class="bn__opts">
            <img
              v-for="img in imageOptions"
              :key="img"
              :src="img"
              :class="{ 'is-active': form.image === img }"
              @click="form.image = img"
            />
          </div>
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

.bn__img {
  width: 110px;
  height: 56px;
  border-radius: 6px;
  object-fit: cover;
  background: $color-surface;
}

.bn__opts {
  display: flex;
  gap: 10px;
  width: 100%;

  img {
    width: 96px;
    height: 54px;
    object-fit: cover;
    border-radius: 6px;
    border: 2px solid transparent;
    cursor: pointer;
    transition: border-color 0.2s;

    &:hover { border-color: $color-primary-light; }
    &.is-active { border-color: $color-primary; }
  }
}
</style>
