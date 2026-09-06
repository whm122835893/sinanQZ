<script setup lang="ts">
/**
 * 用户管理 - 用户详情
 *
 * 数据源：GET /admin/user/detail/:id
 * 聚合内容：基础信息 + 钱包/订单统计 + 持仓统计 + 最近订单 + 最近转赠
 * 分组展示：基本信息 / 钱包信息 / 持仓列表
 */
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ArrowLeft, User } from '@element-plus/icons-vue'
import { fetchUserDetail } from '@/api/user'
import { USER_STATUS, UC_STATUS, money, datetime } from '@/utils/format'

/**
 * 订单状态（后端 orders.status 为字符串枚举，与 format.ts 数字键的 ORDER_STATUS 不匹配，故本地映射）
 */
const ORDER_STATUS_TEXT: Record<string, { text: string; type: string }> = {
  pending: { text: '待支付', type: 'warning' },
  completed: { text: '已完成', type: 'success' },
  cancelled: { text: '已取消', type: 'info' },
  refunding: { text: '退款中', type: 'primary' },
  refunded: { text: '已退款', type: 'danger' },
}

/** 转赠状态（后端 transfers.status 字符串枚举） */
const TRANSFER_STATUS_TEXT: Record<string, { text: string; type: string }> = {
  pending: { text: '待确认', type: 'warning' },
  accepted: { text: '已接收', type: 'success' },
  rejected: { text: '已拒绝', type: 'danger' },
  cancelled: { text: '已取消', type: 'info' },
}

const route = useRoute()
const router = useRouter()

const userId = Number(route.params.id)
const loading = ref(false)
const detail = ref<any>(null)

async function load() {
  if (!userId || Number.isNaN(userId)) return
  loading.value = true
  try {
    detail.value = await fetchUserDetail(userId)
  } catch {
    /* 错误已全局提示（如 4040 用户不存在） */
  } finally {
    loading.value = false
  }
}

onMounted(load)

function goBack() {
  router.back()
}

/** 持仓统计卡（文案与类型取自 format.ts 的 UC_STATUS 字典） */
const holdCards = computed(() => {
  const h = detail.value?.holdStats ?? {}
  return (['held', 'consigned', 'frozen'] as const).map((key) => ({
    key,
    label: UC_STATUS[key].text,
    value: h[key] ?? 0,
  }))
})
</script>

<template>
  <div class="page-container" v-loading="loading">
    <!-- 顶部：返回 -->
    <div class="page-header">
      <el-button :icon="ArrowLeft" @click="goBack">返回</el-button>
      <span class="page-title">用户详情</span>
      <span v-if="detail" class="page-subtitle">UID {{ detail.uid }} · {{ detail.username || '-' }}</span>
    </div>

    <template v-if="detail">
      <!-- 基本信息 -->
      <div class="detail-section">
        <div class="section-title">基本信息</div>
        <el-descriptions :column="3" border>
          <el-descriptions-item label="UID">{{ detail.uid }}</el-descriptions-item>
          <el-descriptions-item label="用户名">
            <div class="user-cell">
              <el-avatar :size="24" :src="detail.avatar">
                <el-icon><User /></el-icon>
              </el-avatar>
              <span>{{ detail.username || '-' }}</span>
            </div>
          </el-descriptions-item>
          <el-descriptions-item label="手机号">{{ detail.phone || '-' }}</el-descriptions-item>
          <el-descriptions-item label="账号状态">
            <el-tag :type="USER_STATUS[detail.status]?.type ?? 'info'" disable-transitions>
              {{ USER_STATUS[detail.status]?.text ?? '未知' }}
            </el-tag>
          </el-descriptions-item>
          <el-descriptions-item label="实名状态">
            <el-tag :type="Number(detail.isRealname) === 1 ? 'success' : 'info'" disable-transitions>
              {{ Number(detail.isRealname) === 1 ? '已实名' : '未实名' }}
            </el-tag>
          </el-descriptions-item>
          <el-descriptions-item label="交易密码">
            {{ detail.hasTransactionPassword ? '已设置' : '未设置' }}
          </el-descriptions-item>
          <el-descriptions-item label="真实姓名">{{ detail.realName || '-' }}</el-descriptions-item>
          <el-descriptions-item label="身份证号">{{ detail.idCard || '-' }}</el-descriptions-item>
          <el-descriptions-item label="登录次数">{{ detail.loginCount ?? 0 }}</el-descriptions-item>
          <el-descriptions-item label="黑名单">
            <el-tag v-if="Number(detail.isBlacklisted) === 1" type="danger" disable-transitions>黑名单用户</el-tag>
            <el-tag v-else type="success" disable-transitions>正常</el-tag>
          </el-descriptions-item>
          <el-descriptions-item label="最后登录时间">{{ datetime(detail.lastLoginAt) }}</el-descriptions-item>
          <el-descriptions-item label="注册时间">{{ datetime(detail.createdAt) }}</el-descriptions-item>
          <el-descriptions-item v-if="detail.blacklistReason" label="黑名单原因" :span="3">
            {{ detail.blacklistReason }}
          </el-descriptions-item>
        </el-descriptions>
      </div>

      <!-- 钱包信息 -->
      <div class="detail-section">
        <div class="section-title">钱包信息</div>
        <el-descriptions :column="4" border>
          <el-descriptions-item label="账户余额（元）">{{ money(detail.wallet?.balance) }}</el-descriptions-item>
          <el-descriptions-item label="可用余额（元）">{{ money(detail.wallet?.available) }}</el-descriptions-item>
          <el-descriptions-item label="冻结金额（元）">{{ money(detail.wallet?.frozen) }}</el-descriptions-item>
          <el-descriptions-item label="积分">{{ money(detail.wallet?.points) }}</el-descriptions-item>
          <el-descriptions-item label="订单总数">{{ detail.orderStats?.total ?? 0 }}</el-descriptions-item>
          <el-descriptions-item label="已完成订单">{{ detail.orderStats?.completed ?? 0 }}</el-descriptions-item>
          <el-descriptions-item label="累计消费（元）">{{ money(detail.orderStats?.paid) }}</el-descriptions-item>
        </el-descriptions>
      </div>

      <!-- 持仓列表 -->
      <div class="detail-section">
        <div class="section-title">持仓列表</div>

        <!-- 持仓统计 -->
        <div class="hold-grid">
          <div v-for="card in holdCards" :key="card.key" class="hold-item">
            <div class="hold-num">{{ card.value }}</div>
            <div class="hold-label">{{ card.label }}</div>
          </div>
        </div>

        <!-- 最近订单 -->
        <div class="sub-title">最近订单</div>
        <el-table :data="detail.recentOrders ?? []" size="small" row-key="id">
          <el-table-column prop="orderNo" label="订单号" min-width="200" show-overflow-tooltip />
          <el-table-column prop="collectibleName" label="藏品名称" min-width="160" show-overflow-tooltip />
          <el-table-column label="订单金额（元）" width="130" align="right">
            <template #default="{ row }">{{ money(row.totalPrice) }}</template>
          </el-table-column>
          <el-table-column label="状态" width="100" align="center">
            <template #default="{ row }">
              <el-tag :type="ORDER_STATUS_TEXT[row.status]?.type ?? 'info'" disable-transitions>
                {{ ORDER_STATUS_TEXT[row.status]?.text ?? row.status }}
              </el-tag>
            </template>
          </el-table-column>
          <el-table-column label="下单时间" width="170">
            <template #default="{ row }">{{ datetime(row.createdAt) }}</template>
          </el-table-column>
        </el-table>

        <!-- 最近转赠 -->
        <div class="sub-title">最近转赠</div>
        <el-table :data="detail.recentTransfers ?? []" size="small" row-key="id">
          <el-table-column prop="collectibleName" label="藏品名称" min-width="160" show-overflow-tooltip />
          <el-table-column label="方向" width="90" align="center">
            <template #default="{ row }">
              <el-tag :type="Number(row.isReceive) === 1 ? 'success' : 'warning'" disable-transitions>
                {{ Number(row.isReceive) === 1 ? '转入' : '转出' }}
              </el-tag>
            </template>
          </el-table-column>
          <el-table-column label="状态" width="100" align="center">
            <template #default="{ row }">
              <el-tag :type="TRANSFER_STATUS_TEXT[row.status]?.type ?? 'info'" disable-transitions>
                {{ TRANSFER_STATUS_TEXT[row.status]?.text ?? row.status }}
              </el-tag>
            </template>
          </el-table-column>
          <el-table-column label="时间" width="170">
            <template #default="{ row }">{{ datetime(row.createdAt) }}</template>
          </el-table-column>
        </el-table>
      </div>
    </template>

    <!-- 加载失败 / 用户不存在 -->
    <div v-else-if="!loading" class="detail-section">
      <el-empty description="用户信息加载失败或不存在">
        <el-button type="primary" @click="goBack">返回列表</el-button>
      </el-empty>
    </div>
  </div>
</template>

<style scoped lang="scss">
.page-header {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 12px;

  .page-title {
    font-size: 18px;
    font-weight: 600;
  }

  .page-subtitle {
    font-size: 13px;
    color: var(--sn-text-secondary);
  }
}

.user-cell {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
}

.hold-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 12px;
}

.hold-item {
  border: 1px solid var(--sn-border);
  border-radius: 8px;
  padding: 14px 16px;
  text-align: center;

  .hold-num {
    font-size: 26px;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
  }

  .hold-label {
    margin-top: 4px;
    font-size: 13px;
    color: var(--sn-text-secondary);
  }
}

.sub-title {
  font-size: 14px;
  font-weight: 600;
  margin: 18px 0 10px;
}
</style>
