<template>
  <div v-loading="loading" class="page-container">
    <div class="sn-card page-head">
      <div class="head-left">
        <el-button text :icon="'ArrowLeft'" @click="router.push(`/blindbox/${id}`)">返回详情</el-button>
        <div>
          <h3 class="head-title">发售配置</h3>
          <span class="head-sub">仅草稿/待发售状态可修改；保存后按发售开始时间自动判定待发售/发售中（文档 8.7 #58）</span>
        </div>
      </div>
    </div>

    <template v-if="detail">
      <el-alert
        v-if="!releaseEditable"
        type="warning"
        :closable="false"
        show-icon
        title="当前状态不允许修改发售配置"
        :description="`仅草稿/待发售状态可配置，当前盲盒状态为「${STATUS_MAP[detail.status] ?? detail.status}」。`"
        style="margin-bottom: 12px"
      />

      <el-alert
        v-if="!detail.items.length"
        type="warning"
        :closable="false"
        show-icon
        title="盲盒尚未配置子藏品，不可发售"
        style="margin-bottom: 12px"
      >
        <el-button text type="primary" v-permission="'blindbox:config'" @click="router.push(`/blindbox/${id}/items`)">去配置子藏品与概率</el-button>
      </el-alert>

      <!-- 发售信息（#58） -->
      <div class="sn-card">
        <div class="card-title">发售信息</div>
        <el-form
          ref="formRef"
          :model="form"
          :rules="rules"
          label-width="130px"
          :disabled="!releaseEditable"
          style="max-width: 560px"
        >
          <el-form-item label="发售价格" prop="price">
            <el-input-number v-model="form.price" :min="0.01" :max="999999" :precision="2" :step="1" step-strictly style="width: 200px" />
            <span class="form-unit">元</span>
          </el-form-item>
          <el-form-item label="发售开始时间" prop="onsaleAt">
            <el-date-picker
              v-model="form.onsaleAt"
              type="datetime"
              value-format="YYYY-MM-DD HH:mm:ss"
              placeholder="选择发售开始时间"
              style="width: 240px"
            />
            <div class="form-hint">开始时间晚于当前时间则进入「待发售」，否则直接「发售中」</div>
          </el-form-item>
          <el-form-item label="发售结束时间" prop="offSaleAt">
            <el-date-picker
              v-model="form.offSaleAt"
              type="datetime"
              value-format="YYYY-MM-DD HH:mm:ss"
              placeholder="可选，到期自动下架"
              style="width: 240px"
            />
          </el-form-item>
          <el-form-item label="计划发售数量" prop="releaseQuantity">
            <el-input-number
              v-model="form.releaseQuantity"
              :min="1"
              :max="detail.edition"
              :step="10"
              step-strictly
              style="width: 200px"
            />
            <span class="form-unit">份</span>
            <div class="form-hint">留空表示不限；不可超过盲盒发行总量 {{ detail.edition }}</div>
          </el-form-item>
          <el-form-item>
            <el-button
              v-permission="'blindbox:release'"
              type="primary"
              :loading="submitting"
              :disabled="!releaseEditable"
              @click="submit"
            >
              保存发售配置
            </el-button>
          </el-form-item>
        </el-form>
      </div>
    </template>
  </div>
</template>

<script setup lang="ts">
// 发售配置（文档 8.7 #58：price/onsaleAt/offSaleAt?/releaseQuantity?）
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import dayjs from 'dayjs'
import type { FormInstance, FormRules } from 'element-plus'
import { fetchBlindboxDetail, releaseBlindbox } from '@/api/blindbox'
import type { BlindboxDetail } from '@/types/api'

const route = useRoute()
const router = useRouter()
const id = route.params.id as string

const STATUS_MAP: Record<string, string> = {
  draft: '草稿', upcoming: '待发售', onsale: '发售中', soldout: '已售罄', off: '已下架'
}

const loading = ref(false)
const detail = ref<BlindboxDetail | null>(null)

/** 发售配置：仅 draft/upcoming（文档 6.2） */
const releaseEditable = computed(() => !!detail.value && ['draft', 'upcoming'].includes(detail.value.status))

const formRef = ref<FormInstance>()
const submitting = ref(false)
const form = reactive({
  price: 0.01,
  onsaleAt: '',
  offSaleAt: '',
  releaseQuantity: null as number | null
})

const rules: FormRules = {
  price: [{ required: true, message: '发售价格必须大于 0', trigger: 'change' }],
  onsaleAt: [{ required: true, message: '请选择发售开始时间', trigger: 'change' }],
  offSaleAt: [
    {
      validator: (_rule, value: string, callback) => {
        if (value && form.onsaleAt && dayjs(value).valueOf() <= dayjs(form.onsaleAt).valueOf()) {
          callback(new Error('发售结束时间必须晚于开始时间'))
          return
        }
        callback()
      },
      trigger: 'change'
    }
  ]
}

async function load(): Promise<void> {
  loading.value = true
  try {
    detail.value = await fetchBlindboxDetail(id)
    form.price = detail.value.price ? Number(detail.value.price) : 0.01
    form.onsaleAt = detail.value.onsaleAt ?? ''
    form.offSaleAt = detail.value.offSaleAt ?? ''
    form.releaseQuantity = detail.value.releaseQuantity ?? null
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
    const res = await releaseBlindbox(id, {
      price: form.price.toFixed(2),
      onsaleAt: form.onsaleAt,
      offSaleAt: form.offSaleAt || undefined,
      releaseQuantity: form.releaseQuantity ?? undefined
    })
    ElMessage.success(`发售配置已保存，当前状态：${STATUS_MAP[res.status] ?? res.status}`)
    await load()
  } catch {
    // 拦截器已提示（未配置子藏品等）
  } finally {
    submitting.value = false
  }
}

onMounted(load)
</script>

<style scoped lang="scss">
@use '@/assets/styles/table-common' as *;

.card-title {
  font-size: 14px;
  font-weight: 600;
  color: $sn-text-main;
  margin-bottom: 14px;
}

.form-unit {
  margin-left: 8px;
  font-size: 13px;
  color: $sn-text-sub;
}

.form-hint {
  font-size: 12px;
  color: $sn-text-muted;
  margin-top: 4px;
  line-height: 1.6;
}
</style>
