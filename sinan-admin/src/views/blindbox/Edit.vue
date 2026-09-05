<template>
  <div v-loading="loading" class="page-container">
    <div class="sn-card page-head">
      <div class="head-left">
        <el-button text :icon="'ArrowLeft'" @click="router.push(`/blindbox/${id}`)">返回详情</el-button>
        <div>
          <h3 class="head-title">编辑盲盒</h3>
          <span class="head-sub">仅草稿/已下架状态可编辑基础信息（文档 6.2）</span>
        </div>
      </div>
    </div>

    <template v-if="detail">
      <el-alert
        v-if="!editable"
        type="warning"
        :closable="false"
        show-icon
        title="当前状态不允许编辑盲盒"
        :description="`仅草稿/已下架可编辑，当前盲盒状态为「${STATUS_MAP[detail.status] ?? detail.status}」。`"
        style="margin-bottom: 12px"
      />

      <div class="sn-card">
        <el-form ref="formRef" :model="form" :rules="rules" label-width="110px" :disabled="!editable" style="max-width: 640px">
          <el-form-item label="盲盒名称" prop="name">
            <el-input v-model="form.name" maxlength="60" show-word-limit />
          </el-form-item>
          <el-form-item label="发行总量" prop="edition">
            <el-input-number v-model="form.edition" :min="detail.edition" :max="1000000" :step="100" step-strictly />
            <div class="form-hint">
              发行总量只能调大不可调小（当前 {{ detail.edition }}，已分配
              {{ detail.sold + detail.reservedCount + detail.airdroppedCount + detail.destroyedCount }}）
            </div>
          </el-form-item>
          <el-form-item label="盲盒价格">
            <el-input-number v-model="form.price" :min="0" :max="999999" :precision="2" :step="1" style="width: 200px" />
            <span class="form-unit">元</span>
          </el-form-item>
          <el-form-item label="简介">
            <el-input v-model="form.description" maxlength="120" show-word-limit />
          </el-form-item>
          <el-form-item label="主图地址">
            <el-input v-model="form.image" />
          </el-form-item>
          <el-form-item>
            <el-button type="primary" :loading="submitting" :disabled="!editable" @click="submit">保存修改</el-button>
            <el-button @click="router.push(`/blindbox/${id}`)">取 消</el-button>
          </el-form-item>
        </el-form>
      </div>
    </template>
  </div>
</template>

<script setup lang="ts">
// 编辑盲盒（文档 8.7 #54：仅 draft/off 可编辑；edition 只可调大）
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import { fetchBlindboxDetail, updateBlindbox } from '@/api/blindbox'
import type { BlindboxDetail } from '@/types/api'

const route = useRoute()
const router = useRouter()
const id = route.params.id as string

const STATUS_MAP: Record<string, string> = {
  draft: '草稿', upcoming: '待发售', onsale: '发售中', soldout: '已售罄', off: '已下架'
}

const loading = ref(false)
const detail = ref<BlindboxDetail | null>(null)

/** 编辑仅 draft/off（文档 6.2 状态机） */
const editable = computed(() => !!detail.value && ['draft', 'off'].includes(detail.value.status))

const formRef = ref<FormInstance>()
const submitting = ref(false)
const form = reactive({
  name: '',
  edition: 100,
  price: null as number | null,
  description: '',
  image: ''
})

const rules: FormRules = {
  name: [{ required: true, message: '盲盒名称不能为空', trigger: 'blur' }],
  edition: [{ required: true, message: '发行总量必须大于 0', trigger: 'change' }]
}

async function load(): Promise<void> {
  loading.value = true
  try {
    detail.value = await fetchBlindboxDetail(id)
    const d = detail.value
    form.name = d.name
    form.edition = d.edition
    form.price = d.price ? Number(d.price) : null
    form.description = d.description ?? ''
    form.image = d.image
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
    await updateBlindbox(id, {
      name: form.name.trim(),
      edition: form.edition,
      price: form.price !== null ? form.price.toFixed(2) : '',
      description: form.description.trim(),
      image: form.image.trim()
    })
    ElMessage.success('盲盒信息已保存')
    router.push(`/blindbox/${id}`)
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
