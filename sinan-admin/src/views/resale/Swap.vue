<script setup>
import { ref, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import request from '@/utils/request'
import { swapPreview, swapCollectible } from '@/api'
import PasswordVerify from '@/components/PasswordVerify.vue'

const loading = ref(true)

onMounted(loadPlans)

// ---- 统一置换回收记录（计划列表 + 名单/明细） ----
const plans = ref([])
const plansTotal = ref(0)
const plansPage = ref(1)
const plansSize = ref(20)

async function loadPlans() {
  loading.value = true
  try {
    const res = await request.get('/swap/plans', { params: { page: plansPage.value, pageSize: plansSize.value } })
    plans.value = res.list || []
    plansTotal.value = res.total || 0
  } finally { loading.value = false }
}
function onPlansPageChange(p) {
  plansPage.value = p
  loadPlans()
}

// ---- 新建统一置换：回收多源藏品 → 按「Σ(持有数量 × 比例)」向持有人空投新藏品 ----
const swapShow = ref(false)
const swapPwdShow = ref(false)
const swapPreviewShow = ref(false)
const swapPreviewData = ref(null)
const swapForm = ref({ sources: [], newCollectibleId: '', reason: '' })
const swapping = ref(false)
const previewing = ref(false)

function openSwap() {
  swapForm.value = { sources: [{ collectibleId: '', ratio: 1 }], newCollectibleId: '', reason: '' }
  swapPreviewData.value = null
  swapShow.value = true
}

function addSwapSource() {
  if (swapForm.value.sources.length >= 10) return ElMessage.warning('单次置换最多支持 10 个源藏品')
  swapForm.value.sources.push({ collectibleId: '', ratio: 1 })
}

function removeSwapSource(idx) {
  swapForm.value.sources.splice(idx, 1)
}

/** 表单校验：通过返回提交载荷，失败返回 null */
function validateSwapForm() {
  const f = swapForm.value
  if (!f.sources.length) { ElMessage.warning('请至少配置一个源藏品'); return null }
  const ids = new Set()
  for (let i = 0; i < f.sources.length; i++) {
    const src = f.sources[i]
    const cid = Number(src.collectibleId)
    if (!cid || cid <= 0) { ElMessage.warning(`请填写第 ${i + 1} 个源藏品ID`); return null }
    if (ids.has(cid)) { ElMessage.warning(`源藏品不可重复（ID: ${cid}）`); return null }
    ids.add(cid)
    if (!Number.isInteger(src.ratio) || src.ratio < 1 || src.ratio > 100) {
      ElMessage.warning(`第 ${i + 1} 个源藏品置换比例需为 1~100 的整数`); return null
    }
  }
  const nid = Number(f.newCollectibleId)
  if (!nid || nid <= 0) { ElMessage.warning('请输入目标（新）藏品ID'); return null }
  if (ids.has(nid)) { ElMessage.warning('目标新藏品不能与源藏品相同'); return null }
  return {
    oldCollectibles: f.sources.map((s) => ({ collectibleId: Number(s.collectibleId), ratio: s.ratio })),
    newCollectibleId: nid,
    reason: f.reason
  }
}

/** 名单预览：按比例计算受影响用户与空投份数（只读） */
async function onSwapPreview() {
  const payload = validateSwapForm()
  if (!payload) return
  previewing.value = true
  try {
    const res = await swapPreview(payload)
    if (res.code === 0) {
      swapPreviewData.value = res.data
      swapPreviewShow.value = true
    }
  } finally {
    previewing.value = false
  }
}

async function onSwapSubmit() {
  const payload = validateSwapForm()
  if (!payload) return
  const s = swapPreviewData.value?.summary
  const summaryText = s
    ? `受影响 ${s.userCount} 人，回收 ${s.totalRecovered} 份，按比例空投 ${s.totalAirdrop} 份。`
    : ''
  await ElMessageBox.confirm(
    `即将执行统一置换：回收 ${payload.oldCollectibles.length} 个源藏品（${payload.oldCollectibles.map((i) => `#${i.collectibleId} 1:${i.ratio}`).join('、')}）的所有有效持仓，并按比例向持有人空投新藏品（ID: ${payload.newCollectibleId}）。\n\n${summaryText}此操作不可撤销，是否继续？`,
    '统一置换确认',
    { type: 'warning', confirmButtonText: '确认置换', cancelButtonText: '取消' }
  )
  swapPwdShow.value = true
}

async function onSwapVerified() {
  const payload = validateSwapForm()
  if (!payload) return
  swapping.value = true
  try {
    const res = await swapCollectible(payload)
    if (res.code === 0) {
      const d = res.data
      ElMessage.success(`置换完成：回收 ${d.recoveredCount} 份（${d.userCount} 人），按比例空投 ${d.airdropQuantity} 份（计划号 ${d.planNo}）`)
      swapShow.value = false
      swapPreviewData.value = null
      loadPlans()
    }
  } finally {
    swapping.value = false
  }
}

// 详情（名单/明细分页）
const detailShow = ref(false)
const detailLoading = ref(false)
const detail = ref(null)
const detailUsersPage = ref(1)
const detailDetailsPage = ref(1)
const detailAction = ref('') // ''全部 1回收 2空投

async function openPlanDetail(row) {
  detailShow.value = true
  detailUsersPage.value = 1
  detailDetailsPage.value = 1
  detailAction.value = ''
  await loadPlanDetail(row.id)
}

async function loadPlanDetail(id) {
  detailLoading.value = true
  try {
    const params = {
      page: detailUsersPage.value,
      pageSize: 20,
      // 后端名单/明细独立分页：dPage/dPageSize 控制资产明细页码
      dPage: detailDetailsPage.value,
      dPageSize: 20
    }
    if (detailAction.value) params.action = detailAction.value
    const res = await request.get(`/swap/plans/${id}`, { params })
    detail.value = res
  } finally { detailLoading.value = false }
}

function onUsersPageChange(p) {
  detailUsersPage.value = p
  loadPlanDetail(detail.value.id)
}
function onDetailsPageChange(p) {
  if (typeof p === 'number') detailDetailsPage.value = p
  loadPlanDetail(detail.value.id)
}
</script>

<template>
  <div class="page">
    <div class="page__head">
      <div class="page__head-row">
        <div>
          <h2>置换管理</h2>
          <p class="page__tip">管理员统一回收源藏品并按比例向持有人空投新藏品；执行后生成计划名单与资产明细</p>
        </div>
        <el-button type="primary" @click="openSwap">+ 新建置换</el-button>
      </div>
    </div>

    <el-table :data="plans" v-loading="loading" stripe style="width:100%;margin-top:12px">
      <el-table-column prop="planNo" label="计划号" width="170" />
      <el-table-column label="回收源藏品（比例）" min-width="280">
        <template #default="{ row }">
          <span v-for="(it, i) in row.items" :key="it.collectibleId">
            <template v-if="i > 0">、</template>{{ it.collectibleName }} 1:{{ it.ratio }}（回收 {{ it.recoveredCount }} 份）
          </span>
        </template>
      </el-table-column>
      <el-table-column label="空投新藏品" min-width="160">
        <template #default="{ row }">{{ row.newCollectibleName }}（#{{ row.newCollectibleId }}）</template>
      </el-table-column>
      <el-table-column prop="userCount" label="受影响用户" width="100" />
      <el-table-column prop="totalRecovered" label="回收份数" width="90" />
      <el-table-column prop="totalAirdropped" label="空投份数" width="90" />
      <el-table-column prop="reason" label="原因" min-width="120" show-overflow-tooltip />
      <el-table-column prop="adminName" label="操作人" width="100" />
      <el-table-column prop="createdAt" label="执行时间" width="180" />
      <el-table-column label="操作" width="100" fixed="right">
        <template #default="{ row }">
          <el-button size="small" link type="primary" @click="openPlanDetail(row)">名单/明细</el-button>
        </template>
      </el-table-column>
    </el-table>
    <div style="margin-top:12px;display:flex;justify-content:flex-end">
      <el-pagination
        v-model:current-page="plansPage"
        layout="total, prev, pager, next"
        :total="plansTotal"
        :page-size="plansSize"
        @current-change="onPlansPageChange"
      />
    </div>

    <!-- 置换计划详情：源配置 + 用户名单 + 资产明细 -->
    <el-dialog v-model="detailShow" :title="`置换计划 ${detail?.planNo || ''} · 名单与明细`" width="980px" append-to-body>
      <div v-loading="detailLoading">
        <template v-if="detail">
          <div class="plan__summary">
            <div class="plan__stat">
              <div class="plan__v">{{ detail.userCount }}</div>
              <div class="plan__l">受影响用户</div>
            </div>
            <div class="plan__stat">
              <div class="plan__v">{{ detail.totalRecovered }}</div>
              <div class="plan__l">回收总份数</div>
            </div>
            <div class="plan__stat">
              <div class="plan__v">{{ detail.totalAirdropped }}</div>
              <div class="plan__l">空投总份数</div>
            </div>
            <div class="plan__stat">
              <div class="plan__v" style="font-size:14px">{{ detail.newCollectibleName }}</div>
              <div class="plan__l">目标新藏品（#{{ detail.newCollectibleId }}）</div>
            </div>
          </div>
          <div class="plan__meta">
            <span>原因：{{ detail.reason || '—' }}</span>
            <span>操作人：{{ detail.adminName }}</span>
            <span>执行时间：{{ detail.createdAt }}</span>
          </div>

          <div class="t-title">源藏品配置</div>
          <el-table :data="detail.items" size="small" border>
            <el-table-column label="藏品" min-width="200">
              <template #default="{ row }">{{ row.collectibleName }}（#{{ row.collectibleId }}）</template>
            </el-table-column>
            <el-table-column label="比例（每份→新藏品）" width="150">
              <template #default="{ row }">1 : {{ row.ratio }}</template>
            </el-table-column>
            <el-table-column prop="recoveredCount" label="实际回收份数" width="120" />
          </el-table>

          <div class="t-title">用户名单（共 {{ detail.users.total }} 人）</div>
          <el-table :data="detail.users.list" size="small" border>
            <el-table-column prop="userId" label="用户ID" width="90" />
            <el-table-column prop="phone" label="手机号" width="130" />
            <el-table-column label="持有明细（数量 × 比例 → 空投）" min-width="320">
              <template #default="{ row }">
                <span v-for="(h, i) in row.holdings" :key="h.collectibleId">
                  <template v-if="i > 0"> + </template>{{ h.name }} {{ h.quantity }}×{{ h.ratio }}（{{ h.newQuantity }}）
                </span>
              </template>
            </el-table-column>
            <el-table-column prop="recoveredTotal" label="回收合计" width="90" />
            <el-table-column label="空投新藏品" width="100">
              <template #default="{ row }">
                <el-tag type="warning" effect="plain" size="small">{{ row.airdropQuantity }} 份</el-tag>
              </template>
            </el-table-column>
          </el-table>
          <div class="plan__pager">
            <el-pagination
              v-model:current-page="detailUsersPage"
              layout="total, prev, pager, next"
              :total="detail.users.total"
              :page-size="detail.users.pageSize"
              @current-change="onUsersPageChange"
            />
          </div>

          <div class="t-title" style="display:flex;align-items:center;gap:12px">
            资产明细
            <el-radio-group v-model="detailAction" size="small" @change="onDetailsPageChange(1)">
              <el-radio-button value="">全部</el-radio-button>
              <el-radio-button value="1">回收</el-radio-button>
              <el-radio-button value="2">空投</el-radio-button>
            </el-radio-group>
            （共 {{ detail.details.total }} 条）
          </div>
          <el-table :data="detail.details.list" size="small" border max-height="320">
            <el-table-column prop="userId" label="用户ID" width="90" />
            <el-table-column label="动作" width="80">
              <template #default="{ row }">
                <el-tag :type="row.action === 1 ? 'info' : 'success'" effect="plain" size="small">
                  {{ row.action === 1 ? '回收' : '空投' }}
                </el-tag>
              </template>
            </el-table-column>
            <el-table-column label="藏品" min-width="180">
              <template #default="{ row }">{{ row.collectibleName }}（#{{ row.collectibleId }}）</template>
            </el-table-column>
            <el-table-column prop="serial" label="资产编号" width="140" />
            <el-table-column label="比例" width="80">
              <template #default="{ row }">{{ row.ratio ? '1:' + row.ratio : '—' }}</template>
            </el-table-column>
            <el-table-column prop="userCollectibleId" label="资产行ID" width="90" />
            <el-table-column prop="createdAt" label="时间" width="180" />
          </el-table>
          <div class="plan__pager">
            <el-pagination
              v-model:current-page="detailDetailsPage"
              layout="total, prev, pager, next"
              :total="detail.details.total"
              :page-size="detail.details.pageSize"
              @current-change="onDetailsPageChange"
            />
          </div>
        </template>
      </div>
      <template #footer>
        <el-button @click="detailShow = false">关闭</el-button>
      </template>
    </el-dialog>

    <!-- 新建统一置换弹窗（多源 + 比例 + 目标 + 名单预览） -->
    <el-dialog v-model="swapShow" title="统一置换（回收 + 按比例空投）" width="680px" append-to-body :close-on-click-modal="false">
      <el-form label-width="110px">
        <el-form-item label="回收源藏品">
          <div class="cd__swap-sources">
            <div v-for="(src, idx) in swapForm.sources" :key="idx" class="cd__swap-source">
              <el-input v-model="src.collectibleId" placeholder="藏品ID" style="width: 150px" />
              <span class="t-tertiary" style="font-size: 12px">每持有 1 份 →</span>
              <el-input-number v-model="src.ratio" :min="1" :max="100" style="width: 110px" />
              <span class="t-tertiary" style="font-size: 12px">份新藏品</span>
              <el-button v-if="swapForm.sources.length > 1" link type="danger" @click="removeSwapSource(idx)">移除</el-button>
            </div>
            <el-button link type="primary" size="small" @click="addSwapSource">+ 添加源藏品</el-button>
          </div>
          <div class="t-tertiary" style="font-size: 12px">
            将统一回收上述藏品的全部有效持仓（含持有/寄售中/转赠冻结），例：a 比例 1:2、b 比例 1:3，用户持有 a、b 各 1 份 → 空投 2 + 3 = 5 份
          </div>
        </el-form-item>
        <el-form-item label="目标新藏品ID">
          <el-input v-model="swapForm.newCollectibleId" placeholder="空投给持有回收藏品用户的新藏品" style="width: 220px" />
        </el-form-item>
        <el-form-item label="置换原因">
          <el-input v-model="swapForm.reason" type="textarea" :rows="2" placeholder="如：版本升级置换（选填）" />
        </el-form-item>
      </el-form>
      <el-alert
        type="warning"
        :closable="false"
        show-icon
        title="置换将统一回收所有源藏品的有效持仓，并按比例向持有人空投目标新藏品；单一事务保证名单精准对齐，操作不可撤销，执行后生成计划名单与资产明细。"
      />
      <template #footer>
        <el-button @click="swapShow = false">取消</el-button>
        <el-button :loading="previewing" @click="onSwapPreview">名单预览</el-button>
        <el-button type="warning" :loading="swapping" @click="onSwapSubmit">确认置换（需密码验证）</el-button>
      </template>
    </el-dialog>

    <!-- 置换名单预览弹窗 -->
    <el-dialog v-model="swapPreviewShow" title="置换名单预览（只读）" width="920px" append-to-body>
      <template v-if="swapPreviewData">
        <div class="cd__swap-summary">
          <div class="cd__swap-stat">
            <div class="cd__swap-v">{{ swapPreviewData.summary.userCount }}</div>
            <div class="cd__swap-l">受影响用户</div>
          </div>
          <div class="cd__swap-stat">
            <div class="cd__swap-v">{{ swapPreviewData.summary.totalRecovered }}</div>
            <div class="cd__swap-l">回收总份数</div>
          </div>
          <div class="cd__swap-stat">
            <div class="cd__swap-v">{{ swapPreviewData.summary.totalAirdrop }}</div>
            <div class="cd__swap-l">按比例空投份数</div>
          </div>
          <div class="cd__swap-stat">
            <div class="cd__swap-v" :class="{ 'is-danger': swapPreviewData.newCollectible.stockPool < swapPreviewData.summary.totalAirdrop }">
              {{ swapPreviewData.newCollectible.stockPool }}
            </div>
            <div class="cd__swap-l">目标库存池</div>
          </div>
        </div>
        <el-alert
          v-if="swapPreviewData.newCollectible.stockPool < swapPreviewData.summary.totalAirdrop"
          type="error"
          :closable="false"
          show-icon
          :title="`目标藏品「${swapPreviewData.newCollectible.name}」库存池不足：${swapPreviewData.newCollectible.stockPool} / ${swapPreviewData.summary.totalAirdrop}，无法执行置换`"
          style="margin-bottom: 12px"
        />

        <div class="t-tertiary" style="font-size: 13px; margin-bottom: 4px">源藏品配置</div>
        <el-table :data="swapPreviewData.items" size="small" border style="margin-bottom: 14px">
          <el-table-column label="藏品" min-width="220">
            <template #default="{ row }">
              <el-image :src="row.image" fit="cover" style="width: 32px; height: 32px; border-radius: 4px; vertical-align: middle; margin-right: 6px" />
              {{ row.name }}（#{{ row.collectibleId }}）
            </template>
          </el-table-column>
          <el-table-column label="比例（每份→新藏品）" width="150">
            <template #default="{ row }">1 : {{ row.ratio }}</template>
          </el-table-column>
          <el-table-column prop="holdingCount" label="有效持仓" width="90" />
          <el-table-column prop="holdingUsers" label="持有人数" width="90" />
          <el-table-column label="该藏品空投合计" width="120">
            <template #default="{ row }">{{ row.holdingCount * row.ratio }}</template>
          </el-table-column>
        </el-table>

        <div class="t-tertiary" style="font-size: 13px; margin-bottom: 4px">
          用户名单（按空投份数降序，最多显示前 {{ swapPreviewData.users.length }} 条；执行后完整名单与明细可在本页计划列表查看）
        </div>
        <el-table :data="swapPreviewData.users" size="small" border max-height="380">
          <el-table-column prop="userId" label="用户ID" width="90" />
          <el-table-column label="持有明细（数量 × 比例）" min-width="300">
            <template #default="{ row }">
              <span v-for="(h, i) in row.holdings" :key="h.collectibleId">
                <template v-if="i > 0"> + </template>{{ h.name }} {{ h.quantity }}×{{ h.ratio }}（{{ h.newQuantity }}）
              </span>
            </template>
          </el-table-column>
          <el-table-column prop="recoveredTotal" label="回收合计" width="90" />
          <el-table-column label="空投新藏品" width="100">
            <template #default="{ row }">
              <el-tag type="warning" effect="plain" size="small">{{ row.airdropQuantity }} 份</el-tag>
            </template>
          </el-table-column>
        </el-table>
      </template>
      <template #footer>
        <el-button @click="swapPreviewShow = false">关闭</el-button>
        <el-button type="warning" :disabled="swapPreviewData && swapPreviewData.newCollectible.stockPool < swapPreviewData.summary.totalAirdrop" @click="swapPreviewShow = false">返回执行</el-button>
      </template>
    </el-dialog>

    <PasswordVerify v-model="swapPwdShow" title="置换验证" @verified="onSwapVerified" />
  </div>
</template>
<style scoped>
.page { padding: 16px; }
.page__head { margin-bottom: 12px; }
.page__head-row { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; }
.page__head h2 { margin: 0 0 4px; font-size: 18px; }
.page__tip { margin: 0; font-size: 13px; color: #999; }
.t-title { font-size: 13px; color: #666; margin: 14px 0 6px; font-weight: 600; }
.plan__summary { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 10px; }
.plan__stat { text-align: center; padding: 10px 0; border-radius: 8px; background: var(--color-surface, #f7f8fa); }
.plan__v { font-size: 20px; font-weight: 700; }
.plan__l { font-size: 12px; color: #999; margin-top: 2px; }
.plan__meta { display: flex; gap: 18px; flex-wrap: wrap; font-size: 13px; color: #666; margin-bottom: 4px; }
.plan__pager { margin-top: 10px; display: flex; justify-content: flex-end; }
.cd__swap-sources { display: flex; flex-direction: column; gap: 8px; width: 100%; }
.cd__swap-source { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.cd__swap-summary { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 14px; }
.cd__swap-stat { text-align: center; padding: 10px 0; border-radius: 8px; background: var(--color-surface, #f7f8fa); }
.cd__swap-v { font-size: 20px; font-weight: 700; }
.cd__swap-v.is-danger { color: #f56c6c; }
.cd__swap-l { font-size: 12px; color: #999; margin-top: 2px; }
</style>
