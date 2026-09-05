<template>
  <div class="page-container">
    <div class="sn-card page-head">
      <div class="head-left">
        <el-button text :icon="'ArrowLeft'" @click="router.back()">返回</el-button>
        <div>
          <h3 class="head-title">新建盲盒</h3>
          <span class="head-sub">创建后进入草稿状态，需先配置子藏品与概率，再完成发售配置（文档 6.2）</span>
        </div>
      </div>
    </div>

    <div class="sn-card">
      <el-form ref="formRef" :model="form" :rules="rules" label-width="110px" style="max-width: 640px">
        <el-form-item label="盲盒名称" prop="name">
          <el-input v-model="form.name" maxlength="60" show-word-limit placeholder="如：新春福袋盲盒" />
        </el-form-item>
        <el-form-item label="盲盒分类" prop="categoryId">
          <el-select v-model="form.categoryId" placeholder="请选择分类" style="width: 240px">
            <el-option v-for="c in CATEGORIES" :key="c.value" :label="c.label" :value="c.value" />
          </el-select>
        </el-form-item>
        <el-form-item label="发行总量" prop="edition">
          <el-input-number v-model="form.edition" :min="1" :max="1000000" :step="100" step-strictly />
          <div class="form-hint">盲盒库存池 = 发行总量 − 已售 − 锁定 − 配额预留 − 空投 − 销毁（文档 4.3.3）</div>
        </el-form-item>
        <el-form-item label="盲盒价格">
          <el-input-number v-model="form.price" :min="0" :max="999999" :precision="2" :step="1" style="width: 200px" />
          <span class="form-unit">元</span>
          <div class="form-hint">可暂不填写，发售配置时必须大于 0（文档 8.7 #52）</div>
        </el-form-item>
        <el-form-item label="简介">
          <el-input v-model="form.description" maxlength="120" show-word-limit placeholder="展示在盲盒卡片的简介" />
        </el-form-item>
        <el-form-item label="主图地址">
          <el-input v-model="form.image" placeholder="/images/blindbox/cover-1.jpg（P0 直接填路径，P2 接入 OSS 上传）" />
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
// 新建盲盒（文档 8.7 #52：创建即草稿；价格可在发售配置中设定）
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import { createBlindbox } from '@/api/blindbox'

const router = useRouter()

/** 分类（种子库静态枚举：水墨/国潮/限定，与藏品共用） */
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
  price: null as number | null,
  description: '',
  image: ''
})

const rules: FormRules = {
  name: [{ required: true, message: '盲盒名称不能为空', trigger: 'blur' }],
  categoryId: [{ required: true, message: '请选择盲盒分类', trigger: 'change' }],
  edition: [{ required: true, message: '发行总量必须大于 0', trigger: 'change' }]
}

async function submit(): Promise<void> {
  const formEl = formRef.value
  if (!formEl) return
  const valid = await formEl.validate().catch(() => false)
  if (!valid) return

  submitting.value = true
  try {
    const data = await createBlindbox({
      name: form.name.trim(),
      categoryId: form.categoryId,
      edition: form.edition,
      price: form.price !== null ? form.price.toFixed(2) : '',
      description: form.description.trim(),
      image: form.image.trim()
    })
    ElMessage.success('盲盒创建成功（草稿），请继续配置子藏品与概率')
    router.replace(`/blindbox/${data.id}/items`)
  } catch {
    // 拦截器已提示
  } finally {
    submitting.value = false
  }
}
</script>

<style scoped lang="scss">
@use '@/assets/styles/table-common' as *;

.form-unit {
  margin-left: 8px;
  font-size: 13px;
  color: $sn-text-sub;
}

.form-hint {
  font-size: 12px;
  color: $sn-text-muted;
  line-height: 1.6;
  margin-top: 4px;
}
</style>
