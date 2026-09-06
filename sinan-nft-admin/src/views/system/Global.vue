<script setup lang="ts">
/**
 * 系统配置 - 全局参数
 *
 * - fetchConfigs 全量拉取 nft_system_configs KV 参数，按 分组 tabs 展示（运营/风控/安全/其他）
 * - 每项参数行内编辑（数值型 el-input-number 限范围、布尔型 el-switch、其余 el-input）+ 单独保存
 * - 保存调 saveConfig(key, configValue)，后端实时生效并写审计日志
 *
 * 接口：GET /admin/system/configs、PUT /admin/system/configs/:key { config_value }
 * 权限：system:config
 */
import { computed, onMounted, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { Refresh } from '@element-plus/icons-vue'
import { fetchConfigs, saveConfig } from '@/api/system'
import { datetime } from '@/utils/format'

/** 后端 configSave 数值型参数范围白名单（前后端一致校验） */
const NUMERIC_RULES: Record<string, { min: number; max: number; unit: string }> = {
  admin_login_fail_limit: { min: 1, max: 20, unit: '次' },
  admin_lock_minutes: { min: 1, max: 1440, unit: '分钟' },
  resale_price_global_max: { min: 1, max: 10000000, unit: '元' },
  large_recharge_alert: { min: 1, max: 10000000, unit: '元' },
  resale_fee_rate: { min: 0, max: 20, unit: '%' },
  resale_cooldown_seconds: { min: 0, max: 604800, unit: '秒' },
}

/** 布尔型参数（后端仅允许 0/1） */
const BOOL_KEYS = ['cleanup_sms_required']

/** 参数分组：按 config_key 关键字/前缀归类（未命中归入「其他参数」） */
const GROUP_ORDER = ['运营参数', '风控参数', '安全参数', '其他参数'] as const

const SECURITY_KEYS = ['admin_login_fail_limit', 'admin_lock_minutes', 'cleanup_sms_required']
const RISK_KEYS = ['resale_price_global_max', 'large_recharge_alert']
const OPS_KEYS = ['purchase_limit_per_user', 'resale_fee_rate', 'resale_cooldown_seconds']

function groupOf(key: string): string {
  if (SECURITY_KEYS.includes(key) || key.startsWith('admin_')) return '安全参数'
  if (RISK_KEYS.includes(key)) return '风控参数'
  if (
    OPS_KEYS.includes(key)
    || key.startsWith('checkin_')
    || key.startsWith('register_')
    || key.startsWith('resale_')
  ) {
    return '运营参数'
  }
  return '其他参数'
}

interface ConfigItem {
  key: string
  description: string
  value: string
  draft: string | number
  type: 'text' | 'number' | 'bool'
  min: number
  max: number
  unit: string
  updatedAt: string
  saving: boolean
}

const loading = ref(false)
const items = ref<ConfigItem[]>([])
const activeGroup = ref('')

/**
 * 后端部分接口直接返回 DB 行（snake_case 键），统一转 camelCase 后再使用；
 * 已是 camelCase 的键经过转换不受影响。
 */
function camelizeKeys<T = any>(data: any): T {
  if (Array.isArray(data)) return data.map(camelizeKeys) as any
  if (data && typeof data === 'object') {
    const out: Record<string, any> = {}
    Object.keys(data).forEach((k) => {
      const nk = k.replace(/_([a-z0-9])/g, (_, c: string) => c.toUpperCase())
      out[nk] = camelizeKeys(data[k])
    })
    return out as any
  }
  return data
}

function buildItem(row: any): ConfigItem {
  const key = String(row.configKey ?? '')
  const value = String(row.configValue ?? '')
  const numeric = NUMERIC_RULES[key]
  const isBool = BOOL_KEYS.includes(key) || /_(required|enabled)$/.test(key)
  return {
    key,
    description: String(row.description || key),
    value,
    draft: isBool ? (Number(value) === 1 ? 1 : 0) : numeric ? Number(value) || 0 : value,
    type: isBool ? 'bool' : numeric ? 'number' : 'text',
    min: numeric?.min ?? 0,
    max: numeric?.max ?? 0,
    unit: numeric?.unit ?? '',
    updatedAt: String(row.updatedAt ?? ''),
    saving: false,
  }
}

async function load() {
  loading.value = true
  try {
    const rows = ((await fetchConfigs()) ?? []).map(camelizeKeys)
    items.value = rows.map(buildItem)
    // 默认选中第一个有参数的分组
    const first = GROUP_ORDER.find((g) => items.value.some((it) => groupOf(it.key) === g))
    activeGroup.value = first ?? GROUP_ORDER[0]
  } finally {
    loading.value = false
  }
}

onMounted(load)

const grouped = computed(() =>
  GROUP_ORDER.map((title) => ({
    title,
    list: items.value.filter((it) => groupOf(it.key) === title),
  })),
)

/** 行内保存单项参数 */
async function saveItem(item: ConfigItem) {
  if (item.type === 'number') {
    const v = Number(item.draft)
    if (!Number.isFinite(v) || v < item.min || v > item.max) {
      ElMessage.warning(`「${item.key}」需为 ${item.min}~${item.max} 之间的数值`)
      return
    }
    item.saving = true
    try {
      await saveConfig(item.key, String(v))
      item.value = String(v)
      ElMessage.success(`参数「${item.key}」已保存并实时生效`)
    } catch {
      /* 业务错误已全局提示 */
    } finally {
      item.saving = false
    }
    return
  }

  if (item.type === 'bool') {
    const v = Number(item.draft) === 1 ? '1' : '0'
    item.saving = true
    try {
      await saveConfig(item.key, v)
      item.value = v
      ElMessage.success(`参数「${item.key}」已保存并实时生效`)
    } catch {
      /* 业务错误已全局提示 */
    } finally {
      item.saving = false
    }
    return
  }

  const v = String(item.draft ?? '').trim()
  if (v === '') {
    ElMessage.warning(`「${item.key}」参数值不能为空`)
    return
  }
  item.saving = true
  try {
    await saveConfig(item.key, v)
    item.value = v
    ElMessage.success(`参数「${item.key}」已保存并实时生效`)
  } catch {
    /* 业务错误已全局提示 */
  } finally {
    item.saving = false
  }
}
</script>

<template>
  <div class="page-container">
    <!-- 参数卡片 -->
    <div class="table-card">
      <div class="card-header">
        <div>
          <div class="card-title">全局参数配置</div>
          <div class="card-sub">
            平台运营 KV 参数，修改保存后实时生效；数值型参数按后端白名单范围校验，全部变更写入操作审计日志
          </div>
        </div>
        <el-button :icon="Refresh" :loading="loading" @click="load">刷新</el-button>
      </div>

      <el-tabs v-model="activeGroup">
        <el-tab-pane v-for="group in grouped" :key="group.title" :name="group.title">
          <template #label>
            {{ group.title }}
            <el-tag size="small" type="info" class="count-tag">{{ group.list.length }}</el-tag>
          </template>

          <el-table v-loading="loading" :data="group.list" border stripe>
            <el-table-column label="参数键" width="260">
              <template #default="{ row }">
                <span class="mono">{{ row.key }}</span>
              </template>
            </el-table-column>
            <el-table-column prop="description" label="说明" min-width="200" show-overflow-tooltip />
            <el-table-column label="当前值" width="140">
              <template #default="{ row }">
                <el-tag v-if="row.type === 'bool'" :type="Number(row.value) === 1 ? 'success' : 'info'" size="small">
                  {{ Number(row.value) === 1 ? '开启' : '关闭' }}
                </el-tag>
                <span v-else class="current-value">{{ row.value }}{{ row.unit ? ` ${row.unit}` : '' }}</span>
              </template>
            </el-table-column>
            <el-table-column label="参数值（行内编辑）" min-width="280">
              <template #default="{ row }">
                <el-switch
                  v-if="row.type === 'bool'"
                  v-model="row.draft"
                  :active-value="1"
                  :inactive-value="0"
                  active-text="开启"
                  inactive-text="关闭"
                  inline-prompt
                />
                <el-input-number
                  v-else-if="row.type === 'number'"
                  v-model="row.draft"
                  :min="row.min"
                  :max="row.max"
                  :step="1"
                  controls-position="right"
                  style="width: 180px"
                />
                <el-input v-else v-model="row.draft" placeholder="请输入参数值" maxlength="200" clearable />
                <span v-if="row.type === 'number' && row.unit" class="unit-text">{{ row.unit }}</span>
                <span v-if="row.type === 'number'" class="range-text">{{ row.min }}~{{ row.max }}</span>
              </template>
            </el-table-column>
            <el-table-column label="更新时间" width="170">
              <template #default="{ row }">{{ datetime(row.updatedAt) }}</template>
            </el-table-column>
            <el-table-column label="操作" width="100" fixed="right" align="center">
              <template #default="{ row }">
                <el-button
                  v-permission="'system:config'"
                  link
                  type="primary"
                  size="small"
                  :loading="row.saving"
                  @click="saveItem(row)"
                >
                  保存
                </el-button>
              </template>
            </el-table-column>
          </el-table>

          <el-empty v-if="!loading && group.list.length === 0" description="该分组暂无参数" :image-size="70" />
        </el-tab-pane>
      </el-tabs>
    </div>
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

.count-tag {
  margin-left: 6px;
}

.mono {
  font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
  font-size: 13px;
}

.current-value {
  font-weight: 600;
  color: var(--sn-red);
}

.unit-text {
  margin-left: 8px;
  font-size: 12px;
  color: var(--sn-text-secondary);
}

.range-text {
  margin-left: 8px;
  font-size: 12px;
  color: var(--sn-text-secondary);
}
</style>
