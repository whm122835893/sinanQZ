<script setup lang="ts">
/**
 * 系统配置 - 安全策略
 *
 * - fetchSecurityConfig 加载安全参数白名单（键/名称/当前值）+ 管理员账号安全概览
 * - 每项参数单独保存：saveSecurityConfig(key, value)（后端白名单校验 int 范围 / bool 0-1）
 * - 登录失败锁定阈值 / 锁定时长 / 大额充值告警阈值 / 平台清库短信二次确认
 *
 * 接口：GET /admin/system/security-config、PUT /admin/system/security-config/:key { value }
 * 权限：system:security
 */
import { computed, onMounted, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { Refresh } from '@element-plus/icons-vue'
import { fetchSecurityConfig, saveSecurityConfig } from '@/api/system'
import { money } from '@/utils/format'

/** 安全参数校验规则（与后端 SECURITY_KEYS 白名单一致） */
const SECURITY_RULES: Record<string, { type: 'int' | 'bool'; min?: number; max?: number; unit?: string }> = {
  admin_login_fail_limit: { type: 'int', min: 1, max: 20, unit: '次' },
  admin_lock_minutes: { type: 'int', min: 1, max: 1440, unit: '分钟' },
  large_recharge_alert: { type: 'int', min: 1, max: 10000000, unit: '元' },
  cleanup_sms_required: { type: 'bool' },
}

interface SecurityItem {
  key: string
  name: string
  description: string
  value: string
  draft: number
  type: 'int' | 'bool'
  min: number
  max: number
  unit: string
  saving: boolean
}

const loading = ref(false)
const overview = ref<any>({})
const items = ref<SecurityItem[]>([])

async function load() {
  loading.value = true
  try {
    const res = await fetchSecurityConfig()
    overview.value = res?.overview ?? {}
    items.value = ((res?.configs ?? []) as any[]).map((row) => {
      const rule = SECURITY_RULES[row.key] ?? { type: 'int' as const, min: 0, max: 999999999 }
      const value = String(row.value ?? '0')
      return {
        key: String(row.key),
        name: String(row.name ?? row.key),
        description: String(row.description ?? ''),
        value,
        draft: Number(value) || 0,
        type: rule.type,
        min: rule.min ?? 0,
        max: rule.max ?? 0,
        unit: rule.unit ?? '',
        saving: false,
      }
    })
  } finally {
    loading.value = false
  }
}

onMounted(load)

/** 概览指标卡 */
const statCards = computed(() => [
  { label: '管理员总数', value: overview.value.adminTotal ?? 0, sub: '在职（未删除）账号', icon: 'User', color: 'blue' },
  { label: '当前锁定账号', value: overview.value.adminLocked ?? 0, sub: '登录失败达到阈值被锁定', icon: 'Lock', color: 'orange' },
  { label: '已禁用账号', value: overview.value.adminDisabled ?? 0, sub: 'status = 0', icon: 'CircleClose', color: 'gray' },
  { label: '24 小时登录失败', value: overview.value.login24hFail ?? 0, sub: '近一天失败登录尝试次数', icon: 'Warning', color: '' },
])

/** 金额型参数（元）当前值提示 */
function isAmountKey(key: string) {
  return key === 'large_recharge_alert'
}

/** 单项保存 */
async function saveItem(item: SecurityItem) {
  if (item.type === 'bool') {
    const v = Number(item.draft) === 1 ? '1' : '0'
    item.saving = true
    try {
      await saveSecurityConfig(item.key, v)
      item.value = v
      ElMessage.success(`「${item.name}」已更新并实时生效`)
    } catch {
      /* 业务错误已全局提示 */
    } finally {
      item.saving = false
    }
    return
  }

  const v = Number(item.draft)
  if (!Number.isInteger(v) || v < item.min || v > item.max) {
    ElMessage.warning(`「${item.name}」需为 ${item.min}~${item.max} 之间的整数`)
    return
  }
  item.saving = true
  try {
    await saveSecurityConfig(item.key, String(v))
    item.value = String(v)
    ElMessage.success(`「${item.name}」已更新并实时生效`)
  } catch {
    /* 业务错误已全局提示 */
  } finally {
    item.saving = false
  }
}
</script>

<template>
  <div class="page-container">
    <!-- 账号安全概览 -->
    <div class="stat-grid">
      <div v-for="card in statCards" :key="card.label" class="stat-card">
        <div class="stat-info">
          <div class="stat-label">{{ card.label }}</div>
          <div class="stat-value">{{ card.value }}</div>
          <div class="stat-sub">{{ card.sub }}</div>
        </div>
        <div class="stat-icon" :class="card.color">
          <el-icon :size="22"><component :is="card.icon" /></el-icon>
        </div>
      </div>
    </div>

    <!-- 安全策略参数 -->
    <div class="table-card">
      <div class="card-header">
        <div>
          <div class="card-title">安全策略参数</div>
          <div class="card-sub">
            参数按白名单校验（整数范围 / 0-1 开关），每项单独保存、实时生效并写入操作审计日志
          </div>
        </div>
        <el-button :icon="Refresh" :loading="loading" @click="load">刷新</el-button>
      </div>

      <el-table v-loading="loading" :data="items" border stripe>
        <el-table-column prop="name" label="安全参数" min-width="220" show-overflow-tooltip />
        <el-table-column label="参数键" width="220">
          <template #default="{ row }">
            <span class="mono">{{ row.key }}</span>
          </template>
        </el-table-column>
        <el-table-column label="当前值" width="150">
          <template #default="{ row }">
            <el-tag v-if="row.type === 'bool'" :type="Number(row.value) === 1 ? 'success' : 'info'" size="small">
              {{ Number(row.value) === 1 ? '开启' : '关闭' }}
            </el-tag>
            <span v-else class="current-value">
              {{ row.type === 'int' && isAmountKey(row.key) ? `¥${money(row.value)}` : `${row.value}${row.unit ? ` ${row.unit}` : ''}` }}
            </span>
          </template>
        </el-table-column>
        <el-table-column label="调整（行内编辑）" min-width="260">
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
            <template v-else>
              <el-input-number
                v-model="row.draft"
                :min="row.min"
                :max="row.max"
                :step="1"
                step-strictly
                controls-position="right"
                style="width: 180px"
              />
              <span class="unit-text">{{ row.unit }}</span>
              <span v-if="isAmountKey(row.key)" class="unit-text">（{{ money(row.draft) }} 元）</span>
              <span class="range-text">{{ row.min }}~{{ row.max }}</span>
            </template>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="100" fixed="right" align="center">
          <template #default="{ row }">
            <el-button
              v-permission="'system:security'"
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
