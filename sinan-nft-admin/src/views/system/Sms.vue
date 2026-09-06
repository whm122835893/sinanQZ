<script setup lang="ts">
/**
 * 系统配置 - 短信配置
 *
 * - fetchSmsConfig 加载单行配置（密钥 AES 加密落库，接口仅回显掩码摘要，永不回显明文）
 * - 表单：服务商下拉 / AccessKey（掩码提示）/ AccessSecret（password 掩码）/ 签名 / 三个模板号
 * - 顶部「发送测试短信」（system:sms）弹窗输入手机号调 sendSmsTest，回写最近一次测试结果
 * - 保存调 saveSmsConfig（密钥留空 = 保持不变；非 mock 渠道启用前必须完整配置密钥与签名）
 *
 * 接口：GET/PUT /admin/system/sms-config、POST /admin/system/sms-config/test
 * 权限：system:sms
 */
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { Promotion, Refresh } from '@element-plus/icons-vue'
import { fetchSmsConfig, saveSmsConfig, sendSmsTest } from '@/api/system'
import { datetime } from '@/utils/format'

/** 服务商选项（与后端 SmsService::PROVIDERS 白名单对齐） */
const PROVIDER_OPTIONS = [
  { value: 'mock', label: '模拟渠道（mock，不外发，联调用）' },
  { value: 'aliyun', label: '阿里云短信' },
  { value: 'tencent', label: '腾讯云短信' },
]

const loading = ref(false)
const saving = ref(false)

/** 接口回显的配置态（掩码摘要/测试结果等只读信息） */
const configInfo = ref<any>({})

const form = reactive({
  provider: 'mock',
  isEnabled: 0,
  dailyLimit: 0,
  accessKey: '',
  accessSecret: '',
  signature: '',
  templateRegister: '',
  templateLogin: '',
  templateReset: '',
})

async function load() {
  loading.value = true
  try {
    const res = await fetchSmsConfig()
    configInfo.value = res ?? {}
    form.provider = String(res?.provider ?? 'mock')
    form.isEnabled = Number(res?.isEnabled ?? 0) === 1 ? 1 : 0
    form.dailyLimit = Number(res?.dailyLimit ?? 0) || 0
    // 密钥永不回显：输入框留空表示保持不变
    form.accessKey = ''
    form.accessSecret = ''
    form.signature = String(res?.signature ?? '')
    form.templateRegister = String(res?.templateRegister ?? '')
    form.templateLogin = String(res?.templateLogin ?? '')
    form.templateReset = String(res?.templateReset ?? '')
  } finally {
    loading.value = false
  }
}

onMounted(load)

function providerText(v?: string) {
  return PROVIDER_OPTIONS.find((p) => p.value === v)?.label ?? v ?? '-'
}

async function save() {
  // 严谨性：启用真实短信渠道前必须具备密钥与签名（本次填入或历史存档）
  const configured = !!configInfo.value?.configured
  if (form.provider !== 'mock' && form.isEnabled === 1) {
    const keyReady = form.accessKey.trim() !== '' || configured
    const secretReady = form.accessSecret.trim() !== '' || configured
    const signReady = form.signature.trim() !== ''
    if (!keyReady || !secretReady || !signReady) {
      ElMessage.warning('启用真实短信渠道前，需完整配置 AccessKey、AccessSecret 与短信签名')
      return
    }
  }

  saving.value = true
  try {
    await saveSmsConfig({
      provider: form.provider,
      is_enabled: form.isEnabled,
      daily_limit: form.dailyLimit,
      access_key: form.accessKey.trim(),
      access_secret: form.accessSecret.trim(),
      signature: form.signature.trim(),
      template_register: form.templateRegister.trim(),
      template_login: form.templateLogin.trim(),
      template_reset: form.templateReset.trim(),
    })
    ElMessage.success('短信配置已保存')
    load()
  } catch {
    /* 业务错误已全局提示 */
  } finally {
    saving.value = false
  }
}

// ===== 发送测试短信 =====

const testVisible = ref(false)
const testSending = ref(false)
const testForm = reactive({ phone: '' })

function openTest() {
  testForm.phone = ''
  testVisible.value = true
}

async function submitTest() {
  const phone = testForm.phone.trim()
  if (!/^1[3-9]\d{9}$/.test(phone)) {
    ElMessage.warning('请输入正确的 11 位手机号')
    return
  }
  testSending.value = true
  try {
    await sendSmsTest(phone)
    ElMessage.success('测试短信已发送，请查收手机')
    testVisible.value = false
    load()
  } catch {
    /* 业务错误已全局提示（渠道未启用/配置不完整等） */
  } finally {
    testSending.value = false
  }
}
</script>

<template>
  <div class="page-container">
    <div class="table-card" v-loading="loading">
      <div class="card-header">
        <div>
          <div class="card-title">短信服务配置</div>
          <div class="card-sub">
            密钥 AES 加密落库、接口永不回显明文（留空表示保持不变）；非 mock 渠道启用前需完整配置密钥与签名
          </div>
        </div>
        <div>
          <el-button :icon="Refresh" :loading="loading" @click="load">刷新</el-button>
          <el-button v-permission="'system:sms'" type="primary" plain :icon="Promotion" @click="openTest">
            发送测试短信
          </el-button>
        </div>
      </div>

      <!-- 当前配置状态 -->
      <el-alert
        :type="Number(configInfo.isEnabled) === 1 ? 'success' : 'info'"
        :closable="false"
        show-icon
        class="status-alert"
      >
        <template #title>
          当前渠道：{{ providerText(configInfo.provider) }} ·
          {{ Number(configInfo.isEnabled) === 1 ? '已启用' : '未启用' }} ·
          配置状态：
          <span :style="{ color: configInfo.configured ? '#2e7d32' : '#b26a00', fontWeight: 600 }">
            {{ configInfo.configured ? '完整可用' : '未配置完整' }}
          </span>
        </template>
      </el-alert>

      <el-form label-width="130px" class="sms-form" @submit.prevent>
        <el-form-item label="服务商">
          <el-select v-model="form.provider" style="width: 320px">
            <el-option v-for="opt in PROVIDER_OPTIONS" :key="opt.value" :label="opt.label" :value="opt.value" />
          </el-select>
        </el-form-item>
        <el-form-item label="启用状态">
          <el-switch v-model="form.isEnabled" :active-value="1" :inactive-value="0" active-text="启用" inactive-text="停用" />
        </el-form-item>
        <el-form-item label="每日发送上限">
          <el-input-number v-model="form.dailyLimit" :min="0" :max="1000000" :step="100" controls-position="right" />
          <span class="form-tip">条 / 天，0 表示不限制</span>
        </el-form-item>
        <el-form-item label="AccessKey">
          <el-input
            v-model="form.accessKey"
            type="password"
            show-password
            autocomplete="new-password"
            :placeholder="configInfo.accessKeyMasked ? `已配置 ${configInfo.accessKeyMasked}，留空保持不变` : '未配置，请输入 AccessKey'"
            style="width: 320px"
          />
        </el-form-item>
        <el-form-item label="AccessSecret">
          <el-input
            v-model="form.accessSecret"
            type="password"
            show-password
            autocomplete="new-password"
            :placeholder="configInfo.accessSecretMasked ? `已配置 ${configInfo.accessSecretMasked}，留空保持不变` : '未配置，请输入 AccessSecret'"
            style="width: 320px"
          />
        </el-form-item>
        <el-form-item label="短信签名">
          <el-input v-model="form.signature" maxlength="64" placeholder="如：司南数字藏品" style="width: 320px" />
        </el-form-item>
        <el-form-item label="注册验证码模板">
          <el-input v-model="form.templateRegister" maxlength="64" placeholder="短信模板号，如 SMS_1234567" style="width: 320px" />
        </el-form-item>
        <el-form-item label="登录验证码模板">
          <el-input v-model="form.templateLogin" maxlength="64" placeholder="短信模板号，如 SMS_1234568" style="width: 320px" />
        </el-form-item>
        <el-form-item label="重置密码模板">
          <el-input v-model="form.templateReset" maxlength="64" placeholder="短信模板号，如 SMS_1234569" style="width: 320px" />
        </el-form-item>

        <el-form-item>
          <el-button v-permission="'system:sms'" type="primary" :loading="saving" @click="save">保存配置</el-button>
        </el-form-item>
      </el-form>

      <!-- 最近一次测试结果 -->
      <el-descriptions v-if="configInfo.lastTestAt" :column="3" border size="small" class="last-test">
        <el-descriptions-item label="最近测试时间">{{ datetime(configInfo.lastTestAt) }}</el-descriptions-item>
        <el-descriptions-item label="测试结果">
          <el-tag :type="Number(configInfo.lastTestStatus) === 1 ? 'success' : 'danger'" size="small">
            {{ Number(configInfo.lastTestStatus) === 1 ? '成功' : '失败' }}
          </el-tag>
        </el-descriptions-item>
        <el-descriptions-item label="结果说明">{{ configInfo.lastTestMessage || '-' }}</el-descriptions-item>
        <el-descriptions-item label="最近更新人">{{ configInfo.updatedByName || '-' }}</el-descriptions-item>
        <el-descriptions-item label="最近更新时间">{{ datetime(configInfo.updatedAt) }}</el-descriptions-item>
      </el-descriptions>
    </div>

    <!-- 发送测试短信弹窗 -->
    <el-dialog v-model="testVisible" title="发送测试短信" width="440px" destroy-on-close>
      <el-alert
        type="info"
        :closable="false"
        show-icon
        title="将向指定手机号发送一条验证码测试短信；需先保存并启用短信渠道"
        style="margin-bottom: 14px"
      />
      <el-form label-width="90px" @submit.prevent>
        <el-form-item label="手机号">
          <el-input v-model="testForm.phone" maxlength="11" placeholder="请输入 11 位手机号" @keyup.enter="submitTest" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="testVisible = false">取消</el-button>
        <el-button type="primary" :loading="testSending" @click="submitTest">发送</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped lang="scss">
.card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 14px;

  .card-title {
    font-size: 15px;
    font-weight: 600;
  }
  .card-sub {
    margin-top: 4px;
    font-size: 12px;
    color: var(--sn-text-secondary);
  }
}

.status-alert {
  margin-bottom: 18px;
}

.sms-form {
  max-width: 640px;
}

.form-tip {
  margin-left: 10px;
  font-size: 12px;
  color: var(--sn-text-secondary);
}

.last-test {
  margin-top: 8px;
}
</style>
