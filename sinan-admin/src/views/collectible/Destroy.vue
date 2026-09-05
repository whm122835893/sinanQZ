<template>
  <div v-loading="loading" class="page-container">
    <div class="sn-card page-head">
      <div class="head-left">
        <el-button text :icon="'ArrowLeft'" @click="router.push(`/collectible/${id}`)">返回详情</el-button>
        <div>
          <h3 class="head-title">销毁库存</h3>
          <span class="head-sub">不可逆操作：自库存池扣减并写入销毁记录与审计（文档 8.6 #42）</span>
        </div>
      </div>
      <div class="stock-strip">
        <div class="stock-item">
          <span class="stock-label">发行总量</span>
          <span class="stock-value din">{{ detail?.edition ?? '—' }}</span>
        </div>
        <div class="stock-item">
          <span class="stock-label">已售出发售</span>
          <span class="stock-value din">{{ detail?.sold ?? '—' }}</span>
        </div>
        <div class="stock-item">
          <span class="stock-label">已配置配额</span>
          <span class="stock-value din">{{ detail?.reservedCount ?? '—' }}</span>
        </div>
        <div class="stock-item">
          <span class="stock-label">库存池</span>
          <span class="stock-value din">{{ detail?.stockPool ?? '—' }}</span>
        </div>
        <div class="stock-item">
          <span class="stock-label">流通量</span>
          <span class="stock-value din">{{ detail?.circulate ?? '—' }}</span>
        </div>
      </div>
    </div>

    <template v-if="detail">
      <el-alert
        type="error"
        :closable="false"
        show-icon
        title="销毁不可逆"
        description="销毁数量自库存池永久扣减，销毁后无法恢复，请谨慎操作；每次操作均需管理员密码并写审计日志。"
        style="margin-bottom: 12px"
      />

      <!-- 销毁表单 -->
      <div class="sn-card">
        <div class="card-title">销毁库存（仅 onsale/soldout 可销毁，文档 6.1）</div>
        <el-form
          ref="formRef"
          :model="form"
          :rules="rules"
          label-width="110px"
          :disabled="!destroyable"
          style="max-width: 560px"
        >
          <el-form-item label="销毁数量" prop="quantity">
            <el-input-number
              v-model="form.quantity"
              :min="1"
              :max="detail.stockPool || 1"
              :step="10"
              step-strictly
              style="width: 200px"
            />
            <div class="form-hint">当前库存池：<b class="din">{{ detail.stockPool }}</b>，销毁数量不可超过库存池</div>
          </el-form-item>
          <el-form-item label="销毁原因" prop="reason">
            <el-input
              v-model="form.reason"
              type="textarea"
              :rows="3"
              maxlength="200"
              show-word-limit
              placeholder="必填，将写入销毁记录与审计日志"
            />
          </el-form-item>
          <el-form-item>
            <el-button
              v-permission="'collectible:destroy'"
              type="danger"
              :loading="submitting"
              :disabled="!destroyable"
              @click="submit"
            >
              确认销毁（不可逆）
            </el-button>
          </el-form-item>
        </el-form>
      </div>

      <!-- 销毁结果 -->
      <div v-if="result" class="sn-card">
        <div class="card-title">本次销毁结果</div>
        <el-descriptions :column="2" border size="small">
          <el-descriptions-item label="已销毁数量">{{ result.destroyed }} 份</el-descriptions-item>
          <el-descriptions-item label="库存池余额">{{ result.stockPoolAfter }}</el-descriptions-item>
        </el-descriptions>
      </div>

      <!-- 销毁记录（#50） -->
      <div class="sn-card">
        <div class="table-toolbar">
          <span class="toolbar-title">销毁记录</span>
        </div>
        <el-table v-loading="recordsLoading" :data="records" size="small" empty-text="暂无销毁记录">
          <el-table-column label="数量" prop="quantity" width="80" align="right" />
          <el-table-column label="原因" prop="reason" min-width="200" show-overflow-tooltip />
          <el-table-column label="操作人" prop="adminName" width="110" />
          <el-table-column label="IP" prop="ip" width="130" />
          <el-table-column label="时间" prop="createdAt" min-width="170" />
        </el-table>
        <div class="table-pagination">
          <el-pagination
            v-model:current-page="page"
            v-model:page-size="pageSize"
            :total="total"
            :page-sizes="[10, 20, 50]"
            layout="total, sizes, prev, pager, next, jumper"
            @current-change="loadRecords"
            @size-change="handleRecordsSearch"
          />
        </div>
      </div>
    </template>

    <PasswordVerify
      ref="pwdRef"
      title="销毁库存确认"
      :require-reason="false"
      hint="销毁为不可逆操作，将自库存池永久扣减指定数量，需管理员密码确认。"
    />
  </div>
</template>

<script setup lang="ts">
// 销毁库存（文档 8.6 #42 + 11.1：二次弹窗 + 数量 + 密码）+ 销毁记录（#50）
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import PasswordVerify from '@/components/PasswordVerify.vue'
import { fetchCollectibleDetail, destroyCollectible, fetchDestroyRecords } from '@/api/collectible'
import type { CollectibleDetail, DestroyRecordRow, PageData } from '@/types/api'

const route = useRoute()
const router = useRouter()
const id = route.params.id as string

const loading = ref(false)
const detail = ref<CollectibleDetail | null>(null)

/** 销毁仅 onsale/soldout 状态可用（文档 6.1 状态机） */
const destroyable = computed(() => !!detail.value && ['onsale', 'soldout'].includes(detail.value.status))

async function load(): Promise<void> {
  loading.value = true
  try {
    detail.value = await fetchCollectibleDetail(id)
  } catch {
    // 拦截器已提示
  } finally {
    loading.value = false
  }
}

// ---------------- 销毁表单（#42） ----------------
const formRef = ref<FormInstance>()
const submitting = ref(false)
const pwdRef = ref<InstanceType<typeof PasswordVerify>>()
const form = reactive({ quantity: 1, reason: '' })

const rules: FormRules = {
  quantity: [{ required: true, message: '销毁数量必须大于 0', trigger: 'change' }],
  reason: [{ required: true, message: '销毁原因不能为空', trigger: 'blur' }]
}

const result = ref<{ destroyed: number; stockPoolAfter: number } | null>(null)

async function submit(): Promise<void> {
  const formEl = formRef.value
  if (!formEl) return
  const valid = await formEl.validate().catch(() => false)
  if (!valid) return

  const ok = await pwdRef.value?.open({ title: '销毁库存确认', requireReason: false })
  if (!ok) return

  submitting.value = true
  try {
    result.value = await destroyCollectible(id, {
      quantity: form.quantity,
      reason: form.reason.trim(),
      password: ok.password
    })
    ElMessage.success(`已销毁 ${result.value.destroyed} 份（不可逆），库存池余额 ${result.value.stockPoolAfter}`)
    form.reason = ''
    await Promise.all([load(), handleRecordsSearch()])
  } catch {
    // 拦截器已提示（库存池不足等）
  } finally {
    submitting.value = false
  }
}

// ---------------- 销毁记录（#50） ----------------
const recordsLoading = ref(false)
const records = ref<DestroyRecordRow[]>([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(10)

function handleRecordsSearch(): void {
  page.value = 1
  loadRecords()
}

async function loadRecords(): Promise<void> {
  recordsLoading.value = true
  try {
    const data = (await fetchDestroyRecords(id, { page: page.value, pageSize: pageSize.value })) as PageData<DestroyRecordRow>
    records.value = data.list
    total.value = data.total
  } catch {
    // 拦截器已提示
  } finally {
    recordsLoading.value = false
  }
}

onMounted(() => {
  load()
  loadRecords()
})
</script>

<style scoped lang="scss">
@use '@/assets/styles/table-common' as *;

.card-title {
  font-size: 14px;
  font-weight: 600;
  color: $sn-text-main;
  margin-bottom: 14px;
}

.stock-strip {
  display: flex;
  gap: 10px;

  .stock-item {
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding: 10px 18px;
    background: $sn-bg;
    border-radius: 10px;
    min-width: 110px;

    .stock-label {
      font-size: 12px;
      color: $sn-text-sub;
    }

    .stock-value {
      font-size: 20px;
      font-weight: 600;
      color: $sn-text-main;
    }
  }
}

.form-hint {
  font-size: 12px;
  color: $sn-text-muted;
  margin-top: 4px;
  line-height: 1.6;

  b {
    color: $sn-primary;
  }
}
</style>
