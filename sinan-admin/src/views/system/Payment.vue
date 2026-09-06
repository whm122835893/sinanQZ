<script setup>
import { ref, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { getPaymentChannels, savePaymentChannel } from '@/api'

// ============================================================
// 支付渠道（system:payment，第三方钱包配置）
// - 渠道密钥 JSON 整体 AES 加密落库，列表仅回显脱敏摘要
// - 启用第三方渠道前必须先配置密钥（后端强校验）
// - 余额渠道为平台内置，不可停用
// ============================================================

const loading = ref(true)
const channels = ref([])

const CODE_LABEL = {
  balance: '平台余额',
  alipay: '支付宝',
  wechat: '微信支付',
  huifu: '汇付天下',
  unionpay: '银联云闪付'
}

onMounted(load)

async function load() {
  loading.value = true
  const res = await getPaymentChannels()
  if (res.code === 0) channels.value = res.data || []
  loading.value = false
}

const thirdParty = computed(() => channels.value.filter((c) => c.channelCode !== 'balance'))
const balanceChannel = computed(() => channels.value.find((c) => c.channelCode === 'balance'))

// ---- 渠道启停 ----
async function onToggle(c) {
  const enabling = !c.status
  if (enabling && c.channelCode !== 'balance' && !c.configExists) {
    return ElMessage.warning('启用第三方渠道前需先配置渠道密钥')
  }
  await ElMessageBox.confirm(
    enabling
      ? `确认启用「${c.channelName}」？启用后 C 端支付将出现该渠道选项。`
      : `确认停用「${c.channelName}」？停用后该渠道立即不可用。`,
    '渠道启停',
    { type: 'warning' }
  )
  const res = await savePaymentChannel({ id: c.id, status: enabling ? 1 : 0 })
  if (res.code === 0) {
    c.status = enabling ? 1 : 0
    ElMessage.success(enabling ? '渠道已启用' : '渠道已停用')
  }
}

async function onRecommend(c) {
  const res = await savePaymentChannel({ id: c.id, isRecommended: !c.isRecommended })
  if (res.code === 0) {
    c.isRecommended = c.isRecommended ? 0 : 1
    ElMessage.success(c.isRecommended ? '已设为推荐渠道' : '已取消推荐')
  }
}

// ---- 渠道编辑（费率/排序/密钥） ----
const editing = ref(null)
const editForm = ref({})

function openEdit(c) {
  editing.value = c
  editForm.value = {
    id: c.id,
    channelName: c.channelName,
    feeRate: c.feeRate,
    sortOrder: c.sortOrder,
    remark: c.remark || '',
    config: ''
  }
}

const editSaving = ref(false)
async function saveEdit() {
  if (editForm.value.feeRate < 0 || editForm.value.feeRate > 100) {
    return ElMessage.warning('手续费率需在 0~100% 之间')
  }
  editSaving.value = true
  const payload = { ...editForm.value }
  // 密钥：JSON 文本；空 = 不变更
  if (payload.config && payload.config.trim() !== '') {
    try {
      payload.config = JSON.parse(payload.config)
    } catch (e) {
      editSaving.value = false
      return ElMessage.error('密钥配置需为合法 JSON 对象')
    }
  } else {
    delete payload.config
  }
  const res = await savePaymentChannel(payload)
  editSaving.value = false
  if (res.code === 0) {
    ElMessage.success('支付渠道配置已保存（已写审计日志）')
    editing.value = null
    load()
  }
}

// ---- 密钥清除 ----
async function onClearConfig(c) {
  await ElMessageBox.confirm(
    `确认清除「${c.channelName}」的渠道密钥？清除后该渠道将无法启用。`,
    '清除密钥',
    { type: 'error', confirmButtonClass: 'el-button--danger' }
  )
  const res = await savePaymentChannel({ id: c.id, config: '' })
  if (res.code === 0) {
    ElMessage.success('渠道密钥已清除')
    load()
  }
}
</script>

<template>
  <div class="adm-page pm">
    <el-skeleton v-if="loading" :rows="6" animated style="padding: 20px" />
    <template v-else>
      <!-- 平台余额（内置） -->
      <div v-if="balanceChannel" class="adm-card">
        <div class="adm-card__title">平台内置</div>
        <div class="pm__balance">
          <div class="pm__balance-main">
            <div class="pm__balance-name">
              <span class="pm__balance-icon">¥</span>
              {{ balanceChannel.channelName }}
            </div>
            <div class="t-tertiary" style="font-size: 12px">
              平台余额为内置支付方式，始终可用，不可停用
            </div>
          </div>
          <el-tag type="success" effect="plain">启用中</el-tag>
        </div>
      </div>

      <!-- 第三方渠道 -->
      <div class="adm-card">
        <div class="adm-card__title">
          第三方支付渠道（密钥 AES 加密存储，启用前必须配置）
        </div>
        <el-table :data="thirdParty">
          <el-table-column label="渠道" min-width="150" fixed="left">
            <template #default="{ row }">
              <div class="pm__channel">
                <span class="pm__channel-badge" :class="`is-${row.channelCode}`">
                  {{ (CODE_LABEL[row.channelCode] || row.channelCode).slice(0, 1) }}
                </span>
                <div>
                  <div class="pm__channel-name">{{ row.channelName }}</div>
                  <div class="t-tertiary" style="font-size: 12px">{{ CODE_LABEL[row.channelCode] || row.channelCode }}</div>
                </div>
              </div>
            </template>
          </el-table-column>
          <el-table-column label="手续费率" width="100" align="right">
            <template #default="{ row }">{{ row.feeRate }}%</template>
          </el-table-column>
          <el-table-column label="密钥配置" min-width="200">
            <template #default="{ row }">
              <el-tag v-if="row.configExists" type="success" effect="plain" size="small">
                {{ row.configMasked || '已配置' }}
              </el-tag>
              <el-tag v-else type="danger" effect="plain" size="small">未配置</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="推荐" width="80" align="center">
            <template #default="{ row }">
              <el-switch :model-value="!!row.isRecommended" @change="onRecommend(row)" />
            </template>
          </el-table-column>
          <el-table-column label="状态" width="90" align="center">
            <template #default="{ row }">
              <el-switch :model-value="row.status === 1" @change="onToggle(row)" />
            </template>
          </el-table-column>
          <el-table-column label="最近更新" width="180">
            <template #default="{ row }">
              <div class="pm__updated">
                <div>{{ row.updatedAt }}</div>
                <div class="t-tertiary" style="font-size: 12px">{{ row.updatedByName || '-' }}</div>
              </div>
            </template>
          </el-table-column>
          <el-table-column label="操作" width="160" fixed="right">
            <template #default="{ row }">
              <el-button link type="primary" size="small" @click="openEdit(row)">配置</el-button>
              <el-button v-if="row.configExists" link type="danger" size="small" @click="onClearConfig(row)">清除密钥</el-button>
            </template>
          </el-table-column>
        </el-table>
      </div>
    </template>

    <!-- 编辑弹窗 -->
    <el-dialog v-model="editing" :title="`渠道配置 · ${editForm.channelName || ''}`" width="560px" destroy-on-close>
      <el-form label-width="100px">
        <el-form-item label="渠道名称">
          <el-input v-model="editForm.channelName" maxlength="50" style="width: 300px" />
        </el-form-item>
        <el-form-item label="手续费率">
          <el-input-number v-model="editForm.feeRate" :min="0" :max="100" :step="0.1" :precision="2" style="width: 180px" />
          <span class="t-tertiary" style="margin-left: 8px; font-size: 12px">%</span>
        </el-form-item>
        <el-form-item label="排序">
          <el-input-number v-model="editForm.sortOrder" :min="0" :max="999" style="width: 180px" />
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="editForm.remark" type="textarea" :rows="2" maxlength="255" placeholder="渠道备注（可选）" />
        </el-form-item>
        <el-form-item label="渠道密钥">
          <el-input
            v-model="editForm.config"
            type="textarea"
            :rows="5"
            placeholder='JSON 对象，如 {"app_id":"...","mch_id":"...","private_key":"..."}&#10;留空 = 保持原有密钥不变'
          />
          <div class="t-tertiary" style="font-size: 12px; margin-top: 4px">
            密钥整体 AES 加密落库，保存后不再回显明文
          </div>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="editing = null">取消</el-button>
        <el-button type="primary" :loading="editSaving" @click="saveEdit">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped lang="scss">
.pm__balance {
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: $color-bg;
  border-radius: 10px;
  padding: 16px 20px;
}

.pm__balance-main {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.pm__balance-name {
  font-size: 16px;
  font-weight: 700;
  color: $color-text-primary;
}

.pm__channel {
  display: flex;
  align-items: center;
  gap: 10px;
}

.pm__channel-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 36px;
  height: 36px;
  border-radius: 8px;
  font-size: 16px;
  font-weight: 700;
  color: #fff;
  background: $color-text-tertiary;

  &.is-alipay { background: #1677ff; }
  &.is-wechat { background: #07c160; }
  &.is-huifu { background: #d4a574; }
  &.is-unionpay { background: #c00000; }
}

.pm__channel-name {
  font-weight: 600;
  color: $color-text-primary;
}

.pm__updated {
  font-size: 12px;
  color: $color-text-secondary;
}
</style>
