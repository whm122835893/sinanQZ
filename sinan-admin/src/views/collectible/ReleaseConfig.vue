<template>
  <div v-loading="loading" class="page-container">
    <div class="sn-card page-head">
      <div class="head-left">
        <el-button text :icon="'ArrowLeft'" @click="router.push(`/collectible/${id}`)">返回详情</el-button>
        <div>
          <h3 class="head-title">发售配置</h3>
          <span class="head-sub">仅草稿/待发售状态可修改发售配置（文档 6.1）；保存后按发售开始时间自动判定待发售/发售中</span>
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
        :description="`仅草稿/待发售状态可配置，当前藏品状态为「${STATUS_MAP[detail.status] ?? detail.status}」。`"
        style="margin-bottom: 12px"
      />

      <!-- 发售信息（#37） -->
      <div class="sn-card">
        <div class="card-title">发售信息</div>
        <el-form
          ref="releaseFormRef"
          :model="releaseForm"
          :rules="releaseRules"
          label-width="130px"
          :disabled="!releaseEditable"
          style="max-width: 560px"
        >
          <el-form-item label="发售价格" prop="price">
            <el-input-number v-model="releaseForm.price" :min="0.01" :max="999999" :precision="2" :step="1" step-strictly style="width: 200px" />
            <span class="form-unit">元</span>
          </el-form-item>
          <el-form-item label="发售开始时间" prop="onsaleAt">
            <el-date-picker
              v-model="releaseForm.onsaleAt"
              type="datetime"
              value-format="YYYY-MM-DD HH:mm:ss"
              placeholder="选择发售开始时间"
              style="width: 240px"
            />
            <div class="form-hint">开始时间晚于当前时间则进入「待发售」，否则直接「发售中」</div>
          </el-form-item>
          <el-form-item label="发售结束时间" prop="offSaleAt">
            <el-date-picker
              v-model="releaseForm.offSaleAt"
              type="datetime"
              value-format="YYYY-MM-DD HH:mm:ss"
              placeholder="可选，到期自动下架"
              style="width: 240px"
            />
          </el-form-item>
          <el-form-item label="每人限购" prop="perUserLimit">
            <el-input-number v-model="releaseForm.perUserLimit" :min="0" :max="9999" :step="1" step-strictly style="width: 200px" />
            <span class="form-unit">份</span>
            <div class="form-hint">0 表示不限购；非 0 时覆盖系统级每人限购（文档 5.1 并存生效）</div>
          </el-form-item>
          <el-form-item label="计划发售数量" prop="releaseQuantity">
            <el-input-number
              v-model="releaseForm.releaseQuantity"
              :min="1"
              :max="detail.edition"
              :step="10"
              step-strictly
              style="width: 200px"
            />
            <span class="form-unit">份</span>
            <div class="form-hint">留空表示不限；不可超过发行总量 {{ detail.edition }}（文档 8.6 #37）</div>
          </el-form-item>
          <el-form-item>
            <el-button
              v-permission="'collectible:release'"
              type="primary"
              :loading="releaseSubmitting"
              :disabled="!releaseEditable"
              @click="submitRelease"
            >
              保存发售配置
            </el-button>
          </el-form-item>
        </el-form>
      </div>

      <!-- 资格购配置（#47，文档 5.1：发售配置内 Switch 开启，与优先购完全独立） -->
      <div class="sn-card">
        <div class="card-title-row">
          <span class="card-title">资格购配置（购买门槛，与优先购完全独立，文档 5.1）</span>
          <el-switch v-model="qualForm.isEnabled" :disabled="!qualEditable" />
        </div>

        <el-alert
          v-if="detail.status !== 'draft'"
          type="info"
          :closable="false"
          show-icon
          title="仅草稿状态可配置资格购（文档 6.1）"
          style="margin-bottom: 14px"
        />

        <el-form
          ref="qualFormRef"
          :model="qualForm"
          :rules="qualRules"
          label-width="130px"
          :disabled="!qualEditable"
          style="max-width: 720px"
        >
          <el-form-item label="条件组合方式">
            <el-radio-group v-model="qualForm.conditionType">
              <el-radio :value="1">满足任一条件</el-radio>
              <el-radio :value="2">满足全部条件</el-radio>
            </el-radio-group>
          </el-form-item>
          <el-form-item label="资格藏品">
            <el-select
              v-model="qualForm.requiredCollectibleIds"
              multiple
              filterable
              placeholder="从流通量 > 0 的藏品中选择（可多选）"
              style="width: 100%"
              :loading="optionsLoading"
            >
              <el-option
                v-for="opt in collectibleOptions"
                :key="opt.value"
                :label="opt.label"
                :value="opt.value"
              />
            </el-select>
            <div class="form-hint">用户持有其中至少 1 个即满足条件；选择器仅展示流通量 &gt; 0 的藏品（文档 5.1-1）</div>
          </el-form-item>
          <el-form-item label="累计签到天数">
            <el-input-number v-model="qualForm.requiredCheckinDays" :min="0" :max="9999" :step="1" step-strictly style="width: 200px" />
            <span class="form-unit">天</span>
            <div class="form-hint">0 表示不启用该条件</div>
          </el-form-item>
          <el-form-item label="累计邀请人数">
            <el-input-number v-model="qualForm.requiredInviteCount" :min="0" :max="9999" :step="1" step-strictly style="width: 200px" />
            <span class="form-unit">人</span>
            <div class="form-hint">0 表示不启用该条件</div>
          </el-form-item>
          <el-form-item label="资格有效期">
            <el-date-picker
              v-model="qualForm.validStartAt"
              type="datetime"
              value-format="YYYY-MM-DD HH:mm:ss"
              placeholder="开始时间（可选）"
              style="width: 220px"
            />
            <span class="range-sep">~</span>
            <el-date-picker
              v-model="qualForm.validEndAt"
              type="datetime"
              value-format="YYYY-MM-DD HH:mm:ss"
              placeholder="结束时间（可选，过期自动失效）"
              style="width: 220px"
            />
          </el-form-item>
          <el-form-item label="白名单手机号">
            <template v-if="existingWhitelist.length">
              <div class="whitelist-tags">
                <el-tag v-for="w in existingWhitelist" :key="w.userId" size="small" type="info" effect="plain">{{ w.phone }}</el-tag>
              </div>
              <el-alert
                type="warning"
                :closable="false"
                show-icon
                title="下方文本框提交后将全量替换现有白名单，留空提交即清空全部白名单"
                style="width: 100%; margin-bottom: 8px"
              />
            </template>
            <el-input
              v-model="qualForm.whitelistPhones"
              type="textarea"
              :rows="4"
              placeholder="额外资格手机号，换行分隔，每行一个（文档 5.1-4）"
            />
            <div class="form-hint">白名单用户无需满足其他条件（唯一「无条件」通道）；手机号必须为平台已注册用户，否则按行号拦截</div>
          </el-form-item>
          <el-form-item>
            <el-button
              v-permission="'collectible:qualification'"
              type="primary"
              :loading="qualSubmitting"
              :disabled="!qualEditable"
              @click="submitQualification"
            >
              保存资格购配置
            </el-button>
          </el-form-item>
        </el-form>
      </div>
    </template>
  </div>
</template>

<script setup lang="ts">
// 发售配置（文档 8.6 #37）+ 资格购配置（#47，文档 5.1：发售配置内 Switch 开启）
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import dayjs from 'dayjs'
import type { FormInstance, FormRules } from 'element-plus'
import { fetchCollectibleDetail, fetchCollectibles, releaseCollectible, configQualification } from '@/api/collectible'
import type { CollectibleDetail, PageData, CollectibleRow } from '@/types/api'

const route = useRoute()
const router = useRouter()
const id = route.params.id as string

const STATUS_MAP: Record<string, string> = {
  draft: '草稿', upcoming: '待发售', onsale: '发售中', soldout: '已售罄', off: '已下架'
}

const loading = ref(false)
const detail = ref<CollectibleDetail | null>(null)

/** 发售配置：仅 draft/upcoming（文档 6.1） */
const releaseEditable = computed(() => !!detail.value && ['draft', 'upcoming'].includes(detail.value.status))
/** 资格购配置：仅 draft（文档 6.1 状态机） */
const qualEditable = computed(() => detail.value?.status === 'draft')

async function load(): Promise<void> {
  loading.value = true
  try {
    detail.value = await fetchCollectibleDetail(id)
    // 发售信息回填
    releaseForm.price = detail.value.price ? Number(detail.value.price) : 0.01
    releaseForm.onsaleAt = detail.value.onsaleAt ?? ''
    releaseForm.offSaleAt = detail.value.offSaleAt ?? ''
    releaseForm.perUserLimit = detail.value.perUserLimit ?? 0
    releaseForm.releaseQuantity = detail.value.releaseQuantity ?? null
    // 资格购回填
    const q = detail.value.qualification
    qualForm.isEnabled = q?.isEnabled ?? false
    qualForm.conditionType = q?.conditionType ?? 1
    qualForm.requiredCollectibleIds = q?.requiredCollectibleIds ? [...q.requiredCollectibleIds] : []
    qualForm.requiredCheckinDays = q?.requiredCheckinDays ?? 0
    qualForm.requiredInviteCount = q?.requiredInviteCount ?? 0
    qualForm.validStartAt = q?.validStartAt ?? ''
    qualForm.validEndAt = q?.validEndAt ?? ''
    await loadCollectibleOptions()
  } catch {
    // 拦截器已提示
  } finally {
    loading.value = false
  }
}

// ---------------- 发售信息（#37） ----------------
const releaseFormRef = ref<FormInstance>()
const releaseSubmitting = ref(false)
const releaseForm = reactive({
  price: 0.01,
  onsaleAt: '',
  offSaleAt: '',
  perUserLimit: 0,
  releaseQuantity: null as number | null
})

const releaseRules: FormRules = {
  price: [{ required: true, message: '发售价格必须大于 0', trigger: 'change' }],
  onsaleAt: [{ required: true, message: '请选择发售开始时间', trigger: 'change' }],
  offSaleAt: [
    {
      validator: (_rule, value: string, callback) => {
        if (value && releaseForm.onsaleAt && dayjs(value).valueOf() <= dayjs(releaseForm.onsaleAt).valueOf()) {
          callback(new Error('发售结束时间必须晚于开始时间'))
          return
        }
        callback()
      },
      trigger: 'change'
    }
  ]
}

async function submitRelease(): Promise<void> {
  const formEl = releaseFormRef.value
  if (!formEl) return
  const valid = await formEl.validate().catch(() => false)
  if (!valid) return

  releaseSubmitting.value = true
  try {
    const res = await releaseCollectible(id, {
      price: releaseForm.price.toFixed(2),
      onsaleAt: releaseForm.onsaleAt,
      offSaleAt: releaseForm.offSaleAt || undefined,
      perUserLimit: releaseForm.perUserLimit,
      releaseQuantity: releaseForm.releaseQuantity ?? undefined
    })
    ElMessage.success(`发售配置已保存，当前状态：${STATUS_MAP[res.status] ?? res.status}`)
    await load()
  } catch {
    // 拦截器已提示
  } finally {
    releaseSubmitting.value = false
  }
}

// ---------------- 资格购配置（#47，文档 5.1） ----------------
const qualFormRef = ref<FormInstance>()
const qualSubmitting = ref(false)
const qualForm = reactive({
  isEnabled: false,
  conditionType: 1,
  requiredCollectibleIds: [] as number[],
  requiredCheckinDays: 0,
  requiredInviteCount: 0,
  validStartAt: '',
  validEndAt: '',
  whitelistPhones: ''
})

const qualRules: FormRules = {
  validEndAt: [
    {
      validator: (_rule, _value, callback) => {
        if (
          qualForm.validStartAt &&
          qualForm.validEndAt &&
          dayjs(qualForm.validEndAt).valueOf() <= dayjs(qualForm.validStartAt).valueOf()
        ) {
          callback(new Error('有效期结束时间必须晚于开始时间'))
          return
        }
        callback()
      },
      trigger: 'change'
    }
  ]
}

const existingWhitelist = computed(() => detail.value?.qualification?.whitelist ?? [])

/** 资格藏品选项：仅流通量 > 0（文档 5.1-1） */
const optionsLoading = ref(false)
const collectibleOptions = ref<Array<{ value: number; label: string }>>([])

async function loadCollectibleOptions(): Promise<void> {
  optionsLoading.value = true
  try {
    const data = (await fetchCollectibles({ page: 1, pageSize: 100 })) as PageData<CollectibleRow>
    collectibleOptions.value = data.list
      .filter((row) => row.circulate > 0)
      .map((row) => ({ value: row.id, label: `#${row.id} ${row.name}（流通 ${row.circulate}）` }))
  } catch {
    // 拦截器已提示
  } finally {
    optionsLoading.value = false
  }
}

async function submitQualification(): Promise<void> {
  const formEl = qualFormRef.value
  if (!formEl) return
  const valid = await formEl.validate().catch(() => false)
  if (!valid) return

  // 白名单手机号逐行校验（文档 5.1-4：提示行号与号码）
  const phones = qualForm.whitelistPhones
    .split(/\r\n|\r|\n/)
    .map((s) => s.trim())
    .filter(Boolean)
  for (let i = 0; i < phones.length; i++) {
    if (!/^1\d{10}$/.test(phones[i])) {
      ElMessage.warning(`白名单第 ${i + 1} 行手机号 ${phones[i]} 格式不正确`)
      return
    }
  }

  qualSubmitting.value = true
  try {
    await configQualification(id, {
      isEnabled: qualForm.isEnabled ? 1 : 0,
      conditionType: qualForm.conditionType,
      requiredCollectibleIds: qualForm.requiredCollectibleIds,
      requiredCheckinDays: qualForm.requiredCheckinDays,
      requiredInviteCount: qualForm.requiredInviteCount,
      validStartAt: qualForm.validStartAt || '',
      validEndAt: qualForm.validEndAt || '',
      whitelistPhones: phones
    })
    ElMessage.success('资格购配置已保存')
    await load()
  } catch {
    // 拦截器已提示
  } finally {
    qualSubmitting.value = false
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

.card-title-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 14px;

  .card-title {
    margin-bottom: 0;
  }
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

.range-sep {
  margin: 0 8px;
  color: $sn-text-muted;
}

.whitelist-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-bottom: 8px;
}
</style>
