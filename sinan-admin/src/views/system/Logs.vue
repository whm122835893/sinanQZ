<script setup>
import { ref, computed } from 'vue'
import { getLoginLogs, getOperationLogs } from '@/api'
import AdminListPage from '@/components/AdminListPage.vue'

const tab = ref('operation')
const tabs = [
  { name: 'operation', label: '操作日志' },
  { name: 'login', label: '登录日志' }
]

const tabStyle = computed(() => ({
  display: 'flex',
  gap: '8px',
  padding: '0 2px 12px'
}))
</script>

<template>
  <div class="adm-page lg">
    <van-tabs v-model:active="tab" :line-width="20" :title-active-color="'var(--color-primary)'">
      <van-tab v-for="t in tabs" :key="t.name" :title="t.label" :name="t.name" />
    </van-tabs>

    <!-- 操作日志 -->
    <AdminListPage
      v-if="tab === 'operation'"
      :fetch="getOperationLogs"
      search-placeholder="搜索管理员 / 模块 / 操作"
    >
      <template #default="{ items }">
        <div class="adm-card" style="padding: 0">
          <div v-for="l in items" :key="l.id" class="lg__row">
            <div class="lg__badge">
              <van-icon name="edit" size="14" />
            </div>
            <div class="lg__body">
              <div class="lg__title">
                <b>{{ l.admin }}</b>
                <van-tag plain round size="medium" type="primary">{{ l.module }}</van-tag>
                {{ l.action }}
              </div>
              <div class="lg__detail">{{ l.detail }}</div>
              <div class="lg__meta">{{ l.time }} · IP {{ l.ip }}</div>
            </div>
          </div>
        </div>
      </template>
    </AdminListPage>

    <!-- 登录日志 -->
    <AdminListPage
      v-else
      :fetch="getLoginLogs"
      search-placeholder="搜索账号 / IP"
    >
      <template #default="{ items }">
        <div class="adm-card" style="padding: 0">
          <div v-for="l in items" :key="l.id" class="lg__row">
            <div class="lg__badge" :class="l.result === 'success' ? 'is-ok' : 'is-fail'">
              <van-icon :name="l.result === 'success' ? 'passed' : 'close'" size="14" />
            </div>
            <div class="lg__body">
              <div class="lg__title">
                <b>{{ l.name }}</b>（{{ l.username }}）
                <van-tag :type="l.result === 'success' ? 'success' : 'danger'" plain round size="medium">
                  {{ l.result === 'success' ? '登录成功' : '登录失败' }}
                </van-tag>
              </div>
              <div class="lg__detail">{{ l.location }}</div>
              <div class="lg__meta">{{ l.time }} · IP {{ l.ip }}</div>
            </div>
          </div>
        </div>
      </template>
    </AdminListPage>
  </div>
</template>

<style scoped lang="scss">
.lg__row {
  display: flex;
  gap: 10px;
  padding: 12px 14px;
  border-bottom: 1px solid $color-border;

  &:last-child { border-bottom: none; }
}

.lg__badge {
  width: 28px;
  height: 28px;
  border-radius: 8px;
  @include flex-center;
  flex-shrink: 0;
  background: var(--color-primary-bg);
  color: $color-primary;

  &.is-ok { background: rgba(7, 193, 96, 0.08); color: var(--color-success); }
  &.is-fail { background: rgba(192, 0, 0, 0.06); color: $color-primary; }
}

.lg__body { flex: 1; min-width: 0; }

.lg__title {
  font-size: 13px;
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}

.lg__detail {
  font-size: 12px;
  color: $color-text-secondary;
  margin-top: 3px;
}

.lg__meta {
  font-size: 11px;
  color: $color-text-tertiary;
  margin-top: 2px;
}
</style>
