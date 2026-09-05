<template>
  <div v-loading="loading" class="page-container">
    <div class="sn-card page-head">
      <div class="head-left">
        <el-button text :icon="'ArrowLeft'" @click="router.back()">返回</el-button>
        <div>
          <h3 class="head-title">编辑藏品</h3>
          <span class="head-sub">仅草稿状态可编辑基础信息（文档 6.1）</span>
        </div>
      </div>
    </div>

    <template v-if="detail">
      <div class="sn-card">
        <el-form ref="formRef" :model="form" :rules="rules" label-width="110px" style="max-width: 640px">
          <el-form-item label="藏品名称" prop="name">
            <el-input v-model="form.name" maxlength="60" show-word-limit />
          </el-form-item>
          <el-form-item label="藏品分类" prop="categoryId">
            <el-select v-model="form.categoryId" style="width: 240px">
              <el-option v-for="c in CATEGORIES" :key="c.value" :label="c.label" :value="c.value" />
            </el-select>
          </el-form-item>
          <el-form-item label="发行总量" prop="edition">
            <el-input-number v-model="form.edition" :min="1" :max="1000000" :step="100" step-strictly />
            <div class="form-hint">已售出 {{ detail.sold }} / 锁定 {{ detail.lockedQuantity }}，发行总量不可低于已消耗数量</div>
          </el-form-item>
          <el-form-item label="简介（副标题）">
            <el-input v-model="form.description" maxlength="120" show-word-limit />
          </el-form-item>
          <el-form-item label="创作故事">
            <el-input v-model="form.story" type="textarea" :rows="4" maxlength="2000" show-word-limit />
          </el-form-item>
          <el-form-item label="主图地址">
            <el-input v-model="form.image" />
          </el-form-item>
          <el-form-item label="发行方">
            <el-input v-model="form.issuer" style="width: 320px" />
          </el-form-item>
          <el-form-item label="创作者">
            <el-input v-model="form.creator" style="width: 320px" />
          </el-form-item>
          <el-form-item label="标签">
            <el-input v-model="form.tag" style="width: 320px" />
          </el-form-item>
          <el-form-item>
            <el-button type="primary" :loading="submitting" @click="submit">保存修改</el-button>
            <el-button @click="router.back()">取 消</el-button>
          </el-form-item>
        </el-form>
      </div>
    </template>
  </div>
</template>

<script setup lang="ts">
// 编辑藏品（文档 8.5 #26：仅 draft 可编辑）
import { onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import { fetchCollectibleDetail, updateCollectible } from '@/api/collectible'
import type { CollectibleDetail } from '@/types/api'

const route = useRoute()
const router = useRouter()

const CATEGORIES = [
  { value: 1, label: '水墨' },
  { value: 2, label: '国潮' },
  { value: 3, label: '限定' }
]

const loading = ref(false)
const detail = ref<CollectibleDetail | null>(null)
const formRef = ref<FormInstance>()
const submitting = ref(false)

const form = reactive({
  name: '',
  categoryId: undefined as number | undefined,
  edition: 100,
  description: '',
  story: '',
  image: '',
  issuer: '',
  creator: '',
  tag: ''
})

const rules: FormRules = {
  name: [{ required: true, message: '藏品名称不能为空', trigger: 'blur' }],
  categoryId: [{ required: true, message: '请选择藏品分类', trigger: 'change' }],
  edition: [{ required: true, message: '发行总量必须大于 0', trigger: 'change' }]
}

async function load(): Promise<void> {
  loading.value = true
  try {
    detail.value = await fetchCollectibleDetail(route.params.id as string)
    const d = detail.value
    form.name = d.name
    form.categoryId = d.categoryId
    form.edition = d.edition
    form.description = d.subtitle ?? ''
    form.story = d.description ?? ''
    form.image = d.image
    form.issuer = d.issuer ?? ''
    form.creator = d.creator ?? ''
    form.tag = d.tag ?? ''
    if (d.status !== 'draft') {
      ElMessage.warning(`当前状态为「${d.status}」，仅草稿状态可保存修改`)
    }
  } catch {
    // 拦截器已提示
  } finally {
    loading.value = false
  }
}

async function submit(): Promise<void> {
  const formEl = formRef.value
  if (!formEl) return
  const valid = await formEl.validate().catch(() => false)
  if (!valid) return

  submitting.value = true
  try {
    await updateCollectible(route.params.id as string, {
      name: form.name.trim(),
      categoryId: form.categoryId,
      edition: form.edition,
      description: form.description.trim(),
      story: form.story.trim(),
      images: form.image.trim() ? [form.image.trim()] : [],
      issuer: form.issuer.trim(),
      creator: form.creator.trim(),
      tag: form.tag.trim()
    })
    ElMessage.success('藏品信息已保存')
    router.back()
  } catch {
    // 拦截器已提示
  } finally {
    submitting.value = false
  }
}

onMounted(load)
</script>

<style scoped lang="scss">
@use '@/assets/styles/table-common' as *;

.form-hint {
  font-size: 12px;
  color: $sn-text-muted;
  line-height: 1.6;
  margin-top: 4px;
}
</style>
