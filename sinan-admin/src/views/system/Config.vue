<script setup>
import { ref, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { get } from '@/utils/request'

// ============================================================
// 全局参数（system:config）
// - nft_system_configs 运营参数 KV（保存后实时生效）
// - 数值型参数后端按白名单做范围校验
// - 变更全部写入审计日志
// ============================================================

const loading = ref(true)
const configs = ref([])
const keyword = ref('')

// 参数分组（按业务域）
const GROUPS = [
  { key: 'trade', name: '交易参数', icon: 'ShoppingCart', keys: ['purchase_limit_per_user', 'order_pay_timeout_seconds'] },
  { key: 'resale', name: '寄售市场', icon: 'Sell', keys: ['resale_cooldown_seconds', 'resale_fee_rate', 'resale_price_global_max'] },
  { key: 'marketing', name: '营销参数', icon: 'Present', keys: ['checkin_rewards'] },
  { key: 'risk', name: '风控阈值', icon: 'Warning', keys: ['large_recharge_alert', 'large_refund_approval_threshold'] },
  { key: 'platform', name: '平台运维', icon: 'Operation', keys: ['cleanup_sms_required', 'chain_mint_batch_limit'] }
]

// 参数展示元数据
const META = {
  purchase_limit_per_user: { label: '每藏品限购数量', unit: '份', hint: '单用户单个藏品最大购买数量' },
  order_pay_timeout_seconds: { label: '订单支付超时', unit: '秒', hint: '超时自动取消并释放库存' },
  resale_cooldown_seconds: { label: '寄售冷却时间', unit: '秒', hint: '取消挂单后重新上架的等待时长' },
  resale_fee_rate: { label: '寄售手续费率', unit: '%', hint: '成交时向卖家收取的手续费比例' },
  resale_price_global_max: { label: '寄售全局限价', unit: '元', hint: '不限价模式仍受此上限约束' },
  checkin_rewards: { label: '连续签到奖励', unit: '', hint: 'JSON：天数 → 司南币数量', json: true },
  large_recharge_alert: { label: '大额充值告警', unit: '元', hint: '单笔充值超过该金额触发风控告警' },
  large_refund_approval_threshold: { label: '大额退款审批阈值', unit: '元', hint: '退款金额超过后需审批中心复核' },
  cleanup_sms_required: { label: '清库短信确认', unit: '', hint: '平台清库强制短信验证码二次确认', bool: true },
  chain_mint_batch_limit: { label: '上链单批上限', unit: '条', hint: '单次铸造最大持仓条数（防长事务）' }
}

onMounted(load)

async function load() {
  loading.value = true
  const res = await get('/system/configs')
  if (res.code === 0) {
    configs.value = (res.data || []).map((c) => ({
      ...c,
      // 后端返回 snake_case：configKey / configValue / description
      key: c.configKey || c.key,
      value: c.configValue ?? c.value ?? '',
      desc: c.description || ''
    }))
  }
  loading.value = false
}

// 按分组归类（未分组的归入「其他」）
const grouped = computed(() => {
  const knownKeys = new Set(GROUPS.flatMap((g) => g.keys))
  const map = Object.fromEntries(configs.value.map((c) => [c.key, c]))
  const result = GROUPS.map((g) => ({
    ...g,
    items: g.keys.map((k) => map[k]).filter(Boolean)
  })).filter((g) => g.items.length)
  const others = configs.value.filter((c) => !knownKeys.has(c.key))
  if (others.length) {
    result.push({ key: 'other', name: '其他参数', icon: 'More', items: others })
  }
  return result
})

const savingKey = ref('')
async function onSave(cfg) {
  if (META[cfg.key]?.json) {
    try {
      JSON.parse(cfg.value)
    } catch (e) {
      return ElMessage.error('值需为合法 JSON')
    }
  }
  await ElMessageBox.confirm(
    `确认更新「${META[cfg.key]?.label || cfg.key}」？保存后立即生效并写入审计日志。`,
    '更新全局参数',
    { type: 'warning' }
  )
  savingKey.value = cfg.key
  const { put } = await import('@/utils/request')
  const res = await put(`/system/configs/${cfg.key}`, { config_value: String(cfg.value) })
  savingKey.value = ''
  if (res.code === 0) {
    ElMessage.success(res.message || '参数已保存并实时生效')
  }
}
</script>

<template>
  <div class="adm-page cfg">
    <el-skeleton v-if="loading" :rows="8" animated style="padding: 20px" />
    <template v-else>
      <div v-for="g in grouped" :key="g.key" class="adm-card cfg__group">
        <div class="adm-card__title">
          <el-icon style="vertical-align: -2px; margin-right: 4px"><component :is="g.icon" /></el-icon>
          {{ g.name }}
        </div>
        <div class="cfg__list">
          <div v-for="cfg in g.items" :key="cfg.key" class="cfg__item">
            <div class="cfg__item-main">
              <div class="cfg__item-name">
                {{ META[cfg.key]?.label || cfg.key }}
                <code class="cfg__item-key">{{ cfg.key }}</code>
              </div>
              <div class="t-tertiary cfg__item-hint">
                {{ META[cfg.key]?.hint || cfg.desc || '—' }}
              </div>
            </div>
            <div class="cfg__item-ctrl">
              <template v-if="META[cfg.key]?.bool">
                <el-switch v-model="cfg.value" active-value="1" inactive-value="0" @change="onSave(cfg)" />
                <span class="cfg__item-state" :class="cfg.value === '1' ? 'is-on' : 'is-off'">
                  {{ cfg.value === '1' ? '开启' : '关闭' }}
                </span>
              </template>
              <template v-else-if="META[cfg.key]?.json">
                <el-input
                  v-model="cfg.value"
                  type="textarea"
                  :rows="2"
                  style="width: 320px"
                  class="cfg__json"
                />
                <el-button
                  type="primary"
                  size="small"
                  :loading="savingKey === cfg.key"
                  @click="onSave(cfg)"
                >保存</el-button>
              </template>
              <template v-else>
                <el-input v-model="cfg.value" style="width: 160px" />
                <span class="cfg__item-unit">{{ META[cfg.key]?.unit }}</span>
                <el-button
                  type="primary"
                  size="small"
                  :loading="savingKey === cfg.key"
                  @click="onSave(cfg)"
                >保存</el-button>
              </template>
            </div>
          </div>
        </div>
      </div>

      <el-alert
        type="info"
        :closable="false"
        show-icon
        title="全局参数保存后实时生效；数值型参数由后端按白名单范围校验，所有变更写入审计日志"
      />
    </template>
  </div>
</template>

<style scoped lang="scss">
.cfg__list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.cfg__item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  background: $color-bg;
  border-radius: 10px;
  padding: 14px 18px;
}

.cfg__item-main {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
}

.cfg__item-name {
  display: flex;
  align-items: center;
  gap: 8px;
  font-weight: 600;
  color: $color-text-primary;
  font-size: 14px;
}

.cfg__item-key {
  font-family: 'JetBrains Mono', Consolas, monospace;
  font-size: 11px;
  color: $color-text-tertiary;
  background: $color-surface;
  padding: 1px 6px;
  border-radius: 4px;
}

.cfg__item-hint { font-size: 12px; }

.cfg__item-ctrl {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-shrink: 0;
}

.cfg__item-unit {
  font-size: 12px;
  color: $color-text-tertiary;
}

.cfg__item-state {
  font-size: 12px;

  &.is-on { color: #07c160; }
  &.is-off { color: $color-text-tertiary; }
}

.cfg__json :deep(textarea) {
  font-family: 'JetBrains Mono', Consolas, monospace;
  font-size: 12px;
}

@media (max-width: 768px) {
  .cfg__item { flex-direction: column; align-items: stretch; }
}
</style>
