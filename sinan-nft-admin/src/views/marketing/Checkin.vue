<script setup lang="ts">
/**
 * 签到活动配置
 *
 * - 连续签到 1~7 天奖励（司南币）表格行内编辑，保存后立即生效
 * - 附今日签到人数 / 近 30 天累计签到统计
 *
 * 接口：GET / POST /admin/marketing/checkin（rewards：{ 天数: 奖励 }）
 * 权限：marketing:checkin:config
 */
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { fetchCheckinConfig, saveCheckinConfig } from '@/api/marketing'
import { money } from '@/utils/format'

const loading = ref(false)
const saving = ref(false)

/** 今日签到人数 */
const todayCount = ref(0)
/** 近 30 天累计签到人次 */
const monthCount = ref(0)

/** 连续签到奖励行（后端仅支持 1~7 天，天数固定，奖励行内编辑） */
const rows = reactive(
  Array.from({ length: 7 }, (_, i) => ({ day: i + 1, amount: 0 as number })),
)

/** 各天数说明文案 */
const DAY_TIPS: Record<number, string> = {
  1: '每日签到基础奖励',
  2: '连续 2 天签到奖励',
  3: '连续 3 天签到奖励',
  4: '连续 4 天签到奖励',
  5: '连续 5 天签到奖励',
  6: '连续 6 天签到奖励',
  7: '连续 7 天签到大奖（建议配置最高奖励）',
}

/** 七日奖励总额（司南币） */
const totalReward = computed(() => rows.reduce((s, r) => s + Number(r.amount || 0), 0))

async function load() {
  loading.value = true
  try {
    const res = await fetchCheckinConfig()
    todayCount.value = Number(res?.todayCount ?? 0)
    const trend = res?.trend ?? {}
    monthCount.value = Object.values(trend).reduce((s: number, v: any) => s + Number(v || 0), 0)
    // rewards：{ "1": 5, "2": 10, ... }（后端 json 对象，键为天数）
    const rewards = res?.rewards ?? {}
    rows.forEach((r) => {
      const v = rewards[r.day] ?? rewards[String(r.day)]
      r.amount = v === undefined || v === null ? 0 : Number(v) || 0
    })
  } finally {
    loading.value = false
  }
}

async function save() {
  const invalid = rows.find((r) => !Number.isInteger(Number(r.amount)) || r.amount < 0 || r.amount > 10000)
  if (invalid) {
    ElMessage.warning(`第 ${invalid.day} 天的奖励需为 0~10000 的整数（司南币）`)
    return
  }
  saving.value = true
  try {
    await saveCheckinConfig({
      rewards: Object.fromEntries(rows.map((r) => [r.day, Math.trunc(Number(r.amount))])),
    })
    ElMessage.success('签到奖励配置已保存')
    load()
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <div v-loading="loading" class="page-container">
    <!-- 签到统计 -->
    <div class="stat-grid">
      <div class="stat-card">
        <div class="stat-info">
          <div class="stat-label">今日签到人数</div>
          <div class="stat-value">{{ todayCount }}</div>
          <div class="stat-sub">实时统计</div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-info">
          <div class="stat-label">近 30 天累计签到（人次）</div>
          <div class="stat-value">{{ monthCount }}</div>
          <div class="stat-sub">按日汇总</div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-info">
          <div class="stat-label">七日连续签到奖励总额（司南币）</div>
          <div class="stat-value">{{ money(totalReward) }}</div>
          <div class="stat-sub">第 1~7 天奖励合计</div>
        </div>
      </div>
    </div>

    <!-- 奖励配置 -->
    <div class="table-card">
      <div class="card-header">
        <div>
          <div class="card-title">连续签到奖励配置</div>
          <div class="card-sub">奖励以司南币发放；连续签到天数后端仅支持 1~7 天，中断后从第 1 天重新累计</div>
        </div>
        <el-button v-permission="'marketing:checkin:config'" type="primary" :loading="saving" @click="save">
          保存配置
        </el-button>
      </div>

      <el-table :data="rows">
        <el-table-column label="连续签到天数" width="180" align="center">
          <template #default="{ row }">第 {{ row.day }} 天</template>
        </el-table-column>
        <el-table-column label="奖励（司南币）" min-width="220">
          <template #default="{ row }">
            <el-input-number v-model="row.amount" :min="0" :max="10000" :step="1" step-strictly />
          </template>
        </el-table-column>
        <el-table-column label="说明" min-width="220">
          <template #default="{ row }">{{ DAY_TIPS[row.day] }}</template>
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
</style>
