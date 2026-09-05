<template>
  <div v-loading="loading" class="page-container">
    <div class="sn-card page-head">
      <div class="head-left">
        <el-button text :icon="'ArrowLeft'" @click="router.push(`/collectible/${id}`)">返回详情</el-button>
        <div>
          <h3 class="head-title">配额配置</h3>
          <span class="head-sub">配额预留自库存池冻结；任何时间可配置（发售前/中/售罄后均可，文档 4.3.2）</span>
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
      <!-- 既有配额 -->
      <div class="sn-card">
        <div class="table-toolbar">
          <span class="toolbar-title">配额列表</span>
          <span class="toolbar-hint">新增/增量合计 ≤ 当前库存池，超发将被拦截</span>
        </div>

        <el-table :data="detail.quotas" size="default" empty-text="暂无配额">
          <el-table-column label="配额名称" prop="quotaName" min-width="160" show-overflow-tooltip />
          <el-table-column label="类型" width="110">
            <template #default="{ row }">
              <el-tag size="small" :type="QUOTA_TYPE_TAG[row.quotaType] ?? 'info'" effect="plain">
                {{ QUOTA_TYPE_MAP[row.quotaType] ?? row.quotaType }}
              </el-tag>
            </template>
          </el-table-column>
          <el-table-column label="计划数量" prop="plannedQuantity" width="100" align="right" />
          <el-table-column label="已使用" prop="usedQuantity" width="90" align="right" />
          <el-table-column label="剩余" width="90" align="right">
            <template #default="{ row }">
              <span class="din">{{ row.plannedQuantity - row.usedQuantity }}</span>
            </template>
          </el-table-column>
          <el-table-column label="状态" width="90">
            <template #default="{ row }">
              <el-tag :type="row.status === 1 ? 'success' : 'info'" size="small">{{ row.status === 1 ? '启用' : '停用' }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="备注" prop="remark" min-width="120" show-overflow-tooltip>
            <template #default="{ row }">{{ row.remark || '—' }}</template>
          </el-table-column>
          <el-table-column label="创建时间" prop="createdAt" width="170">
            <template #default="{ row }">{{ row.createdAt || '—' }}</template>
          </el-table-column>
          <el-table-column label="操作" width="150" fixed="right">
            <template #default="{ row }">
              <el-button
                v-if="row.status === 1"
                v-permission="'collectible:quota'"
                text
                type="primary"
                @click="openIncrement(row)"
              >
                追加增量
              </el-button>
              <el-button v-permission="'collectible:quota'" text type="primary" @click="openEdit(row)">修改</el-button>
            </template>
          </el-table-column>
        </el-table>
      </div>

      <!-- 新建配额 -->
      <div class="sn-card">
        <div class="card-title">新建配额（自库存池冻结，文档 4.3.2）</div>
        <el-form ref="newFormRef" :model="newForm" :rules="newRules" label-width="110px" style="max-width: 560px" inline-message>
          <el-form-item label="配额类型" prop="quotaType">
            <el-select v-model="newForm.quotaType" placeholder="请选择类型" style="width: 240px">
              <el-option v-for="(label, key) in QUOTA_TYPE_MAP" :key="key" :label="label" :value="Number(key)" />
            </el-select>
          </el-form-item>
          <el-form-item label="配额名称" prop="quotaName">
            <el-input v-model="newForm.quotaName" maxlength="50" show-word-limit placeholder="如：首发活动空投预留" style="width: 240px" />
          </el-form-item>
          <el-form-item label="计划数量" prop="plannedQuantity">
            <el-input-number v-model="newForm.plannedQuantity" :min="1" :max="1000000" :step="10" step-strictly style="width: 200px" />
            <div class="form-hint">剩余库存池：<b class="din">{{ detail.stockPool }}</b>，超出将被拦截</div>
          </el-form-item>
          <el-form-item label="备注">
            <el-input v-model="newForm.remark" maxlength="200" placeholder="可选" />
          </el-form-item>
          <el-form-item>
            <el-button v-permission="'collectible:quota'" type="primary" :loading="newSubmitting" @click="submitNew">新建配额</el-button>
            <el-button @click="resetNew">重 置</el-button>
          </el-form-item>
        </el-form>
      </div>
    </template>

    <!-- 追加增量 -->
    <el-dialog v-model="incrementVisible" title="追加配额增量" width="440px" :close-on-click-modal="false">
      <el-form label-position="top" @submit.prevent>
        <el-form-item label="追加数量（追加到「XXX」计划数量，从库存池冻结）" required>
          <el-input-number
            v-model="incrementForm.quantity"
            :min="1"
            :max="detail?.stockPool ?? 1"
            :step="10"
            step-strictly
            style="width: 100%"
          />
          <div class="form-hint">当前库存池：<b class="din">{{ detail?.stockPool ?? '—' }}</b>，追加后计划数量为
            <b class="din">{{ incrementTarget ? incrementTarget.plannedQuantity + incrementForm.quantity : '—' }}</b></div>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="incrementVisible = false">取 消</el-button>
        <el-button type="primary" :loading="incrementSubmitting" @click="submitIncrement">确认追加</el-button>
      </template>
    </el-dialog>

    <!-- 修改配额（高风险：password，文档 11.1） -->
    <el-dialog v-model="editVisible" title="修改配额" width="480px" :close-on-click-modal="false">
      <el-form label-width="100px" @submit.prevent>
        <el-form-item label="配额名称">
          <el-input v-model="editForm.quotaName" maxlength="50" show-word-limit />
        </el-form-item>
        <el-form-item label="计划数量">
          <el-input-number
            v-model="editForm.plannedQuantity"
            :min="editTarget ? editTarget.usedQuantity : 0"
            :max="1000000"
            :step="10"
            step-strictly
            style="width: 100%"
          />
          <div class="form-hint">已使用 {{ editTarget?.usedQuantity ?? 0 }} 份不可减；减少的差额自动释放回库存池</div>
        </el-form-item>
        <el-form-item label="状态">
          <el-radio-group v-model="editForm.status">
            <el-radio :value="1">启用</el-radio>
            <el-radio :value="0">停用（释放全部未用部分）</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="editForm.remark" maxlength="200" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="editVisible = false">取 消</el-button>
        <el-button type="primary" :loading="editSubmitting" @click="submitEditStep1">下一步（验证密码）</el-button>
      </template>
    </el-dialog>
    <PasswordVerify ref="editPwdRef" title="修改配额确认" :require-reason="false" hint="修改配额属高风险操作，需管理员密码确认后生效。" />
  </div>
</template>

<script setup lang="ts">
// 配额配置（文档 8.6 #38/#39 + 4.3.2 配额规则）
// 新增/增量走 POST quotas[]（Σ增量 ≤ 库存池）；修改走 PUT + PasswordVerify
import { onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import PasswordVerify from '@/components/PasswordVerify.vue'
import { fetchCollectibleDetail, configQuotas, updateQuota } from '@/api/collectible'
import type { CollectibleDetail, QuotaRow } from '@/types/api'

const route = useRoute()
const router = useRouter()
const id = route.params.id as string

/** 配额类型（文档 4.3.2） */
const QUOTA_TYPE_MAP: Record<number, string> = {
  1: '优先购', 2: '活动空投', 3: '签到', 4: '注册', 5: '邀请', 6: '抽奖', 7: '其他'
}
const QUOTA_TYPE_TAG: Record<number, string> = {
  1: 'warning', 2: 'success', 3: 'primary', 4: 'info', 5: '', 6: 'danger', 7: 'info'
}

const loading = ref(false)
const detail = ref<CollectibleDetail | null>(null)

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

// ---------------- 新建配额（#38） ----------------
const newFormRef = ref<FormInstance>()
const newSubmitting = ref(false)
const newForm = reactive({
  quotaType: undefined as number | undefined,
  quotaName: '',
  plannedQuantity: 10,
  remark: ''
})

const newRules: FormRules = {
  quotaType: [{ required: true, message: '请选择配额类型', trigger: 'change' }],
  quotaName: [{ required: true, message: '配额名称不能为空', trigger: 'blur' }],
  plannedQuantity: [{ required: true, message: '计划数量必须大于 0', trigger: 'change' }]
}

async function submitNew(): Promise<void> {
  const formEl = newFormRef.value
  if (!formEl || !newForm.quotaType) return
  const valid = await formEl.validate().catch(() => false)
  if (!valid) return

  newSubmitting.value = true
  try {
    await configQuotas(id, [
      {
        quota_type: newForm.quotaType,
        quota_name: newForm.quotaName.trim(),
        planned_quantity: newForm.plannedQuantity,
        remark: newForm.remark || undefined
      }
    ])
    ElMessage.success('配额创建成功（已自库存池冻结）')
    resetNew()
    await load()
  } catch {
    // 拦截器已提示（库存池不足等 409）
  } finally {
    newSubmitting.value = false
  }
}

function resetNew(): void {
  newForm.quotaType = undefined
  newForm.quotaName = ''
  newForm.plannedQuantity = 10
  newForm.remark = ''
  newFormRef.value?.resetFields()
}

// ---------------- 追加增量（#38，id > 0 = 增量模式） ----------------
const incrementVisible = ref(false)
const incrementSubmitting = ref(false)
const incrementTarget = ref<QuotaRow | null>(null)
const incrementForm = reactive({ quantity: 1 })

function openIncrement(row: QuotaRow): void {
  incrementTarget.value = row
  incrementForm.quantity = 1
  incrementVisible.value = true
}

async function submitIncrement(): Promise<void> {
  const target = incrementTarget.value
  if (!target || incrementForm.quantity < 1) {
    ElMessage.warning('请输入有效的追加数量')
    return
  }
  incrementSubmitting.value = true
  try {
    await configQuotas(id, [
      { id: target.id, planned_quantity: incrementForm.quantity }
    ])
    ElMessage.success(`已为「${target.quotaName}」追加 ${incrementForm.quantity} 份（自库存池冻结）`)
    incrementVisible.value = false
    await load()
  } catch {
    // 拦截器已提示
  } finally {
    incrementSubmitting.value = false
  }
}

// ---------------- 修改配额（#39，password 高风险） ----------------
const editVisible = ref(false)
const editSubmitting = ref(false)
const editTarget = ref<QuotaRow | null>(null)
const editPwdRef = ref<InstanceType<typeof PasswordVerify>>()
const editForm = reactive({
  quotaName: '',
  plannedQuantity: 0,
  status: 1,
  remark: ''
})

function openEdit(row: QuotaRow): void {
  editTarget.value = row
  editForm.quotaName = row.quotaName
  editForm.plannedQuantity = row.plannedQuantity
  editForm.status = row.status
  editForm.remark = row.remark ?? ''
  editVisible.value = true
}

async function submitEditStep1(): Promise<void> {
  const target = editTarget.value
  if (!target) return
  if (editForm.plannedQuantity < target.usedQuantity) {
    ElMessage.warning('计划数量不能低于已使用数量')
    return
  }
  editSubmitting.value = true
  try {
    const ok = await editPwdRef.value?.open({ title: '修改配额确认', requireReason: false })
    if (!ok) return
    await updateQuota(id, target.id, {
      quotaName: editForm.quotaName.trim(),
      plannedQuantity: editForm.plannedQuantity,
      status: editForm.status,
      remark: editForm.remark,
      password: ok.password
    })
    ElMessage.success('配额已更新')
    editVisible.value = false
    await load()
  } catch {
    // 拦截器已提示
  } finally {
    editSubmitting.value = false
  }
}

onMounted(load)
</script>

<style scoped lang="scss">
@use '@/assets/styles/table-common' as *;

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

.toolbar-hint {
  font-size: 12px;
  color: $sn-text-muted;
}

.card-title {
  font-size: 14px;
  font-weight: 600;
  color: $sn-text-main;
  margin-bottom: 14px;
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
