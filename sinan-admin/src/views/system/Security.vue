<script setup>
import { ref, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { getSecurityConfig, saveSecurityConfig } from '@/api'

// ============================================================
// 安全策略（system:security）
// - 管理后台登录锁定 / 风控阈值等安全参数（实时生效）
// - 附带管理员账号安全概览（锁定/停用/24h 登录失败）
// ============================================================

const loading = ref(true)
const configs = ref([])
const overview = ref(null)

// 参数键的展示元数据（与后端 SECURITY_KEYS 白名单对应）
const META = {
  admin_login_fail_limit: { icon: 'Lock', hint: '连续失败 N 次后锁定账号' },
  admin_lock_minutes: { icon: 'Timer', hint: '锁定时长，到期自动解锁' },
  large_recharge_alert: { icon: 'Warning', hint: '单笔充值超过该金额触发风控告警' },
  cleanup_sms_required: { icon: 'Message', hint: '平台清库是否强制短信验证码二次确认（本地开发可关闭）' }
}

onMounted(load)

async function load() {
  loading.value = true
  const res = await getSecurityConfig()
  if (res.code === 0 && res.data) {
    configs.value = res.data.configs || []
    overview.value = res.data.overview || null
  }
  loading.value = false
}

const savingKey = ref('')
async function onSave(cfg) {
  await ElMessageBox.confirm(
    `确认更新「${cfg.name}」为 ${cfg.value}？保存后立即生效。`,
    '更新安全策略',
    { type: 'warning' }
  )
  savingKey.value = cfg.key
  const res = await saveSecurityConfig(cfg.key, cfg.value)
  savingKey.value = ''
  if (res.code === 0) {
    ElMessage.success(res.message || '安全策略已更新并实时生效')
  }
}
</script>

<template>
  <div class="adm-page se">
    <el-skeleton v-if="loading" :rows="6" animated style="padding: 20px" />
    <template v-else>
      <!-- 账号安全概览 -->
      <div v-if="overview" class="adm-card">
        <div class="adm-card__title">管理员账号安全概览</div>
        <div class="se__overview">
          <div class="se__ov-item">
            <div class="se__ov-val">{{ overview.adminTotal }}</div>
            <div class="t-tertiary">管理员总数</div>
          </div>
          <div class="se__ov-item is-warn">
            <div class="se__ov-val">{{ overview.adminLocked }}</div>
            <div class="t-tertiary">当前锁定中</div>
          </div>
          <div class="se__ov-item is-danger">
            <div class="se__ov-val">{{ overview.adminDisabled }}</div>
            <div class="t-tertiary">已停用</div>
          </div>
          <div class="se__ov-item is-warn">
            <div class="se__ov-val">{{ overview.login24hFail }}</div>
            <div class="t-tertiary">24h 登录失败次数</div>
          </div>
        </div>
      </div>

      <!-- 策略参数 -->
      <div class="adm-card">
        <div class="adm-card__title">安全策略参数（实时生效）</div>
        <div class="se__list">
          <div v-for="cfg in configs" :key="cfg.key" class="se__item">
            <div class="se__item-main">
              <div class="se__item-name">
                <el-icon><component :is="META[cfg.key]?.icon || 'Setting'" /></el-icon>
                {{ cfg.name }}
              </div>
              <code class="se__item-key">{{ cfg.key }}</code>
              <div class="t-tertiary se__item-hint">{{ META[cfg.key]?.hint }}</div>
            </div>
            <div class="se__item-ctrl">
              <template v-if="cfg.key === 'cleanup_sms_required'">
                <el-switch
                  v-model="cfg.value"
                  active-value="1"
                  inactive-value="0"
                  @change="onSave(cfg)"
                />
                <span class="se__item-state" :class="cfg.value === '1' ? 'is-on' : 'is-off'">
                  {{ cfg.value === '1' ? '强制验证' : '已关闭' }}
                </span>
              </template>
              <template v-else>
                <el-input-number v-model="cfg.value" :min="1" :step="1" style="width: 160px" />
                <el-button
                  type="primary"
                  size="small"
                  :loading="savingKey === cfg.key"
                  @click="onSave(cfg)"
                >保存</el-button>
              </template>
            </div>
          </div>
        </div>
      </div>

      <el-alert
        type="info"
        :closable="false"
        show-icon
        title="所有安全策略变更均写入操作审计日志；参数为白名单制，未列出的键不可修改"
      />
    </template>
  </div>
</template>

<style scoped lang="scss">
.se__overview {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 16px;
}

.se__ov-item {
  background: $color-bg;
  border-radius: 10px;
  padding: 18px 16px;
  text-align: center;

  &.is-warn .se__ov-val { color: #e6a23c; }
  &.is-danger .se__ov-val { color: #c00000; }
}

.se__ov-val {
  font-size: 26px;
  font-weight: 700;
  color: $color-text-primary;
}

.se__list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.se__item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  background: $color-bg;
  border-radius: 10px;
  padding: 14px 18px;
}

.se__item-main {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
}

.se__item-name {
  display: flex;
  align-items: center;
  gap: 6px;
  font-weight: 600;
  color: $color-text-primary;
  font-size: 14px;

  .el-icon { color: $color-primary; }
}

.se__item-key {
  font-family: 'JetBrains Mono', Consolas, monospace;
  font-size: 11px;
  color: $color-text-tertiary;
  width: fit-content;
}

.se__item-hint {
  font-size: 12px;
}

.se__item-ctrl {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-shrink: 0;
}

.se__item-state {
  font-size: 12px;
  color: $color-text-secondary;

  &.is-on { color: #07c160; }
  &.is-off { color: $color-text-tertiary; }
}

@media (max-width: 768px) {
  .se__overview { grid-template-columns: repeat(2, 1fr); }
  .se__item { flex-direction: column; align-items: stretch; }
}
</style>
