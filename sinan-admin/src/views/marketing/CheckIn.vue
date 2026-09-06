<script setup>
import { ref, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import { getCheckinConfig, saveCheckinRules, saveCheckinActivity, toggleCheckin } from '@/api'
import StatusTag from '@/components/StatusTag.vue'
import { ACTIVITY_STATUS } from '@/utils/maps'
import { fmtNumber } from '@/utils/format'

const loading = ref(true)
const config = ref(null)

// ---- 规则编辑 ----
const editShow = ref(false)
const editingRule = ref(null)
const ruleForm = ref({ day: 1, type: 'points', label: '' })

// ---- 活动信息编辑 ----
const infoShow = ref(false)
const infoForm = ref({ name: '', startTime: '', endTime: '' })

// 奖励类型统一（全平台奖励下拉同构）
const REWARD_TYPES = {
  points: { label: '司南币', tag: 'warning' },
  collectible: { label: '藏品', tag: 'primary' },
  draw_chance: { label: '抽奖次数', tag: 'success' },
  priority_qualification: { label: '优先购白名单资格', tag: 'info' },
  eligibility_qualification: { label: '资格购资格', tag: 'info' },
  blindbox: { label: '盲盒', tag: 'danger' },
  none: { label: '无奖励', tag: 'info' }
}

onMounted(load)

async function load() {
  loading.value = true
  const res = await getCheckinConfig()
  config.value = res.data || null
  loading.value = false
}

// ---- 签到全局启停 ----
async function onToggle() {
  const enabling = config.value.enabled !== true
  await ElMessageBox.confirm(
    enabling ? '确认开启签到功能？C 端将展示签到入口。' : '确认关闭签到功能？C 端签到入口隐藏，连续天数冻结。',
    '签到功能',
    { type: 'warning' }
  )
  const res = await toggleCheckin(enabling ? 1 : 0)
  if (res.code === 0) {
    config.value.enabled = enabling
    ElMessage.success(enabling ? '已开启签到' : '已关闭签到')
  }
}

// ---- 活动信息编辑 ----
function openInfo() {
  infoForm.value = {
    name: config.value.name || '每日签到',
    startTime: config.value.startTime || '',
    endTime: config.value.endTime || ''
  }
  infoShow.value = true
}

async function onSaveInfo() {
  const f = infoForm.value
  if (!f.name.trim()) return ElMessage.warning('请输入活动名称')
  const res = await saveCheckinActivity({ name: f.name.trim(), startTime: f.startTime.trim(), endTime: f.endTime.trim() })
  if (res.code === 0) {
    ElMessage.success('活动信息已保存')
    infoShow.value = false
    load()
  }
}

// ---- 规则编辑 ----
function openAddRule() {
  editingRule.value = null
  ruleForm.value = { day: null, type: 'points', label: '' }
  editShow.value = true
}

function openEditRule(r) {
  editingRule.value = r
  ruleForm.value = { day: r.day, type: r.type, label: r.label }
  editShow.value = true
}

async function onSaveRule() {
  const f = ruleForm.value
  if (!Number.isInteger(f.day) || f.day < 1) return ElMessage.warning('请输入有效的天数（第 N 天）')
  if (!f.label.trim()) return ElMessage.warning('请输入奖励内容')
  const exists = config.value.rules.find((r) => r.day === f.day && r !== editingRule.value)
  if (exists) return ElMessage.warning(`第 ${f.day} 天已有奖励规则`)
  if (editingRule.value) {
    Object.assign(editingRule.value, { day: f.day, type: f.type, label: f.label.trim() })
  } else {
    config.value.rules.push({ day: f.day, type: f.type, label: f.label.trim() })
  }
  const res = await saveCheckinRules({ rules: [...config.value.rules].sort((a, b) => a.day - b.day) })
  if (res.code === 0) {
    ElMessage.success('规则已保存，立即生效（历史签到记录不受影响）')
    editShow.value = false
    load()
  }
}

async function onRemoveRule(r) {
  await ElMessageBox.confirm(`确认删除第 ${r.day} 天的奖励规则？`, '删除规则', { type: 'warning' })
  config.value.rules = config.value.rules.filter((x) => x.day !== r.day)
  await saveCheckinRules({ rules: config.value.rules })
  ElMessage.success('已删除')
}
</script>

<template>
  <div class="adm-page ck">
    <el-skeleton v-if="loading" :rows="8" animated style="padding: 20px" />
    <template v-else-if="config">
      <div class="ck__split">
        <!-- 左列 -->
        <div>
          <!-- 开关与统计 -->
          <div class="adm-card">
            <div class="adm-card__title">
              签到功能
              <span style="display: inline-flex; align-items: center; gap: 8px; margin-left: auto">
                <el-button link type="primary" size="small" @click="openInfo">编辑活动信息</el-button>
              </span>
            </div>
            <div class="ck__act-info">
              <div>
                <div class="ck__toggle-label">{{ config.name || '每日签到' }}</div>
                <div class="t-tertiary" style="font-size: 12px; margin-top: 3px">
                  <template v-if="config.startTime || config.endTime">
                    活动时间：{{ config.startTime || '不限' }} ~ {{ config.endTime || '不限' }}
                  </template>
                  <template v-else>活动时间：长期有效（未设置起止时间）</template>
                </div>
              </div>
              <StatusTag :value="config.enabled ? 'enabled' : 'disabled'" :map="ACTIVITY_STATUS" />
            </div>
            <div class="ck__toggle">
              <div>
                <div class="ck__toggle-label">{{ config.enabled ? '签到功能已开启' : '签到功能已关闭' }}</div>
                <div class="t-tertiary" style="font-size: 12px; margin-top: 3px">
                  关闭后 C 端签到入口隐藏，连续天数冻结
                </div>
              </div>
              <el-switch :model-value="config.enabled" size="default" @change="onToggle" />
            </div>
            <div class="ck__stats">
              <div class="ck__stat">
                <div class="price">{{ fmtNumber(config.todayCount) }}</div>
                <div class="t-tertiary">今日签到</div>
              </div>
              <div class="ck__stat">
                <div class="price">{{ fmtNumber(config.monthCount) }}</div>
                <div class="t-tertiary">本月签到</div>
              </div>
              <div class="ck__stat">
                <div class="price">{{ config.rules.length }}</div>
                <div class="t-tertiary">奖励档位</div>
              </div>
            </div>
          </div>

          <!-- 奖励规则 -->
          <div class="adm-card">
            <div class="adm-card__title">
              奖励规则（第 N 天 → 奖励）
              <el-button link type="primary" size="small" :icon="Plus" @click="openAddRule">新增档位</el-button>
            </div>

            <el-table :data="config.rules">
              <el-table-column label="签到天数" width="100" align="center">
                <template #default="{ row }">
                  <span class="ck__day">第 {{ row.day }} 天</span>
                </template>
              </el-table-column>
              <el-table-column label="奖励类型" width="160">
                <template #default="{ row }">
                  <el-tag :type="REWARD_TYPES[row.type]?.tag || 'info'" effect="plain" size="small">
                    {{ REWARD_TYPES[row.type]?.label || row.type }}
                  </el-tag>
                </template>
              </el-table-column>
              <el-table-column label="奖励内容" min-width="160">
                <template #default="{ row }">
                  <span class="ck__reward">{{ row.label }}</span>
                </template>
              </el-table-column>
              <el-table-column label="操作" width="130" fixed="right">
                <template #default="{ row }">
                  <el-button link type="primary" size="small" @click="openEditRule(row)">编辑</el-button>
                  <el-button link type="danger" size="small" @click="onRemoveRule(row)">删除</el-button>
                </template>
              </el-table-column>
            </el-table>

            <el-alert
              type="info"
              :closable="false"
              show-icon
              class="ck__tip"
              title="奖励发放时动态校验藏品配额预留库存，配额不足时拦截提示「配额预留不足」；修改规则立即生效，历史签到记录不受影响"
            />
          </div>
        </div>

        <!-- 右列：连签榜 -->
        <div class="adm-card">
          <div class="adm-card__title">连续签到榜 TOP</div>
          <div v-for="(u, i) in config.streakTop || []" :key="u.nickname" class="ck__rank-item">
            <div class="ck__rank" :class="{ 'is-top': i < 3 }">{{ i + 1 }}</div>
            <div class="ck__rank-name">{{ u.nickname }}</div>
            <div class="ck__rank-streak">
              <span class="price">{{ u.streak }}</span>
              <span class="t-tertiary" style="font-size: 12px"> 天</span>
            </div>
          </div>
          <el-empty v-if="!(config.streakTop || []).length" description="暂无签到数据" :image-size="60" />
        </div>
      </div>

      <!-- 规则编辑弹窗 -->
      <el-dialog v-model="editShow" :title="editingRule ? `编辑第 ${editingRule.day} 天奖励` : '新增奖励档位'" width="440px" :close-on-click-modal="false">
        <el-form label-width="100px">
          <el-form-item label="第 N 天">
            <el-input-number v-model="ruleForm.day" :min="1" :max="365" />
          </el-form-item>
          <el-form-item label="奖励类型">
            <el-select v-model="ruleForm.type" style="width: 100%">
              <el-option
                v-for="(t, k) in REWARD_TYPES"
                :key="k"
                :label="t.label"
                :value="k"
                :disabled="k === 'none'"
              />
            </el-select>
          </el-form-item>
          <el-form-item label="奖励内容">
            <el-input v-model="ruleForm.label" placeholder="如：青铜面具 ×1 / 20 司南币 / 抽奖次数 ×2" />
          </el-form-item>
        </el-form>
        <template #footer>
          <el-button @click="editShow = false">取消</el-button>
          <el-button type="primary" @click="onSaveRule">保存</el-button>
        </template>
      </el-dialog>

      <!-- 活动信息编辑弹窗 -->
      <el-dialog v-model="infoShow" title="编辑签到活动信息" width="480px" :close-on-click-modal="false">
        <el-form label-width="100px">
          <el-form-item label="活动名称">
            <el-input v-model="infoForm.name" placeholder="如：每日签到 · 九月篇" maxlength="50" show-word-limit />
          </el-form-item>
          <el-form-item label="开始时间">
            <el-input v-model="infoForm.startTime" placeholder="如 2026-09-01 00:00:00，留空不限" />
          </el-form-item>
          <el-form-item label="结束时间">
            <el-input v-model="infoForm.endTime" placeholder="如 2026-09-30 23:59:59，留空不限" />
          </el-form-item>
          <el-alert
            type="info"
            :closable="false"
            show-icon
            title="活动信息仅影响 C 端展示文案与活动周期；签到开关独立控制入口显隐"
          />
        </el-form>
        <template #footer>
          <el-button @click="infoShow = false">取消</el-button>
          <el-button type="primary" @click="onSaveInfo">保存</el-button>
        </template>
      </el-dialog>
    </template>
  </div>
</template>

<style scoped lang="scss">
.ck__split {
  display: grid;
  grid-template-columns: 1.5fr 1fr;
  gap: 14px;
  align-items: start;

  @media (max-width: 992px) {
    grid-template-columns: 1fr;
  }
}

.ck__act-info {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 10px 12px;
  border-radius: 8px;
  background: $color-surface;
  margin-top: 10px;
}

.ck__toggle {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 10px 12px;
  border-radius: 8px;
  background: $color-primary-bg;
  margin-top: 10px;
}

.ck__toggle-label { font-size: 14px; font-weight: 600; color: $color-text-primary; }

.ck__stats {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 10px;
  margin-top: 14px;
  text-align: center;
}

.ck__stat {
  padding: 12px 0;
  border-radius: 8px;
  background: $color-surface;
}

.ck__stat .price { font-size: 20px; }
.ck__stat .t-tertiary { font-size: 11px; margin-top: 2px; }

.ck__day { font-weight: 600; color: $color-text-primary; }
.ck__reward { font-size: 13px; color: $color-text-primary; }
.ck__tip { margin-top: 10px; }

.ck__rank-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 11px 0;
  border-bottom: 1px solid $color-border;

  &:last-of-type { border-bottom: none; }
}

.ck__rank {
  width: 22px;
  height: 22px;
  border-radius: 6px;
  background: $color-surface;
  color: $color-text-tertiary;
  font-size: 11px;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;

  &.is-top { background: var(--color-primary-bg); color: $color-primary; }
}

.ck__rank-name { flex: 1; font-size: 13px; font-weight: 600; color: $color-text-primary; }
.ck__rank-streak .price { font-size: 15px; }
</style>
