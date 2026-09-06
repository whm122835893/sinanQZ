<script setup>
import { ref, onMounted } from 'vue'
import { getAdmins, getRoles } from '@/api'
import StatusTag from '@/components/StatusTag.vue'
import { ROLE_MAP } from '@/utils/maps'
import { fmtNumber } from '@/utils/format'

const loading = ref(true)
const admins = ref([])
const rolesData = ref({ roles: [], tree: [] })

// ---- 角色权限明细 ----
const roleShow = ref(false)
const currentRole = ref(null)
const checked = ref([])

onMounted(async () => {
  const [a, r] = await Promise.all([getAdmins(), getRoles()])
  admins.value = a.data
  rolesData.value = r.data
  loading.value = false
})

function openRole(role) {
  currentRole.value = role
  checked.value = role.permissions.includes('*')
    ? rolesData.value.tree.map((t) => t.key)
    : [...role.permissions]
  roleShow.value = true
}
</script>

<template>
  <div class="adm-page ad">
    <el-skeleton v-if="loading" :rows="8" animated style="padding: 20px" />
    <template v-else>
      <!-- 管理员列表 -->
      <div class="adm-card">
        <div class="adm-card__title">管理员账号</div>
        <el-table :data="admins">
          <el-table-column label="管理员" min-width="180" fixed="left">
            <template #default="{ row }">
              <div class="ad__cell">
                <img class="ad__avatar" :src="row.avatar || '/images/platform-logo.png'" :alt="row.name" />
                <div>
                  <div class="ad__name">{{ row.name }}（{{ row.username }}）</div>
                  <div class="t-tertiary" style="font-size: 12px">{{ row.phone }}</div>
                </div>
              </div>
            </template>
          </el-table-column>
          <el-table-column label="角色" width="110" align="center">
            <template #default="{ row }">
              <StatusTag :value="row.role" :map="ROLE_MAP" />
            </template>
          </el-table-column>
          <el-table-column label="2FA" width="80" align="center">
            <template #default="{ row }">
              <el-tag :type="row.twofaEnabled ? 'success' : 'info'" effect="plain" size="small">
                {{ row.twofaEnabled ? '已启用' : '未启用' }}
              </el-tag>
            </template>
          </el-table-column>
          <el-table-column label="IP 白名单" min-width="130">
            <template #default="{ row }">
              <code v-if="row.ipWhitelist" class="ad__ip">{{ row.ipWhitelist }}</code>
              <span v-else class="t-tertiary">不限制</span>
            </template>
          </el-table-column>
          <el-table-column label="状态" width="90" align="center">
            <template #default="{ row }">
              <el-tag :type="row.status === 'enabled' ? 'success' : 'info'" effect="plain" size="small">
                {{ row.status === 'enabled' ? '启用' : '已停用' }}
              </el-tag>
            </template>
          </el-table-column>
          <el-table-column label="最近登录" prop="lastLoginTime" width="160" />
        </el-table>
      </div>

      <!-- 角色权限 -->
      <div class="adm-card">
        <div class="adm-card__title">
          角色与权限
          <span class="t-tertiary" style="font-size: 12px; font-weight: 400">点击角色查看权限明细</span>
        </div>
        <el-table :data="rolesData.roles" class="ad__roles" @row-click="openRole">
          <el-table-column label="角色" min-width="140">
            <template #default="{ row }">
              <div class="ad__role-name">
                <StatusTag :value="row.key" :map="ROLE_MAP" />
                <span style="font-weight: 600">{{ row.name }}</span>
              </div>
            </template>
          </el-table-column>
          <el-table-column label="职责范围" min-width="280" show-overflow-tooltip>
            <template #default="{ row }">
              <span class="t-secondary">{{ row.desc }}</span>
            </template>
          </el-table-column>
          <el-table-column label="成员数" width="90" align="center">
            <template #default="{ row }">{{ fmtNumber(row.members) }} 人</template>
          </el-table-column>
          <el-table-column label="" width="60" align="center">
            <template #default>
              <el-icon color="#999"><ArrowRight /></el-icon>
            </template>
          </el-table-column>
        </el-table>
        <el-alert
          type="info"
          :closable="false"
          show-icon
          class="ad__tip"
          title="角色权限为 Mock 演示，联调后由后端 RBAC 接口下发并强制校验；支持自定义角色与权限树动态分配"
        />
      </div>
    </template>

    <!-- 权限明细弹窗 -->
    <el-dialog v-model="roleShow" :title="currentRole ? `权限配置 · ${currentRole.name}` : ''" width="480px">
      <template v-if="currentRole">
        <div class="ad__perm-desc t-secondary">{{ currentRole.desc }}</div>
        <div class="ad__perms">
          <el-tag v-for="k in checked" :key="k" effect="plain" size="small" class="ad__perm">
            {{ rolesData.tree.find((t) => t.key === k)?.label || k }}
          </el-tag>
        </div>
        <div v-if="currentRole.permissions.includes('*')" class="t-tertiary" style="font-size: 12px; margin-top: 10px">
          * 超级管理员拥有全部权限（含平台清库、完整实名查看）
        </div>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped lang="scss">
.ad__cell {
  display: flex;
  align-items: center;
  gap: 10px;
}

.ad__avatar {
  width: 40px;
  height: 40px;
  border-radius: 50%;
  object-fit: cover;
  background: $color-surface;
  flex-shrink: 0;
}

.ad__name {
  font-weight: 600;
  color: $color-text-primary;
}

.ad__ip {
  font-family: 'JetBrains Mono', Consolas, monospace;
  font-size: 12px;
  background: $color-surface;
  padding: 2px 6px;
  border-radius: 4px;
}

.ad__roles {
  :deep(tbody tr) { cursor: pointer; }
}

.ad__role-name {
  display: flex;
  align-items: center;
  gap: 8px;
}

.ad__tip { margin-top: 12px; }

.ad__perm-desc {
  font-size: 13px;
  margin-bottom: 12px;
}

.ad__perms {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.ad__perm {
  background: $color-primary-bg;
  border-color: $color-primary-light;
  color: var(--color-primary);
}
</style>
