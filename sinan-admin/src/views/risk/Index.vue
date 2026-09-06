<script setup>
import { ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { getRiskAlerts, handleRiskAlert } from '@/api'
import AdminTablePage from '@/components/AdminTablePage.vue'
import StatusTag from '@/components/StatusTag.vue'
import { RISK_LEVEL, RISK_STATUS, RISK_TYPE } from '@/utils/maps'
import { maskPhone } from '@/utils/format'

const filters = [
  {
    field: 'status',
    label: '状态',
    options: [
      { value: 'pending', label: '待处理' },
      { value: 'processing', label: '处理中' },
      { value: 'resolved', label: '已处理' }
    ]
  },
  {
    field: 'level',
    label: '风险等级',
    options: [
      { value: 'high', label: '高风险' },
      { value: 'medium', label: '中风险' },
      { value: 'low', label: '低风险' }
    ]
  },
  {
    field: 'type',
    label: '告警类型',
    options: Object.entries(RISK_TYPE).map(([value, label]) => ({ value, label }))
  }
]

const listRef = ref(null)

// ---- 处理告警（填写结论） ----
async function onHandle(a) {
  const { value } = await ElMessageBox.prompt(
    `告警详情：${a.detail}`,
    `处理告警 · ${a.userName}`,
    {
      type: 'warning',
      inputPlaceholder: '请填写处理结论，如：核实为家人间流转，已放行',
      inputValidator: (v) => (v && v.trim() ? true : '处理结论不能为空')
    }
  )
  const res = await handleRiskAlert({ id: a.id, result: value.trim() })
  if (res.code === 0) {
    ElMessage.success('告警已处理，写入审计日志')
    listRef.value?.refresh()
  }
}
</script>

<template>
  <div class="adm-page rk">
    <AdminTablePage
      ref="listRef"
      :fetch="getRiskAlerts"
      :filters="filters"
      :defaults="{ status: 'pending' }"
      search-placeholder="搜索用户 / 手机号 / 详情"
    >
      <template #default="{ items }">
        <el-table-column label="风险用户" min-width="140" fixed="left">
          <template #default="{ row }">
            <div>{{ row.userName }}</div>
            <div class="t-tertiary" style="font-size: 12px">{{ maskPhone(row.userPhone) }}</div>
          </template>
        </el-table-column>
        <el-table-column label="告警类型" width="110" align="center">
          <template #default="{ row }">{{ RISK_TYPE[row.type] || row.type }}</template>
        </el-table-column>
        <el-table-column label="等级" width="90" align="center">
          <template #default="{ row }">
            <StatusTag :value="row.level" :map="RISK_LEVEL" />
          </template>
        </el-table-column>
        <el-table-column label="告警详情" min-width="260" show-overflow-tooltip>
          <template #default="{ row }">
            <span class="t-secondary">{{ row.detail }}</span>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="90" align="center">
          <template #default="{ row }">
            <StatusTag :value="row.status" :map="RISK_STATUS" />
          </template>
        </el-table-column>
        <el-table-column label="触发时间" prop="createTime" width="160" />
        <el-table-column label="处理信息" min-width="180" show-overflow-tooltip>
          <template #default="{ row }">
            <template v-if="row.status === 'resolved'">
              <div class="t-secondary">{{ row.result }}</div>
              <div class="t-tertiary" style="font-size: 12px">{{ row.handler }} · {{ row.handleTime }}</div>
            </template>
            <span v-else class="t-tertiary">-</span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="90" fixed="right">
          <template #default="{ row }">
            <el-button
              v-if="row.status !== 'resolved'"
              link
              type="primary"
              size="small"
              @click="onHandle(row)"
            >处理</el-button>
            <span v-else class="t-tertiary">已完结</span>
          </template>
        </el-table-column>
      </template>
    </AdminTablePage>

    <el-alert
      type="info"
      :closable="false"
      show-icon
      class="rk__tip"
      title="风控告警由异常交易、批量退款、批量注册、价格操纵等规则引擎触发；处理动作全部写入审计日志，可联动冻结用户 / 下架挂单"
    />
  </div>
</template>

<style scoped lang="scss">
.rk__tip {
  margin-top: 4px;
}
</style>
