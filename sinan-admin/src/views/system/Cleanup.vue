<script setup>
import { ref, computed } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { cleanupPlatform } from '@/api'
import { verifyAdminPassword } from '@/api'

const step = ref(0)                       // 0 未开始 / 1 输入确认文本 / 2 密码 / 3 验证码 / 4 结果
const confirmText = ref('')
const password = ref('')
const smsCode = ref('')
const smsSent = ref(false)
const smsCountdown = ref(0)
const executing = ref(false)
const result = ref(null)

const canNext = computed(() => {
  if (step.value === 1) return confirmText.value.trim() === '确认清除'
  if (step.value === 2) return password.value.length >= 6
  if (step.value === 3) return /^\d{6}$/.test(smsCode.value)
  return false
})

function start() {
  step.value = 1
  confirmText.value = ''
  password.value = ''
  smsCode.value = ''
  smsSent.value = false
  result.value = null
}

// ---- Step1 → Step2 ----
async function next() {
  if (step.value === 1) {
    if (confirmText.value.trim() !== '确认清除') {
      return ElMessage.warning('请手动输入「确认清除」以继续')
    }
    step.value = 2
    return
  }
  if (step.value === 2) {
    // 密码验证
    const res = await verifyAdminPassword(password.value)
    if (!(res.code === 0 && res.data)) {
      return ElMessage.error('管理员密码验证失败')
    }
    step.value = 3
    return
  }
  if (step.value === 3) {
    await execute()
  }
}

// ---- 发送验证码（超管绑定手机 138****0001） ----
async function sendSms() {
  smsSent.value = true
  smsCountdown.value = 60
  const timer = setInterval(() => {
    smsCountdown.value--
    if (smsCountdown.value <= 0) clearInterval(timer)
  }, 1000)
  ElMessage.success('验证码已发送至超管绑定手机 138****0001（Mock 任意 6 位数字可过）')
}

// ---- Step4 最终执行 ----
async function execute() {
  await ElMessageBox.confirm(
    '这是最终确认！执行后所有用户数据将被清除且不可恢复，请再次确认。',
    '最终确认执行',
    { type: 'error', confirmButtonText: '确认执行', confirmButtonClass: 'el-button--danger' }
  )
  executing.value = true
  const res = await cleanupPlatform({ confirmText: '确认清除' })
  executing.value = false
  if (res.code === 0) {
    result.value = res.data
    step.value = 4
  } else {
    ElMessage.error(res.message || '执行失败')
  }
}

function finish() {
  step.value = 0
}
</script>

<template>
  <div class="adm-page cl">
    <!-- 说明卡片 -->
    <div class="adm-card">
      <div class="adm-card__title">平台数据清除（最高风险操作）</div>
      <el-alert
        type="error"
        :closable="false"
        show-icon
        title="该操作将永久清除全部用户业务数据，不可恢复！执行前系统自动全库备份，操作人 / IP / 时间 / 原因完整记录审计日志。"
        class="cl__alert"
      />

      <div class="cl__grid">
        <div class="cl__panel is-danger">
          <div class="cl__panel-title">清除范围（不可恢复）</div>
          <ul class="cl__list">
            <li>所有用户账号与邀请关系</li>
            <li>用户钱包与钱包流水</li>
            <li>藏品 / 盲盒持有记录</li>
            <li>全部订单与支付流水</li>
            <li>转赠、寄售挂单记录</li>
            <li>签到 / 抽奖 / 邀请 / 空投 / 合成 / 盲盒开启记录</li>
            <li>实名认证信息</li>
            <li>退款记录</li>
          </ul>
        </div>
        <div class="cl__panel is-keep">
          <div class="cl__panel-title">保留内容</div>
          <ul class="cl__list">
            <li>管理员账号与角色权限</li>
            <li>藏品元数据（库存重置为发行总量）</li>
            <li>盲盒元数据（盲盒库存重置为发行总量）</li>
            <li>CMS 内容（公告 / 轮播 / 社群 / 文物展馆）</li>
            <li>系统配置（支付 / 区块链 / 存储）</li>
          </ul>
        </div>
      </div>

      <div class="cl__actions">
        <el-button type="danger" size="large" @click="start">进入清除流程</el-button>
      </div>
    </div>

    <!-- 四重安全确认弹窗 -->
    <el-dialog
      :model-value="step > 0 && step < 4"
      title="平台数据清除 · 安全确认"
      width="480px"
      :close-on-click-modal="false"
      :show-close="!executing"
    >
      <!-- Step 1：输入确认文本 -->
      <template v-if="step === 1">
        <div class="cl__step-tip">
          <el-alert
            type="error"
            :closable="false"
            show-icon
            title="红色警示：此操作将清除全部用户数据且不可恢复！"
          />
        </div>
        <el-form label-width="110px">
          <el-form-item label="确认文本">
            <el-input
              v-model="confirmText"
              placeholder="请手动输入「确认清除」"
              maxlength="4"
              clearable
            />
          </el-form-item>
        </el-form>
      </template>

      <!-- Step 2：管理员密码 -->
      <template v-else-if="step === 2">
        <div class="cl__step-tip">第 2 / 3 步：请输入管理员登录密码验证身份。</div>
        <el-form label-width="110px">
          <el-form-item label="管理员密码">
            <el-input
              v-model="password"
              type="password"
              show-password
              placeholder="当前登录管理员的密码"
              @keyup.enter="next"
            />
          </el-form-item>
        </el-form>
      </template>

      <!-- Step 3：短信验证码 -->
      <template v-else-if="step === 3">
        <div class="cl__step-tip">第 3 / 3 步：短信验证码将发送至超管绑定手机 138****0001。</div>
        <el-form label-width="110px">
          <el-form-item label="短信验证码">
            <div class="cl__sms">
              <el-input v-model="smsCode" placeholder="6 位验证码" maxlength="6" />
              <el-button :disabled="smsCountdown > 0" @click="sendSms">
                {{ smsCountdown > 0 ? `${smsCountdown}s 后重发` : '获取验证码' }}
              </el-button>
            </div>
          </el-form-item>
        </el-form>
      </template>

      <template #footer>
        <el-button :disabled="executing" @click="step = 0">取消</el-button>
        <el-button v-if="step < 3" type="danger" :disabled="!canNext" @click="next">下一步</el-button>
        <el-button v-else type="danger" :loading="executing" @click="next">最终确认执行</el-button>
      </template>
    </el-dialog>

    <!-- 执行结果 -->
    <el-dialog :model-value="step === 4" title="清除完成" width="440px" :close-on-click-modal="false">
      <el-result icon="success" title="平台数据已清除" sub-title="执行结果已写入审计日志，备份文件已生成">
        <template #extra>
          <div class="cl__result">
            <div class="cl__kv"><span>清除用户</span><b>{{ result?.clearedUsers ?? 0 }} 个</b></div>
            <div class="cl__kv"><span>清除订单</span><b>{{ result?.clearedOrders ?? 0 }} 笔</b></div>
            <div class="cl__kv"><span>重置藏品库存</span><b>{{ result?.resetCollectibles ?? 0 }} 个</b></div>
            <div class="cl__kv"><span>全库备份</span><b class="cl__backup">{{ result?.backupFile }}</b></div>
          </div>
        </template>
      </el-result>
      <template #footer>
        <el-button type="primary" @click="finish">完成</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped lang="scss">
.cl__alert { margin-bottom: 16px; }

.cl__grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
}

.cl__panel {
  border-radius: 8px;
  padding: 14px 16px;

  &.is-danger {
    background: rgba(192, 0, 0, 0.04);
    border: 1px solid rgba(192, 0, 0, 0.15);
  }

  &.is-keep {
    background: rgba(7, 193, 96, 0.04);
    border: 1px solid rgba(7, 193, 96, 0.15);
  }

  &-title {
    font-weight: 600;
    font-size: 13px;
    margin-bottom: 8px;
    color: $color-text-primary;
  }
}

.cl__list {
  margin: 0;
  padding-left: 18px;
  font-size: 12px;
  color: $color-text-secondary;
  line-height: 1.9;
}

.cl__actions {
  display: flex;
  justify-content: center;
  margin-top: 20px;
}

.cl__step-tip {
  font-size: 13px;
  color: $color-text-secondary;
  margin-bottom: 16px;

  .el-alert { margin-bottom: 0; }
}

.cl__sms {
  display: flex;
  gap: 8px;
  width: 100%;
}

.cl__result {
  text-align: left;
  width: 100%;
}

.cl__kv {
  display: flex;
  justify-content: space-between;
  padding: 8px 0;
  border-bottom: 1px dashed $color-border;
  font-size: 13px;
  color: $color-text-secondary;

  b { color: $color-text-primary; }
}

.cl__backup {
  font-family: 'JetBrains Mono', Consolas, monospace;
  font-size: 12px;
}

@media (max-width: 768px) {
  .cl__grid { grid-template-columns: 1fr; }
}
</style>
