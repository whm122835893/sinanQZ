<template>
  <div v-loading="loading" class="page-container">
    <div class="sn-card page-head">
      <div class="head-left">
        <el-button text :icon="'ArrowLeft'" @click="router.push(`/blindbox/${id}`)">返回详情</el-button>
        <div>
          <h3 class="head-title">独立空投</h3>
          <span class="head-sub">自盲盒库存池扣减发放，计入空投计数与流通量（文档 8.7 #63）</span>
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
        v-if="detail.status === 'draft'"
        type="warning"
        :closable="false"
        show-icon
        title="草稿盲盒不可空投，请先完成发售配置"
        style="margin-bottom: 12px"
      />

      <!-- 空投表单 -->
      <div class="sn-card">
        <div class="card-title">空投发放（每用户份数 × 用户数 ≤ 盲盒库存池）</div>
        <el-form ref="formRef" :model="form" :rules="rules" label-width="130px" style="max-width: 640px">
          <el-form-item label="每用户份数" prop="quantity">
            <el-input-number
              v-model="form.quantity"
              :min="1"
              :max="detail.stockPool || 1"
              :step="1"
              step-strictly
              style="width: 200px"
            />
            <div class="form-hint">当前盲盒库存池：<b class="din">{{ detail.stockPool }}</b>，发放总量超出将被拦截</div>
          </el-form-item>
          <el-form-item label="接收用户手机号" prop="phones">
            <el-input
              v-model="form.phones"
              type="textarea"
              :rows="6"
              placeholder="换行分隔，每行一个手机号（文档 11.1：换行批量）；手机号必须为平台已注册用户"
            />
            <div class="form-hint">共 <b class="din">{{ phoneLines.length }}</b> 个手机号 · 预计发放
              <b class="din">{{ phoneLines.length * form.quantity }}</b> 份
            </div>
          </el-form-item>
          <el-form-item>
            <el-button
              v-permission="'blindbox:airdrop'"
              type="primary"
              :loading="submitting"
              :disabled="detail.status === 'draft'"
              @click="submit"
            >
              发放空投（验证密码）
            </el-button>
          </el-form-item>
        </el-form>
      </div>

      <!-- 发放结果 -->
      <div v-if="result" class="sn-card">
        <div class="card-title">本次发放结果</div>
        <el-descriptions :column="4" border size="small">
          <el-descriptions-item label="用户数">{{ result.users }}</el-descriptions-item>
          <el-descriptions-item label="每用户">{{ result.perUser }} 份</el-descriptions-item>
          <el-descriptions-item label="合计">{{ result.total }} 份</el-descriptions-item>
          <el-descriptions-item label="库存池余额">{{ result.stockPoolAfter }}</el-descriptions-item>
        </el-descriptions>
        <el-table :data="result.issued" size="small" style="margin-top: 12px" empty-text="无发放明细">
          <el-table-column label="手机号" prop="phone" min-width="130" />
          <el-table-column label="用户ID" prop="userId" width="90" />
          <el-table-column label="资产ID" prop="userCollectibleId" width="90" />
          <el-table-column label="编号" prop="serial" min-width="140" />
        </el-table>
      </div>

      <!-- 空投记录（经藏品行 #49 查询） -->
      <div class="sn-card">
        <div class="table-toolbar">
          <span class="toolbar-title">空投发放记录</span>
        </div>
        <el-table v-loading="recordsLoading" :data="records" size="small" empty-text="暂无空投记录">
          <el-table-column label="用户" prop="username" min-width="120" />
          <el-table-column label="手机号" prop="phone" width="130" />
          <el-table-column label="数量" prop="quantity" width="70" align="right" />
          <el-table-column label="状态" width="90">
            <template #default="{ row }">
              <el-tag :type="row.status === 'issued' ? 'success' : 'info'" size="small">
                {{ RECORD_STATUS_MAP[row.status] ?? row.status }}
              </el-tag>
            </template>
          </el-table-column>
          <el-table-column label="来源" prop="source" min-width="130" show-overflow-tooltip />
          <el-table-column label="发放时间" prop="issuedAt" min-width="170">
            <template #default="{ row }">{{ row.issuedAt || '—' }}</template>
          </el-table-column>
          <el-table-column label="创建时间" prop="createdAt" min-width="170" />
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
      title="独立空投确认"
      :require-reason="false"
      hint="空投将自盲盒库存池扣减并直接发放给用户（计入流通量，C 端可开启），需管理员密码确认。"
    />
  </div>
</template>

<script setup lang="ts">
// 独立空投盲盒（文档 8.7 #63 + 11.1：二次弹窗 + 密码）+ 空投记录（经藏品行 #49）
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import PasswordVerify from '@/components/PasswordVerify.vue'
import { fetchBlindboxDetail, airdropBlindbox } from '@/api/blindbox'
import { fetchAirdropRecords } from '@/api/collectible'
import type { AirdropRecordRow, AirdropResult, BlindboxDetail, PageData } from '@/types/api'

const route = useRoute()
const router = useRouter()
const id = route.params.id as string

const RECORD_STATUS_MAP: Record<string, string> = {
  issued: '已发放', reverted: '已撤销', pending: '待发放'
}

const loading = ref(false)
const detail = ref<BlindboxDetail | null>(null)

async function load(): Promise<void> {
  loading.value = true
  try {
    detail.value = await fetchBlindboxDetail(id)
  } catch {
    // 拦截器已提示
  } finally {
    loading.value = false
  }
}

// ---------------- 空投表单（#63） ----------------
const formRef = ref<FormInstance>()
const submitting = ref(false)
const pwdRef = ref<InstanceType<typeof PasswordVerify>>()
const form = reactive({ quantity: 1, phones: '' })

/** 文本框按行拆分手机号（去空行/去重） */
const phoneLines = computed<string[]>(() => {
  const lines = form.phones
    .split(/\r\n|\r|\n/)
    .map((s) => s.trim())
    .filter(Boolean)
  return [...new Set(lines)]
})

const rules: FormRules = {
  quantity: [{ required: true, message: '每用户空投份数必须大于 0', trigger: 'change' }],
  phones: [
    {
      validator: (_rule, _value, callback) => {
        const lines = phoneLines.value
        if (!lines.length) {
          callback(new Error('请输入至少一个手机号'))
          return
        }
        for (let i = 0; i < lines.length; i++) {
          if (!/^1\d{10}$/.test(lines[i])) {
            callback(new Error(`第 ${i + 1} 行手机号 ${lines[i]} 格式不正确`))
            return
          }
        }
        callback()
      },
      trigger: 'blur'
    }
  ]
}

const result = ref<AirdropResult | null>(null)

async function submit(): Promise<void> {
  const formEl = formRef.value
  if (!formEl) return
  const valid = await formEl.validate().catch(() => false)
  if (!valid) return

  const ok = await pwdRef.value?.open({ title: '独立空投确认', requireReason: false })
  if (!ok) return

  submitting.value = true
  try {
    result.value = await airdropBlindbox(id, {
      quantity: form.quantity,
      phones: phoneLines.value,
      password: ok.password
    })
    ElMessage.success(`空投成功：${result.value.users} 名用户共 ${result.value.total} 份`)
    form.phones = ''
    await Promise.all([load(), handleRecordsSearch()])
  } catch {
    // 拦截器已提示（库存池不足/手机号未注册等）
  } finally {
    submitting.value = false
  }
}

// ---------------- 空投记录（经盲盒藏品行 #49） ----------------
const recordsLoading = ref(false)
const records = ref<AirdropRecordRow[]>([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(10)

function handleRecordsSearch(): void {
  page.value = 1
  loadRecords()
}

async function loadRecords(): Promise<void> {
  const collectibleId = detail.value?.collectibleId
  if (!collectibleId) return
  recordsLoading.value = true
  try {
    const data = (await fetchAirdropRecords(collectibleId, { page: page.value, pageSize: pageSize.value })) as PageData<AirdropRecordRow>
    records.value = data.list
    total.value = data.total
  } catch {
    // 拦截器已提示
  } finally {
    recordsLoading.value = false
  }
}

onMounted(async () => {
  await load()
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
