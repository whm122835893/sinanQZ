<script setup>
import { ref } from 'vue'
import { getLoginLogs, getOperationLogs } from '@/api'
import AdminTablePage from '@/components/AdminTablePage.vue'

const activeTab = ref('operation')
</script>

<template>
  <div class="adm-page lg">
    <el-tabs v-model="activeTab">
      <!-- 操作日志 -->
      <el-tab-pane label="操作日志" name="operation" lazy>
        <AdminTablePage :fetch="getOperationLogs" search-placeholder="搜索管理员 / 模块 / 操作">
          <template #default="{ items }">
            <el-table-column label="管理员" width="120" fixed="left">
              <template #default="{ row }">
                <b>{{ row.admin }}</b>
              </template>
            </el-table-column>
            <el-table-column label="模块" width="110" align="center">
              <template #default="{ row }">
                <el-tag type="primary" effect="plain" size="small">{{ row.module }}</el-tag>
              </template>
            </el-table-column>
            <el-table-column label="操作" prop="action" width="140" />
            <el-table-column label="明细" min-width="280" show-overflow-tooltip>
              <template #default="{ row }">
                <span class="t-secondary">{{ row.detail }}</span>
              </template>
            </el-table-column>
            <el-table-column label="IP" prop="ip" width="130" />
            <el-table-column label="时间" prop="time" width="160" />
          </template>
        </AdminTablePage>
      </el-tab-pane>

      <!-- 登录日志 -->
      <el-tab-pane label="登录日志" name="login" lazy>
        <AdminTablePage :fetch="getLoginLogs" search-placeholder="搜索账号 / IP">
          <template #default="{ items }">
            <el-table-column label="管理员" width="160" fixed="left">
              <template #default="{ row }">
                <b>{{ row.name }}</b>（{{ row.username }}）
              </template>
            </el-table-column>
            <el-table-column label="结果" width="100" align="center">
              <template #default="{ row }">
                <el-tag :type="row.result === 'success' ? 'success' : 'danger'" effect="plain" size="small">
                  {{ row.result === 'success' ? '登录成功' : '登录失败' }}
                </el-tag>
              </template>
            </el-table-column>
            <el-table-column label="属地" prop="location" min-width="140" />
            <el-table-column label="IP" prop="ip" width="130" />
            <el-table-column label="时间" prop="time" width="160" />
          </template>
        </AdminTablePage>
      </el-tab-pane>
    </el-tabs>

    <el-alert
      type="info"
      :closable="false"
      show-icon
      class="lg__tip"
      title="审计日志全覆盖：后台修改、批量导入、空投发放、活动配置、白名单操作、实名完整查看、强制回收、平台清库等操作均记录；支持按操作人 / 模块 / 时间范围筛选导出"
    />
  </div>
</template>

<style scoped lang="scss">
.lg__tip { margin-top: 4px; }
</style>
