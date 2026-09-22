<script setup>
import { ref, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { getSecurityConfig, saveSecurityConfig } from '@/api'

// ============================================================
// 安全策略（system:security）
// - 管理后台登录锁定 / 风控阈值等安全参数（实时生效）
// - 验证码服务商切换（local 本地图形码 / aliyun 阿里云验证码 2.0）
// - 附带管理员账号安全概览（锁定/停用/24h 登录失败）
// ============================================================

const loading = ref(true)
const configs = ref([])
const overview = ref(null)
const captchaScenes = ref([])
const captchaEnabled = ref(true)

// 验证码服务商（后端 captchaProvider 区块：provider + mode + 阿里云参数，密钥仅掩码回显）
const captchaProvider = ref({ provider: 'local', mode: 'graphic', aliyun: {} })
const aliyunForm = ref({ scene_id: '', prefix: '', access_key_id: '', access_key_secret: '' })

// 参数键的展示元数据（与后端 SECURITY_KEYS 白名单对应）
const META = {
  'captcha.enable': { icon: 'Key', hint: '关闭后前后端所有图形验证码入口均隐藏（开发调试用，生产务必开启）' },
  sms_daily_limit: { icon: 'Message', hint: '每手机号每日短信验证码发送上限（全场景合计，超限次日恢复）' },
  admin_login_fail_limit: { icon: 'Lock', hint: '连续失败 N 次后锁定账号' },
  admin_lock_minutes: { icon: 'Timer', hint: '锁定时长，到期自动解锁' },
  large_recharge_alert: { icon: 'Warning', hint: '单笔充值超过该金额触发风控告警' },
  cleanup_sms_required: { icon: 'Message', hint: '平台清库是否强制短信验证码二次确认（本地开发可关闭）' }
}

// 图形验证码场景展示元数据（与后端 CaptchaService::SCENES 对应）
const SCENE_META = {
  auth_login_password: { icon: 'Lock', hint: 'C 端密码登录表单前置图形码' },
  auth_login_sms: { icon: 'Iphone', hint: 'C 端验证码登录：发送短信前前置图形码' },
  auth_register: { icon: 'User', hint: 'C 端注册：发送短信前前置图形码' },
  auth_forgot: { icon: 'Key', hint: 'C 端忘记密码：发送短信前前置图形码' },
  user_change_pwd: { icon: 'EditPen', hint: '已登录修改登录密码：发送短信前前置图形码' },
  user_op_pwd: { icon: 'Wallet', hint: '设置/修改支付密码：发送短信前前置图形码' },
  user_cancel: { icon: 'Delete', hint: '注销账户：发送短信前前置图形码' },
  admin_login: { icon: 'Monitor', hint: '管理后台登录表单前置图形码' }
}

// 阿里云参数字段元数据（key 对应后端 captcha.aliyun.* 配置键）
const ALIYUN_FIELDS = [
  { key: 'scene_id', name: '场景ID（SceneId）', hint: '阿里云验证码控制台「场景管理」新建场景后获取', secret: false, placeholder: '请输入场景ID' },
  { key: 'prefix', name: '身份标（prefix）', hint: '控制台「概览」页实例基本信息中获取，前端 SDK 初始化用', secret: false, placeholder: '请输入身份标' },
  { key: 'access_key_id', name: 'AccessKey ID', hint: 'AES 加密落库，永不回显明文；留空提交保持不变', secret: true, placeholder: '如已配置将显示掩码' },
  { key: 'access_key_secret', name: 'AccessKey Secret', hint: 'AES 加密落库，永不回显明文；留空提交保持不变', secret: true, placeholder: '如已配置将显示掩码' }
]

// 开关型参数（值为 '0' / '1'）
const SWITCH_KEYS = ['captcha.enable', 'cleanup_sms_required']

/** 阿里云参数是否配齐（切 aliyun 前的前置提示） */
const aliyunReady = computed(() => {
  const a = captchaProvider.value.aliyun || {}
  return !!(a.sceneId && a.prefix && a.akConfigured && a.skConfigured)
})

/** 密钥字段是否已配置（后端回显 akConfigured/skConfigured） */
function isAliyunFieldConfigured(key) {
  const a = captchaProvider.value.aliyun || {}
  if (key === 'access_key_id') return !!a.akConfigured
  if (key === 'access_key_secret') return !!a.skConfigured
  return !!aliyunForm.value[key]
}

/** 输入框占位符：密钥显示后端掩码，明文字段显示默认提示 */
function aliyunPlaceholder(field) {
  const a = captchaProvider.value.aliyun || {}
  if (field.key === 'access_key_id') return a.akMasked || '请输入 AccessKey ID'
  if (field.key === 'access_key_secret') return a.skMasked || '请输入 AccessKey Secret'
  return field.placeholder
}

onMounted(load)

async function load() {
  loading.value = true
  const res = await getSecurityConfig()
  if (res.code === 0 && res.data) {
    // 开关型参数保持 '0'/'1' 字符串（el-switch active-value/inactive-value 用字符串匹配）
    // 数值型参数转 Number（el-input-number 的 modelValue 须为数字）
    configs.value = (res.data.configs || []).map((c) =>
      SWITCH_KEYS.includes(c.key) ? c : { ...c, value: Number(c.value) || 0 }
    )
    overview.value = res.data.overview || null
    captchaScenes.value = res.data.captchaScenes || []
    captchaEnabled.value = res.data.captchaEnabled !== false
    captchaProvider.value = res.data.captchaProvider || { provider: 'local', aliyun: {} }
    // 明文参数回填表单；密钥不回显（占位符显示掩码）
    aliyunForm.value = {
      scene_id: captchaProvider.value.aliyun?.sceneId || '',
      prefix: captchaProvider.value.aliyun?.prefix || '',
      access_key_id: '',
      access_key_secret: ''
    }
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
    // 总开关切换后联动场景区禁用态
    if (cfg.key === 'captcha.enable') captchaEnabled.value = cfg.value === '1'
  }
}

/** 服务商切换（后端强制校验阿里云参数配齐后才允许切 aliyun） */
async function onSaveProvider(next) {
  const prev = next === 'aliyun' ? 'local' : 'aliyun'
  try {
    await ElMessageBox.confirm(
      next === 'aliyun'
        ? '确认切换到阿里云验证码 2.0？切换后前后端所有图形码场景立即改为阿里云验证（滑块/图形由下方「验证模式」决定）。'
        : '确认切换回本地图形验证码？切换后立即恢复图片输入框验证。',
      '切换验证码服务商',
      { type: 'warning' }
    )
  } catch {
    captchaProvider.value.provider = prev // 取消：回滚选择
    return
  }
  savingKey.value = 'captcha.provider'
  const res = await saveSecurityConfig('captcha.provider', next)
  savingKey.value = ''
  if (res.code === 0) {
    captchaProvider.value.provider = next
    // 切回 local 时 mode 强制归一化为 graphic
    if (next === 'local') captchaProvider.value.mode = 'graphic'
    ElMessage.success('验证码服务商已切换，实时生效')
  } else {
    captchaProvider.value.provider = prev // 失败（全局已弹错误提示）：回滚
  }
}

/** 验证模式切换（仅 aliyun 有效：slider=滑块行为验证 / graphic=图形点选验证码） */
async function onSaveMode(next) {
  if (captchaProvider.value.provider === 'local') {
    captchaProvider.value.mode = 'graphic'
    ElMessage.info('本地图形码仅支持 graphic 模式')
    return
  }
  const prev = next === 'slider' ? 'graphic' : 'slider'
  try {
    await ElMessageBox.confirm(
      next === 'slider'
        ? '确认切换为滑块行为验证？用户将在弹窗中拖动滑块完成验证。'
        : '确认切换为图形验证码？用户将在弹窗中完成图形点选验证。',
      '切换验证模式',
      { type: 'warning' }
    )
  } catch {
    captchaProvider.value.mode = prev
    return
  }
  savingKey.value = 'captcha.mode'
  const res = await saveSecurityConfig('captcha.mode', next)
  savingKey.value = ''
  if (res.code === 0) {
    captchaProvider.value.mode = next
    ElMessage.success('验证模式已切换，实时生效')
  } else {
    captchaProvider.value.mode = prev
  }
}

/** 阿里云参数保存（密钥留空提交 = 保持不变） */
async function onSaveAliyunField(field) {
  const key = 'captcha.aliyun.' + field.key
  const value = aliyunForm.value[field.key]
  if (!value) {
    ElMessage.warning(field.secret ? '请输入新密钥后再保存（留空提交将保持不变）' : '请输入后再保存')
    return
  }
  savingKey.value = key
  const res = await saveSecurityConfig(key, value)
  savingKey.value = ''
  if (res.code === 0) {
    ElMessage.success(res.message || '已保存')
    // 重新拉取（刷新掩码回显与 aliyunReady 状态）
    await load()
  }
}

/** 场景开关保存：合并全部场景为 JSON 一次性落库（后端校验白名单并归一化） */
async function onSaveScene(scene) {
  const next = scene.value
  savingKey.value = 'captcha.scenes:' + scene.key
  const scenesMap = {}
  captchaScenes.value.forEach((item) => { scenesMap[item.key] = item.value })
  const res = await saveSecurityConfig('captcha.scenes', JSON.stringify(scenesMap))
  savingKey.value = ''
  if (res.code === 0) {
    ElMessage.success(`「${scene.name}」已${next === '1' ? '开启' : '关闭'}，实时生效`)
  } else {
    // 保存失败（全局已弹错误提示）：回滚开关状态
    scene.value = next === '1' ? '0' : '1'
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
              <template v-if="SWITCH_KEYS.includes(cfg.key)">
                <el-switch
                  v-model="cfg.value"
                  active-value="1"
                  inactive-value="0"
                  @change="onSave(cfg)"
                />
                <span class="se__item-state" :class="cfg.value === '1' ? 'is-on' : 'is-off'">
                  {{ cfg.value === '1' ? '已开启' : '已关闭' }}
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

      <!-- 验证码服务商 -->
      <div class="adm-card">
        <div class="adm-card__title">验证码服务商</div>
        <el-alert
          type="info"
          :closable="false"
          show-icon
          title="总开关与场景开关对两种服务商均生效；切换后前后端立即按新方式验证（C 端与管理端登录页刷新后生效）"
          style="margin-bottom: 12px"
        />
        <div class="se__list">
          <!-- 服务商选择 -->
          <div class="se__item">
            <div class="se__item-main">
              <div class="se__item-name">
                <el-icon><component :is="captchaProvider.provider === 'aliyun' ? 'Cloudy' : 'Picture'" /></el-icon>
                当前服务商
              </div>
              <code class="se__item-key">captcha.provider</code>
              <div class="t-tertiary se__item-hint">
                local = 本地图形码（弹窗展示，默认）；aliyun = 阿里云验证码 2.0（滑块/图形由下方「验证模式」决定）
              </div>
            </div>
            <div class="se__item-ctrl">
              <el-radio-group
                :model-value="captchaProvider.provider"
                :disabled="savingKey === 'captcha.provider'"
                @change="onSaveProvider"
              >
                <el-radio-button value="local">本地图形码</el-radio-button>
                <el-radio-button value="aliyun">阿里云验证码</el-radio-button>
              </el-radio-group>
            </div>
          </div>

          <!-- 验证模式（仅 aliyun 时可切换；local 固定 graphic） -->
          <div class="se__item">
            <div class="se__item-main">
              <div class="se__item-name">
                <el-icon><component :is="captchaProvider.mode === 'slider' ? 'Pointer' : 'PictureFilled'" /></el-icon>
                验证模式
              </div>
              <code class="se__item-key">captcha.mode</code>
              <div class="t-tertiary se__item-hint">
                slider = 滑块行为验证（阿里云行为验证码 SceneId）；graphic = 图形点选验证码。
                本地图形码固定 graphic，阿里云两种模式自由切换。
              </div>
            </div>
            <div class="se__item-ctrl">
              <el-radio-group
                :model-value="captchaProvider.mode"
                :disabled="savingKey === 'captcha.mode' || captchaProvider.provider === 'local'"
                @change="onSaveMode"
              >
                <el-radio-button value="slider">滑块（行为验证）</el-radio-button>
                <el-radio-button value="graphic">图形（点选验证）</el-radio-button>
              </el-radio-group>
              <div v-if="captchaProvider.provider === 'local'" class="t-tertiary se__item-hint" style="margin-top:4px">
                local 服务商仅支持 graphic 模式
              </div>
            </div>
          </div>

          <!-- 阿里云参数（切 aliyun 前需配齐；密钥 AES 加密落库、仅掩码回显） -->
          <el-alert
            v-if="!aliyunReady"
            type="warning"
            :closable="false"
            show-icon
            title="阿里云参数尚未配齐：切换到阿里云验证码前，需先保存以下全部参数"
            style="margin-bottom: 12px"
          />
          <div
            v-for="field in ALIYUN_FIELDS"
            :key="field.key"
            class="se__item se__item--block"
          >
            <div class="se__item-main">
              <div class="se__item-name">
                <el-icon><component :is="field.secret ? 'Lock' : 'Postcard'" /></el-icon>
                {{ field.name }}
                <el-tag
                  v-if="field.secret"
                  :type="isAliyunFieldConfigured(field.key) ? 'success' : 'info'"
                  size="small"
                >
                  {{ isAliyunFieldConfigured(field.key) ? '已配置' : '未配置' }}
                </el-tag>
              </div>
              <code class="se__item-key">captcha.aliyun.{{ field.key }}</code>
              <div class="t-tertiary se__item-hint">{{ field.hint }}</div>
              <el-input
                v-model="aliyunForm[field.key]"
                :type="field.secret ? 'password' : 'text'"
                :show-password="field.secret"
                :placeholder="aliyunPlaceholder(field)"
                style="max-width: 360px; margin-top: 6px"
              />
            </div>
            <div class="se__item-ctrl">
              <el-button
                type="primary"
                size="small"
                :loading="savingKey === 'captcha.aliyun.' + field.key"
                @click="onSaveAliyunField(field)"
              >保存</el-button>
            </div>
          </div>
        </div>
      </div>

      <!-- 图形验证码场景开关 -->
      <div v-if="captchaScenes.length" class="adm-card">
        <div class="adm-card__title">图形验证码场景开关</div>
        <el-alert
          v-if="!captchaEnabled"
          type="warning"
          :closable="false"
          show-icon
          title="图形验证码总开关已关闭，以下场景开关均不生效"
          style="margin-bottom: 12px"
        />
        <div class="se__list">
          <div v-for="scene in captchaScenes" :key="scene.key" class="se__item" :class="{ 'is-disabled': !captchaEnabled }">
            <div class="se__item-main">
              <div class="se__item-name">
                <el-icon><component :is="SCENE_META[scene.key]?.icon || 'Key'" /></el-icon>
                {{ scene.name }}
              </div>
              <code class="se__item-key">{{ scene.key }}</code>
              <div class="t-tertiary se__item-hint">{{ SCENE_META[scene.key]?.hint }}</div>
            </div>
            <div class="se__item-ctrl">
              <el-switch
                v-model="scene.value"
                active-value="1"
                inactive-value="0"
                :disabled="!captchaEnabled || savingKey === 'captcha.scenes:' + scene.key"
                @change="onSaveScene(scene)"
              />
              <span class="se__item-state" :class="scene.value === '1' && captchaEnabled ? 'is-on' : 'is-off'">
                {{ scene.value === '1' ? '已开启' : '已关闭' }}
              </span>
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

  &.is-disabled { opacity: 0.55; }
  // 阿里云参数行：含输入框，改为顶部对齐
  &--block { align-items: flex-start; }
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
