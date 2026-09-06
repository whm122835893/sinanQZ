<script setup lang="ts">
/**
 * 系统配置 - 支付渠道配置（第三方钱包）
 *
 * - fetchPaymentChannels 渠道列表，每个渠道一张卡：渠道名/编码/启用状态/费率/密钥脱敏摘要/编辑按钮
 * - 编辑弹窗：启用状态、推荐、排序、费率、备注 + 按渠道差异化参数配置表单（密钥 type=password 永不回显，留空保持不变）
 * - 保存调 savePaymentChannel；后端将 config JSON 整体 AES 加密落库，启用非余额渠道前必须已有密钥
 *
 * 接口：GET /admin/system/payment-channels、PUT /admin/system/payment-channels/:id
 * 权限：system:payment
 */
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { Refresh } from '@element-plus/icons-vue'
import { fetchPaymentChannels, savePaymentChannel } from '@/api/system'
import { datetime } from '@/utils/format'

/** 渠道编码展示字典（与后端白名单 balance/alipay/wechat/huifu/unionpay 对齐） */
const CHANNEL_NAME: Record<string, string> = {
  balance: '余额',
  alipay: '支付宝',
  wechat: '微信支付',
  huifu: '汇付',
  unionpay: '银联',
}

/** 渠道密钥 config JSON 字段定义（按渠道差异化；secret 字段以密码框掩码输入） */
interface ConfigField {
  key: string
  label: string
  secret?: boolean
  hint?: string
}

const CHANNEL_CONFIG_FIELDS: Record<string, ConfigField[]> = {
  alipay: [
    { key: 'app_id', label: '应用ID（AppID）' },
    { key: 'private_key', label: '应用私钥', secret: true },
    { key: 'alipay_public_key', label: '支付宝公钥', secret: true },
    { key: 'gateway', label: '网关地址', hint: '选填' },
  ],
  wechat: [
    { key: 'app_id', label: '应用ID（AppID）' },
    { key: 'mch_id', label: '商户号' },
    { key: 'mch_key', label: '商户密钥（MchKey）', secret: true },
    { key: 'api_v3_key', label: 'APIv3 密钥', secret: true, hint: '选填' },
  ],
  huifu: [
    { key: 'huifu_id', label: '汇付商户号' },
    { key: 'private_key', label: '商户私钥', secret: true },
    { key: 'gateway', label: '网关地址', hint: '选填' },
  ],
  unionpay: [
    { key: 'mer_id', label: '银联商户号' },
    { key: 'private_key', label: '商户私钥', secret: true },
    { key: 'gateway', label: '网关地址', hint: '选填' },
  ],
}

const loading = ref(false)
const channels = ref<any[]>([])

async function load() {
  loading.value = true
  try {
    channels.value = (await fetchPaymentChannels()) ?? []
  } finally {
    loading.value = false
  }
}

onMounted(load)

function channelText(code: string) {
  return CHANNEL_NAME[code] ?? code
}

// ===== 编辑弹窗 =====

const editVisible = ref(false)
const saving = ref(false)

const editForm = reactive({
  id: 0,
  channelCode: '',
  channelName: '',
  feeRate: 0,
  status: 1,
  isRecommended: 0,
  sortOrder: 0,
  remark: '',
  configExists: false,
  clearConfig: false,
  config: {} as Record<string, string>,
})

const configFields = computed<ConfigField[]>(() => CHANNEL_CONFIG_FIELDS[editForm.channelCode] ?? [])
const isBalance = computed(() => editForm.channelCode === 'balance')

function openEdit(row: any) {
  editForm.id = Number(row.id)
  editForm.channelCode = String(row.channelCode ?? '')
  editForm.channelName = String(row.channelName ?? '')
  editForm.feeRate = Number(row.feeRate ?? 0)
  editForm.status = Number(row.status) === 1 ? 1 : 0
  editForm.isRecommended = Number(row.isRecommended) === 1 ? 1 : 0
  editForm.sortOrder = Number(row.sortOrder ?? 0)
  editForm.remark = String(row.remark ?? '')
  editForm.configExists = !!row.configExists
  editForm.clearConfig = false
  // 密钥永不回显：进入编辑时密钥输入框一律留空，留空提交表示保持原密钥不变
  editForm.config = {}
  editVisible.value = true
}

async function submitEdit() {
  if (editForm.channelName.trim() === '') {
    ElMessage.warning('渠道名称不能为空')
    return
  }
  if (editForm.feeRate < 0 || editForm.feeRate > 100) {
    ElMessage.warning('手续费率需在 0~100% 之间')
    return
  }

  const payload: Record<string, any> = {
    channel_name: editForm.channelName.trim(),
    fee_rate: editForm.feeRate,
    status: editForm.status,
    is_recommended: editForm.isRecommended,
    sort_order: editForm.sortOrder,
    remark: editForm.remark.trim(),
  }

  // 密钥配置：填写任一字段才提交 config（整体覆盖）；勾选「清除」提交空串
  if (!isBalance.value) {
    if (editForm.clearConfig) {
      payload.config = ''
    } else {
      const filled = configFields.value.filter((f) => (editForm.config[f.key] ?? '').trim() !== '')
      if (filled.length > 0) {
        payload.config = Object.fromEntries(
          filled.map((f) => [f.key, editForm.config[f.key].trim()]),
        )
      }
    }
  }

  // 严谨性：启用第三方渠道前必须已有（或本次填入）密钥配置
  const hasConfigAfterSave = editForm.clearConfig ? false : !!payload.config || editForm.configExists
  if (editForm.status === 1 && !isBalance.value && !hasConfigAfterSave) {
    ElMessage.warning('启用第三方支付渠道前，需先配置渠道密钥（config）')
    return
  }

  saving.value = true
  try {
    await savePaymentChannel(editForm.id, payload)
    editVisible.value = false
    ElMessage.success('支付渠道配置已保存')
    load()
  } catch {
    /* 业务错误已全局提示 */
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="page-container">
    <div class="table-card">
      <div class="card-header">
        <div>
          <div class="card-title">支付渠道配置（第三方钱包）</div>
          <div class="card-sub">
            渠道密钥整体 AES 加密落库、接口永不回显明文（仅展示脱敏摘要）；启用第三方渠道前必须完成密钥配置
          </div>
        </div>
        <el-button :icon="Refresh" :loading="loading" @click="load">刷新</el-button>
      </div>

      <div v-loading="loading" class="channel-grid">
        <div v-for="ch in channels" :key="ch.id" class="channel-card">
          <div class="channel-head">
            <div class="channel-title">
              <span class="channel-name">{{ ch.channelName }}</span>
              <el-tag size="small" type="info" class="code-tag">{{ channelText(ch.channelCode) }}</el-tag>
              <el-tag v-if="Number(ch.isRecommended) === 1" size="small" type="warning">推荐</el-tag>
            </div>
            <el-tag :type="Number(ch.status) === 1 ? 'success' : 'info'" size="small">
              {{ Number(ch.status) === 1 ? '已启用' : '已停用' }}
            </el-tag>
          </div>

          <div class="channel-body">
            <div class="info-row">
              <span class="info-label">渠道编码</span>
              <span class="mono">{{ ch.channelCode }}</span>
            </div>
            <div class="info-row">
              <span class="info-label">手续费率</span>
              <span class="fee">{{ Number(ch.feeRate ?? 0) }}%</span>
            </div>
            <div class="info-row">
              <span class="info-label">密钥配置</span>
              <span v-if="ch.configExists" class="masked">{{ ch.configMasked || '已配置' }}</span>
              <span v-else class="not-configured">
                {{ ch.channelCode === 'balance' ? '余额渠道无需密钥' : '未配置' }}
              </span>
            </div>
            <div class="info-row">
              <span class="info-label">备注</span>
              <span>{{ ch.remark || '-' }}</span>
            </div>
          </div>

          <div class="channel-foot">
            <span class="updater">
              {{ ch.updatedByName || '-' }} · {{ datetime(ch.updatedAt) }}
            </span>
            <el-button v-permission="'system:payment'" type="primary" size="small" @click="openEdit(ch)">
              编辑
            </el-button>
          </div>
        </div>
      </div>

      <el-empty v-if="!loading && channels.length === 0" description="暂无支付渠道" />
    </div>

    <!-- 编辑弹窗 -->
    <el-dialog v-model="editVisible" title="编辑支付渠道" width="560px" destroy-on-close>
      <el-form label-width="110px">
        <el-form-item label="渠道名称">
          <el-input v-model="editForm.channelName" maxlength="50" placeholder="请输入渠道名称" />
        </el-form-item>
        <el-form-item label="启用状态">
          <el-switch v-model="editForm.status" :active-value="1" :inactive-value="0" active-text="启用" inactive-text="停用" />
        </el-form-item>
        <el-form-item label="推荐渠道">
          <el-switch v-model="editForm.isRecommended" :active-value="1" :inactive-value="0" active-text="是" inactive-text="否" />
        </el-form-item>
        <el-form-item label="手续费率（%）">
          <el-input-number v-model="editForm.feeRate" :min="0" :max="100" :precision="2" :step="0.1" controls-position="right" />
        </el-form-item>
        <el-form-item label="排序值">
          <el-input-number v-model="editForm.sortOrder" :min="0" :max="9999" :step="1" controls-position="right" />
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="editForm.remark" type="textarea" :rows="2" maxlength="255" show-word-limit placeholder="选填" />
        </el-form-item>

        <el-divider content-position="left">渠道密钥参数（config）</el-divider>

        <template v-if="isBalance">
          <el-alert type="info" :closable="false" show-icon title="余额渠道为平台内建支付方式，无需配置第三方密钥" />
        </template>
        <template v-else>
          <el-alert
            type="info"
            :closable="false"
            show-icon
            title="密钥加密存储且永不回显：留空表示保持原密钥不变，填写后将整体覆盖更新"
            style="margin-bottom: 14px"
          />
          <el-form-item v-for="field in configFields" :key="field.key" :label="field.label">
            <el-input
              v-model="editForm.config[field.key]"
              :type="field.secret ? 'password' : 'text'"
              :show-password="field.secret"
              :placeholder="field.secret ? '留空表示保持不变' : (field.hint === '选填' ? '选填' : '请输入')"
              clearable
            />
            <div v-if="field.hint" class="field-hint">{{ field.hint }}</div>
          </el-form-item>
          <el-form-item v-if="editForm.configExists" label="清除密钥">
            <el-checkbox v-model="editForm.clearConfig">清除已配置的渠道密钥（清除后不可启用）</el-checkbox>
          </el-form-item>
        </template>
      </el-form>

      <template #footer>
        <el-button @click="editVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="submitEdit">保存配置</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped lang="scss">
.card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 14px;

  .card-title {
    font-size: 15px;
    font-weight: 600;
  }
  .card-sub {
    margin-top: 4px;
    font-size: 12px;
    color: var(--sn-text-secondary);
  }
}

.channel-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
  gap: 12px;
  min-height: 120px;
}

.channel-card {
  border: 1px solid var(--sn-border);
  border-radius: 8px;
  padding: 16px;
  display: flex;
  flex-direction: column;
  gap: 10px;
  transition: all 0.15s ease;

  &:hover {
    border-color: var(--sn-red);
    box-shadow: 0 2px 8px rgba(176, 0, 0, 0.08);
  }
}

.channel-head {
  display: flex;
  justify-content: space-between;
  align-items: center;

  .channel-title {
    display: flex;
    align-items: center;
    gap: 8px;
    min-width: 0;

    .channel-name {
      font-size: 15px;
      font-weight: 600;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
    .code-tag {
      flex-shrink: 0;
    }
  }
}

.channel-body {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 6px;

  .info-row {
    display: flex;
    font-size: 13px;
    line-height: 1.5;

    .info-label {
      width: 72px;
      flex-shrink: 0;
      color: var(--sn-text-secondary);
    }
  }

  .fee {
    font-weight: 600;
    color: var(--sn-red);
  }

  .masked {
    color: var(--sn-text-secondary);
    word-break: break-all;
  }

  .not-configured {
    color: #b26a00;
  }
}

.channel-foot {
  display: flex;
  justify-content: space-between;
  align-items: center;
  border-top: 1px solid var(--sn-border);
  padding-top: 10px;

  .updater {
    font-size: 12px;
    color: var(--sn-text-secondary);
  }
}

.mono {
  font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
  font-size: 13px;
}

.field-hint {
  font-size: 12px;
  color: var(--sn-text-secondary);
  line-height: 1.4;
  margin-top: 2px;
}
</style>
