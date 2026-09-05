<template>
  <div class="page-container">
    <div class="sn-card page-head">
      <div class="head-left">
        <el-button text :icon="'ArrowLeft'" @click="router.back()">返回</el-button>
        <div>
          <h3 class="head-title">新建藏品</h3>
          <span class="head-sub">创建后进入草稿状态，需完成发售配置后才会对外发售</span>
        </div>
      </div>
    </div>

    <div class="sn-card">
      <el-form ref="formRef" :model="form" :rules="rules" label-width="110px" style="max-width: 640px">
        <el-form-item label="藏品名称" prop="name">
          <el-input v-model="form.name" maxlength="60" show-word-limit placeholder="如：龙纹罗盘" />
        </el-form-item>
        <el-form-item label="藏品分类" prop="categoryId">
          <el-select v-model="form.categoryId" placeholder="请选择分类" style="width: 240px">
            <el-option v-for="c in CATEGORIES" :key="c.value" :label="c.label" :value="c.value" />
          </el-select>
        </el-form-item>
        <el-form-item label="发行总量" prop="edition">
          <el-input-number v-model="form.edition" :min="1" :max="1000000" :step="100" step-strictly />
          <div class="form-hint">库存池 = 发行总量 − 已售 − 锁定 − 配额预留 − 空投 − 销毁（文档 4.3.1）</div>
        </el-form-item>
        <el-form-item label="简介（副标题）">
          <el-input v-model="form.description" maxlength="120" show-word-limit placeholder="展示在藏品卡片的副标题" />
        </el-form-item>
        <el-form-item label="创作故事">
          <el-input v-model="form.story" type="textarea" :rows="4" maxlength="2000" show-word-limit placeholder="藏品详情页的创作故事介绍" />
        </el-form-item>
        <el-form-item label="主图地址">
          <el-input v-model="form.image" placeholder="/images/collections/cover-1.jpg（P0 直接填路径，P2 接入 OSS 上传）" />
        </el-form-item>
        <el-form-item label="发行方">
          <el-input v-model="form.issuer" placeholder="如：司南数字藏品" style="width: 320px" />
        </el-form-item>
        <el-form-item label="创作者">
          <el-input v-model="form.creator" placeholder="原创艺术家/机构" style="width: 320px" />
        </el-form-item>
        <el-form-item label="标签">
          <el-input v-model="form.tag" placeholder="如：首发 / 限定" style="width: 320px" />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" :loading="submitting" @click="submit">保存草稿</el-button>
          <el-button @click="router.back()">取 消</el-button>
        </el-form-item>
      </el-form>
    </div>
  </div>
</template>

<script setup lang="ts">
// 新建藏品（文档 8.6 #34：创建即草稿，价格在发售配置中设定）
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import { createCollectible } from '@/api/collectible'

const router = useRouter()

/** 分类（种子库静态枚举：水墨/国潮/限定） */
const CATEGORIES = [
  { value: 1, label: '水墨' },
  { value: 2, label: '国潮' },
  { value: 3, label: '限定' }
]

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

async function submit(): Promise<void> {
  const formEl = formRef.value
  if (!formEl) return
  const valid = await formEl.validate().catch(() => false)
  if (!valid) return

  submitting.value = true
  try {
    const data = await createCollectible({
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
    ElMessage.success('藏品创建成功（草稿）')
    router.replace(`/collectible/${data.id}/release`)
  } catch {
    // 拦截器已提示
  } finally {
    submitting.value = false
  }
}
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
