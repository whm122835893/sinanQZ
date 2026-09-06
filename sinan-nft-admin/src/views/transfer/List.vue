<script setup lang="ts">
/**
 * 转赠列表
 *
 * - 搜索：用户UID（转赠人/受赠人）/ 藏品ID / 时间区间（后端 keyword / collectibleId / startDate+endDate）
 * - 表格：字段与 TransferController::list 返回严格一致（后端已驼峰化）
 * - 操作：撤销转赠（transfer:manage，需填写原因，仅待处理状态可撤销，资产解冻回转出方）
 */
import { onMounted } from 'vue'
import { ElMessageBox } from 'element-plus'
import { useListPage } from '@/utils/useListPage'
import { datetime, maskPhone } from '@/utils/format'
import { fetchTransfers, revokeTransfer } from '@/api/order'

/** 转赠状态（后端 nft_transfers.status 字符串枚举） */
const TRANSFER_STATUS: Record<string, { text: string; type: 'success' | 'warning' | 'info' | 'danger' }> = {
  pending: { text: '待确认', type: 'warning' },
  accepted: { text: '已接受', type: 'success' },
  rejected: { text: '已拒绝', type: 'danger' },
  cancelled: { text: '已取消', type: 'info' },
}

function statusTag(status?: string) {
  return TRANSFER_STATUS[status ?? ''] ?? { text: status || '-', type: 'info' as const }
}

interface TransferRow {
  id: number
  fromUserId: number
  fromUid: string
  fromName: string
  fromPhone: string
  toUserId: number
  toUid: string
  toName: string
  toPhone: string
  toNickname?: string
  collectibleId: number
  collectibleName: string
  image: string
  serial: string
  status: string
  createdAt: string
  confirmedAt?: string
}

/** 时间区间数组 → 后端 startDate/endDate，并剔除空参数 */
function withDateRange(fetcher: (p: Record<string, any>) => Promise<any>) {
  return (params: Record<string, any>) => {
    const { dateRange, ...rest } = params
    const query: Record<string, any> = {}
    Object.keys(rest).forEach((k) => {
      if (rest[k] !== '' && rest[k] !== null && rest[k] !== undefined) query[k] = rest[k]
    })
    if (Array.isArray(dateRange)) {
      if (dateRange[0]) query.startDate = dateRange[0]
      if (dateRange[1]) query.endDate = dateRange[1]
    }
    return fetcher(query)
  }
}

const pager = useListPage(withDateRange(fetchTransfers), {
  keyword: '',
  collectibleId: '',
})

onMounted(() => {
  pager.refresh()
})

function handlePageChange() {
  pager.load()
}

function handleSizeChange() {
  pager.page = 1
  pager.load()
}

/** 撤销待处理转赠（资产解冻回转出方，需填写原因） */
async function handleRevoke(row: TransferRow) {
  try {
    const { value } = await ElMessageBox.prompt(
      `撤销后转赠单取消，资产解冻回转赠人持有（藏品：${row.collectibleName || '-'} ${row.serial || ''}，受赠人：${row.toUid || row.toUserId}）`,
      `撤销转赠 · ID ${row.id}`,
      {
        confirmButtonText: '确认撤销',
        cancelButtonText: '取消',
        type: 'warning',
        inputPlaceholder: '请输入撤销原因（必填，将写入审计日志）',
        inputValidator: (v: string) => (v && v.trim() ? true : '撤销原因不能为空'),
      },
    )
    await revokeTransfer(row.id, value.trim())
    await pager.done('转赠已撤销，资产已解冻回转出方')
  } catch {
    /* 弹窗取消或接口失败（错误已全局提示） */
  }
}
</script>

<template>
  <div class="page-container">
    <!-- 搜索栏 -->
    <div class="search-bar">
      <el-form inline @submit.prevent>
        <el-form-item label="用户UID">
          <el-input
            v-model="pager.filters.keyword"
            placeholder="转赠人 / 受赠人 UID 或手机号"
            clearable
            style="width: 220px"
          />
        </el-form-item>
        <el-form-item label="藏品ID">
          <el-input
            v-model="pager.filters.collectibleId"
            placeholder="藏品ID（后端暂不支持名称筛选）"
            clearable
            style="width: 210px"
          />
        </el-form-item>
        <el-form-item label="发起时间">
          <el-date-picker
            v-model="pager.filters.dateRange"
            type="daterange"
            value-format="YYYY-MM-DD"
            range-separator="至"
            start-placeholder="开始日期"
            end-placeholder="结束日期"
            style="width: 240px"
          />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="pager.search">查询</el-button>
          <el-button @click="pager.reset">重置</el-button>
        </el-form-item>
      </el-form>
    </div>

    <!-- 表格卡片 -->
    <div class="table-card">
      <div class="table-header">
        <span class="table-title">转赠列表</span>
        <span class="table-tip">每笔转赠对应一份藏品资产（单对单），待确认期间资产处于冻结状态</span>
      </div>

      <el-table v-loading="pager.loading" :data="pager.list" border stripe>
        <el-table-column label="转赠人" min-width="130">
          <template #default="{ row }">
            <div>{{ row.fromUid || row.fromUserId }}</div>
            <div class="cell-sub">{{ row.fromName || '-' }}</div>
          </template>
        </el-table-column>
        <el-table-column label="受赠人" min-width="150">
          <template #default="{ row }">
            <div>{{ row.toUid || row.toUserId }}</div>
            <div class="cell-sub">
              {{ row.toName || row.toNickname || '-' }} · {{ maskPhone(row.toPhone) }}
            </div>
          </template>
        </el-table-column>
        <el-table-column label="藏品" min-width="200">
          <template #default="{ row }">
            <div class="collectible-cell">
              <el-image
                v-if="row.image"
                :src="row.image"
                :preview-src-list="[row.image]"
                preview-teleported
                fit="cover"
                class="table-img"
              />
              <div class="collectible-info">
                <div>{{ row.collectibleName || '-' }}</div>
                <div class="cell-sub">编号：{{ row.serial || '-' }}</div>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="100" align="center">
          <template #default="{ row }">
            <el-tag :type="statusTag(row.status).type" size="small">{{ statusTag(row.status).text }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="发起时间" width="170">
          <template #default="{ row }">{{ datetime(row.createdAt) }}</template>
        </el-table-column>
        <el-table-column label="确认时间" width="170">
          <template #default="{ row }">{{ datetime(row.confirmedAt) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="110" fixed="right">
          <template #default="{ row }">
            <el-button
              v-if="row.status === 'pending'"
              v-permission="'transfer:manage'"
              link
              type="danger"
              size="small"
              @click="handleRevoke(row)"
            >撤销转赠</el-button>
          </template>
        </el-table-column>
      </el-table>

      <div class="pagination-wrap">
        <el-pagination
          v-model:current-page="pager.page"
          v-model:page-size="pager.pageSize"
          :total="pager.total"
          :page-sizes="[10, 20, 50, 100]"
          layout="total, sizes, prev, pager, next, jumper"
          @current-change="handlePageChange"
          @size-change="handleSizeChange"
        />
      </div>
    </div>
  </div>
</template>

<style scoped lang="scss">
.table-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 14px;

  .table-title {
    font-size: 15px;
    font-weight: 600;
  }

  .table-tip {
    font-size: 12px;
    color: var(--sn-text-secondary);
  }
}

.collectible-cell {
  display: flex;
  align-items: center;
  gap: 10px;

  .collectible-info {
    min-width: 0;

    > div:first-child {
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
  }
}

.cell-sub {
  font-size: 12px;
  color: var(--sn-text-secondary);
}
</style>
