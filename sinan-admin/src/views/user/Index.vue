<script setup>
import { ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { getUserList, getUserDetail, freezeUser, resetTradePwd } from '@/api'
import AdminTablePage from '@/components/AdminTablePage.vue'
import StatusTag from '@/components/StatusTag.vue'
import { USER_STATUS, REALNAME_STATUS } from '@/utils/maps'
import { fmtMoney } from '@/utils/format'

const drawerShow = ref(false)
const detail = ref(null)

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
}

async function onFreeze() {
  const u = detail.value
  const freezing = u.status === 'normal'
  await ElMessageBox.confirm(
    freezing
      ? `确认冻结「${u.nickname}」？冻结后该用户无法登录与交易。`
      : `确认解冻「${u.nickname}」？`,
    freezing ? '冻结账号' : '解冻账号',
    { type: 'warning', confirmButtonText: freezing ? '确认冻结' : '确认解冻' }
  )
  const res = await freezeUser(u.id)
  if (res.code === 0) {
    u.status = res.data
    ElMessage.success(res.data === 'normal' ? '已解冻' : '已冻结')
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
    <el-drawer v-model="drawerShow" :title="detail?.nickname || '用户详情'" size="420px">
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
          <div v-for="t in detail.transfers" :key="t.id" class="adm-kv">
            <span class="k" style="max-width: 60%">{{ t.title }}</span>
            <span class="v" :class="t.direction > 0 ? 't-success' : 't-primary'">
              {{ t.direction > 0 ? '+' : '-' }}{{ t.amount }}
            </span>
          </div>
          <el-empty v-if="!detail.transfers.length" description="暂无流水" :image-size="60" />
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

.user__actions {
  display: flex;
  gap: 10px;

  .el-button { flex: 1; }
}
</style>
