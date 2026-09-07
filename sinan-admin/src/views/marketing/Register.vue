<script setup>
import { ref, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus, Delete } from '@element-plus/icons-vue'
import {
  getRegisterActivities,
  saveRegisterActivity,
  deleteRegisterActivity,
  getCollectibleList,
  getPrioritySales
} from '@/api'
import StatusTag from '@/components/StatusTag.vue'
import RewardListEditor from '@/components/RewardListEditor.vue'
import { ACTIVITY_STATUS } from '@/utils/maps'
import { fmtNumber } from '@/utils/format'

const loading = ref(true)
const list = ref([])

// ---- 下拉数据源 ----
const collectibles = ref([])
const prioritySales = ref([])

// ---- 新建/编辑 ----
const editShow = ref(false)
const editing = ref(null)
const submitting = ref(false)
const form = ref(emptyForm())

const MAX_TIERS = 10

const REWARD_TYPES = {
  points: { label: '司南币', tag: 'warning' },
  collectible: { label: '藏品', tag: 'primary' },
  draw_chance: { label: '抽奖次数', tag: 'success' },
  priority_qualification: { label: '优先购白名单资格', tag: 'info' },
  eligibility_qualification: { label: '资格购资格', tag: 'info' },
  blindbox: { label: '盲盒', tag: 'danger' },
  none: { label: '无奖励', tag: 'info' }
}

/** 名次快捷档位（注册实名前 N 名） */
const RANK_PRESETS = [100, 500, 1000, 2000, 3000, 5000, 10000]

function emptyForm() {
  return {
    name: '',
    status: 'disabled',
    // 档位：[{ rankLimit: N, rewards: [{type,...}] }]（实名前 N 名）
    tiers: [{ rankLimit: 1000, rewards: [{ type: 'points', amount: 10 }] }],
    grantMode: 'realtime',
    startTime: '',
    endTime: '',
    description: ''
  }
}

onMounted(async () => {
  load()
  const [col, pri] = await Promise.all([
    getCollectibleList({ page: 1, pageSize: 200 }),
    getPrioritySales()
  ])
  if (col.code === 0) collectibles.value = col.data.list || []
  if (pri.code === 0) prioritySales.value = pri.data || []
})

async function load() {
  loading.value = true
  const res = await getRegisterActivities()
  list.value = res.code === 0 && Array.isArray(res.data) ? res.data : []
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

/** 校验单个奖励项完整性，返回错误文案或 null */
function rewardError(r) {
  if (r.type === 'collectible' && !r.collectibleId) return '藏品奖励需选择藏品'
  if (r.type === 'points' && !(r.amount > 0)) return '司南币奖励金额需大于 0'
  if (r.type === 'priority_qualification' && !r.prioritySaleId) return '优先购资格需选择优先购活动'
  if (r.type === 'eligibility_qualification' && !r.collectibleId) return '资格购白名单需选择目标藏品'
  return null
}

// ---- 活动开关 ----
async function onToggle(a) {
  const enabling = a.status !== 'enabled'
  await ElMessageBox.confirm(
    enabling
      ? `确认开启注册活动「${a.name}」？开启后新实名用户将按档位获得奖励。`
      : `确认停用注册活动「${a.name}」？停用后新实名用户不再发放该活动奖励。`,
    '活动启停',
    { type: 'warning' }
  )
  const res = await saveRegisterActivity({
    id: a.id,
    name: a.name,
    status: enabling ? 'enabled' : 'disabled',
    startTime: a.startTime,
    endTime: a.endTime,
    tiers: a.tiers,
    grantMode: a.grantMode,
    description: a.description
  })
  if (res.code === 0) {
    a.status = enabling ? 'enabled' : 'disabled'
    ElMessage.success(enabling ? '已开启' : '已停用')
  }
}

// ---- 删除 ----
async function onDelete(a) {
  await ElMessageBox.confirm(
    `确认删除注册活动「${a.name}」？已产生发放记录的活动将转为停用。`,
    '删除活动',
    { type: 'warning' }
  )
  const res = await deleteRegisterActivity(a.id)
  if (res.code === 0) {
    ElMessage.success('已删除')
    load()
  }
}

// ---- 新建/编辑 ----
function openCreate() {
  editing.value = null
  form.value = emptyForm()
  editShow.value = true
}

function openEdit(a) {
  editing.value = a
  form.value = {
    name: a.name,
    status: a.status,
    tiers: (a.tiers || []).map((t) => ({ rankLimit: t.rankLimit, rewards: (t.rewards || []).map((x) => ({ ...x })) })),
    grantMode: a.grantMode || 'realtime',
    startTime: a.startTime || '',
    endTime: a.endTime || '',
    description: a.description || ''
  }
  editShow.value = true
}

// ---- 档位编辑 ----
function addTier() {
  if (form.value.tiers.length >= MAX_TIERS) return ElMessage.warning(`最多 ${MAX_TIERS} 个档位`)
  const max = form.value.tiers.reduce((m, t) => Math.max(m, t.rankLimit || 0), 0)
  form.value.tiers.push({ rankLimit: Math.min(1000000, max ? max * 2 : 1000), rewards: [{ type: 'points', amount: 10 }] })
}
function removeTier(idx) {
  form.value.tiers.splice(idx, 1)
}

async function onSave() {
  const f = form.value
  if (!f.name.trim()) return ElMessage.warning('请输入活动名称')
  if (!f.tiers.length) return ElMessage.warning('至少配置一个实名档位')

  const seen = new Set()
  for (const t of f.tiers) {
    if (!Number.isInteger(t.rankLimit) || t.rankLimit < 1 || t.rankLimit > 1000000) {
      return ElMessage.warning('档位名次需在 1~1000000（如：实名前1000名 → 1000）')
    }
    if (seen.has(t.rankLimit)) return ElMessage.warning(`档位名次重复：前 ${t.rankLimit} 名`)
    seen.add(t.rankLimit)
    if (!(t.rewards || []).length) return ElMessage.warning(`「实名前 ${t.rankLimit} 名」档位需至少配置一项奖励`)
    for (const r of t.rewards) {
      const err = rewardError(r)
      if (err) return ElMessage.warning(`「实名前 ${t.rankLimit} 名」档位：${err}`)
    }
  }

  submitting.value = true
  const res = await saveRegisterActivity({
    id: editing.value?.id,
    name: f.name.trim(),
    status: f.status,
    tiers: f.tiers.map((t) => ({ rankLimit: t.rankLimit, rewards: t.rewards })),
    grantMode: f.grantMode,
    startTime: f.startTime,
    endTime: f.endTime,
    description: f.description
  })
  submitting.value = false
  if (res.code === 0) {
    ElMessage.success(editing.value ? '活动已更新' : '活动已创建')
    editShow.value = false
    load()
  }
}
</script>

<template>
  <div class="adm-page rg">
    <el-skeleton v-if="loading" :rows="8" animated style="padding: 20px" />
    <template v-else>
      <div class="rg__toolbar">
        <div class="t-tertiary" style="font-size: 12px">
          注册活动：用户完成注册实名后，按平台实名顺序命中档位发放奖励（如：实名前 1000 名、前 3000 名）
        </div>
        <el-button type="primary" :icon="Plus" @click="openCreate">新建活动</el-button>
      </div>

      <el-empty v-if="!list.length" description="暂无注册活动，点击右上角「新建活动」创建" />

      <div v-for="a in list" :key="a.id" class="adm-card rg__card">
        <div class="rg__head">
          <div class="rg__title">
            <span class="rg__name">{{ a.name }}</span>
            <StatusTag :value="a.status" :map="ACTIVITY_STATUS" />
          </div>
          <div class="rg__ops">
            <el-button link type="primary" size="small" @click="openEdit(a)">编辑</el-button>
            <el-button link type="danger" size="small" @click="onDelete(a)">删除</el-button>
            <el-switch :model-value="a.status === 'enabled'" @change="onToggle(a)" />
          </div>
        </div>

        <div class="rg__meta">
          <span>奖励发放：{{ a.grantMode === 'manual' ? '记录名单 · 统一发放' : '实时到账' }}</span>
          <span v-if="a.startTime">开始 {{ a.startTime }}</span>
          <span v-if="a.endTime">截止 {{ a.endTime }}</span>
          <span>已发放：{{ fmtNumber(a.grantedCount) }} 人</span>
          <span>平台累计实名：{{ fmtNumber(a.realnameCount) }} 人</span>
        </div>

        <div class="rg__tiers">
          <div class="rg__sec-title">实名档位奖励（按实名顺序命中）</div>
          <div v-for="t in a.tiers" :key="t.rankLimit" class="rg__tier">
            <div class="rg__tier-count">
              实名前 <b>{{ fmtNumber(t.rankLimit) }}</b> 名
            </div>
            <div class="rg__tier-rewards">
              <div v-for="(r, i) in t.rewards" :key="i" class="rg__tier-reward">
                <el-tag :type="REWARD_TYPES[r.type]?.tag || 'info'" effect="plain" size="small">
                  {{ REWARD_TYPES[r.type]?.label || r.type }}
                </el-tag>
                <span>{{ rewardText(r) }}</span>
              </div>
            </div>
          </div>
        </div>

        <div v-if="a.description" class="rg__desc">{{ a.description }}</div>
      </div>
    </template>

    <!-- 新建/编辑活动弹窗 -->
    <el-dialog
      v-model="editShow"
      :title="editing ? `编辑注册活动 · ${editing.name}` : '新建注册活动'"
      width="720px"
      :close-on-click-modal="false"
    >
      <el-form label-width="120px">
        <el-form-item label="活动名称">
          <el-input v-model="form.name" placeholder="如：开馆纪 · 注册实名礼" maxlength="50" show-word-limit />
        </el-form-item>

        <el-form-item label="活动状态">
          <el-switch
            v-model="form.status"
            active-value="enabled"
            inactive-value="disabled"
            active-text="启用"
            inactive-text="停用"
          />
          <div class="t-tertiary" style="font-size: 12px; margin-top: 4px; width: 100%">
            启用后新完成实名的用户按顺序命中档位即得奖励；新建建议先停用，配好档位后再开启
          </div>
        </el-form-item>

        <el-form-item label="实名档位奖励">
          <div class="rg__edit-tiers">
            <div class="rg__presets">
              <span class="t-tertiary" style="font-size: 12px">快捷档位：</span>
              <el-button
                v-for="p in RANK_PRESETS"
                :key="p"
                size="small"
                plain
                @click="form.tiers.push({ rankLimit: p, rewards: [{ type: 'points', amount: 10 }] })"
              >
                前{{ fmtNumber(p) }}名
              </el-button>
            </div>
            <div v-for="(t, idx) in form.tiers" :key="idx" class="rg__edit-tier">
              <div class="rg__edit-tier-head">
                <span class="t-tertiary" style="font-size: 12px">注册实名前</span>
                <el-input-number v-model="t.rankLimit" :min="1" :max="1000000" :step="100" size="small" style="width: 150px" />
                <span class="t-tertiary" style="font-size: 12px">名，发放</span>
                <el-button link type="danger" :icon="Delete" style="margin-left: auto" @click="removeTier(idx)" />
              </div>
              <RewardListEditor
                v-model="t.rewards"
                :collectibles="collectibles"
                :priority-sales="prioritySales"
              />
            </div>
            <el-button
              v-if="form.tiers.length < 10"
              link
              type="primary"
              size="small"
              :icon="Plus"
              @click="addTier"
            >
              添加档位（最多 10 档）
            </el-button>
            <div v-else class="t-tertiary" style="font-size: 12px">已达档位上限（10 档）</div>
          </div>
        </el-form-item>

        <el-form-item label="奖励发放">
          <el-radio-group v-model="form.grantMode">
            <el-radio value="realtime">实时到账</el-radio>
            <el-radio value="manual">记录名单 · 统一发放</el-radio>
          </el-radio-group>
          <div class="t-tertiary" style="font-size: 12px; margin-top: 4px; width: 100%">
            实时到账：实名审核通过立即发放；记录名单：进入「奖励名单」页导出 CSV 统一发放
          </div>
        </el-form-item>

        <el-form-item label="起止时间">
          <el-input v-model="form.startTime" placeholder="开始时间 2026-09-01 00:00:00（可留空）" style="width: 46%; margin-right: 4px" />
          <el-input v-model="form.endTime" placeholder="截止时间（可留空）" style="width: 48%" />
        </el-form-item>

        <el-form-item label="活动说明">
          <el-input
            v-model="form.description"
            type="textarea"
            :rows="2"
            maxlength="200"
            show-word-limit
            placeholder="C 端活动页展示的说明文案"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="editShow = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="onSave">
          {{ editing ? '保存修改' : '创建活动' }}
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped lang="scss">
.rg__toolbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  margin-bottom: 14px;
}

.rg__card + .rg__card { margin-top: 14px; }

.rg__head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
}

.rg__title { display: flex; align-items: center; gap: 8px; min-width: 0; }
.rg__name { font-size: 15px; font-weight: 700; color: $color-text-primary; }
.rg__ops { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }

.rg__meta {
  display: flex;
  flex-wrap: wrap;
  gap: 6px 18px;
  font-size: 12px;
  color: $color-text-secondary;
  margin-top: 8px;
}

.rg__sec-title { font-size: 11px; color: $color-text-tertiary; margin-bottom: 6px; }

.rg__tiers {
  margin-top: 12px;
  padding: 10px 12px;
  border-radius: 8px;
  background: $color-surface;
}

.rg__tier {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  padding: 6px 0;

  & + .rg__tier { border-top: 1px dashed $color-border; }
}

.rg__tier-count {
  width: 130px;
  flex-shrink: 0;
  font-size: 13px;
  font-weight: 600;
  color: $color-text-primary;

  b { color: $color-primary; }
}

.rg__tier-rewards { flex: 1; display: flex; flex-direction: column; gap: 5px; }

.rg__tier-reward {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  color: $color-text-primary;
}

.rg__desc {
  margin-top: 10px;
  font-size: 12px;
  color: $color-text-secondary;
}

.rg__edit-tiers { width: 100%; }

.rg__presets {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 6px;
  margin-bottom: 10px;
}

.rg__edit-tier {
  padding: 10px 12px;
  border: 1px solid $color-border;
  border-radius: 8px;
  margin-bottom: 10px;
}

.rg__edit-tier-head {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 8px;
}
</style>
