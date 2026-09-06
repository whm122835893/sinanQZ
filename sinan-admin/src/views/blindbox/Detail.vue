<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus, Delete } from '@element-plus/icons-vue'
import {
  getBlindBoxDetail,
  saveBlindBoxPrize,
  removeBlindBoxPrize,
  getBlindBoxSelectableCollectibles,
  airdropBlindBox,
  destroyBlindBox,
  releaseBlindBox,
  toggleBlindBoxOpenable
} from '@/api'
import StatusTag from '@/components/StatusTag.vue'
import PasswordVerify from '@/components/PasswordVerify.vue'
import { COLLECTIBLE_STATUS } from '@/utils/maps'
import { fmtMoney, blindBoxPool } from '@/utils/format'

const route = useRoute()
const router = useRouter()
const id = Number(route.params.id)
const loading = ref(true)
const detail = ref(null)
const selectable = ref([])

// ---- 空投 ----
const airShow = ref(false)
const airConfirmShow = ref(false)
const airPwdShow = ref(false)
const airForm = ref({ phones: '', quantity: 1 })

// ---- 销毁 ----
const destroyShow = ref(false)
const destroyQty = ref(1)
const destroyPwdShow = ref(false)

// ---- 发售配置 ----
const releaseShow = ref(false)
const releaseForm = ref({ saleQuantity: 100, price: 99, perUserLimit: 5 })

// ---- 子藏品新增 / 编辑 ----
const prizeShow = ref(false)
const editingPrize = ref(null)
const prizeForm = ref({ prizeCollectibleId: null, probability: 10, quantityLimit: null })

async function load() {
  loading.value = true
  const res = await getBlindBoxDetail(id)
  detail.value = res.data
  releaseForm.value = {
    saleQuantity: Math.min(detail.value.audit.pool, detail.value.edition),
    price: detail.value.price,
    perUserLimit: detail.value.perUserLimit || 5
  }
  loading.value = false
}

onMounted(async () => {
  await load()
  const res = await getBlindBoxSelectableCollectibles()
  selectable.value = res.data
})

// 概率合计（百分比，保留 4 位小数）
const probSum = computed(() => (detail.value?.items || []).reduce((s, i) => s + i.probability, 0))
const probSumOk = computed(() => probSum.value <= 1.0000001)
const emptyRate = computed(() => Math.max(0, 100 - probSum.value * 100))

// ---- 子藏品配置 ----
function openAddPrize() {
  editingPrize.value = null
  prizeForm.value = { prizeCollectibleId: null, probability: 10, quantityLimit: 100 }
  prizeShow.value = true
}

function openEditPrize(p) {
  editingPrize.value = p
  prizeForm.value = {
    prizeCollectibleId: p.prizeCollectibleId,
    probability: Number((p.probability * 100).toFixed(4)),
    quantityLimit: p.quantityLimit
  }
  prizeShow.value = true
}

async function onSavePrize() {
  const f = prizeForm.value
  if (!editingPrize.value && !f.prizeCollectibleId) return ElMessage.warning('请选择子藏品')
  if (!(f.probability > 0 && f.probability <= 100)) return ElMessage.warning('概率需为 0.0001 ~ 100 之间的数字（支持 4 位小数）')
  if (f.quantityLimit !== null && (!Number.isInteger(f.quantityLimit) || f.quantityLimit < 1)) {
    return ElMessage.warning('计划数量需为正整数（留空表示不限量）')
  }
  const res = await saveBlindBoxPrize({
    boxId: id,
    prizeCollectibleId: editingPrize.value ? editingPrize.value.prizeCollectibleId : f.prizeCollectibleId,
    probability: Number((f.probability / 100).toFixed(6)),
    quantityLimit: f.quantityLimit
  })
  if (res.code === 0) {
    ElMessage.success(editingPrize.value ? '子藏品已更新' : '子藏品已添加')
    prizeShow.value = false
    load()
  } else {
    ElMessage.error(res.message)
  }
}

async function onRemovePrize(p) {
  if (p.quantityDistributed > 0) {
    return ElMessage.warning('该子藏品已产生发放记录，不可删除')
  }
  await ElMessageBox.confirm(
    `确认从奖池移除「${p.prizeName}」？移除后概率合计将重新计算。`,
    '移除子藏品',
    { type: 'warning' }
  )
  const res = await removeBlindBoxPrize({ boxId: id, prizeCollectibleId: p.prizeCollectibleId })
  if (res.code === 0) {
    ElMessage.success('已移除')
    load()
  }
}

// ---- 允许开启开关 ----
async function onToggleOpen(val) {
  await ElMessageBox.confirm(
    val ? '确认允许开启该盲盒？用户可开启盲盒按概率获得子藏品。' : '确认暂停开启该盲盒？用户将无法开启（已持有的不受影响）。',
    '开启开关',
    { type: 'warning' }
  )
  const res = await toggleBlindBoxOpenable(id)
  if (res.code === 0) {
    detail.value.isOpenable = res.data
    ElMessage.success(res.data === 1 ? '已允许开启' : '已暂停开启')
  }
}

// ---- 空投流程：表单 → 二次确认摘要 → 密码验证 ----
function onAirdropSubmit() {
  const phones = airForm.value.phones.split(/[\n,，\s]+/).filter(Boolean)
  if (!phones.length) return ElMessage.warning('请输入至少一个手机号')
  if (phones.some((p) => !/^1\d{10}$/.test(p))) return ElMessage.warning('存在格式错误的手机号')
  if (!Number.isInteger(airForm.value.quantity) || airForm.value.quantity < 1) return ElMessage.warning('请输入有效数量')
  const total = airForm.value.quantity * phones.length
  if (total > blindBoxPool(detail.value)) return ElMessage.warning(`盲盒库存池不足，当前库存池为 ${blindBoxPool(detail.value)}`)
  airConfirmShow.value = true
}

async function onAirdropConfirmed() {
  airConfirmShow.value = false
  airPwdShow.value = true
}

async function onAirdropVerified() {
  const phones = airForm.value.phones.split(/[\n,，\s]+/).filter(Boolean)
  const res = await airdropBlindBox({ id, phones, quantity: airForm.value.quantity })
  if (res.code === 0) {
    ElMessage.success(`已空投给 ${res.data.users} 位用户（共 ${res.data.total} 份），生成发放记录并写入审计日志`)
    airShow.value = false
    load()
  }
}

// ---- 销毁 ----
function onDestroySubmit() {
  if (!Number.isInteger(destroyQty.value) || destroyQty.value < 1) return ElMessage.warning('请输入有效数量')
  if (destroyQty.value > blindBoxPool(detail.value)) return ElMessage.warning(`销毁数量不可超过当前盲盒库存池（${blindBoxPool(detail.value)}）`)
  destroyPwdShow.value = true
}

async function onDestroyVerified() {
  const res = await destroyBlindBox({ id, quantity: destroyQty.value })
  if (res.code === 0) {
    ElMessage.success(`已销毁 ${res.data.destroyed} 份（不可恢复），生成销毁记录`)
    destroyShow.value = false
    load()
  }
}

// ---- 发售配置 ----
async function onReleaseSubmit() {
  const f = releaseForm.value
  if (f.saleQuantity > blindBoxPool(detail.value)) {
    return ElMessage.warning(`发售数量不可超过当前盲盒库存池（${blindBoxPool(detail.value)}）`)
  }
  await ElMessageBox.confirm(
    `确认发布发售配置：发售 ${f.saleQuantity} 份 × ¥${fmtMoney(f.price)}，每人限购 ${f.perUserLimit} 份？`,
    '发售配置',
    { type: 'warning' }
  )
  const res = await releaseBlindBox({ id, ...f })
  if (res.code === 0) {
    ElMessage.success('发售配置已生效，盲盒进入发售中')
    releaseShow.value = false
    load()
  }
}

const phoneCount = () => airForm.value.phones.split(/[\n,，\s]+/).filter(Boolean).length
</script>

<template>
  <div class="adm-page bd">
    <el-skeleton v-if="loading" :rows="8" animated style="padding: 20px" />
    <template v-else-if="detail">
      <div class="bd__split">
        <!-- 左列 -->
        <div>
          <!-- 头图 -->
          <div class="adm-card bd__hero">
            <img class="bd__cover" :src="detail.cover" :alt="detail.name" />
            <div class="bd__hero-info">
              <div class="bd__name">
                {{ detail.name }}
                <StatusTag :value="detail.status" :map="COLLECTIBLE_STATUS" />
                <el-tag type="danger" effect="plain" size="small">盲盒</el-tag>
              </div>
              <div class="t-tertiary" style="font-size: 12px; margin-top: 4px">
                发行 {{ detail.edition }} · 已售 {{ detail.sold }} · 已开启 {{ detail.openedCount }} · 每人限购 {{ detail.perUserLimit }} 份
              </div>
              <div class="bd__price price">¥{{ fmtMoney(detail.price) }}</div>
              <div class="bd__ops">
                <el-button type="primary" plain @click="airShow = true">独立空投</el-button>
                <el-button type="danger" plain @click="destroyShow = true">销毁库存</el-button>
                <el-button plain @click="router.push(`/blindbox/edit/${id}`)">编辑盲盒</el-button>
                <el-button type="primary" @click="releaseShow = true">发售配置</el-button>
              </div>
            </div>
          </div>

          <!-- 盲盒库存守恒审计 -->
          <div class="adm-card">
            <div class="adm-card__title">
              盲盒库存守恒审计
              <el-tag :type="detail.audit.ok ? 'success' : 'danger'" effect="plain" round size="small">
                {{ detail.audit.ok ? '守恒正常' : '数据异常' }}
              </el-tag>
            </div>
            <div class="bd__audit">
              <div class="bd__audit-item">
                <div class="bd__audit-v price">{{ detail.edition }}</div>
                <div class="bd__audit-l">盲盒发行总量</div>
              </div>
              <div class="bd__audit-item">
                <div class="bd__audit-v price">{{ detail.audit.pool }}</div>
                <div class="bd__audit-l">盲盒库存池</div>
              </div>
              <div class="bd__audit-item">
                <div class="bd__audit-v price">{{ detail.sold }}</div>
                <div class="bd__audit-l">盲盒已售出发售</div>
              </div>
              <div class="bd__audit-item">
                <div class="bd__audit-v price">{{ detail.airdroppedCount }}</div>
                <div class="bd__audit-l">盲盒已独立空投</div>
              </div>
              <div class="bd__audit-item">
                <div class="bd__audit-v price">{{ detail.destroyedCount }}</div>
                <div class="bd__audit-l">盲盒已销毁</div>
              </div>
            </div>
            <div class="bd__audit-formula">
              盲盒发行量 {{ detail.edition }} = 盲盒库存池 {{ detail.audit.pool }} + 盲盒已售出发售 {{ detail.sold }} + 盲盒已独立空投 {{ detail.airdroppedCount }} + 盲盒已销毁 {{ detail.destroyedCount }}
            </div>
            <div class="adm-kv" style="margin-top: 8px">
              <span class="k">盲盒流通量（实时）</span>
              <span class="v price">{{ detail.circulate }} / 发行量 {{ detail.edition }}</span>
            </div>
            <div class="adm-kv">
              <span class="k">允许开启</span>
              <el-switch :model-value="detail.isOpenable === 1" size="small" @change="onToggleOpen" />
            </div>
            <div class="adm-kv">
              <span class="k">转赠 / 寄售开关</span>
              <span class="v">
                {{ detail.isTransferable ? '转赠已开启' : '转赠已关闭' }} ·
                {{ detail.isResaleable ? `寄售已开启（${detail.resalePriceMode === 'limit' ? `限价 ¥${detail.resalePriceMin}-¥${detail.resalePriceMax}` : '不限价'}）` : '寄售已关闭' }}
              </span>
            </div>
          </div>
        </div>

        <!-- 右列：奖池配置 -->
        <div class="adm-card">
          <div class="adm-card__title">
            子藏品奖池配置
            <el-tag :type="probSumOk ? 'success' : 'danger'" effect="plain" round size="small">
              概率合计 {{ (probSum * 100).toFixed(2) }}%
            </el-tag>
            <el-button link type="primary" size="small" :icon="Plus" @click="openAddPrize">添加子藏品</el-button>
          </div>

          <el-table :data="detail.items" class="bd__prize-table">
            <el-table-column label="子藏品" min-width="180">
              <template #default="{ row }">
                <div class="bd__prize-cell">
                  <img class="bd__prize-cover" :src="row.cover" :alt="row.prizeName" />
                  <span class="bd__prize-name">{{ row.prizeName }}</span>
                </div>
              </template>
            </el-table-column>
            <el-table-column label="中奖概率" width="100" align="center">
              <template #default="{ row }">
                <span class="price">{{ (row.probability * 100).toFixed(2) }}%</span>
              </template>
            </el-table-column>
            <el-table-column label="计划数量" width="90" align="center">
              <template #default="{ row }">
                {{ row.quantityLimit === null ? '不限量' : row.quantityLimit }}
              </template>
            </el-table-column>
            <el-table-column label="已发放" width="80" align="center">
              <template #default="{ row }">
                {{ row.quantityDistributed }}
              </template>
            </el-table-column>
            <el-table-column label="操作" width="110" fixed="right">
              <template #default="{ row }">
                <el-button link type="primary" size="small" @click="openEditPrize(row)">调整</el-button>
                <el-button link type="danger" size="small" :icon="Delete" @click="onRemovePrize(row)" />
              </template>
            </el-table-column>
          </el-table>

          <el-alert
            :type="probSumOk ? 'info' : 'error'"
            :closable="false"
            show-icon
            class="bd__prob-tip"
            :title="probSumOk
              ? `空奖（未中奖）概率 = ${emptyRate.toFixed(2)}%；开启盲盒时实时校验子藏品库存池 / 配额预留，不足自动降级为空奖并记录异常日志`
              : `概率合计超出 100%（当前 ${(probSum * 100).toFixed(2)}%），请调整`"
          />
        </div>
      </div>

      <!-- 空投弹窗 -->
      <el-dialog v-model="airShow" title="盲盒独立空投" width="480px" :close-on-click-modal="false">
        <el-form label-width="110px">
          <el-form-item label="当前库存池">
            <el-tag type="warning" effect="plain">{{ blindBoxPool(detail) }} 份</el-tag>
          </el-form-item>
          <el-form-item label="接收手机号">
            <el-input
              v-model="airForm.phones"
              type="textarea"
              :rows="4"
              placeholder="批量手机号，换行分隔，每行一个（须为平台已注册用户）"
            />
          </el-form-item>
          <el-form-item label="每人份数">
            <el-input-number v-model="airForm.quantity" :min="1" :max="blindBoxPool(detail)" />
          </el-form-item>
        </el-form>
        <el-alert type="info" :closable="false" show-icon title="空投可在任何阶段执行（未发售前 / 发售中 / 售罄后），从盲盒库存池扣减、发放资产到用户仓库，生成发放记录并写入审计日志" />
        <template #footer>
          <el-button @click="airShow = false">取消</el-button>
          <el-button type="primary" @click="onAirdropSubmit">下一步（确认摘要）</el-button>
        </template>
      </el-dialog>

      <!-- 空投二次确认摘要 -->
      <el-dialog v-model="airConfirmShow" title="空投确认" width="420px">
        <div class="adm-kv"><span class="k">盲盒名称</span><span class="v">{{ detail.name }}</span></div>
        <div class="adm-kv"><span class="k">空投数量</span><span class="v">每人 {{ airForm.quantity }} 份</span></div>
        <div class="adm-kv"><span class="k">接收用户数</span><span class="v">{{ phoneCount() }} 人</span></div>
        <div class="adm-kv">
          <span class="k">预计扣减库存池</span>
          <span class="v price">{{ airForm.quantity * phoneCount() }} 份</span>
        </div>
        <template #footer>
          <el-button @click="airConfirmShow = false">返回修改</el-button>
          <el-button type="primary" @click="onAirdropConfirmed">下一步（密码验证）</el-button>
        </template>
      </el-dialog>

      <!-- 销毁弹窗 -->
      <el-dialog v-model="destroyShow" title="销毁盲盒库存" width="440px" :close-on-click-modal="false">
        <el-form label-width="110px">
          <el-form-item label="当前库存池">
            <el-tag type="warning" effect="plain">{{ blindBoxPool(detail) }} 份</el-tag>
          </el-form-item>
          <el-form-item label="销毁份数">
            <el-input-number v-model="destroyQty" :min="1" :max="blindBoxPool(detail)" />
          </el-form-item>
        </el-form>
        <el-alert type="error" :closable="false" show-icon title="销毁从盲盒库存池扣减且不可恢复，需管理员密码验证，生成销毁记录" />
        <template #footer>
          <el-button @click="destroyShow = false">取消</el-button>
          <el-button type="danger" @click="onDestroySubmit">销毁（需密码验证）</el-button>
        </template>
      </el-dialog>

      <!-- 发售配置弹窗 -->
      <el-dialog v-model="releaseShow" title="盲盒发售配置" width="460px" :close-on-click-modal="false">
        <el-form label-width="110px">
          <el-form-item label="当前库存池">
            <el-tag type="warning" effect="plain">{{ blindBoxPool(detail) }} 份（发售数量不可超过库存池）</el-tag>
          </el-form-item>
          <el-form-item label="发售数量">
            <el-input-number v-model="releaseForm.saleQuantity" :min="1" :max="blindBoxPool(detail)" />
          </el-form-item>
          <el-form-item label="发售价格（元）">
            <el-input-number v-model="releaseForm.price" :min="0.01" :precision="2" :step="10" />
          </el-form-item>
          <el-form-item label="每人限购">
            <el-input-number v-model="releaseForm.perUserLimit" :min="1" />
          </el-form-item>
        </el-form>
        <el-alert type="info" :closable="false" show-icon title="发售中每卖出 1 份：盲盒已售出发售 +1、盲盒库存池 -1；库存池为 0 或到期自动停止；重新上架需重新配置发售参数" />
        <template #footer>
          <el-button @click="releaseShow = false">取消</el-button>
          <el-button type="primary" @click="onReleaseSubmit">确认发布</el-button>
        </template>
      </el-dialog>

      <!-- 新增 / 调整子藏品弹窗 -->
      <el-dialog
        v-model="prizeShow"
        :title="editingPrize ? `调整子藏品 · ${editingPrize.prizeName}` : '添加子藏品'"
        width="480px"
        :close-on-click-modal="false"
      >
        <el-form label-width="110px">
          <el-form-item v-if="!editingPrize" label="选择藏品">
            <el-select
              v-model="prizeForm.prizeCollectibleId"
              filterable
              placeholder="仅可选择流通量 > 0 的藏品"
              style="width: 100%"
            >
              <el-option
                v-for="c in selectable"
                :key="c.id"
                :label="c.name"
                :value="c.id"
              >
                <div class="bd__select-option">
                  <span>{{ c.name }}（{{ c.category }}）</span>
                  <span class="t-tertiary">流通 {{ c.circulate }} · 库存池 {{ c.stockPool }}</span>
                </div>
              </el-option>
            </el-select>
          </el-form-item>
          <el-form-item label="中奖概率（%）">
            <el-input-number
              v-model="prizeForm.probability"
              :min="0.0001"
              :max="100"
              :precision="4"
              :step="0.1"
              style="width: 180px"
            />
            <div class="t-tertiary" style="font-size: 12px; margin-top: 4px">
              0.0001 ~ 100，支持小数点后 4 位；所有子藏品概率之和 &lt;= 100%，差额视为空奖
            </div>
          </el-form-item>
          <el-form-item label="计划数量">
            <el-input-number v-model="prizeForm.quantityLimit" :min="1" style="width: 180px" />
            <div class="t-tertiary" style="font-size: 12px; margin-top: 4px">
              计划数量之和不强制等于盲盒发行总量（允许部分盲盒开出空奖）；子藏品库存不预先冻结，开启时实时校验
            </div>
          </el-form-item>
        </el-form>
        <template #footer>
          <el-button @click="prizeShow = false">取消</el-button>
          <el-button type="primary" @click="onSavePrize">保存</el-button>
        </template>
      </el-dialog>

      <!-- 密码验证 -->
      <PasswordVerify v-model="airPwdShow" title="盲盒空投验证" @verified="onAirdropVerified" />
      <PasswordVerify v-model="destroyPwdShow" title="盲盒销毁验证" @verified="onDestroyVerified" />
    </template>
  </div>
</template>

<style scoped lang="scss">
.bd__split {
  display: grid;
  grid-template-columns: 1.1fr 1.2fr;
  gap: 14px;
  align-items: start;

  @media (max-width: 992px) {
    grid-template-columns: 1fr;
  }
}

.bd__hero { display: flex; gap: 16px; }

.bd__cover {
  width: 110px;
  height: 110px;
  border-radius: 8px;
  object-fit: cover;
  flex-shrink: 0;
  background: $color-surface;
}

.bd__hero-info { flex: 1; min-width: 0; padding-top: 4px; }

.bd__name {
  font-size: 16px;
  font-weight: 700;
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.bd__price { font-size: 20px; margin-top: 8px; }

.bd__ops {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 12px;
}

.bd__audit {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 12px 4px;
  text-align: center;
  margin-top: 10px;

  @media (min-width: 769px) {
    grid-template-columns: repeat(5, 1fr);
  }
}

.bd__audit-v { font-size: 16px; }
.bd__audit-l { font-size: 10px; color: $color-text-tertiary; margin-top: 2px; }

.bd__audit-formula {
  margin-top: 12px;
  padding: 8px 10px;
  border-radius: 8px;
  background: $color-surface;
  font-size: 11px;
  color: $color-text-secondary;
  line-height: 1.7;
}

.bd__prize-table {
  :deep(.el-table__header th) {
    background: #F5F7FA;
    color: $color-text-primary;
    font-weight: 600;
  }
}

.bd__prize-cell {
  display: flex;
  align-items: center;
  gap: 8px;
}

.bd__prize-cover {
  width: 36px;
  height: 36px;
  border-radius: 6px;
  object-fit: cover;
  flex-shrink: 0;
  border: 1px solid $color-border;
}

.bd__prize-name { font-size: 13px; color: $color-text-primary; }

.bd__prob-tip { margin-top: 10px; }

.bd__select-option {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
}
</style>
