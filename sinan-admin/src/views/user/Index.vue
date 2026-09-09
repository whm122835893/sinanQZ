<script setup>
import { ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { getUserList, getUserDetail, getUserAssets, recoverUserCollectible, freezeUser, resetTradePwd } from '@/api'
import AdminTablePage from '@/components/AdminTablePage.vue'
import StatusTag from '@/components/StatusTag.vue'
import { USER_STATUS, REALNAME_STATUS } from '@/utils/maps'
import { fmtMoney } from '@/utils/format'

const drawerShow = ref(false)
const detail = ref(null)

// ---- 用户资产（回收入口） ----
const ASSET_STATUS = {
  held: { label: '持有中', type: 'success' },
  consigned: { label: '寄售中', type: 'warning' },
  frozen: { label: '冻结中', type: 'info' },
  recovered: { label: '已回收', type: 'danger' },
  consumed: { label: '已消耗', type: 'info' },
  transferred: { label: '已转赠', type: 'info' }
}
const SOURCE_LABEL = { purchase: '购买', airdrop: '空投', blindbox: '盲盒', synthesis: '合成', lucky_draw: '抽奖', transfer: '受赠' }
const ASSET_STATUS_OPTS = [
  { value: '', label: '有效持仓' },
  { value: 'held', label: '持有中' },
  { value: 'consigned', label: '寄售中' },
  { value: 'frozen', label: '冻结中' },
  { value: 'recovered', label: '已回收' },
  { value: 'consumed', label: '已消耗' },
  { value: 'transferred', label: '已转赠' }
]

const assets = ref({ list: [], total: 0 })
const assetsLoading = ref(false)
const assetStatus = ref('')
const assetPage = ref(1)

const filters = [
  {
    field: 'status',
    label: '状态',
    options: [
      { value: 'normal', label: '正常' },
      { value: 'frozen', label: '已冻结' }
    ]
  },
  {
    field: 'realnameStatus',
    label: '实名',
    options: [
      { value: 'approved', label: '已实名' },
      { value: 'pending', label: '待审核' },
      { value: 'rejected', label: '已驳回' },
      { value: 'none', label: '未实名' }
    ]
  }
]

async function openDetail(u) {
  const res = await getUserDetail(u.id)
  detail.value = res.data
  drawerShow.value = true
  assetStatus.value = ''
  assetPage.value = 1
  loadAssets()
}

async function loadAssets() {
  if (!detail.value) return
  assetsLoading.value = true
  try {
    const res = await getUserAssets(detail.value.id, {
      page: assetPage.value,
      pageSize: 20,
      status: assetStatus.value
    })
    if (res.code === 0) assets.value = res.data
  } finally {
    assetsLoading.value = false
  }
}

function onAssetStatusChange() {
  assetPage.value = 1
  loadAssets()
}

/** 强制回收（超卖/错空投/多合处置）：填原因 → 回收 → 按来源回退计数器 */
async function onRecover(a) {
  const { value } = await ElMessageBox.prompt(
    `确认回收「${a.name}」（${a.serial}）？回收后资产退出该用户账户，库存计数器按来源自动回退。`,
    '强制回收藏品',
    {
      type: 'warning',
      confirmButtonText: '确认回收',
      inputPlaceholder: '回收原因（必填，写入审计日志）',
      inputValidator: (v) => (v && v.trim() ? true : '回收原因必填')
    }
  )
  const res = await recoverUserCollectible({ id: a.id, reason: value.trim() })
  if (res.code === 0) {
    const d = res.data || {}
    ElMessage.success(d.counterReverted ? `已回收，已回退计数器（${d.counter}）` : '已回收（计数器守卫拦截，未回退）')
    loadAssets()
    // 刷新抽屉头部持仓统计
    const fresh = await getUserDetail(detail.value.id)
    if (fresh.code === 0) detail.value = fresh.data
  }
}

async function onFreeze() {
  const u = detail.value
  const freezing = u.status === 'normal'
  // 冻结必须填写原因（写入审计日志）；解冻直接确认
  let reason = ''
  if (freezing) {
    const { value } = await ElMessageBox.prompt(
      `确认冻结「${u.nickname}」？冻结后该用户无法登录与交易。`,
      '冻结账号',
      {
        type: 'warning',
        confirmButtonText: '确认冻结',
        inputPlaceholder: '冻结原因（必填，写入审计日志）',
        inputValidator: (v) => (v && v.trim() ? true : '冻结原因必填')
      }
    )
    reason = value.trim()
  } else {
    await ElMessageBox.confirm(
      `确认解冻「${u.nickname}」？`,
      '解冻账号',
      { type: 'warning', confirmButtonText: '确认解冻' }
    )
  }
  const res = await freezeUser(u.id, freezing, reason)
  if (res.code === 0) {
    u.status = freezing ? 'frozen' : 'normal'
    ElMessage.success(freezing ? '已冻结并强制下线' : '已解冻')
  }
}

async function onResetPwd() {
  await ElMessageBox.confirm(
    `确认重置「${detail.value.nickname}」的交易密码？重置后用户可重新设置。`,
    '重置交易密码',
    { type: 'warning' }
  )
  const res = await resetTradePwd(detail.value.id)
  if (res.code === 0) ElMessage.success('已重置')
}
</script>

<template>
  <div class="adm-page">
    <AdminTablePage :fetch="getUserList" :filters="filters" search-placeholder="搜索昵称 / 手机号 / UID">
      <template #default="{ items }">
        <el-table-column label="用户" min-width="200" fixed="left">
          <template #default="{ row }">
            <div class="u-cell" @click="openDetail(row)">
              <img class="u-avatar" :src="row.avatar" :alt="row.nickname" />
              <div>
                <div class="u-name">{{ row.nickname }}</div>
                <div class="u-sub">UID {{ row.id }} · {{ row.phone }}</div>
              </div>
            </div>
          </template>
        </el-table-column>

        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <StatusTag :value="row.status" :map="USER_STATUS" />
          </template>
        </el-table-column>

        <el-table-column label="实名" width="90">
          <template #default="{ row }">
            <StatusTag :value="row.realnameStatus" :map="REALNAME_STATUS" />
          </template>
        </el-table-column>

        <el-table-column label="余额（元）" width="110" align="right">
          <template #default="{ row }">
            <span class="price">{{ fmtMoney(row.balance) }}</span>
          </template>
        </el-table-column>

        <el-table-column label="藏品" width="70" align="center">
          <template #default="{ row }">{{ row.collectibleCount }}</template>
        </el-table-column>

        <el-table-column label="订单" width="70" align="center">
          <template #default="{ row }">{{ row.orderCount }}</template>
        </el-table-column>

        <el-table-column label="注册时间" width="110">
          <template #default="{ row }">{{ row.registerTime.slice(0, 10) }}</template>
        </el-table-column>

        <el-table-column label="最近登录" width="150" prop="lastLoginTime" />

        <el-table-column label="操作" width="80" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" size="small" @click="openDetail(row)">详情</el-button>
          </template>
        </el-table-column>
      </template>
    </AdminTablePage>

    <!-- 用户详情抽屉 -->
    <el-drawer v-model="drawerShow" :title="detail?.nickname || '用户详情'" size="520px">
      <template v-if="detail">
        <div class="adm-card" style="margin-bottom: 12px; box-shadow: none">
          <div class="adm-card__title">基础信息</div>
          <div class="adm-kv"><span class="k">用户 ID</span><span class="v">{{ detail.id }}</span></div>
          <div class="adm-kv"><span class="k">手机号</span><span class="v">{{ detail.phone }}</span></div>
          <div class="adm-kv"><span class="k">注册时间</span><span class="v">{{ detail.registerTime }}</span></div>
          <div class="adm-kv"><span class="k">最近登录</span><span class="v">{{ detail.lastLoginTime }}</span></div>
          <div class="adm-kv"><span class="k">实名状态</span><span class="v"><StatusTag :value="detail.realnameStatus" :map="REALNAME_STATUS" /></span></div>
          <div class="adm-kv" v-if="detail.realnameName"><span class="k">实名信息</span><span class="v">{{ detail.realnameName }}（{{ detail.realnameIdNo }}）</span></div>
          <div class="adm-kv"><span class="k">账号状态</span><span class="v"><StatusTag :value="detail.status" :map="USER_STATUS" /></span></div>
        </div>

        <div class="adm-card" style="margin-bottom: 12px; box-shadow: none">
          <div class="adm-card__title">资产概况</div>
          <div class="user__assets">
            <div class="user__asset">
              <div class="price">{{ fmtMoney(detail.balance) }}</div>
              <div class="t-tertiary">余额（元）</div>
            </div>
            <div class="user__asset">
              <div class="price">{{ detail.points }}</div>
              <div class="t-tertiary">司南币</div>
            </div>
            <div class="user__asset">
              <div class="price">{{ detail.collectibleCount }}</div>
              <div class="t-tertiary">藏品数</div>
            </div>
          </div>
        </div>

        <!-- 持有资产（回收入口） -->
        <div class="adm-card" style="margin-bottom: 12px; box-shadow: none">
          <div class="asset__head">
            <div class="adm-card__title">持有资产</div>
            <el-select v-model="assetStatus" size="small" style="width: 110px" @change="onAssetStatusChange">
              <el-option v-for="o in ASSET_STATUS_OPTS" :key="o.value" :value="o.value" :label="o.label" />
            </el-select>
          </div>
          <div v-loading="assetsLoading">
            <div v-for="a in assets.list" :key="a.id" class="asset__row">
              <img class="asset__cover" :src="a.cover" :alt="a.name" />
              <div class="asset__info">
                <div class="asset__name">{{ a.name }}</div>
                <div class="asset__sub">{{ a.serial }} · {{ SOURCE_LABEL[a.source] || a.source }} · {{ a.acquiredTime.slice(0, 10) }}</div>
              </div>
              <div class="asset__ops">
                <StatusTag :value="a.status" :map="ASSET_STATUS" />
                <el-button
                  v-if="['held', 'consigned', 'frozen'].includes(a.status)"
                  v-permission="'user:recover'"
                  link
                  type="danger"
                  size="small"
                  @click="onRecover(a)"
                >回收</el-button>
              </div>
            </div>
            <el-empty
              v-if="!assetsLoading && !assets.list.length"
              :description="assetStatus === '' ? '暂无有效持仓' : '该状态下暂无资产'"
              :image-size="60"
            />
            <div v-if="assets.total > 20" class="asset__pager">
              <el-pagination
                v-model:current-page="assetPage"
                :total="assets.total"
                :page-size="20"
                layout="prev, pager, next"
                small
                @current-change="loadAssets"
              />
            </div>
          </div>
        </div>

        <div class="adm-card" style="margin-bottom: 12px; box-shadow: none">
          <div class="adm-card__title">最近订单</div>
          <div v-for="o in detail.orders" :key="o.id" class="adm-kv">
            <span class="k" style="max-width: 60%">{{ o.collectibleName }} ×{{ o.quantity }}</span>
            <span class="v">¥{{ o.amount }} · {{ o.createTime.slice(5, 16) }}</span>
          </div>
          <el-empty v-if="!detail.orders.length" description="暂无订单" :image-size="60" />
        </div>

        <div class="adm-card" style="margin-bottom: 12px; box-shadow: none">
          <div class="adm-card__title">最近钱包流水</div>
          <div v-for="t in detail.walletLogs" :key="t.id" class="adm-kv">
            <span class="k" style="max-width: 60%">{{ t.title }}</span>
            <span class="v" :class="t.direction === 1 ? 't-success' : 't-primary'">
              {{ t.direction === 1 ? '+' : '-' }}{{ t.amount }}
            </span>
          </div>
          <el-empty v-if="!detail.walletLogs.length" description="暂无流水" :image-size="60" />
        </div>

        <div class="adm-card" style="margin-bottom: 12px; box-shadow: none">
          <div class="adm-card__title">最近转赠</div>
          <div v-for="t in detail.transfers" :key="t.id" class="adm-kv">
            <span class="k" style="max-width: 60%">{{ t.collectibleName }}（{{ t.isReceive ? '受赠' : '转出' }}）</span>
            <span class="v">{{ t.createTime.slice(5, 16) }}</span>
          </div>
          <el-empty v-if="!detail.transfers.length" description="暂无转赠" :image-size="60" />
        </div>

        <div class="user__actions">
          <el-button type="warning" plain @click="onResetPwd">重置交易密码</el-button>
          <el-button :type="detail.status === 'normal' ? 'danger' : 'primary'" @click="onFreeze">
            {{ detail.status === 'normal' ? '冻结账号' : '解冻账号' }}
          </el-button>
        </div>
      </template>
    </el-drawer>
  </div>
</template>

<style scoped lang="scss">
.u-cell {
  display: flex;
  align-items: center;
  gap: 10px;
  cursor: pointer;
}

.u-avatar {
  width: 38px;
  height: 38px;
  border-radius: 50%;
  object-fit: cover;
  flex-shrink: 0;
  border: 1px solid $color-border;
}

.u-name { font-size: 13px; font-weight: 600; color: $color-text-primary; }
.u-sub { font-size: 12px; color: $color-text-tertiary; margin-top: 2px; }

.user__assets {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 8px;
  text-align: center;
}

.user__asset .price { font-size: 17px; }
.user__asset .t-tertiary { font-size: 11px; margin-top: 3px; }

.asset__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 8px;
}

.asset__row {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 0;
  border-bottom: 1px solid $color-border;

  &:last-of-type { border-bottom: none; }
}

.asset__cover {
  width: 40px;
  height: 40px;
  border-radius: 6px;
  object-fit: cover;
  flex-shrink: 0;
  border: 1px solid $color-border;
}

.asset__info { flex: 1; min-width: 0; }
.asset__name {
  font-size: 13px;
  font-weight: 600;
  color: $color-text-primary;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.asset__sub { font-size: 12px; color: $color-text-tertiary; margin-top: 2px; }

.asset__ops {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-shrink: 0;
}

.asset__pager {
  display: flex;
  justify-content: center;
  padding-top: 8px;
}

.user__actions {
  display: flex;
  gap: 10px;

  .el-button { flex: 1; }
}
</style>
