<script setup>
import { ref, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { getSmsConfig, saveSmsConfig, testSms } from '@/api'

// ============================================================
// 短信配置（system:sms）
// - 密钥 AES 加密落库，接口永不回显明文（仅掩码）
// - 重新输入密钥才提交变更；留空 = 保持不变
// - 支持向指定手机号发送测试短信验证配置
// ============================================================

const loading = ref(true)
const saving = ref(false)
const testing = ref(false)

const PROVIDERS = [
  { value: 'aliyun', label: '阿里云短信' },
  { value: 'tencent', label: '腾讯云短信' },
  { value: 'mock', label: 'Mock（开发联调）' }
]

const form = ref({
  provider: 'mock',
  isEnabled: 0,
  dailyLimit: 1000,
  signature: '',
  templateRegister: '',
  templateLogin: '',
  templateReset: '',
  accessKey: '',
  accessSecret: ''
})

// 回显元信息（掩码 / 最近测试结果）
const meta = ref(null)

onMounted(async () => {
  const res = await getSmsConfig()
  if (res.code === 0 && res.data) {
    const d = res.data
    meta.value = d
    form.value = {
      provider: d.provider || 'mock',
      isEnabled: d.isEnabled ? 1 : 0,
      dailyLimit: d.dailyLimit ?? 1000,
      signature: d.signature || '',
      templateRegister: d.templateRegister || '',
      templateLogin: d.templateLogin || '',
      templateReset: d.templateReset || '',
      accessKey: '',
      accessSecret: ''
    }
  }
  loading.value = false
})

async function onSave() {
  await ElMessageBox.confirm(
    '短信配置变更将立即影响 C 端验证码发送，确认保存？',
    '保存确认',
    { type: 'warning' }
  )
  saving.value = true
  const res = await saveSmsConfig({ ...form.value })
  saving.value = false
  if (res.code === 0) {
    ElMessage.success(res.message || '短信配置已保存（已写审计日志）')
    // 重新拉取掩码元信息
    const r = await getSmsConfig()
    if (r.code === 0) meta.value = r.data
  }
}

const testPhone = ref('')
async function onTest() {
  if (!/^1\d{10}$/.test(testPhone.value)) {
    return ElMessage.warning('请输入正确的 11 位手机号')
  }
  testing.value = true
  const res = await testSms(testPhone.value)
  testing.value = false
  if (res.code === 0) {
    ElMessage.success(res.message || '测试短信已发送')
  }
}
</script>

<template>
  <div class="adm-page sm">
    <el-skeleton v-if="loading" :rows="8" animated style="padding: 20px" />
    <template v-else>
      <!-- 配置状态概览 -->
      <div class="adm-card">
        <div class="adm-card__title">配置状态</div>
        <div class="sm__meta">
          <div class="sm__meta-item">
            <span class="t-tertiary">渠道</span>
            <b>{{ PROVIDERS.find((p) => p.value === form.provider)?.label || form.provider }}</b>
          </div>
          <div class="sm__meta-item">
            <span class="t-tertiary">状态</span>
            <el-tag :type="form.isEnabled ? 'success' : 'info'" effect="plain" size="small">
              {{ form.isEnabled ? '已启用' : '已停用' }}
            </el-tag>
          </div>
          <div class="sm__meta-item">
            <span class="t-tertiary">密钥配置</span>
            <el-tag :type="meta?.configured ? 'success' : 'danger'" effect="plain" size="small">
              {{ meta?.configured ? '已配置' : '未配置（验证码将无法发送）' }}
            </el-tag>
          </div>
          <div class="sm__meta-item">
            <span class="t-tertiary">AccessKey</span>
            <code class="sm__mask">{{ meta?.accessKeyMasked || '（未配置）' }}</code>
          </div>
          <div class="sm__meta-item">
            <span class="t-tertiary">AccessSecret</span>
            <code class="sm__mask">{{ meta?.accessSecretMasked || '（未配置）' }}</code>
          </div>
        </div>
        <el-alert
          v-if="meta?.lastTestAt"
          :type="meta.lastTestStatus === 1 ? 'success' : 'error'"
          :closable="false"
          show-icon
          class="sm__last-test"
          :title="`最近测试（${meta.lastTestAt}）：${meta.lastTestMessage || (meta.lastTestStatus === 1 ? '发送成功' : '发送失败')}`"
        />
      </div>

      <!-- 配置表单 -->
      <div class="adm-card">
        <div class="adm-card__title">短信服务商配置</div>
        <el-form label-width="130px" class="sm__form">
          <el-form-item label="短信服务商">
            <el-select v-model="form.provider" style="width: 240px">
              <el-option v-for="p in PROVIDERS" :key="p.value" :value="p.value" :label="p.label" />
            </el-select>
          </el-form-item>
          <el-form-item label="启用短信">
            <el-switch v-model="form.isEnabled" :active-value="1" :inactive-value="0" />
            <span class="t-tertiary" style="margin-left: 10px; font-size: 12px">停用后 C 端将无法获取验证码</span>
          </el-form-item>
          <el-form-item label="每日发送上限">
            <el-input-number v-model="form.dailyLimit" :min="0" :max="1000000" :step="100" style="width: 180px" />
            <span class="t-tertiary" style="margin-left: 10px; font-size: 12px">条 / 天（0 = 不限制）</span>
          </el-form-item>
          <el-form-item label="短信签名">
            <el-input v-model="form.signature" placeholder="如：司南数字藏品" maxlength="30" style="width: 320px" />
          </el-form-item>

          <el-divider content-position="left">短信模板（服务商模板 Code）</el-divider>
          <el-form-item label="注册验证码模板">
            <el-input v-model="form.templateRegister" placeholder="如：SMS_123456789" style="width: 320px" />
          </el-form-item>
          <el-form-item label="登录验证码模板">
            <el-input v-model="form.templateLogin" placeholder="如：SMS_123456789" style="width: 320px" />
          </el-form-item>
          <el-form-item label="重置密码模板">
            <el-input v-model="form.templateReset" placeholder="如：SMS_123456789" style="width: 320px" />
          </el-form-item>

          <el-divider content-position="left">访问密钥（AES 加密存储，留空 = 保持不变）</el-divider>
          <el-form-item label="AccessKey ID">
            <el-input v-model="form.accessKey" placeholder="留空则保持原有密钥不变" show-password style="width: 320px" />
          </el-form-item>
          <el-form-item label="AccessKey Secret">
            <el-input v-model="form.accessSecret" placeholder="留空则保持原有密钥不变" show-password style="width: 320px" />
          </el-form-item>
          <el-form-item>
            <el-button type="primary" :loading="saving" @click="onSave">保存配置</el-button>
          </el-form-item>
        </el-form>
      </div>

      <!-- 发送测试 -->
      <div class="adm-card">
        <div class="adm-card__title">发送测试短信</div>
        <div class="sm__test">
          <el-input v-model="testPhone" placeholder="输入测试手机号" style="width: 240px" maxlength="11" />
          <el-button type="primary" plain :loading="testing" @click="onTest">发送测试</el-button>
          <span class="t-tertiary" style="font-size: 12px">使用当前已保存的配置发送（非表单草稿）</span>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped lang="scss">
.sm__meta {
  display: flex;
  flex-wrap: wrap;
  gap: 24px;
}

.sm__meta-item {
  display: flex;
  align-items: center;
  gap: 8px;

  span { font-size: 12px; }
  b { font-size: 14px; color: $color-text-primary; }
}

.sm__mask {
  font-family: 'JetBrains Mono', Consolas, monospace;
  font-size: 12px;
  color: $color-text-secondary;
  background: $color-surface;
  padding: 2px 8px;
  border-radius: 4px;
}

.sm__last-test { margin-top: 12px; }

.sm__form {
  max-width: 640px;
}

.sm__test {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}
</style>
