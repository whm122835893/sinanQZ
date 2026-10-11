<script setup>
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { UploadFilled } from '@element-plus/icons-vue'
import { getBlindBoxDetail, saveBlindBox, getCategories, uploadImage } from '@/api'

const route = useRoute()
const router = useRouter()
const id = route.params.id ? Number(route.params.id) : null
const submitting = ref(false)
const uploading = ref(false)
const formRef = ref(null)

const form = ref({
  name: '',
  categoryId: null,
  description: '',
  edition: null,
  price: null,
  perUserLimit: 5,
  // 封面不再从 5 张预设图里挑：盲盒 1:1 绑一条 collectibles，
  // 这里提交的 cover 就是那条藏品的 image，所以不加列、也不动现有设计
  cover: ''
})

// 分类选项（动态：内容管理 → 分类管理，market 场景）
const categories = ref([])

const rules = {
  name: [{ required: true, message: '请输入盲盒名称', trigger: 'blur' }],
  price: [{ required: true, message: '请输入发售价格', trigger: 'blur' }],
  edition: [{ required: true, message: '请输入发行总量', trigger: 'blur' }],
  cover: [{ required: true, message: '请上传盲盒封面图', trigger: 'change' }]
}

onMounted(async () => {
  // 加载分类选项（失败不阻塞表单）
  const cat = await getCategories('market')
  if (cat.code === 0) {
    categories.value = cat.data
    if (!id && cat.data.length) form.value.categoryId = cat.data[0].id
  }

  if (id) {
    const res = await getBlindBoxDetail(id)
    const b = res.data
    form.value = {
      name: b.name,
      categoryId: b.categoryId || null,
      description: b.description || '',
      edition: b.edition, // 发行总量不可变更
      price: b.price,
      perUserLimit: b.perUserLimit || 5,
      cover: b.cover
    }
  }
})

// ---- 盲盒封面上传（口径对齐「创建藏品」：≤5MB；非四格式由 api/index.js 总闸转成 JPEG 再传）----
async function onUploadCover({ file }) {
  if (!file) return
  if (file.size > 5 * 1024 * 1024) {
    return ElMessage.warning('图片大小不能超过 5MB')
  }
  uploading.value = true
  const res = await uploadImage(file, 'blindbox')
  uploading.value = false
  if (res.code === 0 && res.data?.url) {
    form.value.cover = res.data.url
    formRef.value?.clearValidate('cover')
    ElMessage.success('封面图已上传')
  } else {
    ElMessage.error(res.message || '上传失败，请重试')
  }
}

async function onSubmit() {
  await formRef.value.validate()
  const f = form.value
  submitting.value = true
  const res = await saveBlindBox({
    id,
    name: f.name.trim(),
    categoryId: f.categoryId,
    description: f.description,
    price: Number(f.price) || 0,
    edition: Number(f.edition) || 0,
    perUserLimit: f.perUserLimit,
    cover: f.cover
  })
  submitting.value = false
  if (res.code === 0) {
    ElMessage.success(id ? '保存成功' : '创建成功，请在详情页配置子藏品奖池')
    router.back()
  }
}
</script>

<template>
  <div class="adm-page be">
    <div class="adm-card">
      <div class="adm-card__title">{{ id ? '编辑盲盒' : '新建盲盒' }}</div>

      <el-form ref="formRef" :model="form" :rules="rules" label-width="110px" style="max-width: 640px">
        <el-form-item label="盲盒名称" prop="name">
          <el-input v-model="form.name" placeholder="请输入盲盒名称" maxlength="30" show-word-limit />
        </el-form-item>

        <el-form-item label="分类">
          <el-radio-group v-model="form.categoryId">
            <el-radio v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</el-radio>
          </el-radio-group>
          <div v-if="!categories.length" class="t-tertiary" style="font-size: 12px; width: 100%">
            暂无分类，请先到「内容 → 分类管理」新增市场分类
          </div>
        </el-form-item>

        <el-form-item label="发行总量" prop="edition">
          <el-input-number
            v-model="form.edition"
            :min="1"
            :step="100"
            :disabled="!!id"
            style="width: 200px"
          />
          <div class="t-tertiary" style="font-size: 12px; margin-top: 4px; width: 100%">
            盲盒发行总量创建时设定，固定不变；盲盒库存池 = 发行总量 - 已售出发售 - 已独立空投 - 已销毁
          </div>
        </el-form-item>

        <el-form-item label="发售价格（元）" prop="price">
          <el-input-number v-model="form.price" :min="0.01" :precision="2" :step="10" style="width: 200px" />
        </el-form-item>

        <el-form-item label="每人限购">
          <el-input-number v-model="form.perUserLimit" :min="1" style="width: 200px" />
        </el-form-item>

        <el-form-item label="盲盒描述">
          <el-input
            v-model="form.description"
            type="textarea"
            :rows="4"
            maxlength="200"
            show-word-limit
            placeholder="盲盒介绍（C 端详情页展示）"
          />
        </el-form-item>

        <el-form-item label="封面图" prop="cover">
          <div class="be__cover-edit">
            <div class="be__cover-preview">
              <img v-if="form.cover" :src="form.cover" alt="盲盒封面预览" />
              <div v-else class="be__cover-empty">
                <el-icon><UploadFilled /></el-icon>
                <span>暂无图片</span>
              </div>
            </div>
            <div class="be__cover-ops">
              <el-upload
                :show-file-list="false"
                :http-request="onUploadCover"
                accept="image/*"
              >
                <el-button type="primary" plain :loading="uploading">
                  {{ uploading ? '上传中…' : form.cover ? '重新上传' : '上传封面图' }}
                </el-button>
              </el-upload>
              <div class="t-tertiary be__cover-tip">
                支持 JPG / PNG / WEBP / GIF（HEIC 会自动转成 JPG），大小不超过 5MB；点「保存」后生效
              </div>
              <div v-if="id" class="t-tertiary be__cover-tip">
                编辑已有盲盒时上传新图将替换原封面
              </div>
            </div>
          </div>
        </el-form-item>

        <el-form-item>
          <el-button type="primary" :loading="submitting" @click="onSubmit">
            {{ id ? '保存修改' : '创建盲盒' }}
          </el-button>
          <el-button @click="router.back()">取消</el-button>
        </el-form-item>
      </el-form>

      <el-alert
        type="info"
        :closable="false"
        show-icon
        title="创建后请在盲盒详情页配置子藏品奖池：每个盲盒关联 1~N 个子藏品，独立配置中奖概率与计划数量，概率之和 ≤ 100%（差额为空奖率，上架前须为 100%）"
      />
    </div>
  </div>
</template>

<style scoped lang="scss">
// 封面上传区：与「创建藏品」同一套排版（5 张硬编码预设图已下线，
// 盲盒封面就是它绑定的那条 collectibles.image，C 端卡片与详情页都读它）
.be__cover-edit {
  display: flex;
  gap: 16px;
  align-items: flex-start;
}

.be__cover-preview {
  width: 140px;
  aspect-ratio: 1;
  border-radius: 8px;
  border: 1px dashed $color-border;
  overflow: hidden;
  flex-shrink: 0;
  background: $color-surface;

  img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
  }
}

.be__cover-empty {
  width: 100%;
  height: 100%;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 6px;
  color: $color-text-tertiary;
  font-size: 12px;

  .el-icon { font-size: 28px; }
}

.be__cover-ops { flex: 1; min-width: 220px; }

.be__cover-tip {
  font-size: 12px;
  margin-top: 8px;
  line-height: 1.6;
}

@media (max-width: 600px) {
  .be__cover-edit { flex-direction: column; }
}
</style>
