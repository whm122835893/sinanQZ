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
  releaseCollectible
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
              <div class="cd__price price">¥{{ fmtMoney(detail.price) }}</div>
              <div class="cd__ops">
                <el-button type="primary" plain @click="airShow = true">独立空投</el-button>
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
            <div class="adm-kv"><span class="k">转赠开关</span><span class="v">{{ detail.isTransferable ? '已开启' : '已关闭' }}</span></div>
            <div class="adm-kv"><span class="k">寄售开关</span><span class="v">{{ detail.isResaleable ? `已开启（${detail.resalePriceMode === 'limit' ? `限价 ¥${detail.resalePriceMin}-¥${detail.resalePriceMax}` : '不限价'}）` : '已关闭' }}</span></div>
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
      <el-dialog v-model="airShow" title="独立空投" width="480px" :close-on-click-modal="false">
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
            <el-input-number v-model="airForm.quantity" :min="1" :max="stockPool(detail)" />
          </el-form-item>
        </el-form>
        <el-alert type="info" :closable="false" show-icon title="空投从库存池扣减、已独立空投增加、发放资产到用户仓库，生成发放记录并写入审计日志" />
        <template #footer>
          <el-button @click="airShow = false">取消</el-button>
          <el-button type="primary" @click="onAirdropSubmit">下一步（确认摘要）</el-button>
        </template>
      </el-dialog>

      <!-- 空投二次确认摘要 -->
      <el-dialog v-model="airConfirmShow" title="空投确认" width="420px">
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
      <el-dialog v-model="destroyShow" title="销毁库存" width="440px" :close-on-click-modal="false">
        <el-form label-width="90px">
          <el-form-item label="当前库存池">
            <el-tag type="warning" effect="plain">{{ stockPool(detail) }} 份（配额预留不可销毁）</el-tag>
          </el-form-item>
          <el-form-item label="销毁份数">
            <el-input-number v-model="destroyQty" :min="1" :max="stockPool(detail)" />
          </el-form-item>
        </el-form>
        <el-alert type="error" :closable="false" show-icon title="销毁从库存池扣减且不可恢复，需管理员密码验证，生成销毁记录" />
        <template #footer>
          <el-button @click="destroyShow = false">取消</el-button>
          <el-button type="danger" @click="onDestroySubmit">销毁（需密码验证）</el-button>
        </template>
      </el-dialog>

      <!-- 新增配额弹窗 -->
      <el-dialog v-model="quotaShow" title="新增配额" width="460px" :close-on-click-modal="false">
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
            <el-input-number v-model="quotaForm.quantity" :min="1" :max="stockPool(detail)" />
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
      <el-dialog v-model="releaseShow" title="发售配置" width="460px" :close-on-click-modal="false">
        <el-form label-width="100px">
          <el-form-item label="当前库存池">
            <el-tag type="warning" effect="plain">{{ stockPool(detail) }} 份（发售数量不可超过库存池）</el-tag>
          </el-form-item>
          <el-form-item label="发售数量">
            <el-input-number v-model="releaseForm.saleQuantity" :min="1" :max="stockPool(detail)" />
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

      <!-- 密码验证 -->
      <PasswordVerify v-model="airPwdShow" title="空投验证" @verified="onAirdropVerified" />
      <PasswordVerify v-model="destroyPwdShow" title="销毁验证" @verified="onDestroyVerified" />
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

.cd__ops {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 12px;
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
