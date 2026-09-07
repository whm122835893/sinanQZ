<script setup>
import { ref, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import {
  getLuckyDraws,
  toggleLuckyDraw,
  saveLuckyActivity,
  saveLuckyPrizes,
  getCollectibleList,
  getPrioritySales,
  uploadImage
} from '@/api'
import StatusTag from '@/components/StatusTag.vue'
import RewardListEditor from '@/components/RewardListEditor.vue'
import EligibilityEditor from '@/components/EligibilityEditor.vue'
import { ACTIVITY_STATUS } from '@/utils/maps'
import { fmtNumber } from '@/utils/format'

const loading = ref(true)
const activities = ref([])
const activeTab = ref('')

// ---- 下拉数据源 ----
const collectibles = ref([])
const prioritySales = ref([])

// ---- 活动（新建/编辑） ----
const actShow = ref(false)
const actEditing = ref(null)
const actSubmitting = ref(false)
const actForm = ref({
  name: '',
  enabled: false,
  startTime: '',
  endTime: '',
  eligibility: { type: 'all', config: {} },
  grantMode: 'realtime'
})

// ---- 奖项池编辑 ----
const poolShow = ref(false)
const poolActivity = ref(null)
const poolDraft = ref([])
const poolSubmitting = ref(false)

// ---- 单个奖项编辑 ----
const prizeShow = ref(false)
const prizeEditingIdx = ref(-1)
const prizeForm = ref({ tier: '', name: '', cover: '', rewards: [], probability: 10, total: 10 })
const uploading = ref(false)

const PRIZE_TYPES = {
  collectible: { label: '藏品空投', tag: 'primary' },
  points: { label: '司南币', tag: 'warning' },
  draw_chance: { label: '抽奖次数', tag: 'success' },
  priority_qualification: { label: '优先购资格', tag: 'info' },
  eligibility_qualification: { label: '资格购白名单', tag: 'info' },
  blindbox: { label: '盲盒', tag: 'danger' },
  none: { label: '空奖', tag: 'info' }
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
  const res = await getLuckyDraws()
  activities.value = Array.isArray(res.data) ? res.data : []
  if (activities.value.length) {
    // 保持当前选中，若已不存在则回退到第一个
    if (!activities.value.some((a) => String(a.id) === activeTab.value)) {
      activeTab.value = String(activities.value[0].id)
    }
  } else {
    activeTab.value = ''
  }
  loading.value = false
}

const current = computed(() => activities.value.find((a) => String(a.id) === activeTab.value) || activities.value[0])

// 非空奖概率合计
const nonEmptySum = (a) => (a.prizes || []).filter((p) => p.type !== 'none').reduce((s, p) => s + p.probability, 0)
const probOk = (a) => nonEmptySum(a) <= 1.0000001
const emptyRate = (a) => Math.max(0, 100 - nonEmptySum(a) * 100)

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

// ---- 活动启停 ----
async function onToggle(a) {
  const enabling = a.status !== 'enabled'
  await ElMessageBox.confirm(
    enabling ? `确认开启活动「${a.name}」？启用将校验奖池完整性。` : `确认停用活动「${a.name}」？停用后 C 端入口隐藏。`,
    '活动启停',
    { type: 'warning' }
  )
  const res = await toggleLuckyDraw(a)
  if (res.code === 0) {
    a.status = enabling ? 'enabled' : 'disabled'
    ElMessage.success(enabling ? '已开启' : '已停用')
  } else if (res.message) {
    ElMessage.error(res.message)
  }
}

// ---- 新建/编辑活动 ----
function openCreate() {
  actEditing.value = null
  actForm.value = {
    name: '',
    enabled: false,
    startTime: '',
    endTime: '',
    eligibility: { type: 'all', config: {} },
    grantMode: 'realtime'
  }
  actShow.value = true
}

function openEditActivity(a) {
  actEditing.value = a
  actForm.value = {
    name: a.name,
    enabled: a.status === 'enabled',
    startTime: a.startTime || '',
    endTime: a.endTime || '',
    eligibility: JSON.parse(JSON.stringify(a.eligibility || { type: 'all', config: {} })),
    grantMode: a.grantMode || 'realtime'
  }
  actShow.value = true
}

async function onSaveActivity() {
  const f = actForm.value
  if (!f.name.trim()) return ElMessage.warning('请输入活动名称')
  if (f.eligibility.type === 'hold' && !(f.eligibility.config.collectibleIds || []).filter(Boolean).length) {
    return ElMessage.warning('持有藏品资格需至少选择一个藏品')
  }
  actSubmitting.value = true
  const res = await saveLuckyActivity({
    id: actEditing.value?.id,
    name: f.name.trim(),
    enabled: f.enabled,
    startTime: f.startTime.trim(),
    endTime: f.endTime.trim(),
    eligibility: f.eligibility,
    grantMode: f.grantMode
  })
  actSubmitting.value = false
  if (res.code === 0) {
    ElMessage.success(actEditing.value ? '活动已更新' : '活动已创建，可继续在奖项池中配置奖品')
    actShow.value = false
    load()
  }
}

// ---- 奖项池编辑（整池草稿，一次提交） ----
function openPool(a) {
  poolActivity.value = a
  poolDraft.value = (a.prizes || []).map((p) => ({
    id: p.id,
    tier: p.tier,
    name: p.name || '',
    cover: p.cover || '',
    type: p.type,
    rewardConfig: p.rewardConfig ? { ...p.rewardConfig } : null,
    total: p.total,
    won: p.won || 0,
    probability: p.probability
  }))
  poolShow.value = true
}

const poolProbSum = computed(() =>
  poolDraft.value.filter((p) => p.type !== 'none').reduce((s, p) => s + Number(p.probability || 0), 0)
)

function openAddPrize() {
  prizeEditingIdx.value = -1
  prizeForm.value = { tier: '', name: '', cover: '', rewards: [{ type: 'points', amount: 88 }], probability: 10, total: 10 }
  prizeShow.value = true
}

function openEditPrize(idx) {
  const p = poolDraft.value[idx]
  prizeEditingIdx.value = idx
  const rewards = p.type === 'none' ? [] : [p.rewardConfig || { type: p.type }]
  prizeForm.value = {
    tier: p.tier,
    name: p.name || '',
    cover: p.cover || '',
    rewards,
    probability: Number((p.probability * 100).toFixed(4)),
    total: p.total
  }
  prizeShow.value = true
}

async function onRemovePrize(idx) {
  const p = poolDraft.value[idx]
  if (p.won > 0) return ElMessage.warning('该奖项已有人中奖，禁止删除')
  await ElMessageBox.confirm(`确认删除奖项「${p.tier}」？`, '删除奖项', { type: 'warning' })
  poolDraft.value.splice(idx, 1)
}

/** 单个奖项草稿 → 池条目 */
function onConfirmPrize() {
  const f = prizeForm.value
  if (!f.tier.trim()) return ElMessage.warning('请输入奖项名（如：一等奖）')
  if (!(f.probability > 0 && f.probability <= 100)) return ElMessage.warning('概率需为 0.0001 ~ 100')
  if (!Number.isInteger(f.total) || f.total < 1) return ElMessage.warning('奖项数量需为正整数')
  const type = f.rewards.length ? f.rewards[0].type : 'none'
  const rewardConfig = type !== 'none' ? f.rewards[0] : null
  if (type === 'collectible' && !rewardConfig.collectibleId) return ElMessage.warning('藏品奖项需选择藏品')
  if (type === 'points' && !(rewardConfig.amount > 0)) return ElMessage.warning('司南币奖项金额需大于 0')
  if (type === 'priority_qualification' && !rewardConfig.prioritySaleId) return ElMessage.warning('优先购资格需选择优先购活动')
  if (type === 'eligibility_qualification' && !rewardConfig.collectibleId) return ElMessage.warning('资格购白名单需选择目标藏品')
  const entry = {
    tier: f.tier.trim(),
    name: f.name.trim(),
    cover: f.cover,
    type,
    rewardConfig,
    total: f.total,
    won: prizeEditingIdx.value >= 0 ? poolDraft.value[prizeEditingIdx.value].won : 0,
    probability: Number((f.probability / 100).toFixed(6))
  }
  if (prizeEditingIdx.value >= 0) {
    entry.id = poolDraft.value[prizeEditingIdx.value].id
    if (entry.total < entry.won) return ElMessage.warning(`奖项数量不可低于已发出数量（已发出 ${entry.won} 份）`)
    poolDraft.value[prizeEditingIdx.value] = entry
  } else {
    poolDraft.value.push(entry)
  }
  prizeShow.value = false
}

async function onSavePool() {
  if (!poolDraft.value.length) return ElMessage.warning('至少配置一个奖项')
  if (Math.abs(poolProbSum.value - 1) > 0.0001) {
    return ElMessage.warning(`非空奖概率合计需为 100%（当前 ${(poolProbSum.value * 100).toFixed(2)}%）`)
  }
  poolSubmitting.value = true
  const res = await saveLuckyPrizes(poolActivity.value.id, poolDraft.value)
  poolSubmitting.value = false
  if (res.code === 0) {
    ElMessage.success('奖项池已保存')
    poolShow.value = false
    load()
  } else if (res.message) {
    ElMessage.error(res.message)
  }
}

// ---- 图片上传 ----
async function onUploadPrizeImage({ file }) {
  if (!file) return
  uploading.value = true
  const res = await uploadImage(file, 'marketing')
  uploading.value = false
  if (res.code === 0 && res.data?.url) {
    prizeForm.value.cover = res.data.url
    ElMessage.success('奖品图已上传')
  } else {
    ElMessage.error(res.message || '上传失败，请重试')
  }
}
</script>

<template>
  <div class="adm-page ld">
    <el-skeleton v-if="loading" :rows="8" animated style="padding: 20px" />
    <template v-else>
      <div class="ld__toolbar">
        <div class="t-tertiary" style="font-size: 12px">
          抽奖活动支持多期并存；新活动创建后需在奖项池配置完整奖品（概率合计=100%）方可启用
        </div>
        <el-button type="primary" :icon="Plus" @click="openCreate">新建活动</el-button>
      </div>

      <el-empty v-if="!activities.length" description="暂无抽奖活动，点击右上角「新建活动」创建" />

      <template v-else>
        <el-tabs v-model="activeTab" type="card">
          <el-tab-pane v-for="a in activities" :key="a.id" :name="String(a.id)">
            <template #label>
              <span class="ld__tab-label">{{ a.name }}</span>
              <StatusTag :value="a.status" :map="ACTIVITY_STATUS" style="margin-left: 6px" />
            </template>
          </el-tab-pane>
        </el-tabs>

        <div v-if="current" class="ld__body">
          <div class="adm-card">
            <div class="ld__head">
              <div>
                <div class="ld__name">{{ current.name }}</div>
                <div class="t-tertiary" style="font-size: 12px; margin-top: 4px">
                  {{ current.startTime || '不限' }} ~ {{ current.endTime || '不限' }}
                </div>
              </div>
              <div class="ld__head-ops">
                <el-button link type="primary" size="small" @click="openEditActivity(current)">编辑活动</el-button>
                <el-switch
                  :model-value="current.status === 'enabled'"
                  active-text="进行中"
                  inactive-text="已停用"
                  @change="onToggle(current)"
                />
              </div>
            </div>

            <div class="ld__settings">
              <div class="ld__setting">
                <div class="t-tertiary">参与资格</div>
                <div class="ld__setting-val">{{ eligibilityText(current.eligibility) }}</div>
              </div>
              <div class="ld__setting">
                <div class="t-tertiary">奖励发放</div>
                <div class="ld__setting-val">{{ current.grantMode === 'manual' ? '记录名单 · 统一发放' : '实时到账' }}</div>
              </div>
            </div>

            <div class="ld__stats">
              <div class="ld__stat">
                <div class="price">{{ fmtNumber(current.chancesIssued) }}</div>
                <div class="t-tertiary">发放次数</div>
              </div>
              <div class="ld__stat">
                <div class="price">{{ fmtNumber(current.chancesUsed) }}</div>
                <div class="t-tertiary">已消耗</div>
              </div>
              <div class="ld__stat">
                <div class="price" :class="{ 't-danger': !probOk(current) }">{{ (nonEmptySum(current) * 100).toFixed(2) }}%</div>
                <div class="t-tertiary">非空奖概率合计</div>
              </div>
              <div class="ld__stat">
                <div class="price">{{ emptyRate(current).toFixed(2) }}%</div>
                <div class="t-tertiary">空奖概率</div>
              </div>
            </div>
          </div>

          <!-- 奖项池 -->
          <div class="adm-card">
            <div class="adm-card__title">
              奖项池配置
              <span style="margin-left: auto; display: inline-flex; align-items: center; gap: 8px">
                <el-tag :type="probOk(current) ? 'success' : 'danger'" effect="plain" round size="small">
                  {{ probOk(current) ? '概率校验通过' : '概率超出 100%，请调整' }}
                </el-tag>
                <el-button type="primary" size="small" :icon="Plus" @click="openPool(current)">配置奖项</el-button>
              </span>
            </div>

            <el-table :data="current.prizes">
              <el-table-column label="奖项" min-width="220">
                <template #default="{ row }">
                  <div class="ld__prize-cell">
                    <img v-if="row.cover" class="ld__prize-img" :src="row.cover" :alt="row.tier" />
                    <div v-else class="ld__prize-img ld__prize-img--none">空</div>
                    <div>
                      <div class="ld__prize-tier">{{ row.tier }}</div>
                      <div v-if="row.name" class="ld__prize-name">{{ row.name }}</div>
                      <el-tag :type="PRIZE_TYPES[row.type]?.tag || 'info'" effect="plain" size="small" style="margin-top: 3px">
                        {{ PRIZE_TYPES[row.type]?.label || row.type }}
                      </el-tag>
                    </div>
                  </div>
                </template>
              </el-table-column>
              <el-table-column label="中奖概率" width="110" align="center">
                <template #default="{ row }">
                  <span class="price">{{ (row.probability * 100).toFixed(2) }}%</span>
                </template>
              </el-table-column>
              <el-table-column label="奖项数量" width="90" align="center" prop="total" />
              <el-table-column label="已发出" width="80" align="center" prop="won" />
              <el-table-column label="剩余" width="80" align="center">
                <template #default="{ row }">
                  <span :class="{ 't-danger': row.total - row.won <= 0 }">{{ row.total - row.won }}</span>
                </template>
              </el-table-column>
            </el-table>

            <el-empty
              v-if="!current.prizes.length"
              description="该活动尚未配置奖项，启用前需先配置完整奖池"
              :image-size="70"
            />

            <el-alert
              type="info"
              :closable="false"
              show-icon
              class="ld__tip"
              title="奖项支持藏品空投/优先购资格/资格购白名单/抽奖次数/司南币/盲盒/空奖；奖品名称与图片由管理员配置；库存不足自动降级为空奖并记录异常日志，禁止超发"
            />
          </div>
        </div>
      </template>
    </template>

    <!-- 新建/编辑活动弹窗 -->
    <el-dialog v-model="actShow" :title="actEditing ? `编辑抽奖活动 · ${actEditing.name}` : '新建抽奖活动'" width="560px" :close-on-click-modal="false">
      <el-form label-width="100px">
        <el-form-item label="活动名称">
          <el-input v-model="actForm.name" placeholder="如：司南九月抽奖 · 第一期" maxlength="50" show-word-limit />
        </el-form-item>
        <el-form-item label="开始时间">
          <el-input v-model="actForm.startTime" placeholder="如 2026-09-01 00:00:00，留空不限" />
        </el-form-item>
        <el-form-item label="结束时间">
          <el-input v-model="actForm.endTime" placeholder="如 2026-09-30 23:59:59，留空不限" />
        </el-form-item>
        <el-form-item label="参与资格">
          <EligibilityEditor v-model="actForm.eligibility" :collectibles="collectibles" />
        </el-form-item>
        <el-form-item label="奖励发放">
          <el-radio-group v-model="actForm.grantMode">
            <el-radio value="realtime">实时到账</el-radio>
            <el-radio value="manual">记录名单 · 统一发放</el-radio>
          </el-radio-group>
          <div class="t-tertiary" style="font-size: 12px; margin-top: 4px; width: 100%">
            实时到账：中奖立即发放；记录名单：进入「奖励名单」页导出 CSV 统一发放
          </div>
        </el-form-item>
        <el-form-item label="活动状态">
          <el-switch v-model="actForm.enabled" active-text="启用" inactive-text="停用" />
          <div class="t-tertiary" style="font-size: 12px; margin-top: 4px; width: 100%">
            启用需先配置完整奖池（概率合计=100%）；建议创建时保持停用
          </div>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="actShow = false">取消</el-button>
        <el-button type="primary" :loading="actSubmitting" @click="onSaveActivity">
          {{ actEditing ? '保存修改' : '创建活动' }}
        </el-button>
      </template>
    </el-dialog>

    <!-- 奖项池编辑弹窗 -->
    <el-dialog v-model="poolShow" :title="`奖项池配置 · ${poolActivity?.name || ''}`" width="760px" :close-on-click-modal="false">
      <div class="ld__pool-toolbar">
        <div class="t-tertiary" style="font-size: 12px">
          非空奖概率合计：<b :class="Math.abs(poolProbSum - 1) <= 0.0001 ? 't-ok' : 't-danger'">{{ (poolProbSum * 100).toFixed(2) }}%</b>（需为 100%）
        </div>
        <el-button type="primary" size="small" :icon="Plus" @click="openAddPrize">添加奖项</el-button>
      </div>

      <el-table :data="poolDraft">
        <el-table-column label="奖项" min-width="200">
          <template #default="{ row }">
            <div class="ld__prize-cell">
              <img v-if="row.cover" class="ld__prize-img" :src="row.cover" :alt="row.tier" />
              <div v-else class="ld__prize-img ld__prize-img--none">空</div>
              <div>
                <div class="ld__prize-tier">{{ row.tier }}</div>
                <div v-if="row.name" class="ld__prize-name">{{ row.name }}</div>
                <el-tag :type="PRIZE_TYPES[row.type]?.tag || 'info'" effect="plain" size="small" style="margin-top: 3px">
                  {{ PRIZE_TYPES[row.type]?.label || row.type }}
                </el-tag>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="概率" width="90" align="center">
          <template #default="{ row }">
            <span class="price">{{ (row.probability * 100).toFixed(2) }}%</span>
          </template>
        </el-table-column>
        <el-table-column label="数量" width="80" align="center" prop="total" />
        <el-table-column label="已发出" width="70" align="center" prop="won" />
        <el-table-column label="操作" width="130" fixed="right">
          <template #default="{ $index }">
            <el-button link type="primary" size="small" @click="openEditPrize($index)">编辑</el-button>
            <el-button link type="danger" size="small" :disabled="row.won > 0" @click="onRemovePrize($index)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
      <el-empty v-if="!poolDraft.length" description="尚未配置奖项" :image-size="60" />

      <template #footer>
        <el-button @click="poolShow = false">取消</el-button>
        <el-button type="primary" :loading="poolSubmitting" @click="onSavePool">保存奖项池</el-button>
      </template>
    </el-dialog>

    <!-- 单个奖项编辑弹窗 -->
    <el-dialog v-model="prizeShow" :title="prizeEditingIdx >= 0 ? `编辑奖项 · ${poolDraft[prizeEditingIdx]?.tier || ''}` : '添加奖项'" width="600px" :close-on-click-modal="false">
      <el-form label-width="100px">
        <el-form-item label="奖项名">
          <el-input v-model="prizeForm.tier" placeholder="如：一等奖 / 谢谢参与" maxlength="20" show-word-limit style="width: 240px" />
        </el-form-item>
        <el-form-item label="奖品名称">
          <el-input v-model="prizeForm.name" placeholder="管理员填写奖品名称（C 端展示），空奖可留空" maxlength="100" show-word-limit />
        </el-form-item>
        <el-form-item label="奖品图片">
          <div class="ld__upload">
            <img v-if="prizeForm.cover" class="ld__prize-img" :src="prizeForm.cover" alt="奖品图" />
            <el-upload
              :show-file-list="false"
              :http-request="onUploadPrizeImage"
              accept="image/jpeg,image/png,image/webp,image/gif"
            >
              <el-button plain :loading="uploading">
                {{ uploading ? '上传中…' : prizeForm.cover ? '重新上传' : '上传奖品图' }}
              </el-button>
            </el-upload>
            <div class="t-tertiary" style="font-size: 12px">支持 JPG / PNG / WEBP / GIF，不超过 5MB</div>
          </div>
        </el-form-item>
        <el-form-item label="奖励配置">
          <RewardListEditor
            v-model="prizeForm.rewards"
            :collectibles="collectibles"
            :priority-sales="prioritySales"
            :max="1"
          />
          <div class="t-tertiary" style="font-size: 12px; margin-top: 2px; width: 100%">
            配置为「无奖励」即空奖（未中奖兜底奖项）
          </div>
        </el-form-item>
        <el-form-item label="中奖概率（%）">
          <el-input-number v-model="prizeForm.probability" :min="0.0001" :max="100" :precision="4" :step="0.1" style="width: 180px" />
        </el-form-item>
        <el-form-item label="奖项数量">
          <el-input-number v-model="prizeForm.total" :min="1" :step="10" style="width: 180px" />
          <div v-if="prizeEditingIdx >= 0 && poolDraft[prizeEditingIdx]?.won" class="t-tertiary" style="font-size: 12px; margin-top: 4px; width: 100%">
            当前已发出 {{ poolDraft[prizeEditingIdx].won }} 份，数量不能低于该值
          </div>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="prizeShow = false">取消</el-button>
        <el-button type="primary" @click="onConfirmPrize">确定</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped lang="scss">
.ld__toolbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  margin-bottom: 14px;
}

.ld__tab-label { font-size: 13px; }
.ld__body { margin-top: 14px; }

.ld__head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
}

.ld__head-ops { display: flex; align-items: center; gap: 10px; }

.ld__name { font-size: 15px; font-weight: 700; color: $color-text-primary; }

.ld__settings {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 10px;
  margin-top: 12px;
}

.ld__setting {
  padding: 10px 12px;
  border-radius: 8px;
  background: $color-surface;

  .t-tertiary { font-size: 11px; }
}

.ld__setting-val {
  font-size: 13px;
  font-weight: 600;
  color: $color-text-primary;
  margin-top: 3px;
}

.ld__stats {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 10px;
  margin-top: 14px;
  text-align: center;
}

.ld__stat {
  padding: 12px 0;
  border-radius: 8px;
  background: $color-surface;
}

.ld__stat .price { font-size: 17px; }
.ld__stat .t-tertiary { font-size: 11px; margin-top: 2px; }

.ld__pool-toolbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  margin-bottom: 10px;
}

.ld__prize-cell {
  display: flex;
  align-items: center;
  gap: 10px;
}

.ld__prize-img {
  width: 44px;
  height: 44px;
  border-radius: 8px;
  object-fit: cover;
  flex-shrink: 0;
  background: $color-surface;
  border: 1px solid $color-border;

  &--none {
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    color: $color-text-tertiary;
  }
}

.ld__prize-tier { font-size: 13px; font-weight: 600; color: $color-text-primary; }
.ld__prize-name { font-size: 12px; color: $color-text-secondary; margin-top: 2px; }

.ld__upload {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}

.ld__tip { margin-top: 10px; }
.t-danger { color: $color-primary; }
.t-ok { color: var(--color-success, #67c23a); }
</style>
