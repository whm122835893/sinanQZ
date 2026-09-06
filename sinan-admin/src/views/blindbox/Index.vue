<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import {
  getBlindBoxList,
  toggleBlindBoxStatus,
  toggleBlindBoxOpenable,
  toggleBlindBoxTransferable,
  toggleBlindBoxResale
} from '@/api'
import AdminTablePage from '@/components/AdminTablePage.vue'
import StatusTag from '@/components/StatusTag.vue'
import PasswordVerify from '@/components/PasswordVerify.vue'
import { COLLECTIBLE_STATUS } from '@/utils/maps'
import { fmtMoney, blindBoxPool } from '@/utils/format'

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
  }
]

// ---- 状态操作 ----
const actionMap = {
  online: { title: '重新上架盲盒', msg: (b) => `确认重新上架「${b.name}」？可重新配置发售参数后进入发售中。`, type: 'warning' },
  offline: { title: '下架盲盒', msg: (b) => `确认下架「${b.name}」？下架后 C 端不再展示。`, type: 'warning' },
  forceSoldout: { title: '强制售罄', msg: (b) => `确认将「${b.name}」标记为已售罄？发售停止，未售出的发售剩余保留在盲盒库存池中（${blindBoxPool(b)} 份），不清零。`, type: 'error' }
}

async function onAction(b, action) {
  const cfg = actionMap[action]
  await ElMessageBox.confirm(cfg.msg(b), cfg.title, { type: cfg.type })
  const res = await toggleBlindBoxStatus(b.id, action)
  if (res.code === 0) {
    b.status = res.data
    ElMessage.success('操作成功')
  }
}

// ---- 允许开启开关 ----
async function onToggleOpen(b, val) {
  await ElMessageBox.confirm(
    val ? `确认允许开启「${b.name}」？用户可开启盲盒获得子藏品。` : `确认暂停开启「${b.name}」？用户将无法开启盲盒（已持有的不受影响）。`,
    '开启开关',
    { type: 'warning' }
  )
  const res = await toggleBlindBoxOpenable(b.id)
  if (res.code === 0) ElMessage.success(res.data === 1 ? '已允许开启' : '已暂停开启')
  else b.isOpenable = val ? 0 : 1
}

// ---- 转赠开关（独立） ----
async function onTransferable(b, val) {
  await ElMessageBox.confirm(
    val
      ? `确认开启「${b.name}」的转赠开关？用户端将显示转赠入口。`
      : `确认关闭「${b.name}」的转赠开关？用户端转赠按钮置灰（已发起待确认的转赠不受影响）。`,
    '转赠开关',
    { type: 'warning' }
  )
  const res = await toggleBlindBoxTransferable(b.id, val)
  if (res.code === 0) ElMessage.success(val ? '已开启转赠' : '已关闭转赠')
  else b.isTransferable = val ? 0 : 1
}

// ---- 寄售开关（联动价格管控，需密码验证） ----
const priceShow = ref(false)
const priceForm = ref({ id: null, name: '', enabled: 1, priceMode: 'free', priceMin: null, priceMax: null })
const pwdShow = ref(false)

function openPrice(b) {
  priceForm.value = {
    id: b.id,
    name: b.name,
    enabled: b.isResaleable ? 1 : 0,
    priceMode: b.resalePriceMode || 'free',
    priceMin: b.resalePriceMin,
    priceMax: b.resalePriceMax
  }
  priceShow.value = true
}

async function onPriceSubmit() {
  const f = priceForm.value
  if (f.enabled && f.priceMode === 'limit') {
    if (f.priceMin == null || f.priceMax == null) return ElMessage.warning('限价模式需填写价格上限与下限')
    if (Number(f.priceMin) >= Number(f.priceMax)) return ElMessage.warning('价格下限需小于上限')
  }
  pwdShow.value = true
}

async function onPriceVerified() {
  const f = priceForm.value
  const res = await toggleBlindBoxResale({
    id: f.id,
    enabled: f.enabled,
    priceMode: f.enabled ? f.priceMode : 'free',
    priceMin: f.priceMode === 'limit' ? Number(f.priceMin) : null,
    priceMax: f.priceMode === 'limit' ? Number(f.priceMax) : null
  })
  if (res.code === 0) {
    ElMessage.success(f.enabled ? '已开启二级市场' : '已关闭二级市场，在售挂单已全部系统下架')
    priceShow.value = false
  }
}
</script>

<template>
  <div class="adm-page">
    <AdminTablePage :fetch="getBlindBoxList" :filters="filters" search-placeholder="搜索盲盒名称">
      <template #extra>
        <el-button type="primary" :icon="Plus" @click="router.push('/blindbox/edit')">新建盲盒</el-button>
      </template>

      <template #default="{ items }">
        <el-table-column label="盲盒" min-width="260" fixed="left">
          <template #default="{ row }">
            <div class="col-cell" @click="router.push(`/blindbox/detail/${row.id}`)">
              <img class="col-cover" :src="row.cover" :alt="row.name" />
              <div>
                <div class="col-name">
                  {{ row.name }}
                  <el-tag type="danger" effect="plain" size="small">盲盒</el-tag>
                </div>
                <div class="col-sub">奖池 {{ row.items?.length || 0 }} 个子藏品 · 已开启 {{ row.openedCount }} 份</div>
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
            <span :class="{ 't-danger': blindBoxPool(row) === 0 }">{{ blindBoxPool(row) }}</span>
          </template>
        </el-table-column>

        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <StatusTag :value="row.status" :map="COLLECTIBLE_STATUS" />
          </template>
        </el-table-column>

        <el-table-column label="开启 / 转赠 / 寄售" width="170" align="center">
          <template #default="{ row }">
            <div class="col-switches">
              <el-switch
                :model-value="row.isOpenable === 1"
                size="small"
                inline-prompt
                active-text="开"
                inactive-text="开"
                @change="(v) => onToggleOpen(row, v)"
              />
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
          </template>
        </el-table-column>

        <el-table-column label="操作" width="170" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" size="small" @click="router.push(`/blindbox/detail/${row.id}`)">详情</el-button>
            <el-button
              v-if="row.status === 'offline' || row.status === 'soldout'"
              link type="success" size="small"
              @click="onAction(row, 'online')"
            >重新上架</el-button>
            <el-button
              v-if="row.status === 'onsale'"
              link type="warning" size="small"
              @click="onAction(row, 'forceSoldout')"
            >强制售罄</el-button>
            <el-button
              v-if="row.status === 'onsale'"
              link size="small"
              @click="onAction(row, 'offline')"
            >下架</el-button>
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
            关闭后该盲盒所有在售挂单将全部系统下架，用户无法重新上架
          </div>
        </el-form-item>
        <template v-if="priceForm.enabled">
          <el-form-item label="价格管控模式">
            <el-radio-group v-model="priceForm.priceMode">
              <el-radio value="limit">限价模式</el-radio>
              <el-radio value="free">不限价模式</el-radio>
            </el-radio-group>
          </el-form-item>
          <template v-if="priceForm.priceMode === 'limit'">
            <el-form-item label="价格下限（元）">
              <el-input-number v-model="priceForm.priceMin" :min="0.01" :precision="2" :step="10" style="width: 180px" />
            </el-form-item>
            <el-form-item label="价格上限（元）">
              <el-input-number v-model="priceForm.priceMax" :min="0.01" :precision="2" :step="10" style="width: 180px" />
            </el-form-item>
            <el-alert type="info" :closable="false" show-icon title="用户挂单价格必须落在上下限闭区间内" />
          </template>
          <el-alert v-else type="info" :closable="false" show-icon title="不限价模式：用户自由定价，仅保留全局最大金额校验" />
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
  gap: 6px;
}

.t-danger { color: $color-primary; }
</style>
