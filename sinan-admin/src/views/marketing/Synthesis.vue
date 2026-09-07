<script setup>
import { ref, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import { getSynthesisList, toggleSynthesis, saveSynthesis, getCollectibleList } from '@/api'
import StatusTag from '@/components/StatusTag.vue'
import EligibilityEditor from '@/components/EligibilityEditor.vue'
import { ACTIVITY_STATUS } from '@/utils/maps'
import { fmtNumber } from '@/utils/format'

const loading = ref(true)
const list = ref([])

// ---- 藏品下拉（材料/产物选择）----
const collectibles = ref([])

// ---- 新建/编辑活动 ----
const editShow = ref(false)
const editing = ref(null)
const submitting = ref(false)
const form = ref({
  title: '',
  type: 'permanent',
  rules: '',
  materials: [{ collectibleId: null, count: 1 }],
  resultCollectibleId: null,
  resultQuantity: 1,
  perUserLimit: 1,
  totalLimit: null,
  startTime: '',
  endTime: '',
  eligibility: { type: 'all', config: {} },
  grantMode: 'realtime',
  enabled: false
})

onMounted(async () => {
  await load()
  // 藏品下拉（失败不阻断列表展示）
  const col = await getCollectibleList({ page: 1, pageSize: 200 })
  if (col.code === 0) collectibles.value = col.data.list || []
})

async function load() {
  loading.value = true
  const res = await getSynthesisList()
  // 适配层返回 { list, total }；失败/空数据时回退空数组（防止空白页）
  list.value = (res.code === 0 && res.data && Array.isArray(res.data.list)) ? res.data.list : []
  loading.value = false
}

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
    enabling ? `确认开启合成活动「${a.title}」？C 端将展示合成入口。` : `确认停用合成活动「${a.title}」？停用后 C 端入口隐藏，已合成的资产不受影响。`,
    '活动启停',
    { type: 'warning' }
  )
  const res = await toggleSynthesis(a.id)
  if (res.code === 0) {
    a.status = enabling ? 'enabled' : 'disabled'
    ElMessage.success(enabling ? '已开启' : '已停用')
  } else if (res.message) {
    ElMessage.error(res.message)
  }
}

// ---- 新建/编辑 ----
function openCreate() {
  editing.value = null
  form.value = {
    title: '',
    type: 'permanent',
    rules: '',
    materials: [{ collectibleId: null, count: 1 }],
    resultCollectibleId: null,
    resultQuantity: 1,
    perUserLimit: 1,
    totalLimit: null,
    startTime: '',
    endTime: '',
    eligibility: { type: 'all', config: {} },
    grantMode: 'realtime',
    enabled: false
  }
  editShow.value = true
}

function openEdit(a) {
  editing.value = a
  form.value = {
    title: a.title,
    type: a.type,
    rules: a.rules || a.title,
    materials: (a.materials || []).map((m) => ({ collectibleId: m.collectibleId, count: m.count })),
    resultCollectibleId: a.result?.collectibleId || null,
    resultQuantity: a.result?.quantity || 1,
    perUserLimit: a.perUserLimit,
    totalLimit: a.totalLimit,
    startTime: a.startTime || '',
    endTime: a.endTime || '',
    eligibility: JSON.parse(JSON.stringify(a.eligibility || { type: 'all', config: {} })),
    grantMode: a.grantMode || 'realtime',
    enabled: a.status === 'enabled'
  }
  editShow.value = true
}

function addMaterial() {
  form.value.materials.push({ collectibleId: null, count: 1 })
}

function removeMaterial(idx) {
  form.value.materials.splice(idx, 1)
}

async function onSave() {
  const f = form.value
  if (!f.title.trim()) return ElMessage.warning('请输入活动名称')
  if (!f.rules.trim()) return ElMessage.warning('请输入活动规则说明（C 端展示）')
  const mats = f.materials.filter((m) => m.collectibleId)
  if (!mats.length) return ElMessage.warning('请至少配置一种合成材料')
  if (mats.some((m) => !Number.isInteger(m.count) || m.count < 1)) return ElMessage.warning('材料数量需为正整数')
  if (!f.resultCollectibleId) return ElMessage.warning('请选择合成产物藏品')
  if (f.type === 'limited' && !f.endTime) return ElMessage.warning('限时活动需填写截止时间')
  if (f.eligibility.type === 'hold' && !(f.eligibility.config.collectibleIds || []).filter(Boolean).length) {
    return ElMessage.warning('持有藏品资格需至少选择一个藏品')
  }
  if (editing.value && f.totalLimit !== null && f.totalLimit < editing.value.usedCount) {
    return ElMessage.warning(`总限次不可低于已合成数量（已合成 ${fmtNumber(editing.value.usedCount)} 份）`)
  }
  submitting.value = true
  const res = await saveSynthesis({
    id: editing.value?.id,
    title: f.title.trim(),
    type: f.type,
    rules: f.rules.trim(),
    materials: mats.map((m) => ({ collectibleId: m.collectibleId, count: m.count })),
    result: { collectibleId: f.resultCollectibleId, quantity: f.resultQuantity },
    perUserLimit: f.perUserLimit,
    totalLimit: f.totalLimit,
    startTime: f.startTime.trim(),
    endTime: f.endTime.trim(),
    eligibility: f.eligibility,
    grantMode: f.grantMode,
    status: f.enabled ? 'enabled' : 'disabled'
  })
  submitting.value = false
  if (res.code === 0) {
    ElMessage.success(editing.value ? '合成活动已更新' : '合成活动已创建')
    editShow.value = false
    load()
  } else if (res.message) {
    ElMessage.error(res.message)
  }
}
</script>

<template>
  <div class="adm-page sy">
    <el-skeleton v-if="loading" :rows="8" animated style="padding: 20px" />
    <template v-else>
      <div class="sy__toolbar">
        <div class="t-tertiary" style="font-size: 12px">
          合成材料从用户仓库扣除，产物实时校验库存；建议新活动创建后先停用观察再开启
        </div>
        <el-button type="primary" :icon="Plus" @click="openCreate">新建活动</el-button>
      </div>

      <el-empty v-if="!list.length" description="暂无合成活动，点击右上角「新建活动」创建" />

      <div v-for="a in list" :key="a.id" class="adm-card sy__card">
        <div class="sy__head">
          <div class="sy__title">
            <span class="sy__name">{{ a.title }}</span>
            <el-tag :type="a.type === 'limited' ? 'warning' : 'primary'" effect="plain" size="small">
              {{ a.type === 'limited' ? '限时' : '常驻' }}
            </el-tag>
            <StatusTag :value="a.status" :map="ACTIVITY_STATUS" />
          </div>
          <div class="sy__head-ops">
            <el-button link type="primary" size="small" @click="openEdit(a)">编辑</el-button>
            <el-switch :model-value="a.status === 'enabled'" @change="onToggle(a)" />
          </div>
        </div>
        <div class="sy__desc">{{ a.rules }}</div>

        <!-- 合成公式：材料 M:N → 产物 -->
        <div class="sy__formula">
          <div class="sy__mats">
            <div v-for="m in a.materials" :key="m.collectibleId" class="sy__mat">
              <img :src="m.cover" :alt="m.name" />
              <div class="sy__mat-name">{{ m.name }}</div>
              <div class="sy__mat-count price">×{{ m.count }}</div>
            </div>
          </div>
          <el-icon class="sy__plus"><Plus /></el-icon>
          <div class="sy__result">
            <img :src="a.result.cover" :alt="a.result.name" />
            <div class="sy__mat-name">{{ a.result.name }}</div>
            <div class="t-tertiary" style="font-size: 11px">产物 ×{{ a.result.quantity || 1 }}</div>
          </div>
        </div>

        <div class="sy__meta">
          <span>每人限合成 <b class="price">{{ a.perUserLimit }}</b> 次</span>
          <span>总量 <b class="price">{{ a.totalLimit === null ? '不限' : fmtNumber(a.totalLimit) }}</b></span>
          <span>已合成 <b class="price">{{ fmtNumber(a.usedCount) }}</b></span>
          <span>参与资格：{{ eligibilityText(a.eligibility) }}</span>
          <span>奖励发放：{{ a.grantMode === 'manual' ? '记录名单 · 统一发放' : '实时到账' }}</span>
          <span v-if="a.type === 'limited' && a.endTime" class="t-tertiary">截止 {{ a.endTime }}</span>
        </div>
      </div>

      <!-- 新建/编辑活动弹窗 -->
      <el-dialog v-model="editShow" :title="editing ? `编辑合成活动 · ${editing.title}` : '新建合成活动'" width="660px" :close-on-click-modal="false">
        <el-form label-width="110px">
          <el-form-item label="活动名称">
            <el-input v-model="form.title" placeholder="如：四象聚宝 · 材料合成" maxlength="50" show-word-limit />
          </el-form-item>

          <el-form-item label="活动类型">
            <el-radio-group v-model="form.type">
              <el-radio value="permanent">常驻</el-radio>
              <el-radio value="limited">限时</el-radio>
            </el-radio-group>
            <div class="t-tertiary" style="font-size: 12px; margin-top: 4px; width: 100%">
              限时活动需填写截止时间，到期自动停用
            </div>
          </el-form-item>

          <el-form-item label="活动规则">
            <el-input
              v-model="form.rules"
              type="textarea"
              :rows="2"
              maxlength="200"
              show-word-limit
              placeholder="C 端展示的活动规则说明"
            />
          </el-form-item>

          <el-form-item label="合成材料">
            <div class="sy__mat-edit">
              <div v-for="(m, idx) in form.materials" :key="idx" class="sy__mat-row">
                <el-select
                  v-model="m.collectibleId"
                  clearable
                  filterable
                  placeholder="选择材料藏品"
                  style="flex: 1"
                >
                  <el-option v-for="c in collectibles" :key="c.id" :label="c.name" :value="c.id" />
                </el-select>
                <el-input-number v-model="m.count" :min="1" :max="99" style="width: 110px" />
                <el-button
                  link
                  type="danger"
                  size="small"
                  :disabled="form.materials.length <= 1"
                  @click="removeMaterial(idx)"
                >移除</el-button>
              </div>
              <el-button link type="primary" size="small" :icon="Plus" @click="addMaterial">添加材料</el-button>
            </div>
          </el-form-item>

          <el-form-item label="合成产物">
            <el-select
              v-model="form.resultCollectibleId"
              clearable
              filterable
              placeholder="选择合成产物藏品"
              style="width: 100%"
            >
              <el-option v-for="c in collectibles" :key="c.id" :label="c.name" :value="c.id" />
            </el-select>
            <div class="t-tertiary" style="font-size: 12px; margin-top: 4px; width: 100%">
              产物需为未发售/在售藏品，合成成功后从库存池扣减并发放到用户仓库
            </div>
          </el-form-item>

          <el-form-item label="产出数量">
            <el-input-number v-model="form.resultQuantity" :min="1" :max="100" style="width: 160px" />
            <span class="t-tertiary" style="font-size: 12px; margin-left: 8px">份 / 每次合成</span>
          </el-form-item>

          <el-form-item label="每人限次">
            <el-input-number v-model="form.perUserLimit" :min="1" style="width: 160px" />
          </el-form-item>

          <el-form-item label="总限次">
            <el-input-number v-model="form.totalLimit" :min="1" :step="100" placeholder="留空不限" style="width: 160px" />
            <div class="t-tertiary" style="font-size: 12px; margin-top: 4px; width: 100%">
              留空表示不限合成总量
            </div>
          </el-form-item>

          <el-form-item label="起止时间">
            <el-input v-model="form.startTime" placeholder="开始时间（可留空）" style="width: 46%; margin-right: 4px" />
            <el-input v-model="form.endTime" placeholder="截止时间（限时活动必填）" style="width: 48%" />
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
              实时到账：合成成功立即发放产物；记录名单：进入「奖励名单」页导出 CSV 统一发放
            </div>
          </el-form-item>

          <el-form-item label="活动状态">
            <el-switch v-model="form.enabled" active-text="启用" inactive-text="停用" />
            <div class="t-tertiary" style="font-size: 12px; margin-top: 4px; width: 100%">
              建议创建时保持停用，确认材料/产物配置无误后再开启
            </div>
          </el-form-item>
        </el-form>
        <template #footer>
          <el-button @click="editShow = false">取消</el-button>
          <el-button type="primary" :loading="submitting" @click="onSave">
            {{ editing ? '保存修改' : '创建活动' }}
          </el-button>
        </template>
      </el-dialog>
    </template>
  </div>
</template>

<style scoped lang="scss">
.sy__toolbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  margin-bottom: 14px;
}

.sy__card + .sy__card { margin-top: 14px; }

.sy__mat-edit {
  width: 100%;
}

.sy__mat-row {
  display: flex;
  align-items: center;
  gap: 8px;
  width: 100%;
  margin-bottom: 8px;
}

.sy__head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
}

.sy__title { display: flex; align-items: center; gap: 8px; min-width: 0; }
.sy__name { font-size: 15px; font-weight: 600; color: $color-text-primary; }

.sy__head-ops { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }

.sy__desc {
  font-size: 12px;
  color: $color-text-secondary;
  margin-top: 6px;
}

.sy__formula {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 14px 0;
  overflow-x: auto;
  padding-bottom: 2px;
}

.sy__mats { display: flex; gap: 10px; }

.sy__mat, .sy__result {
  flex-shrink: 0;
  width: 80px;
  text-align: center;

  img {
    width: 100%;
    aspect-ratio: 1;
    object-fit: cover;
    border-radius: 8px;
    background: $color-surface;
  }
}

.sy__result img { border: 2px solid var(--color-gold); }

.sy__mat-name {
  font-size: 11px;
  color: $color-text-secondary;
  margin-top: 5px;
  @include ellipsis;
}

.sy__mat-count { font-size: 12px; }
.sy__plus { color: $color-text-tertiary; flex-shrink: 0; font-size: 18px; }

.sy__meta {
  display: flex;
  flex-wrap: wrap;
  gap: 6px 18px;
  font-size: 12px;
  color: $color-text-secondary;
  padding-top: 12px;
  border-top: 1px dashed $color-border;

  b { font-size: 13px; }
}
</style>
