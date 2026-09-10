<script setup>
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  getCollectibleDetail,
  airdropCollectible,
  destroyCollectible,
  addQuota,
  toggleQuota,
  releaseCollectible,
  toggleCollectibleStatus,
  toggleCollectibleResale,
  toggleCollectibleTransferable,
  toggleCollectibleBuyRequest,
  swapCollectible
} from '@/api'
import StatusTag from '@/components/StatusTag.vue'
import PasswordVerify from '@/components/PasswordVerify.vue'
import { COLLECTIBLE_STATUS, QUOTA_TYPES } from '@/utils/maps'
import { fmtMoney, stockPool } from '@/utils/format'

const route = useRoute()
const router = useRouter()
const id = Number(route.params.id)
const loading = ref(true)
const detail = ref(null)

// ---- 空投 ----
const airShow = ref(false)
const airConfirmShow = ref(false)
const airPwdShow = ref(false)
const airForm = ref({ phones: '', quantity: 1 })

// ---- 销毁 ----
const destroyShow = ref(false)
const destroyQty = ref(1)
const destroyPwdShow = ref(false)

// ---- 配额 ----
const quotaShow = ref(false)
const quotaForm = ref({ quotaType: 1, quotaName: '', quantity: 10 })

// ---- 发售配置 ----
const releaseShow = ref(false)
const releaseForm = ref({ saleQuantity: 100, price: 99, perUserLimit: 2 })

async function load() {
  loading.value = true
  const res = await getCollectibleDetail(id)
  detail.value = res.data
  releaseForm.value = {
    saleQuantity: Math.min(detail.value.audit.pool, 100),
    price: detail.value.price,
    perUserLimit: 2
  }
  loading.value = false
}
onMounted(load)

// ---- 空投流程：表单 → 二次确认摘要 → 密码验证 ----
function onAirdropSubmit() {
  const phones = airForm.value.phones.split(/[\n,，\s]+/).filter(Boolean)
  if (!phones.length) return ElMessage.warning('请输入至少一个手机号')
  if (phones.some((p) => !/^1\d{10}$/.test(p))) return ElMessage.warning('存在格式错误的手机号')
  if (!Number.isInteger(airForm.value.quantity) || airForm.value.quantity < 1) return ElMessage.warning('请输入有效数量')
  const total = airForm.value.quantity * phones.length
  if (total > stockPool(detail.value)) return ElMessage.warning(`库存池不足，当前库存池为 ${stockPool(detail.value)}`)
  airConfirmShow.value = true
}

async function onAirdropConfirmed() {
  airConfirmShow.value = false
  airPwdShow.value = true
}

async function onAirdropVerified() {
  const phones = airForm.value.phones.split(/[\n,，\s]+/).filter(Boolean)
  const res = await airdropCollectible({ id, phones, quantity: airForm.value.quantity })
  if (res.code === 0) {
    ElMessage.success(`已空投给 ${res.data.users} 位用户（共 ${res.data.total} 份），生成发放记录并写入审计日志`)
    airShow.value = false
    load()
  }
}

// ---- 销毁 ----
function onDestroySubmit() {
  if (!Number.isInteger(destroyQty.value) || destroyQty.value < 1) return ElMessage.warning('请输入有效数量')
  if (destroyQty.value > stockPool(detail.value)) return ElMessage.warning(`销毁数量不可超过当前库存池（${stockPool(detail.value)}）`)
  destroyPwdShow.value = true
}

async function onDestroyVerified() {
  const res = await destroyCollectible({ id, quantity: destroyQty.value })
  if (res.code === 0) {
    ElMessage.success(`已销毁 ${res.data.destroyed} 份（不可恢复），生成销毁记录`)
    destroyShow.value = false
    load()
  }
}

// ---- 置换：回收当前藏品 → 向同一批用户空投新藏品 ----
const swapShow = ref(false)
const swapPwdShow = ref(false)
const swapForm = ref({ newCollectibleId: '', quantityPerUser: 1, reason: '' })
const swapping = ref(false)

function openSwap() {
  swapForm.value = { newCollectibleId: '', quantityPerUser: 1, reason: '' }
  swapShow.value = true
}

async function onSwapSubmit() {
  const f = swapForm.value
  if (!f.newCollectibleId) return ElMessage.warning('请输入新藏品ID')
  if (Number(f.newCollectibleId) === Number(id)) return ElMessage.warning('新藏品不能与当前藏品相同')
  if (!Number.isInteger(f.quantityPerUser) || f.quantityPerUser < 1) return ElMessage.warning('每人空投份数需为正整数')
  // 二次确认
  await ElMessageBox.confirm(
    `即将执行置换：回收「${detail.value.name}」所有有效持仓，并向同一批用户每人空投 ${f.quantityPerUser} 份新藏品（ID: ${f.newCollectibleId}）。\n\n此操作不可撤销，将批量回收并空投，是否继续？`,
    '藏品置换确认',
    { type: 'warning', confirmButtonText: '确认置换', cancelButtonText: '取消' }
  )
  swapPwdShow.value = true
}

async function onSwapVerified() {
  swapping.value = true
  try {
    const res = await swapCollectible({
      oldCollectibleId: id,
      newCollectibleId: Number(swapForm.value.newCollectibleId),
      quantityPerUser: swapForm.value.quantityPerUser,
      reason: swapForm.value.reason
    })
    if (res.code === 0) {
      const d = res.data
      ElMessage.success(`置换完成：回收 ${d.recoveredCount} 份（${d.userCount} 人），空投新藏品 ${d.airdropQuantity} 份`)
      swapShow.value = false
      load()
    }
  } finally {
    swapping.value = false
  }
}

// ---- 配额 ----
function onAddQuotaSubmit() {
  if (!quotaForm.value.quotaName.trim()) return ElMessage.warning('请输入配额名称')
  if (quotaForm.value.quantity > stockPool(detail.value)) {
    return ElMessage.warning(`库存池不足，当前库存池为 ${stockPool(detail.value)}`)
  }
  addQuotaAndClose()
}

async function addQuotaAndClose() {
  const res = await addQuota({
    collectibleId: id,
    quotaType: quotaForm.value.quotaType,
    quotaName: quotaForm.value.quotaName,
    quantity: quotaForm.value.quantity
  })
  if (res.code === 0) {
    ElMessage.success('配额已添加，已从库存池冻结预留')
    quotaShow.value = false
    load()
  }
}

async function onToggleQuota(q) {
  const enabling = q.status !== 1
  await ElMessageBox.confirm(
    enabling
      ? `确认启用配额「${q.quotaName}」？未使用的 ${q.plannedQuantity - q.usedQuantity} 份将从库存池冻结预留。`
      : `确认停用配额「${q.quotaName}」？未使用的 ${q.plannedQuantity - q.usedQuantity} 份将释放回库存池（已使用的不可减少）。`,
    '配额操作',
    { type: 'warning' }
  )
  const res = await toggleQuota(q.id)
  if (res.code === 0) {
    q.status = res.data
    ElMessage.success(res.data === 1 ? '已启用' : '已停用')
    load()
  }
}

// ---- 发售配置 ----
async function onReleaseSubmit() {
  const f = releaseForm.value
  if (f.saleQuantity > stockPool(detail.value)) {
    return ElMessage.warning(`发售数量不可超过当前库存池（${stockPool(detail.value)}）`)
  }
  await ElMessageBox.confirm(
    `确认发布发售配置：发售 ${f.saleQuantity} 份 × ¥${fmtMoney(f.price)}，每人限购 ${f.perUserLimit} 份？`,
    '发售配置',
    { type: 'warning' }
  )
  const res = await releaseCollectible({ id, saleQuantity: f.saleQuantity, price: f.price, perUserLimit: f.perUserLimit })
  if (res.code === 0) {
    ElMessage.success('发售配置已生效，藏品进入发售中')
    releaseShow.value = false
    load()
  }
}

// ---- 上架售卖开关 ----
async function onSaleSwitch(val) {
  if (val) {
    await ElMessageBox.confirm(
      `确认上架售卖「${detail.value.name}」？上架后 C 端立即可见并可购买（需库存池大于 0）。`,
      '上架售卖',
      { type: 'warning' }
    )
    const res = await toggleCollectibleStatus(id, 'online')
    if (res.code === 0) {
      ElMessage.success('已上架售卖')
      load()
    }
  } else {
    await ElMessageBox.confirm(
      `确认下架「${detail.value.name}」？下架后 C 端不再展示，未售出库存保留在库存池。`,
      '下架藏品',
      { type: 'warning' }
    )
    const res = await toggleCollectibleStatus(id, 'offline')
    if (res.code === 0) {
      ElMessage.success('已下架')
      load()
    }
  }
}

// ---- 转赠开关（创建后配置，独立于寄售） ----
async function onTransferableSwitch(val) {
  await ElMessageBox.confirm(
    val
      ? `确认开启「${detail.value.name}」的转赠开关？用户端将显示转赠入口。`
      : `确认关闭「${detail.value.name}」的转赠开关？用户端转赠按钮置灰（已发起待确认的转赠不受影响）。`,
    '转赠开关',
    { type: 'warning' }
  )
  const res = await toggleCollectibleTransferable(id, val)
  if (res.code === 0) {
    ElMessage.success(val ? '已开启转赠' : '已关闭转赠')
    load()
  }
}

// ---- 求购开关（藏品级别，控制 C 端求购 tab 显隐） ----
async function onBuyRequestSwitch(val) {
  await ElMessageBox.confirm(
    val
      ? `确认开启「${detail.value.name}」的求购功能？用户端将显示求购 tab，允许用户发布求购挂单。`
      : `确认关闭「${detail.value.name}」的求购功能？用户端将隐藏求购 tab，已有的求购挂单不再展示。`,
    '求购开关',
    { type: 'warning' }
  )
  const res = await toggleCollectibleBuyRequest(id, val)
  if (res.code === 0) {
    ElMessage.success(val ? '已开启求购' : '已关闭求购')
    load()
  }
}

// ---- 寄售开关（联动价格管控：0=不限价 1=固定价 2=区间价） ----
const priceShow = ref(false)
const pricePwdShow = ref(false)
const priceForm = ref({ enabled: 1, priceMode: 0, priceMin: null, priceMax: null })

function openResaleDialog() {
  const d = detail.value
  priceForm.value = {
    enabled: d.isResaleable ? 1 : 0,
    priceMode: Number(d.resalePriceMode) || 0,
    priceMin: d.resalePriceMin ?? null,
    priceMax: d.resalePriceMax ?? null
  }
  priceShow.value = true
}

function onResaleSubmit() {
  const f = priceForm.value
  if (f.enabled) {
    if (f.priceMode === 1 && (f.priceMin === null || f.priceMin === undefined)) {
      return ElMessage.warning('固定价模式需填写寄售价格')
    }
    if (f.priceMode === 2) {
      if (f.priceMin === null || f.priceMin === undefined || f.priceMax === null || f.priceMax === undefined) {
        return ElMessage.warning('区间价模式需填写价格上下限')
      }
      if (Number(f.priceMin) >= Number(f.priceMax)) return ElMessage.warning('价格下限需小于上限')
    }
  }
  pricePwdShow.value = true
}

async function onResaleVerified() {
  const f = priceForm.value
  const res = await toggleCollectibleResale({
    id,
    enabled: !!f.enabled,
    priceMode: f.enabled ? f.priceMode : 0,
    priceMin: f.enabled && f.priceMode >= 1 ? Number(f.priceMin) : null,
    priceMax: f.enabled && f.priceMode === 2 ? Number(f.priceMax) : null
  })
  if (res.code === 0) {
    ElMessage.success(f.enabled ? '已开启寄售' : '已关闭寄售，在售挂单已全部系统下架')
    priceShow.value = false
    load()
  }
}
</script>

<template>
  <div class="adm-page cd">
    <el-skeleton v-if="loading" :rows="8" animated style="padding: 20px" />
    <template v-else-if="detail">
      <div class="cd__split">
        <!-- 左列 -->
        <div>
          <!-- 头图 -->
          <div class="adm-card cd__hero">
            <img class="cd__cover" :src="detail.cover" :alt="detail.name" />
            <div class="cd__hero-info">
              <div class="cd__name">
                {{ detail.name }}
                <StatusTag :value="detail.status" :map="COLLECTIBLE_STATUS" />
              </div>
              <div class="t-tertiary" style="font-size: 12px; margin-top: 4px">
                {{ detail.subtitle }} · {{ detail.category }} · {{ detail.issuer }}
              </div>
              <div class="cd__sale-row">
                <span class="t-tertiary" style="font-size: 12px">上架售卖</span>
                <el-switch
                  :model-value="detail.status === 'onsale'"
                  active-text="发售中"
                  inactive-text="已下架"
                  @change="onSaleSwitch"
                />
              </div>
              <div class="cd__price price">¥{{ fmtMoney(detail.price) }}</div>
              <div class="cd__ops">
                <el-button type="primary" plain @click="airShow = true">独立空投</el-button>
                <el-button type="warning" plain @click="openSwap">置换</el-button>
                <el-button type="danger" plain @click="destroyShow = true">销毁库存</el-button>
                <el-button plain @click="router.push(`/collectible/edit/${id}`)">编辑藏品</el-button>
                <el-button type="primary" @click="releaseShow = true">发售配置</el-button>
              </div>
            </div>
          </div>

          <!-- 库存守恒审计 -->
          <div class="adm-card">
            <div class="adm-card__title">
              库存守恒审计
              <el-tag :type="detail.audit.ok ? 'success' : 'danger'" effect="plain" round size="small">
                {{ detail.audit.ok ? '守恒正常' : '数据异常' }}
              </el-tag>
            </div>
            <div class="cd__audit">
              <div class="cd__audit-item">
                <div class="cd__audit-v price">{{ detail.edition }}</div>
                <div class="cd__audit-l">发行总量</div>
              </div>
              <div class="cd__audit-item">
                <div class="cd__audit-v price">{{ detail.audit.pool }}</div>
                <div class="cd__audit-l">库存池</div>
              </div>
              <div class="cd__audit-item">
                <div class="cd__audit-v price">{{ detail.sold }}</div>
                <div class="cd__audit-l">已售出发售</div>
              </div>
              <div class="cd__audit-item">
                <div class="cd__audit-v price">{{ detail.reservedCount }}</div>
                <div class="cd__audit-l">已配置配额</div>
              </div>
              <div class="cd__audit-item">
                <div class="cd__audit-v price">{{ detail.airdroppedCount }}</div>
                <div class="cd__audit-l">已独立空投</div>
              </div>
              <div class="cd__audit-item">
                <div class="cd__audit-v price">{{ detail.destroyedCount }}</div>
                <div class="cd__audit-l">已销毁</div>
              </div>
            </div>
            <div class="cd__audit-formula">
              发行 {{ detail.edition }} = 库存 {{ detail.audit.pool }} + 已售 {{ detail.sold }} + 配额 {{ detail.reservedCount }} + 空投 {{ detail.airdroppedCount }} + 销毁 {{ detail.destroyedCount }}
            </div>
            <div class="adm-kv" style="margin-top: 8px">
              <span class="k">流通量（实时）</span>
              <span class="v price">{{ detail.sold + detail.airdroppedCount }} / 发行量 {{ detail.edition }}</span>
            </div>
          </div>

          <!-- 基本信息 -->
          <div class="adm-card">
            <div class="adm-card__title">基本信息</div>
            <div class="adm-kv"><span class="k">发售时间</span><span class="v">{{ detail.saleTime }}</span></div>
            <div class="adm-kv"><span class="k">首发推荐</span><span class="v">{{ detail.featured ? '是' : '否' }}</span></div>
            <div class="adm-kv cd__switch-row">
              <span class="k">转赠开关</span>
              <span class="v cd__switch-ops">
                <el-switch :model-value="detail.isTransferable" @change="onTransferableSwitch" />
                <span class="t-tertiary" style="font-size: 12px">{{ detail.isTransferable ? '已开启：用户可无偿转赠' : '已关闭：用户端转赠入口置灰' }}</span>
              </span>
            </div>
            <div class="adm-kv cd__switch-row">
              <span class="k">求购开关</span>
              <span class="v cd__switch-ops">
                <el-switch :model-value="detail.isBuyRequestEnabled" @change="onBuyRequestSwitch" />
                <span class="t-tertiary" style="font-size: 12px">{{ detail.isBuyRequestEnabled ? '已开启：用户可发布求购挂单' : '已关闭：用户端隐藏求购 tab' }}</span>
              </span>
            </div>
            <div class="adm-kv cd__switch-row">
              <span class="k">寄售开关</span>
              <span class="v cd__switch-ops">
                <el-switch :model-value="detail.isResaleable" @change="openResaleDialog" />
                <span class="t-tertiary" style="font-size: 12px">
                  {{ detail.isResaleable ? `已开启（${detail.resalePriceMode === 1 ? `固定价 ¥${fmtMoney(detail.resalePriceMin)}` : detail.resalePriceMode === 2 ? `限价 ¥${fmtMoney(detail.resalePriceMin)}-¥${fmtMoney(detail.resalePriceMax)}` : '不限价'}）` : '已关闭：用户端无法挂单寄售' }}
                </span>
              </span>
            </div>
            <div class="adm-kv"><span class="k">藏品描述</span><span class="v" style="max-width: 400px">{{ detail.description }}</span></div>
          </div>
        </div>

        <!-- 右列 -->
        <div>
          <!-- 配额管理 -->
          <div class="adm-card">
            <div class="adm-card__title">
              配额管理
              <el-button link type="primary" size="small" @click="quotaShow = true">新增配额</el-button>
            </div>
            <div v-for="q in detail.quotas" :key="q.id" class="cd__quota">
              <div class="cd__quota-body">
                <div class="cd__quota-name">{{ q.quotaName }}</div>
                <div class="cd__quota-desc">{{ QUOTA_TYPES[q.quotaType] }} · 计划 {{ q.plannedQuantity }} / 已用 {{ q.usedQuantity }}</div>
                <el-progress
                  :percentage="Number((q.usedQuantity / q.plannedQuantity * 100).toFixed(1))"
                  :stroke-width="4"
                  :show-text="false"
                  style="margin-top: 6px"
                />
              </div>
              <el-switch :model-value="q.status === 1" size="small" @change="onToggleQuota(q)" />
            </div>
            <el-empty v-if="!detail.quotas.length" description="暂无配额（配额可随时配置：发售前 / 发售中 / 售罄后均可）" :image-size="60" />
          </div>

          <!-- 持有人 TOP -->
          <div class="adm-card">
            <div class="adm-card__title">持有人 TOP5</div>
            <div v-for="(h, i) in detail.holders" :key="h.serial" class="cd__holder">
              <div class="cd__rank" :class="{ 'is-top': i < 3 }">{{ i + 1 }}</div>
              <div class="cd__holder-body">
                <div class="cd__holder-name">{{ h.nickname }}</div>
                <div class="cd__holder-serial">{{ h.serial }}</div>
              </div>
              <span class="price">×{{ h.quantity }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- 空投弹窗 -->
      <el-dialog v-model="airShow" title="独立空投" width="480px" append-to-body :close-on-click-modal="false">
        <el-form label-width="90px">
          <el-form-item label="当前库存池">
            <el-tag type="warning" effect="plain">{{ stockPool(detail) }} 份</el-tag>
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
            <el-input-number v-model="airForm.quantity" :min="1" :max="Math.max(1, stockPool(detail))" />
          </el-form-item>
        </el-form>
        <el-alert type="info" :closable="false" show-icon title="空投从库存池扣减、已独立空投增加、发放资产到用户仓库，生成发放记录并写入审计日志" />
        <template #footer>
          <el-button @click="airShow = false">取消</el-button>
          <el-button type="primary" @click="onAirdropSubmit">下一步（确认摘要）</el-button>
        </template>
      </el-dialog>

      <!-- 空投二次确认摘要 -->
      <el-dialog v-model="airConfirmShow" title="空投确认" width="420px" append-to-body>
        <div class="adm-kv"><span class="k">藏品名称</span><span class="v">{{ detail.name }}</span></div>
        <div class="adm-kv"><span class="k">空投数量</span><span class="v">每人 {{ airForm.quantity }} 份</span></div>
        <div class="adm-kv"><span class="k">接收用户数</span><span class="v">{{ airForm.phones.split(/[\n,，\s]+/).filter(Boolean).length }} 人</span></div>
        <div class="adm-kv">
          <span class="k">预计扣减库存池</span>
          <span class="v price">{{ airForm.quantity * airForm.phones.split(/[\n,，\s]+/).filter(Boolean).length }} 份</span>
        </div>
        <template #footer>
          <el-button @click="airConfirmShow = false">返回修改</el-button>
          <el-button type="primary" @click="onAirdropConfirmed">下一步（密码验证）</el-button>
        </template>
      </el-dialog>

      <!-- 销毁弹窗 -->
      <el-dialog v-model="destroyShow" title="销毁库存" width="440px" append-to-body :close-on-click-modal="false">
        <el-form label-width="90px">
          <el-form-item label="当前库存池">
            <el-tag type="warning" effect="plain">{{ stockPool(detail) }} 份（配额预留不可销毁）</el-tag>
          </el-form-item>
          <el-form-item label="销毁份数">
            <el-input-number v-model="destroyQty" :min="1" :max="Math.max(1, stockPool(detail))" />
          </el-form-item>
        </el-form>
        <el-alert type="error" :closable="false" show-icon title="销毁从库存池扣减且不可恢复，需管理员密码验证，生成销毁记录" />
        <template #footer>
          <el-button @click="destroyShow = false">取消</el-button>
          <el-button type="danger" @click="onDestroySubmit">销毁（需密码验证）</el-button>
        </template>
      </el-dialog>

      <!-- 置换弹窗 -->
      <el-dialog v-model="swapShow" title="藏品置换" width="480px" append-to-body :close-on-click-modal="false">
        <el-form label-width="110px">
          <el-form-item label="回收旧藏品">
            <el-tag type="info" effect="plain">{{ detail.name }}（ID: {{ id }}）</el-tag>
            <span class="t-tertiary" style="margin-left: 8px; font-size: 12px">将回收该藏品所有有效持仓</span>
          </el-form-item>
          <el-form-item label="空投新藏品ID">
            <el-input v-model="swapForm.newCollectibleId" placeholder="请输入新藏品ID" />
          </el-form-item>
          <el-form-item label="每人空投份数">
            <el-input-number v-model="swapForm.quantityPerUser" :min="1" :max="100" />
          </el-form-item>
          <el-form-item label="置换原因">
            <el-input v-model="swapForm.reason" type="textarea" :rows="2" placeholder="如：版本升级置换（选填）" />
          </el-form-item>
        </el-form>
        <el-alert
          type="warning"
          :closable="false"
          show-icon
          title="置换将批量回收当前藏品的所有有效持仓（含寄售中），并向完全相同的一批用户空投新藏品。单一事务保证回收与空投用户精准对齐，操作不可撤销。"
        />
        <template #footer>
          <el-button @click="swapShow = false">取消</el-button>
          <el-button type="warning" :loading="swapping" @click="onSwapSubmit">确认置换（需密码验证）</el-button>
        </template>
      </el-dialog>

      <!-- 新增配额弹窗 -->
      <el-dialog v-model="quotaShow" title="新增配额" width="460px" append-to-body :close-on-click-modal="false">
        <el-form label-width="90px">
          <el-form-item label="配额类型">
            <el-select v-model="quotaForm.quotaType" style="width: 100%">
              <el-option v-for="(label, value) in QUOTA_TYPES" :key="value" :label="label" :value="Number(value)" />
            </el-select>
          </el-form-item>
          <el-form-item label="配额名称">
            <el-input v-model="quotaForm.quotaName" placeholder="如：优先购预留 / 活动空投" />
          </el-form-item>
          <el-form-item label="预留数量">
            <el-input-number v-model="quotaForm.quantity" :min="1" :max="Math.max(1, stockPool(detail))" />
            <div class="t-tertiary" style="font-size: 12px; margin-top: 4px">
              当前库存池 {{ stockPool(detail) }} 份，配置后从库存池冻结预留
            </div>
          </el-form-item>
        </el-form>
        <template #footer>
          <el-button @click="quotaShow = false">取消</el-button>
          <el-button type="primary" @click="onAddQuotaSubmit">确认添加</el-button>
        </template>
      </el-dialog>

      <!-- 发售配置弹窗 -->
      <el-dialog v-model="releaseShow" title="发售配置" width="460px" append-to-body :close-on-click-modal="false">
        <el-form label-width="100px">
          <el-form-item label="当前库存池">
            <el-tag type="warning" effect="plain">{{ stockPool(detail) }} 份（发售数量不可超过库存池）</el-tag>
          </el-form-item>
          <el-form-item label="发售数量">
            <el-input-number v-model="releaseForm.saleQuantity" :min="1" :max="Math.max(1, stockPool(detail))" />
          </el-form-item>
          <el-form-item label="发售价格（元）">
            <el-input-number v-model="releaseForm.price" :min="0.01" :precision="2" :step="10" />
          </el-form-item>
          <el-form-item label="每人限购">
            <el-input-number v-model="releaseForm.perUserLimit" :min="1" />
          </el-form-item>
        </el-form>
        <el-alert type="info" :closable="false" show-icon title="发售中每卖出 1 份：已售出发售 +1、库存池 -1；库存池为 0 或到期自动停止；资格购 / 优先购可在营销中心独立配置" />
        <template #footer>
          <el-button @click="releaseShow = false">取消</el-button>
          <el-button type="primary" @click="onReleaseSubmit">确认发布</el-button>
        </template>
      </el-dialog>

      <!-- 寄售开关 + 价格管控弹窗 -->
      <el-dialog v-model="priceShow" :title="`寄售管控 · ${detail.name}`" width="480px" append-to-body :close-on-click-modal="false">
        <el-form label-width="110px">
          <el-form-item label="允许寄售">
            <el-switch v-model="priceForm.enabled" :active-value="1" :inactive-value="0" />
            <div class="t-tertiary" style="font-size: 12px; margin-top: 4px">
              关闭后该藏品所有在售挂单将全部系统下架，用户无法重新上架
            </div>
          </el-form-item>
          <template v-if="priceForm.enabled">
            <el-form-item label="限价策略">
              <el-radio-group v-model="priceForm.priceMode">
                <el-radio :value="0">不限价</el-radio>
                <el-radio :value="1">固定价</el-radio>
                <el-radio :value="2">区间价</el-radio>
              </el-radio-group>
            </el-form-item>
            <el-form-item v-if="priceForm.priceMode === 1" label="固定寄售价（元）">
              <el-input-number v-model="priceForm.priceMin" :min="0.01" :precision="2" :step="10" style="width: 180px" />
              <div class="t-tertiary" style="font-size: 12px; margin-top: 4px">用户挂单价格必须等于该固定价</div>
            </el-form-item>
            <template v-if="priceForm.priceMode === 2">
              <el-form-item label="寄售区间（元）">
                <el-input-number v-model="priceForm.priceMin" :min="0.01" :precision="2" placeholder="最低" style="width: 140px" />
                <span style="margin: 0 10px">~</span>
                <el-input-number v-model="priceForm.priceMax" :min="0.01" :precision="2" placeholder="最高" style="width: 140px" />
              </el-form-item>
              <el-alert type="info" :closable="false" show-icon title="用户挂单价格必须落在上下限闭区间内" />
            </template>
            <el-alert v-if="priceForm.priceMode === 0" type="info" :closable="false" show-icon title="不限价模式：用户自由定价，仅保留全局最大金额校验" />
          </template>
        </el-form>
        <template #footer>
          <el-button @click="priceShow = false">取消</el-button>
          <el-button type="primary" @click="onResaleSubmit">提交（需密码验证）</el-button>
        </template>
      </el-dialog>

      <!-- 密码验证 -->
      <PasswordVerify v-model="airPwdShow" title="空投验证" @verified="onAirdropVerified" />
      <PasswordVerify v-model="destroyPwdShow" title="销毁验证" @verified="onDestroyVerified" />
      <PasswordVerify v-model="swapPwdShow" title="置换验证" @verified="onSwapVerified" />
      <PasswordVerify v-model="pricePwdShow" title="寄售管控验证" @verified="onResaleVerified" />
    </template>
  </div>
</template>

<style scoped lang="scss">
.cd__split {
  display: grid;
  grid-template-columns: 1.2fr 1fr;
  gap: 14px;
  align-items: start;

  @media (max-width: 992px) {
    grid-template-columns: 1fr;
  }
}

.cd__hero { display: flex; gap: 16px; }

.cd__cover {
  width: 110px;
  height: 110px;
  border-radius: 8px;
  object-fit: cover;
  flex-shrink: 0;
  background: $color-surface;
}

.cd__hero-info { flex: 1; min-width: 0; padding-top: 4px; }

.cd__name {
  font-size: 16px;
  font-weight: 700;
  display: flex;
  align-items: center;
  gap: 8px;
}

.cd__price { font-size: 20px; margin-top: 8px; }

.cd__sale-row {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-top: 10px;
}

.cd__switch-row {
  .v { flex: 1; }
}

.cd__switch-ops {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}

.cd__ops {
  // 功能按钮一行两个：两列等宽网格，按钮撑满单元格
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 8px;
  margin-top: 12px;

  // 重置 Element Plus 相邻按钮默认左边距（grid 布局下会错位），并让按钮等宽
  .el-button {
    width: 100%;
    margin-left: 0;
  }
}

.cd__audit {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 12px 4px;
  text-align: center;

  @media (min-width: 769px) {
    grid-template-columns: repeat(6, 1fr);
  }
}

.cd__audit-v { font-size: 16px; }
.cd__audit-l { font-size: 10px; color: $color-text-tertiary; margin-top: 2px; }

.cd__audit-formula {
  margin-top: 12px;
  padding: 8px 10px;
  border-radius: 8px;
  background: $color-surface;
  font-size: 11px;
  color: $color-text-secondary;
  line-height: 1.7;
}

.cd__quota {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 0;
  border-bottom: 1px solid $color-border;

  &:last-of-type { border-bottom: none; }
}

.cd__quota-body { flex: 1; min-width: 0; }
.cd__quota-name { font-size: 13px; font-weight: 600; color: $color-text-primary; }
.cd__quota-desc { font-size: 12px; color: $color-text-tertiary; margin-top: 3px; }

.cd__holder {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 9px 0;
  border-bottom: 1px solid $color-border;

  &:last-of-type { border-bottom: none; }
}

.cd__holder-body { flex: 1; min-width: 0; }
.cd__holder-name { font-size: 13px; font-weight: 600; color: $color-text-primary; }
.cd__holder-serial { font-size: 12px; color: $color-text-tertiary; margin-top: 2px; }

.cd__rank {
  width: 20px;
  height: 20px;
  border-radius: 6px;
  background: $color-surface;
  color: $color-text-tertiary;
  font-size: 11px;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;

  &.is-top {
    background: var(--color-primary-bg);
    color: $color-primary;
  }
}
</style>
