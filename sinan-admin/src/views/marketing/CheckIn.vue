<script setup>
import { ref, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import {
  getCheckinActivities,
  saveCheckinActivityV2,
  deleteCheckinActivity,
  getFeatureSwitches,
  saveFeatureSwitch,
  getCollectibleList,
  getPrioritySales
} from '@/api'
import StatusTag from '@/components/StatusTag.vue'
import RewardListEditor from '@/components/RewardListEditor.vue'
import EligibilityEditor from '@/components/EligibilityEditor.vue'
import { ACTIVITY_STATUS } from '@/utils/maps'
import { fmtNumber } from '@/utils/format'

const loading = ref(true)
const list = ref([])
const checkinEnabled = ref(true) // 签到模块开关（checkin_enabled）

// ---- 下拉数据源 ----
const collectibles = ref([])
const prioritySales = ref([])

const REWARD_TYPES = {
  points: { label: '司南币', tag: 'warning' },
  collectible: { label: '藏品', tag: 'primary' },
  draw_chance: { label: '抽奖次数', tag: 'success' },
  priority_qualification: { label: '优先购白名单资格', tag: 'info' },
  eligibility_qualification: { label: '资格购资格', tag: 'info' },
  blindbox: { label: '盲盒', tag: 'danger' },
  none: { label: '无奖励', tag: 'info' }
}

onMounted(async () => {
  load()
  loadSwitches()
  // 下拉数据源（失败不阻断）
  const [col, pri] = await Promise.all([
    getCollectibleList({ page: 1, pageSize: 200 }),
    getPrioritySales()
  ])
  if (col.code === 0) collectibles.value = col.data.list || []
  if (pri.code === 0) prioritySales.value = pri.data || []
})

async function load() {
  loading.value = true
  const res = await getCheckinActivities()
  if (res.code === 0) list.value = res.data.list || []
  loading.value = false
}

async function loadSwitches() {
  const res = await getFeatureSwitches()
  if (res.code === 0) checkinEnabled.value = res.data.checkin !== false
}

// ---- 模块开关 ----
async function onToggleModule(val) {
  const enabling = !!val
  await ElMessageBox.confirm(
    enabling ? '确认开启签到模块？C 端将展示签到页。' : '确认关闭签到模块？C 端签到页将变为空状态。',
    '签到模块开关',
    { type: 'warning' }
  )
  const res = await saveFeatureSwitch('checkin', enabling)
  if (res.code === 0) {
    checkinEnabled.value = enabling
    ElMessage.success(enabling ? '已开启签到模块' : '已关闭签到模块')
  }
}

const cname = (id) => collectibles.value.find((c) => c.id === id)?.name || `藏品 #${id}`
const pname = (id) => prioritySales.value.find((p) => p.id === id)?.name || `优先购 #${id}`

/** 奖励项摘要 */
function rewardText(r) {
  const t = REWARD_TYPES[r.type]?.label || r.type
  if (r.type === 'collectible') return `${cname(r.collectibleId)} ×${r.quantity ?? 1}`
  if (r.type === 'points') return `${r.amount ?? 0} 司南币`
  if (r.type === 'priority_qualification') return `${pname(r.prioritySaleId)} 可购${r.quantity ?? 1}份`
  if (r.type === 'eligibility_qualification') return `${cname(r.collectibleId)} 资格 ×${r.quantity ?? 1}`
  if (r.type === 'draw_chance' || r.type === 'blindbox') return `${t} ×${r.quantity ?? 1}`
  return t
}

/** 资格摘要 */
function eligibilityText(e) {
  const c = e?.config || {}
  switch (e?.type) {
    case 'realname': return '已实名用户'
    case 'checkin': return `累计签到 ${c.days ?? 1} 天`
    case 'invite': return `累计邀请 ${c.count ?? 1} 人`
    case 'hold': return `持有指定藏品（${c.match === 'all' ? '全部' : '任一'} ${(c.collectibleIds || []).length} 个）`
    case 'checkin_rank': return `签到前 ${c.rank ?? 100} 名`
    default: return '所有人'
  }
}

/** 活动状态摘要（状态 + 时间窗） */
function activityPhase(a) {
  if (a.status === 'disabled') return { text: '已停用', tag: 'info' }
  if (a.ended) return { text: '已结束', tag: 'info' }
  if (!a.started) return { text: '未开始', tag: 'warning' }
  return { text: '进行中', tag: 'success' }
}

// ---- 新建/编辑活动 ----
const editShow = ref(false)
const saving = ref(false)
const editing = ref(null) // null = 新建
const form = ref({
  name: '',
  status: 'enabled',
  startTime: '',
  endTime: '',
  rewardRules: [], // [{day, rewards: []}]
  eligibility: { type: 'all', config: {} },
  grantMode: 'realtime'
})

function openCreate() {
  editing.value = null
  form.value = {
    name: '',
    status: 'enabled',
    startTime: '',
    endTime: '',
    rewardRules: [{ day: 1, rewards: [{ type: 'points', amount: 10 }] }],
    eligibility: { type: 'all', config: {} },
    grantMode: 'realtime'
  }
  editShow.value = true
}

function openEdit(a) {
  editing.value = a
  const rc = a.rewardConfig || {}
  const rewardRules = Object.keys(rc)
    .map((day) => ({ day: Number(day), rewards: (rc[day] || []).map((x) => ({ ...x })) }))
    .sort((x, y) => x.day - y.day)
  form.value = {
    name: a.name,
    status: a.status,
    startTime: a.startTime || '',
    endTime: a.endTime || '',
    rewardRules,
    eligibility: JSON.parse(JSON.stringify(a.eligibility || { type: 'all', config: {} })),
    grantMode: a.grantMode || 'realtime'
  }
  editShow.value = true
}

async function onSave() {
  const f = form.value
  if (!f.name.trim()) return ElMessage.warning('请输入活动名称')
  if (!f.startTime.trim()) return ElMessage.warning('请输入开始时间')
  if (f.endTime.trim() && f.endTime.trim() <= f.startTime.trim()) return ElMessage.warning('结束时间需晚于开始时间')
  // 校验奖励项
  const days = new Set()
  for (const r of f.rewardRules) {
    if (!Number.isInteger(r.day) || r.day < 1) return ElMessage.warning('签到天数需为正整数（第 N 天）')
    if (days.has(r.day)) return ElMessage.warning(`第 ${r.day} 天重复配置`)
    days.add(r.day)
    if (!(r.rewards || []).length) return ElMessage.warning(`第 ${r.day} 天至少配置一项奖励`)
    for (const item of r.rewards) {
      if (item.type === 'collectible' && !item.collectibleId) return ElMessage.warning('藏品奖励需选择藏品')
      if (item.type === 'points' && !(item.amount > 0)) return ElMessage.warning('司南币奖励金额需大于 0')
      if (item.type === 'priority_qualification' && !item.prioritySaleId) return ElMessage.warning('优先购资格需选择优先购活动')
      if (item.type === 'eligibility_qualification' && !item.collectibleId) return ElMessage.warning('资格购白名单需选择目标藏品')
    }
  }
  if (f.eligibility.type === 'hold' && !(f.eligibility.config.collectibleIds || []).filter(Boolean).length) {
    return ElMessage.warning('持有藏品资格需至少选择一个藏品')
  }

  saving.value = true
  const res = await saveCheckinActivityV2({
    id: editing.value?.id,
    name: f.name.trim(),
    status: f.status,
    startTime: f.startTime.trim(),
    endTime: f.endTime.trim(),
    rewardRules: f.rewardRules,
    eligibility: f.eligibility,
    grantMode: f.grantMode
  })
  saving.value = false
  if (res.code === 0) {
    ElMessage.success(editing.value ? '活动已更新' : '活动已创建')
    editShow.value = false
    load()
  }
}

async function onRemove(a) {
  await ElMessageBox.confirm(
    `确认删除活动「${a.name}」？删除后 C 端不再展示该活动，历史签到记录保留。`,
    '删除签到活动',
    { type: 'warning' }
  )
  const res = await deleteCheckinActivity(a.id)
  if (res.code === 0) {
    ElMessage.success('活动已删除')
    load()
  }
}

// ---- 奖励档位编辑（弹窗内） ----
function addRuleSlot() {
  const next = (f.value.rewardRules.reduce((m, r) => Math.max(m, r.day), 0) || 0) + 1
  if (next > 7) return ElMessage.warning('连续签到天数仅支持 1~7')
  f.value.rewardRules.push({ day: next, rewards: [{ type: 'points', amount: 10 }] })
}

function removeRuleSlot(idx) {
  f.value.rewardRules.splice(idx, 1)
}
</script>

<template>
  <div class="adm-page ck">
    <el-skeleton v-if="loading" :rows="8" animated style="padding: 20px" />
    <template v-else>
      <!-- 模块开关 -->
      <div class="adm-card">
        <div class="ck__module-toggle">
          <div>
            <div class="ck__toggle-label">签到模块</div>
            <div class="t-tertiary" style="font-size: 12px; margin-top: 3px">
              关闭后 C 端签到页为空状态；开启后展示当前生效活动（未配置活动时不显示数据）
            </div>
          </div>
          <el-switch :model-value="checkinEnabled" @change="onToggleModule" />
        </div>
      </div>

      <!-- 活动列表 -->
      <div class="adm-card">
        <div class="adm-card__title">
          签到活动（同时仅最新一条「启用中且在时间窗内」的活动生效）
          <el-button type="primary" size="small" :icon="Plus" @click="openCreate">新建活动</el-button>
        </div>

        <el-table :data="list">
          <el-table-column label="活动名称" min-width="160">
            <template #default="{ row }">
              <span class="ck__day">{{ row.name }}</span>
            </template>
          </el-table-column>
          <el-table-column label="状态" width="100" align="center">
            <template #default="{ row }">
              <el-tag :type="activityPhase(row).tag" size="small" effect="plain">{{ activityPhase(row).text }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="启停" width="80" align="center">
            <template #default="{ row }">
              <StatusTag :value="row.status" :map="ACTIVITY_STATUS" />
            </template>
          </el-table-column>
          <el-table-column label="活动时间" min-width="220">
            <template #default="{ row }">
              <span class="t-secondary">{{ row.startTime || '不限' }} ~ {{ row.endTime || '长期' }}</span>
            </template>
          </el-table-column>
          <el-table-column label="奖励档位" width="90" align="center">
            <template #default="{ row }">{{ row.rewardDays }} 档</template>
          </el-table-column>
          <el-table-column label="参与资格" min-width="130">
            <template #default="{ row }">{{ eligibilityText(row.eligibility) }}</template>
          </el-table-column>
          <el-table-column label="发放方式" width="120" align="center">
            <template #default="{ row }">{{ row.grantMode === 'manual' ? '名单统一发放' : '实时到账' }}</template>
          </el-table-column>
          <el-table-column label="累计签到" width="90" align="center">
            <template #default="{ row }">{{ fmtNumber(row.signinCount) }}</template>
          </el-table-column>
          <el-table-column label="操作" width="130" fixed="right">
            <template #default="{ row }">
              <el-button link type="primary" size="small" @click="openEdit(row)">编辑</el-button>
              <el-button link type="danger" size="small" @click="onRemove(row)">删除</el-button>
            </template>
          </el-table-column>
        </el-table>
        <el-empty v-if="!list.length" description="暂无签到活动，点击右上角「新建活动」创建" :image-size="80" />

        <el-alert
          type="info"
          :closable="false"
          show-icon
          class="ck__tip"
          title="每档可配置多项奖励（藏品/司南币/抽奖次数/优先购/资格购/盲盒）；连续签到天数支持 1~7 天；删除活动为软删除，历史签到记录保留"
        />
      </div>

      <!-- 新建/编辑活动弹窗 -->
      <el-dialog v-model="editShow" :title="editing ? `编辑活动：${editing.name}` : '新建签到活动'" width="720px" :close-on-click-modal="false">
        <el-form label-width="100px">
          <el-form-item label="活动名称">
            <el-input v-model="form.name" placeholder="如：每日签到 · 九月篇" maxlength="50" show-word-limit />
          </el-form-item>
          <el-form-item label="开始时间">
            <el-input v-model="form.startTime" placeholder="如 2026-09-01 00:00:00（必填）" />
          </el-form-item>
          <el-form-item label="结束时间">
            <el-input v-model="form.endTime" placeholder="如 2026-09-30 23:59:59，留空为长期有效" />
          </el-form-item>
          <el-form-item label="活动状态">
            <el-radio-group v-model="form.status">
              <el-radio value="enabled">启用</el-radio>
              <el-radio value="disabled">停用</el-radio>
            </el-radio-group>
          </el-form-item>
          <el-form-item label="参与资格">
            <EligibilityEditor v-model="form.eligibility" :collectibles="collectibles" />
          </el-form-item>
          <el-form-item label="奖励发放">
            <el-radio-group v-model="form.grantMode">
              <el-radio value="realtime">实时到账</el-radio>
              <el-radio value="manual">记录名单 · 统一发放</el-radio>
            </el-radio-group>
            <div class="t-tertiary" style="font-size: 12px; margin-top: 4px; width: 100%">
              实时到账：签到成功立即发放；记录名单：进入「奖励名单」页导出 CSV 统一发放
            </div>
          </el-form-item>

          <el-form-item label="奖励规则">
            <div class="ck__rules-editor">
              <div v-for="(r, idx) in form.rewardRules" :key="idx" class="ck__rule-slot">
                <div class="ck__rule-head">
                  <span>第 <el-input-number v-model="r.day" :min="1" :max="7" size="small" style="width: 90px" /> 天</span>
                  <el-button link type="danger" size="small" @click="removeRuleSlot(idx)">删除档位</el-button>
                </div>
                <RewardListEditor
                  v-model="r.rewards"
                  :collectibles="collectibles"
                  :priority-sales="prioritySales"
                />
              </div>
              <el-button :icon="Plus" size="small" @click="addRuleSlot">添加档位</el-button>
            </div>
          </el-form-item>
        </el-form>
        <template #footer>
          <el-button @click="editShow = false">取消</el-button>
          <el-button type="primary" :loading="saving" @click="onSave">保存</el-button>
        </template>
      </el-dialog>
    </template>
  </div>
</template>

<style scoped lang="scss">
.ck__module-toggle {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 10px 12px;
  border-radius: 8px;
  background: $color-primary-bg;
}

.ck__toggle-label { font-size: 14px; font-weight: 600; color: $color-text-primary; }

.ck__day { font-weight: 600; color: $color-text-primary; }

.ck__tip { margin-top: 10px; }

.ck__rules-editor {
  width: 100%;
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.ck__rule-slot {
  padding: 12px;
  border: 1px solid $color-border;
  border-radius: 8px;
  background: $color-surface;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.ck__rule-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: 13px;
  font-weight: 600;
  color: $color-text-primary;
}
</style>
