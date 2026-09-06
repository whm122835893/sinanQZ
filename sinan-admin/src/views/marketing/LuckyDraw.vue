<script setup>
import { ref, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import { getLuckyDraws, toggleLuckyDraw, saveLuckyDrawPrize, saveLuckyActivity } from '@/api'
import StatusTag from '@/components/StatusTag.vue'
import { ACTIVITY_STATUS } from '@/utils/maps'
import { fmtNumber } from '@/utils/format'

const loading = ref(true)
const activities = ref([])
const activeTab = ref('')

// ---- 奖项编辑 ----
const editShow = ref(false)
const editing = ref(null) // { activity, prize }
const form = ref({ probability: 10, total: 10 })

// ---- 新建活动 ----
const actShow = ref(false)
const actSubmitting = ref(false)
const actForm = ref({ name: '', enabled: false, startTime: '', endTime: '' })

const PRIZE_TYPES = {
  collectible: { label: '藏品', tag: 'primary' },
  points: { label: '司南币', tag: 'warning' },
  draw_chance: { label: '抽奖次数', tag: 'success' },
  priority_qualification: { label: '优先购资格', tag: 'info' },
  eligibility_qualification: { label: '资格购资格', tag: 'info' },
  blindbox: { label: '盲盒', tag: 'danger' },
  none: { label: '空奖', tag: 'info' }
}

onMounted(load)

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

// ---- 新建活动 ----
function openCreate() {
  actForm.value = { name: '', enabled: false, startTime: '', endTime: '' }
  actShow.value = true
}

async function onCreateActivity() {
  const f = actForm.value
  if (!f.name.trim()) return ElMessage.warning('请输入活动名称')
  actSubmitting.value = true
  const res = await saveLuckyActivity({
    name: f.name.trim(),
    enabled: f.enabled,
    startTime: f.startTime.trim(),
    endTime: f.endTime.trim()
  })
  actSubmitting.value = false
  if (res.code === 0) {
    ElMessage.success('活动已创建，可继续在奖项池中配置奖品')
    actShow.value = false
    load()
  }
}

// ---- 奖项编辑 ----
function openEdit(a, p) {
  editing.value = { activity: a, prize: p }
  form.value.probability = Number((p.probability * 100).toFixed(4))
  form.value.total = p.total
  editShow.value = true
}

async function onSavePrize() {
  const f = form.value
  if (!(f.probability > 0 && f.probability <= 100)) {
    return ElMessage.warning('概率需为 0.0001 ~ 100 之间的数字（支持小数点后 4 位）')
  }
  if (!Number.isInteger(f.total) || f.total < 1) return ElMessage.warning('奖项数量需为正整数')
  if (editing.value.prize.won !== undefined && f.total < editing.value.prize.won) {
    return ElMessage.warning(`奖项数量不可低于已发出数量（已发出 ${editing.value.prize.won} 份）`)
  }
  const res = await saveLuckyDrawPrize({
    activityId: editing.value.activity.id,
    prizeId: editing.value.prize.id,
    probability: Number((f.probability / 100).toFixed(6)),
    total: f.total
  })
  if (res.code === 0) {
    ElMessage.success('奖品已更新')
    editShow.value = false
    load()
  } else {
    ElMessage.error(res.message)
  }
}
</script>

<template>
  <div class="adm-page ld">
    <el-skeleton v-if="loading" :rows="8" animated style="padding: 20px" />
    <template v-else>
      <div class="ld__toolbar">
        <div class="t-tertiary" style="font-size: 12px">
          抽奖活动支持多期并存；新活动创建后需在奖项池配置奖品方可启用
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
              <el-switch
                :model-value="current.status === 'enabled'"
                active-text="进行中"
                inactive-text="已停用"
                @change="onToggle(current)"
              />
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
              <el-tag :type="probOk(current) ? 'success' : 'danger'" effect="plain" round size="small">
                {{ probOk(current) ? '概率校验通过' : '概率超出 100%，请调整' }}
              </el-tag>
            </div>

            <el-table :data="current.prizes">
              <el-table-column label="奖项" min-width="200">
                <template #default="{ row }">
                  <div class="ld__prize-cell">
                    <img v-if="row.cover" class="ld__prize-img" :src="row.cover" :alt="row.tier" />
                    <div v-else class="ld__prize-img ld__prize-img--none">空</div>
                    <div>
                      <div class="ld__prize-tier">{{ row.tier }}</div>
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
              <el-table-column label="操作" width="90" fixed="right">
                <template #default="{ row }">
                  <el-button link type="primary" size="small" @click="openEdit(current, row)">调整</el-button>
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
              title="抽奖双保险：预生成随机数按概率命中奖项 + 奖品库存实时校验；库存不足自动降级为空奖并记录异常日志，禁止超发；空奖概率 = 100% - 非空奖概率总和"
            />
          </div>
        </div>
      </template>
    </template>

    <!-- 新建活动弹窗 -->
    <el-dialog v-model="actShow" title="新建抽奖活动" width="480px" :close-on-click-modal="false">
      <el-form label-width="100px">
        <el-form-item label="活动名称">
          <el-input v-model="actForm.name" placeholder="如：司南九月抽奖 · 第一期" maxlength="50" show-word-limit />
        </el-form-item>
        <el-form-item label="活动状态">
          <el-switch v-model="actForm.enabled" active-text="启用" inactive-text="停用" />
          <div class="t-tertiary" style="font-size: 12px; margin-top: 4px; width: 100%">
            建议创建时保持停用，配置完整奖池后再开启
          </div>
        </el-form-item>
        <el-form-item label="开始时间">
          <el-input v-model="actForm.startTime" placeholder="如 2026-09-01 00:00:00，留空不限" />
        </el-form-item>
        <el-form-item label="结束时间">
          <el-input v-model="actForm.endTime" placeholder="如 2026-09-30 23:59:59，留空不限" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="actShow = false">取消</el-button>
        <el-button type="primary" :loading="actSubmitting" @click="onCreateActivity">创建活动</el-button>
      </template>
    </el-dialog>

    <!-- 奖项编辑弹窗 -->
    <el-dialog v-model="editShow" :title="editing ? `调整奖项 · ${editing.prize.tier}` : ''" width="440px" :close-on-click-modal="false">
      <template v-if="editing">
        <el-form label-width="100px">
          <el-form-item label="中奖概率（%）">
            <el-input-number v-model="form.probability" :min="0.0001" :max="100" :precision="4" :step="0.1" style="width: 180px" />
            <div class="t-tertiary" style="font-size: 12px; margin-top: 4px; width: 100%">
              0.0001 ~ 100，支持小数点后 4 位；所有非空奖概率之和 &lt;= 100%
            </div>
          </el-form-item>
          <el-form-item label="奖项数量">
            <el-input-number v-model="form.total" :min="1" :step="10" style="width: 180px" />
            <div class="t-tertiary" style="font-size: 12px; margin-top: 4px; width: 100%">
              当前已发出 {{ editing.prize.won ?? 0 }} 份，调整数量不能低于已发出数量
            </div>
          </el-form-item>
        </el-form>
      </template>
      <template #footer>
        <el-button @click="editShow = false">取消</el-button>
        <el-button type="primary" @click="onSavePrize">保存调整</el-button>
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

.ld__name { font-size: 15px; font-weight: 700; color: $color-text-primary; }

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
.ld__tip { margin-top: 10px; }
.t-danger { color: $color-primary; }
</style>
