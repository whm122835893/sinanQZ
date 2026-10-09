<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import {
  getCollectibleList,
  toggleCollectibleStatus,
  toggleCollectibleResale,
  toggleCollectibleTransferable,
  moveCollectibleMarket,
  toggleCollectibleMarketRecommend,
  toggleCollectibleHomeCarousel,
  mintOnChain
} from '@/api'
import AdminTablePage from '@/components/AdminTablePage.vue'
import StatusTag from '@/components/StatusTag.vue'
import PasswordVerify from '@/components/PasswordVerify.vue'
import { COLLECTIBLE_STATUS, RESALE_PRICE_MODE } from '@/utils/maps'
import { fmtMoney, stockPool } from '@/utils/format'

const router = useRouter()

const filters = [
  {
    field: 'status',
    label: '状态',
    options: [
      { value: 'onsale', label: '发售中' },
      { value: 'upcoming', label: '待发售' },
      { value: 'soldout', label: '已售罄' },
      { value: 'offline', label: '已下架' }
    ]
  },
  {
    field: 'category',
    label: '分类',
    options: [
      { value: '青铜', label: '青铜' },
      { value: '水墨', label: '水墨' },
      { value: '国潮', label: '国潮' },
      { value: '限定', label: '限定' },
      { value: '盲盒', label: '盲盒' }
    ]
  },
  {
    // 归属市场（后端 market_type）：两个市场数据互不相通，靠「移动市场」互相调仓
    field: 'marketType',
    label: '归属市场',
    options: [
      { value: 'activity', label: '活动市场' },
      { value: 'free', label: '自由市场' }
    ]
  }
]

// ---- 状态操作 ----
const actionMap = {
  forceSoldout: { title: '强制售罄', msg: (c) => `确认将「${c.name}」标记为已售罄？发售停止，未售出的发售剩余保留在库存池中（${stockPool(c)} 份），不清零。`, type: 'error' }
}

async function onAction(c, action) {
  const cfg = actionMap[action]
  await ElMessageBox.confirm(cfg.msg(c), cfg.title, { type: cfg.type })
  const res = await toggleCollectibleStatus(c.id, action)
  if (res.code === 0) {
    c.status = res.data
    ElMessage.success('操作成功')
  }
}

// ---- 上架售卖开关（创建后不自动发售，由此开关控制）----
async function onSaleSwitch(c, val) {
  if (val) {
    await ElMessageBox.confirm(
      `确认上架售卖「${c.name}」？上架后 C 端立即可见并可购买（需库存池大于 0）。`,
      '上架售卖',
      { type: 'warning' }
    )
    const res = await toggleCollectibleStatus(c.id, 'online')
    if (res.code === 0) {
      c.status = 'onsale'
      ElMessage.success('已上架售卖')
    }
  } else {
    await ElMessageBox.confirm(
      `确认下架「${c.name}」？下架后 C 端不再展示，未售出库存保留在库存池。`,
      '下架藏品',
      { type: 'warning' }
    )
    const res = await toggleCollectibleStatus(c.id, 'offline')
    if (res.code === 0) {
      c.status = 'offline'
      ElMessage.success('已下架')
    }
  }
}

// ---- 转赠开关（独立） ----
async function onTransferable(c, val) {
  await ElMessageBox.confirm(
    val
      ? `确认开启「${c.name}」的转赠开关？用户端将显示转赠入口。`
      : `确认关闭「${c.name}」的转赠开关？用户端转赠按钮置灰（已发起待确认的转赠不受影响）。`,
    '转赠开关',
    { type: 'warning' }
  )
  const res = await toggleCollectibleTransferable(c.id, val)
  if (res.code === 0) ElMessage.success(val ? '已开启转赠' : '已关闭转赠')
  else c.isTransferable = val ? 0 : 1
}

// ---- 寄售开关（联动价格管控：0=不限价 1=固定价 2=区间价） ----
const priceShow = ref(false)
const priceForm = ref({ id: null, name: '', enabled: 1, priceMode: 0, priceMin: null, priceMax: null })
const pwdShow = ref(false)

function openPrice(c) {
  priceForm.value = {
    id: c.id,
    name: c.name,
    enabled: c.isResaleable ? 1 : 0,
    priceMode: Number(c.resalePriceMode) || 0,
    priceMin: c.resalePriceMin ?? null,
    priceMax: c.resalePriceMax ?? null
  }
  priceShow.value = true
}

async function onPriceSubmit() {
  const f = priceForm.value
  if (f.enabled) {
    if (f.priceMode === 1 && f.priceMin == null) return ElMessage.warning('固定价模式需填写寄售价格')
    if (f.priceMode === 2) {
      if (f.priceMin == null || f.priceMax == null) return ElMessage.warning('区间价模式需填写价格上下限')
      if (Number(f.priceMin) >= Number(f.priceMax)) return ElMessage.warning('价格下限需小于上限')
    }
  }
  pwdShow.value = true
}

async function onPriceVerified() {
  const f = priceForm.value
  const res = await toggleCollectibleResale({
    id: f.id,
    enabled: f.enabled,
    priceMode: f.enabled ? f.priceMode : 0,
    priceMin: f.enabled && f.priceMode >= 1 ? Number(f.priceMin) : null,
    priceMax: f.enabled && f.priceMode === 2 ? Number(f.priceMax) : null
  })
  if (res.code === 0) {
    ElMessage.success(f.enabled ? '已开启二级市场' : '已关闭二级市场，在售挂单已全部系统下架')
    priceShow.value = false
  }
}

// ---- 市场归属与推荐（2026-10-09：活动市场 / 自由市场数据互不相通）----
const MARKET_LABEL = { activity: '活动市场', free: '自由市场' }

/** 移动市场：只改 market_type，挂单/价格/库存都不动；移到自由市场时后端会清掉推荐标记 */
async function onMoveMarket(c) {
  const target = c.marketType === 'activity' ? 'free' : 'activity'
  try {
    await ElMessageBox.confirm(
      `确认把「${c.name}」从${MARKET_LABEL[c.marketType] || '未知市场'}移动到${MARKET_LABEL[target]}？` +
      '两个市场的列表数据互不相通，移动后该藏品（连同其寄售挂单）只出现在' + MARKET_LABEL[target] + '列表。' +
      (target === 'free' ? '推荐标记会一并清除（推荐分类只有活动市场有）。' : ''),
      '移动市场',
      { type: 'warning' }
    )
  } catch {
    return
  }
  const res = await moveCollectibleMarket(c.id, target)
  if (res.code === 0) {
    c.marketType = target
    if (target !== 'activity') c.isMarketRecommended = false
    ElMessage.success(res.message || `已移动到${MARKET_LABEL[target]}`)
  } else {
    ElMessage.error(res.message || '移动失败')
  }
}

/** 上/下推荐：el-switch 为受控组件（model-value 不变则不翻转），失败无需回滚 */
async function onRecommend(c, val) {
  if (val && c.marketType !== 'activity') {
    return ElMessage.warning('仅活动市场藏品可上推荐，请先移回活动市场')
  }
  // C 端市场展示门槛（2026-10-09 定稿）：只看那枚「寄售开关」，不看发售状态。
  // 开关关着时上推荐是无效的，提前在确认框里说清楚，省得运营以为改坏了。
  const marketHidden = !c.isResaleable
  const hiddenTip = marketHidden
    ? '注意：该藏品当前不会出现在 C 端市场（需先打开「转赠 / 寄售」列的寄售开关），上推荐也看不到。'
    : ''
  try {
    await ElMessageBox.confirm(
      val
        ? `确认将「${c.name}」上推荐？C 端活动市场的「推荐」分类下会展示该藏品（需后台「系统 → 全局参数 → 寄售市场 → 活动市场推荐分类」开关处于开启状态）。` + hiddenTip
        : `确认取消「${c.name}」的推荐？C 端「推荐」分类将不再展示。`,
      '市场推荐',
      { type: 'warning' }
    )
  } catch {
    return
  }
  const res = await toggleCollectibleMarketRecommend(c.id, val)
  if (res.code === 0) {
    c.isMarketRecommended = !!val
    ElMessage.success(val ? '已上推荐' : '已取消推荐')
  } else {
    ElMessage.error(res.message || '操作失败')
  }
}

/** 上/下首页轮播位：与市场推荐独立，可同时开启；轮播图取藏品封面，点击跳到市场里该藏品的寄售页 */
async function onHomeCarousel(c, val) {
  try {
    await ElMessageBox.confirm(
      val
        ? `确认将「${c.name}」放上首页轮播？C 端首页轮播会把它排在最前面，用藏品封面图，左上角带「推荐藏品」角标，点击跳到市场里该藏品的寄售页（与市场卡片同一个入口）。` +
          (c.isResaleable ? '' : '注意：该藏品的寄售开关当前是关的，市场页会是「暂无寄售挂单」的空态。')
        : `确认取消「${c.name}」的首页轮播位？C 端首页轮播将不再展示该藏品（活动市场的推荐分类不受影响）。`,
      '首页轮播',
      { type: 'warning' }
    )
  } catch {
    return
  }
  const res = await toggleCollectibleHomeCarousel(c.id, val)
  if (res.code === 0) {
    c.isHomeCarouselRecommended = !!val
    ElMessage.success(val ? '已上首页轮播' : '已取消首页轮播')
  } else {
    ElMessage.error(res.message || '操作失败')
  }
}

// ---- 上链铸造（藏品已配置上链链时可用；为全部未上链持仓生成链上凭证，幂等） ----
const CHAIN_LABELS = { wenchang: '文昌链', consortium: '联盟链', antchain: '蚂蚁链' }
const mintingId = ref(0)

async function onMint(c) {
  try {
    await ElMessageBox.confirm(
      `确认为「${c.name}」的全部未上链持仓发起铸造？将按 ${CHAIN_LABELS[c.chainType] || c.chainType} 生成链上凭证（交易哈希 / Token ID）。已上链的持仓自动跳过。`,
      '上链铸造',
      { type: 'warning' }
    )
  } catch {
    return
  }
  mintingId.value = c.id
  const res = await mintOnChain(c.id)
  mintingId.value = 0
  if (res.code === 0) {
    ElMessage.success(res.message || '铸造完成')
  } else if (res.code !== -1) {
    ElMessage.error(res.message || '上链铸造失败')
  }
}
</script>

<template>
  <div class="adm-page">
    <AdminTablePage :fetch="getCollectibleList" :filters="filters" search-placeholder="搜索藏品名称 / 系列">
      <template #extra>
        <el-button type="primary" :icon="Plus" @click="router.push('/collectible/edit')">新建藏品</el-button>
      </template>

      <template #default="{ items }">
        <el-table-column label="藏品" min-width="240" fixed="left">
          <template #default="{ row }">
            <div class="col-cell" @click="router.push(`/collectible/detail/${row.id}`)">
              <img class="col-cover" :src="row.cover" :alt="row.name" />
              <div>
                <div class="col-name">
                  {{ row.name }}
                  <el-tag v-if="row.tag" type="primary" effect="plain" size="small">{{ row.tag }}</el-tag>
                </div>
                <div class="col-sub">{{ row.subtitle }} · {{ row.category }}</div>
              </div>
            </div>
          </template>
        </el-table-column>

        <el-table-column label="价格" width="100" align="right">
          <template #default="{ row }">
            <span class="price">¥{{ fmtMoney(row.price) }}</span>
          </template>
        </el-table-column>

        <el-table-column label="发行 / 流通" width="110">
          <template #default="{ row }">
            <div>{{ row.edition }} / {{ row.circulate }}</div>
          </template>
        </el-table-column>

        <el-table-column label="销售进度" width="170">
          <template #default="{ row }">
            <div class="col-progress">
              <el-progress
                :percentage="Number((row.sold / row.edition * 100).toFixed(1))"
                :stroke-width="6"
                :show-text="false"
                class="col-progress__bar"
              />
              <span class="col-progress__text">{{ row.sold }}/{{ row.edition }}</span>
            </div>
          </template>
        </el-table-column>

        <el-table-column label="库存池" width="80" align="center">
          <template #default="{ row }">
            <span :class="{ 't-danger': stockPool(row) === 0 }">{{ stockPool(row) }}</span>
          </template>
        </el-table-column>

        <el-table-column label="状态" width="120">
          <template #default="{ row }">
            <div class="col-status">
              <StatusTag :value="row.status" :map="COLLECTIBLE_STATUS" />
              <el-switch
                :model-value="row.status === 'onsale'"
                size="small"
                inline-prompt
                active-text="售"
                inactive-text="售"
                @change="(v) => onSaleSwitch(row, v)"
              />
            </div>
          </template>
        </el-table-column>

        <el-table-column label="市场 / 推荐" width="150" align="center">
          <template #default="{ row }">
            <el-tag :type="row.marketType === 'free' ? 'success' : 'warning'" effect="plain" size="small">
              {{ MARKET_LABEL[row.marketType] || '活动市场' }}
            </el-tag>
            <!-- 推 = 活动市场「推荐」分类；播 = 首页轮播位。两枚开关互相独立，可同时开 -->
            <div class="col-switches" style="margin-top: 4px">
              <el-switch
                :model-value="!!row.isMarketRecommended"
                size="small"
                inline-prompt
                active-text="推"
                inactive-text="推"
                :disabled="row.marketType !== 'activity'"
                @change="(v) => onRecommend(row, v)"
              />
              <el-switch
                :model-value="!!row.isHomeCarouselRecommended"
                size="small"
                inline-prompt
                active-text="播"
                inactive-text="播"
                @change="(v) => onHomeCarousel(row, v)"
              />
            </div>
          </template>
        </el-table-column>

        <el-table-column label="转赠 / 寄售" width="130" align="center">
          <template #default="{ row }">
            <div class="col-switches">
              <el-switch
                :model-value="!!row.isTransferable"
                size="small"
                inline-prompt
                active-text="赠"
                inactive-text="赠"
                @change="(v) => onTransferable(row, v)"
              />
              <el-switch
                :model-value="!!row.isResaleable"
                size="small"
                inline-prompt
                active-text="售"
                inactive-text="售"
                @change="openPrice(row)"
              />
            </div>
            <el-tag
              v-if="row.isResaleable"
              :type="RESALE_PRICE_MODE[row.resalePriceMode]?.type || 'info'"
              effect="plain"
              size="small"
              style="margin-top: 4px"
            >
              {{ RESALE_PRICE_MODE[row.resalePriceMode]?.label || '不限价' }}
            </el-tag>
          </template>
        </el-table-column>

        <el-table-column label="操作" width="250" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" size="small" @click="router.push(`/collectible/detail/${row.id}`)">详情</el-button>
            <el-button link type="primary" size="small" @click="router.push(`/collectible/edit/${row.id}`)">编辑</el-button>
            <el-button
              v-if="row.chainType"
              link type="success" size="small"
              :loading="mintingId === row.id"
              @click="onMint(row)"
            >上链</el-button>
            <el-button
              v-if="row.status === 'onsale'"
              link type="warning" size="small"
              @click="onAction(row, 'forceSoldout')"
            >强制售罄</el-button>
            <el-button link type="info" size="small" @click="onMoveMarket(row)">
              移至{{ row.marketType === 'activity' ? '自由市场' : '活动市场' }}
            </el-button>
          </template>
        </el-table-column>
      </template>
    </AdminTablePage>

    <!-- 寄售开关 & 价格管控 -->
    <el-dialog v-model="priceShow" :title="`二级市场管控 · ${priceForm.name}`" width="480px" :close-on-click-modal="false">
      <el-form label-width="110px">
        <el-form-item label="二级市场">
          <el-switch v-model="priceForm.enabled" :active-value="1" :inactive-value="0" />
          <div class="t-tertiary" style="font-size: 12px; margin-top: 4px">
            开启后该藏品就会出现在 C 端市场列表（不看发售状态，也无需等用户挂单）；<br />
            关闭后该藏品所有在售挂单将全部系统下架，用户无法重新上架，并从市场列表消失
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
        <el-button type="primary" @click="onPriceSubmit">提交（需密码验证）</el-button>
      </template>
    </el-dialog>

    <PasswordVerify
      v-model="pwdShow"
      title="二级市场管控验证"
      tip="寄售开关与价格管控影响二级市场流通，需管理员密码验证并写入审计日志"
      @verified="onPriceVerified"
    />
  </div>
</template>

<style scoped lang="scss">
.col-cell {
  display: flex;
  align-items: center;
  gap: 10px;
  cursor: pointer;
}

.col-cover {
  width: 44px;
  height: 44px;
  border-radius: 8px;
  object-fit: cover;
  flex-shrink: 0;
  border: 1px solid $color-border;
}

.col-name { font-size: 13px; font-weight: 600; color: $color-text-primary; display: flex; align-items: center; gap: 6px; }
.col-sub { font-size: 12px; color: $color-text-tertiary; margin-top: 3px; }

.col-progress {
  display: flex;
  align-items: center;
  gap: 8px;
}

.col-progress__bar { flex: 1; }

.col-progress__text {
  font-size: 11px;
  color: $color-text-tertiary;
  font-family: $font-price;
  white-space: nowrap;
}

.col-switches {
  display: flex;
  justify-content: center;
  gap: 8px;
}

.col-status {
  display: flex;
  align-items: center;
  gap: 8px;
}

.t-danger { color: $color-primary; }
</style>
