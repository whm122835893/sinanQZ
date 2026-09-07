<script setup>
import { ref, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import {
  getCheckinConfig,
  saveCheckinRules,
  saveCheckinActivity,
  saveCheckinSettings,
  toggleCheckin,
  getCollectibleList,
  getPrioritySales
} from '@/api'
import StatusTag from '@/components/StatusTag.vue'
import RewardListEditor from '@/components/RewardListEditor.vue'
import EligibilityEditor from '@/components/EligibilityEditor.vue'
import { ACTIVITY_STATUS } from '@/utils/maps'
import { fmtNumber } from '@/utils/format'

const loading = ref(true)
const config = ref(null)

// ---- 下拉数据源 ----
const collectibles = ref([])
const prioritySales = ref([])

// ---- 规则编辑 ----
const editShow = ref(false)
const editingRule = ref(null)
const ruleForm = ref({ day: 1, rewards: [] })

// ---- 活动信息编辑（名称/起止时间 + 参与资格 + 发放方式） ----
const infoShow = ref(false)
const infoForm = ref({ name: '', startTime: '', endTime: '' })
const setForm = ref({ eligibility: { type: 'all', config: {} }, grantMode: 'realtime' })

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
  const res = await getCheckinConfig()
  config.value = res.data || null
  loading.value = false
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

// ---- 活动信息 + 参与资格 + 发放方式编辑 ----
function openInfo() {
  infoForm.value = {
    name: config.value.name || '每日签到',
    startTime: config.value.startTime || '',
    endTime: config.value.endTime || ''
  }
  setForm.value = {
    eligibility: JSON.parse(JSON.stringify(config.value.eligibility || { type: 'all', config: {} })),
    grantMode: config.value.grantMode || 'realtime'
  }
  infoShow.value = true
}

async function onSaveInfo() {
  const f = infoForm.value
  if (!f.name.trim()) return ElMessage.warning('请输入活动名称')
  const st = setForm.value
  if (st.eligibility.type === 'hold' && !(st.eligibility.config.collectibleIds || []).filter(Boolean).length) {
    return ElMessage.warning('持有藏品资格需至少选择一个藏品')
  }
  const res = await saveCheckinActivity({ name: f.name.trim(), startTime: f.startTime.trim(), endTime: f.endTime.trim() })
  if (res.code !== 0) return
  const res2 = await saveCheckinSettings(st)
  if (res2.code === 0) {
    ElMessage.success('活动配置已保存')
    infoShow.value = false
    load()
  }
}

// ---- 规则编辑 ----
function openAddRule() {
  editingRule.value = null
  ruleForm.value = { day: null, rewards: [{ type: 'points', amount: 10 }] }
  editShow.value = true
}

function openEditRule(r) {
  editingRule.value = r
  ruleForm.value = { day: r.day, rewards: (r.rewards || []).map((x) => ({ ...x })) }
  editShow.value = true
}

async function onSaveRule() {
  const f = ruleForm.value
  if (!Number.isInteger(f.day) || f.day < 1) return ElMessage.warning('请输入有效的天数（第 N 天）')
  if (!(f.rewards || []).length) return ElMessage.warning('请至少配置一项奖励')
  const exists = config.value.rules.find((r) => r.day === f.day && r !== editingRule.value)
  if (exists) return ElMessage.warning(`第 ${f.day} 天已有奖励规则`)
  // 校验奖励项完整性
  for (const r of f.rewards) {
    if (r.type === 'collectible' && !r.collectibleId) return ElMessage.warning('藏品奖励需选择藏品')
    if (r.type === 'points' && !(r.amount > 0)) return ElMessage.warning('司南币奖励金额需大于 0')
    if (r.type === 'priority_qualification' && !r.prioritySaleId) return ElMessage.warning('优先购资格需选择优先购活动')
    if (r.type === 'eligibility_qualification' && !r.collectibleId) return ElMessage.warning('资格购白名单需选择目标藏品')
  }
  if (editingRule.value) {
    Object.assign(editingRule.value, { day: f.day, rewards: f.rewards })
  } else {
    config.value.rules.push({ day: f.day, rewards: f.rewards })
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
                <el-button link type="primary" size="small" @click="openInfo">编辑活动配置</el-button>
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
            <div class="ck__settings">
              <div class="ck__setting">
                <div class="t-tertiary">参与资格</div>
                <div class="ck__setting-val">{{ eligibilityText(config.eligibility) }}</div>
              </div>
              <div class="ck__setting">
                <div class="t-tertiary">奖励发放</div>
                <div class="ck__setting-val">
                  {{ config.grantMode === 'manual' ? '记录名单 · 统一发放' : '实时到账' }}
                </div>
              </div>
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
              奖励规则（第 N 天 → 奖励，可配置多项）
              <el-button link type="primary" size="small" :icon="Plus" @click="openAddRule">新增档位</el-button>
            </div>

            <el-table :data="config.rules">
              <el-table-column label="签到天数" width="100" align="center">
                <template #default="{ row }">
                  <span class="ck__day">第 {{ row.day }} 天</span>
                </template>
              </el-table-column>
              <el-table-column label="奖励内容" min-width="260">
                <template #default="{ row }">
                  <div class="ck__rewards">
                    <div v-for="(r, i) in row.rewards" :key="i" class="ck__reward-item">
                      <el-tag :type="REWARD_TYPES[r.type]?.tag || 'info'" effect="plain" size="small">
                        {{ REWARD_TYPES[r.type]?.label || r.type }}
                      </el-tag>
                      <span class="ck__reward">{{ rewardText(r) }}</span>
                    </div>
                  </div>
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
              title="每档可配置多项奖励（藏品/司南币/抽奖次数/优先购/资格购/盲盒）；发放时动态校验配额预留库存；选择「记录名单」的奖励在奖励名单页导出统一发放"
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
      <el-dialog v-model="editShow" :title="editingRule ? `编辑第 ${editingRule.day} 天奖励` : '新增奖励档位'" width="620px" :close-on-click-modal="false">
        <el-form label-width="100px">
          <el-form-item label="第 N 天">
            <el-input-number v-model="ruleForm.day" :min="1" :max="365" />
          </el-form-item>
          <el-form-item label="奖励配置">
            <RewardListEditor
              v-model="ruleForm.rewards"
              :collectibles="collectibles"
              :priority-sales="prioritySales"
            />
          </el-form-item>
        </el-form>
        <template #footer>
          <el-button @click="editShow = false">取消</el-button>
          <el-button type="primary" @click="onSaveRule">保存</el-button>
        </template>
      </el-dialog>

      <!-- 活动配置编辑弹窗（信息 + 参与资格 + 发放方式） -->
      <el-dialog v-model="infoShow" title="编辑签到活动配置" width="560px" :close-on-click-modal="false">
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
          <el-form-item label="参与资格">
            <EligibilityEditor v-model="setForm.eligibility" :collectibles="collectibles" />
          </el-form-item>
          <el-form-item label="奖励发放">
            <el-radio-group v-model="setForm.grantMode">
              <el-radio value="realtime">实时到账</el-radio>
              <el-radio value="manual">记录名单 · 统一发放</el-radio>
            </el-radio-group>
            <div class="t-tertiary" style="font-size: 12px; margin-top: 4px; width: 100%">
              实时到账：签到成功立即发放；记录名单：进入「奖励名单」页导出 CSV 统一发放
            </div>
          </el-form-item>
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

.ck__settings {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 10px;
  margin-top: 10px;
}

.ck__setting {
  padding: 10px 12px;
  border-radius: 8px;
  background: $color-surface;

  .t-tertiary { font-size: 11px; }
}

.ck__setting-val {
  font-size: 13px;
  font-weight: 600;
  color: $color-text-primary;
  margin-top: 3px;
}

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

.ck__rewards { display: flex; flex-direction: column; gap: 5px; }

.ck__reward-item {
  display: flex;
  align-items: center;
  gap: 6px;
}

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
