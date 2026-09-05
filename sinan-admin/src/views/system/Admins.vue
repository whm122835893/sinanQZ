<script setup>
import { ref, onMounted } from 'vue'
import { getAdmins, getRoles } from '@/api'
import DetailSheet from '@/components/DetailSheet.vue'
import { ROLE_MAP } from '@/utils/maps'
import { fmtDateTime } from '@/utils/format'

const loading = ref(true)
const admins = ref([])
const rolesData = ref({ roles: [], tree: [] })

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
    <van-skeleton v-if="loading" title :row="6" style="padding: 16px" />
    <template v-else>
      <!-- 管理员列表 -->
      <div class="adm-card">
        <div class="adm-card__title">管理员账号</div>
        <div v-for="a in admins" :key="a.id" class="adm-item">
          <img class="ad__avatar" :src="a.avatar || '/images/platform-logo.png'" :alt="a.name" />
          <div class="adm-item__body">
            <div class="adm-item__title">
              {{ a.name }}（{{ a.username }}）
              <van-tag :type="ROLE_MAP[a.role]?.type || 'default'" plain round size="medium">
                {{ ROLE_MAP[a.role]?.label || a.role }}
              </van-tag>
              <van-tag v-if="a.status === 'disabled'" type="default" plain round size="medium">已停用</van-tag>
            </div>
            <div class="adm-item__desc">{{ a.phone }}</div>
            <div class="adm-item__desc">最近登录 {{ a.lastLoginTime }}</div>
          </div>
        </div>
      </div>

      <!-- 角色权限 -->
      <div class="adm-card">
        <div class="adm-card__title">
          角色与权限
          <span class="t-tertiary" style="font-size: 11px; font-weight: 400">点击角色查看权限明细</span>
        </div>
        <div v-for="r in rolesData.roles" :key="r.id" class="adm-item" @click="openRole(r)">
          <div class="adm-item__body">
            <div class="adm-item__title">{{ r.name }}</div>
            <div class="adm-item__desc">{{ r.desc }}</div>
          </div>
          <div class="adm-item__side">
            <div class="price" style="font-size: 14px">{{ r.members }}</div>
            <div class="t-tertiary" style="font-size: 11px">成员</div>
          </div>
        </div>
      </div>

      <van-notice-bar
        left-icon="info-o"
        text="角色权限为 Mock 演示，联调后由后端 RBAC 接口下发并强制校验。"
      />
    </template>

    <DetailSheet v-model:show="roleShow" :title="currentRole ? `权限配置 · ${currentRole.name}` : ''">
      <template v-if="currentRole">
        <div class="ad__desc t-secondary" style="font-size: 12px; margin-bottom: 10px">{{ currentRole.desc }}</div>
        <van-checkbox-group v-model="checked">
          <div v-for="t in rolesData.tree" :key="t.key" class="adm-kv">
            <span class="k">{{ t.label }}</span>
            <van-checkbox :name="t.key" shape="square" :disabled="currentRole.permissions.includes('*')" />
          </div>
        </van-checkbox-group>
        <van-notice-bar
          v-if="currentRole.permissions.includes('*')"
          left-icon="lock"
          text="超级管理员拥有全部权限，不可修改。"
          style="margin-top: 10px"
        />
      </template>
      <template #actions v-if="currentRole && !currentRole.permissions.includes('*')">
        <van-button block round type="primary">保存权限（演示）</van-button>
      </template>
    </DetailSheet>
  </div>
</template>

<style scoped lang="scss">
.ad__avatar {
  width: 40px;
  height: 40px;
  border-radius: 50%;
  object-fit: cover;
  flex-shrink: 0;
  border: 1px solid $color-border;
}
</style>
