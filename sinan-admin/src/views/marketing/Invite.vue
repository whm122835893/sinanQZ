<script setup>
import { ref, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus, Delete } from '@element-plus/icons-vue'
import {
  getInviteList,
  saveInviteActivity,
  toggleInviteActivity,
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

const MAX_TIERS = 20

const REWARD_TYPES = {
  points: { label: '司南币', tag: 'warning' },
  collectible: { label: '藏品', tag: 'primary' },
  draw_chance: { label: '抽奖次数', tag: 'success' },
  priority_qualification: { label: '优先购白名单资格', tag: 'info' },
  eligibility_qualification: { label: '资格购资格', tag: 'info' },
  blindbox: { label: '盲盒', tag: 'danger' },
  none: { label: '无奖励', tag: 'info' }
}

/** 被邀请人完成条件（任一满足即算有效邀请） */
const INVITEE_CONDITIONS = {
  realname: '完成实名认证',
  wallet: '开通第三方钱包（完成充值）',
  checkin: '完成签到',
  consume: '产生消费记录'
}

function emptyForm() {
  return {
    name: '',
    status: 'disabled',
    // 档位奖励：[{ inviteCount: 1-50, rewards: [{type,...}] }]
    tiers: [],
    // 被邀请人奖励（单选一项）
    inviteeRewards: [],
    // 被邀请人完成条件（多选，任一满足）
    inviteeConditions: ['realname'],
    grantMode: 'realtime',
    totalLimit: null,
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
  const res = await getInviteList()
  list.value = res.code === 0 && Array.isArray(res.data) ? res.data : []
  loading.value = false
}

// ---- 活动开关 ----
async function onToggle(a) {
  const enabling = a.status !== 'enabled'
  await ElMessageBox.confirm(
    enabling
      ? `确认开启邀请活动「${a.name}」？开启后 C 端将展示邀请入口。`
      : `确认停用邀请活动「${a.name}」？停用后 C 端邀请入口隐藏。`,
    '活动启停',
    { type: 'warning' }
  )
  const res = await toggleInviteActivity(a)
  if (res.code === 0) {
    a.status = enabling ? 'enabled' : 'disabled'
    ElMessage.success(enabling ? '已开启' : '已停用')
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
    ...emptyForm(),
    name: a.name,
    status: a.status,
    tiers: (a.tiers || []).map((t) => ({ inviteCount: t.inviteCount, rewards: (t.rewards || []).map((x) => ({ ...x })) })),
    inviteeRewards: a.inviteeRewardConfig ? [{ ...a.inviteeRewardConfig }] : [],
    inviteeConditions: (a.inviteeConditions || []).length ? [...a.inviteeConditions] : [],
    grantMode: a.grantMode || 'realtime',
    totalLimit: a.totalLimit ?? null,
    startTime: a.startTime || '',
    endTime: a.endTime || '',
    description: a.description || ''
  }
  editShow.value = true
}

// ---- 档位编辑 ----
function addTier() {
  if (form.value.tiers.length >= MAX_TIERS) return ElMessage.warning(`最多 ${MAX_TIERS} 个档位`)
  const next = nextTierCount()
  form.value.tiers.push({ inviteCount: next, rewards: [{ type: 'points', amount: 10 }] })
}
function removeTier(idx) {
  form.value.tiers.splice(idx, 1)
}
/** 新档位默认人数：已有最大档 +1（1-50） */
function nextTierCount() {
  const max = form.value.tiers.reduce((m, t) => Math.max(m, t.inviteCount || 0), 0)
  return Math.min(50, max + 1)
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

async function onSave() {
  const f = form.value
  if (!f.name.trim()) return ElMessage.warning('请输入活动名称')

  // 档位校验
  const seen = new Set()
  for (const t of f.tiers) {
    if (!Number.isInteger(t.inviteCount) || t.inviteCount < 1 || t.inviteCount > 50) {
      return ElMessage.warning('邀请档位人数需在 1~50 之间')
    }
    if (seen.has(t.inviteCount)) return ElMessage.warning(`邀请档位人数重复：${t.inviteCount} 人`)
    seen.add(t.inviteCount)
    if (!(t.rewards || []).length) return ElMessage.warning(`「邀请 ${t.inviteCount} 人」档位需至少配置一项奖励`)
    for (const r of t.rewards) {
      const err = rewardError(r)
      if (err) return ElMessage.warning(`「邀请 ${t.inviteCount} 人」档位：${err}`)
    }
  }

  // 被邀请人奖励校验
  const inviteeReward = f.inviteeRewards[0] || null
  if (inviteeReward) {
    const err = rewardError(inviteeReward)
    if (err) return ElMessage.warning(`被邀请人奖励：${err}`)
  }

  // 启用校验：新版档位或被邀请人奖励至少其一；旧版空投藏品兜底
  const hasLegacy = editing.value && (editing.value.inviterReward?.collectibleId || editing.value.inviteeReward?.collectibleId)
  if (f.status === 'enabled' && !f.tiers.length && !inviteeReward && !hasLegacy) {
    return ElMessage.warning('启用活动需配置邀请档位奖励或被邀请人奖励至少其一')
  }

  submitting.value = true
  const res = await saveInviteActivity({
    id: editing.value?.id,
    name: f.name.trim(),
    status: f.status,
    tiers: f.tiers.map((t) => ({ inviteCount: t.inviteCount, rewards: t.rewards })),
    inviteeRewardConfig: inviteeReward,
    inviteeConditions: f.inviteeConditions,
    grantMode: f.grantMode,
    // 旧版空投字段回传（兼容已配置旧数据的活动）
    inviterReward: { collectibleId: editing.value?.inviterReward?.collectibleId || null, quantity: editing.value?.inviterReward?.quantity || 1 },
    inviteeReward: { collectibleId: editing.value?.inviteeReward?.collectibleId || null, quantity: editing.value?.inviteeReward?.quantity || 1 },
    mode: editing.value?.mode || 'realtime',
    totalLimit: f.totalLimit,
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

/** 条件摘要文案 */
function conditionsText(conds) {
  if (!(conds || []).length) return '无条件（注册即有效）'
  return (conds || []).map((c) => INVITEE_CONDITIONS[c] || c).join(' 或 ')
}
</script>

<template>
  <div class="adm-page iv">
    <el-skeleton v-if="loading" :rows="8" animated style="padding: 20px" />
    <template v-else>
      <div class="iv__toolbar">
        <div class="t-tertiary" style="font-size: 12px">
          邀请活动支持多期并存，同一时间仅一个启用中的活动对 C 端生效；档位奖励按累计有效邀请数逐档发放
        </div>
        <el-button type="primary" :icon="Plus" @click="openCreate">新建活动</el-button>
      </div>

      <el-empty v-if="!list.length" description="暂无邀请活动，点击右上角「新建活动」创建" />

      <div v-for="a in list" :key="a.id" class="adm-card iv__card">
        <div class="iv__head">
          <div class="iv__title">
            <span class="iv__name">{{ a.name }}</span>
            <StatusTag :value="a.status" :map="ACTIVITY_STATUS" />
          </div>
          <div class="iv__ops">
            <el-button link type="primary" size="small" @click="openEdit(a)">编辑</el-button>
            <el-switch :model-value="a.status === 'enabled'" @change="onToggle(a)" />
          </div>
        </div>

        <div class="iv__meta">
          <span>奖励发放：{{ a.grantMode === 'manual' ? '记录名单 · 统一发放' : '实时到账' }}</span>
          <span v-if="a.startTime">开始 {{ a.startTime }}</span>
          <span v-if="a.endTime">截止 {{ a.endTime }}</span>
          <span>总量限额：{{ a.totalLimit == null ? '不限' : fmtNumber(a.totalLimit) }}（已邀 {{ fmtNumber(a.stats.invitedCount) }}）</span>
        </div>

        <!-- 邀请档位奖励 -->
        <div v-if="(a.tiers || []).length" class="iv__tiers">
          <div class="iv__sec-title">邀请档位奖励（累计有效邀请）</div>
          <div v-for="t in a.tiers" :key="t.inviteCount" class="iv__tier">
            <div class="iv__tier-count">
              邀请 <b>{{ t.inviteCount }}</b> 人
            </div>
            <div class="iv__tier-rewards">
              <div v-for="(r, i) in t.rewards" :key="i" class="iv__tier-reward">
                <el-tag :type="REWARD_TYPES[r.type]?.tag || 'info'" effect="plain" size="small">
                  {{ REWARD_TYPES[r.type]?.label || r.type }}
                </el-tag>
                <span>{{ rewardText(r) }}</span>
              </div>
            </div>
          </div>
        </div>
        <div v-else-if="a.inviterReward?.collectibleId" class="iv__tiers">
          <div class="iv__sec-title">邀请方奖励（旧版配置）</div>
          <div class="iv__tier">
            <div class="iv__tier-count">每成功邀请 1 人</div>
            <div class="iv__tier-rewards">
              <div class="iv__tier-reward">
                <el-tag type="primary" effect="plain" size="small">藏品</el-tag>
                <span>{{ a.inviterReward.name || cname(a.inviterReward.collectibleId) }} ×{{ a.inviterReward.quantity }}</span>
              </div>
            </div>
          </div>
        </div>

        <!-- 被邀请人奖励 -->
        <div class="iv__invitee">
          <div class="iv__sec-title">被邀请人奖励</div>
          <div class="iv__invitee-body">
            <template v-if="a.inviteeRewardConfig">
              <el-tag :type="REWARD_TYPES[a.inviteeRewardConfig.type]?.tag || 'info'" effect="plain" size="small">
                {{ REWARD_TYPES[a.inviteeRewardConfig.type]?.label || a.inviteeRewardConfig.type }}
              </el-tag>
              <span>{{ rewardText(a.inviteeRewardConfig) }}</span>
            </template>
            <template v-else-if="a.inviteeReward?.collectibleId">
              <el-tag type="primary" effect="plain" size="small">藏品</el-tag>
              <span>{{ a.inviteeReward.name || cname(a.inviteeReward.collectibleId) }} ×{{ a.inviteeReward.quantity }}</span>
            </template>
            <span v-else class="t-tertiary" style="font-size: 12px">未配置</span>
            <el-divider direction="vertical" />
            <span class="t-tertiary" style="font-size: 12px">
              完成条件：{{ conditionsText(a.inviteeConditions) }}
            </span>
          </div>
        </div>

        <div v-if="a.description" class="iv__desc">{{ a.description }}</div>

        <el-alert
          type="info"
          :closable="false"
          show-icon
          class="iv__tip"
          title="奖励发放时动态校验藏品配额预留 / 盲盒库存池，不足则挂起待补发并生成异常日志，禁止超发"
        />
      </div>
    </template>

    <!-- 新建/编辑活动弹窗 -->
    <el-dialog
      v-model="editShow"
      :title="editing ? `编辑邀请活动 · ${editing.name}` : '新建邀请活动'"
      width="720px"
      :close-on-click-modal="false"
    >
      <el-form label-width="120px">
        <el-form-item label="活动名称">
          <el-input v-model="form.name" placeholder="如：青铜纪事 · 好友邀请第一期" maxlength="50" show-word-limit />
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
            启用需至少配置档位奖励或被邀请人奖励其一；新建建议先停用，配好奖励后再开启
          </div>
        </el-form-item>

        <el-form-item label="邀请档位奖励">
          <div class="iv__edit-tiers">
            <div v-for="(t, idx) in form.tiers" :key="idx" class="iv__edit-tier">
              <div class="iv__edit-tier-head">
                <span class="t-tertiary" style="font-size: 12px">累计邀请</span>
                <el-input-number v-model="t.inviteCount" :min="1" :max="50" size="small" style="width: 110px" />
                <span class="t-tertiary" style="font-size: 12px">人，发放</span>
                <el-button link type="danger" :icon="Delete" style="margin-left: auto" @click="removeTier(idx)" />
              </div>
              <RewardListEditor
                v-model="t.rewards"
                :collectibles="collectibles"
                :priority-sales="prioritySales"
              />
            </div>
            <el-button
              v-if="form.tiers.length < 20"
              link
              type="primary"
              size="small"
              :icon="Plus"
              @click="addTier"
            >
              添加档位（最多 20 档，每档人数 1~50）
            </el-button>
            <div v-else class="t-tertiary" style="font-size: 12px">已达档位上限（20 档）</div>
          </div>
        </el-form-item>

        <el-form-item label="被邀请人奖励">
          <RewardListEditor
            v-model="form.inviteeRewards"
            :collectibles="collectibles"
            :priority-sales="prioritySales"
            :max="1"
          />
          <div class="t-tertiary" style="font-size: 12px; margin-top: 4px; width: 100%">
            被邀请人完成下方条件后发放，每人限一次
          </div>
        </el-form-item>

        <el-form-item label="完成条件">
          <el-checkbox-group v-model="form.inviteeConditions">
            <el-checkbox v-for="(label, key) in INVITEE_CONDITIONS" :key="key" :value="key">
              {{ label }}
            </el-checkbox>
          </el-checkbox-group>
          <div class="t-tertiary" style="font-size: 12px; margin-top: 4px; width: 100%">
            多选时满足任一条件即视为有效邀请；不勾选则注册即有效
          </div>
        </el-form-item>

        <el-form-item label="奖励发放">
          <el-radio-group v-model="form.grantMode">
            <el-radio value="realtime">实时到账</el-radio>
            <el-radio value="manual">记录名单 · 统一发放</el-radio>
          </el-radio-group>
          <div class="t-tertiary" style="font-size: 12px; margin-top: 4px; width: 100%">
            实时到账：达标立即发放；记录名单：进入「奖励名单」页导出 CSV 统一发放
          </div>
        </el-form-item>

        <el-form-item label="总量限额">
          <el-input-number v-model="form.totalLimit" :min="1" :step="100" placeholder="留空不限" style="width: 180px" />
          <div class="t-tertiary" style="font-size: 12px; margin-top: 4px; width: 100%">
            留空表示不限制邀请总量
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
.iv__toolbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  margin-bottom: 14px;
}

.iv__card + .iv__card { margin-top: 14px; }

.iv__head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
}

.iv__title { display: flex; align-items: center; gap: 8px; min-width: 0; }
.iv__name { font-size: 15px; font-weight: 700; color: $color-text-primary; }
.iv__ops { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }

.iv__meta {
  display: flex;
  flex-wrap: wrap;
  gap: 6px 18px;
  font-size: 12px;
  color: $color-text-secondary;
  margin-top: 8px;
}

.iv__sec-title { font-size: 11px; color: $color-text-tertiary; margin-bottom: 6px; }

.iv__tiers {
  margin-top: 12px;
  padding: 10px 12px;
  border-radius: 8px;
  background: $color-surface;
}

.iv__tier {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  padding: 6px 0;

  & + .iv__tier { border-top: 1px dashed $color-border; }
}

.iv__tier-count {
  width: 110px;
  flex-shrink: 0;
  font-size: 13px;
  font-weight: 600;
  color: $color-text-primary;

  b { color: $color-primary; }
}

.iv__tier-rewards { flex: 1; display: flex; flex-direction: column; gap: 5px; }

.iv__tier-reward {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  color: $color-text-primary;
}

.iv__invitee {
  margin-top: 10px;
  padding: 10px 12px;
  border-radius: 8px;
  background: $color-surface;
}

.iv__invitee-body {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  font-size: 13px;
  color: $color-text-primary;
}

.iv__desc {
  margin-top: 10px;
  font-size: 12px;
  color: $color-text-secondary;
}

.iv__tip { margin-top: 10px; }

.iv__edit-tiers { width: 100%; }

.iv__edit-tier {
  padding: 10px 12px;
  border: 1px solid $color-border;
  border-radius: 8px;
  margin-bottom: 10px;
}

.iv__edit-tier-head {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 8px;
}
</style>
