<script setup>
import { ref, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  getCleanupPreview,
  sendCleanupCode,
  executeCleanup,
  getCleanupLogs,
  verifyAdminPassword
} from '@/api'

// ============================================================
// 平台清库（真实后端四步流）
// 预览（GET cleanup-preview）→ 确认文本+原因 → 管理员密码
// → 短信验证码（发至当前管理员绑定手机）→ 执行（备份+事务清库）
// ============================================================

const loading = ref(true)
const preview = ref(null)          // { tables, totalRows, protected, smsRequired }
const maskedPhone = ref('')        // 后端返回的脱敏手机号

const step = ref(0)                // 0 未开始 / 1 确认文本+原因 / 2 密码 / 3 验证码 / 4 结果
const confirmText = ref('')
const reason = ref('')
const password = ref('')
const smsCode = ref('')
const smsCountdown = ref(0)
const sending = ref(false)
const executing = ref(false)
const result = ref(null)           // { backupPath, affectedUsers, affectedOrders, executionTime }

// 清库日志
const logs = ref([])
const logsTotal = ref(0)
const logsPage = ref(1)

const canNext = computed(() => {
  if (step.value === 1) {
    return confirmText.value.trim() === '确认清除' && reason.value.trim().length >= 5
  }
  if (step.value === 2) return password.value.length >= 6
  if (step.value === 3) return /^\d{6}$/.test(smsCode.value)
  return false
})

async function load() {
  loading.value = true
  const res = await getCleanupPreview()
  loading.value = false
  if (res.code === 0) preview.value = res.data
}

async function loadLogs() {
  const res = await getCleanupLogs({ page: logsPage.value, page_size: 10 })
  if (res.code === 0) {
    logs.value = res.data?.list || []
    logsTotal.value = res.data?.total || 0
  }
}

onMounted(() => {
  load()
  loadLogs()
})

function start() {
  step.value = 1
  confirmText.value = ''
  reason.value = ''
  password.value = ''
  smsCode.value = ''
  result.value = null
}

// ---- 步骤推进 ----
async function next() {
  if (step.value === 1) {
    if (confirmText.value.trim() !== '确认清除') {
      return ElMessage.warning('请手动输入「确认清除」以继续')
    }
    if (reason.value.trim().length < 5) {
      return ElMessage.warning('清库原因不能少于 5 字')
    }
    step.value = 2
    return
  }
  if (step.value === 2) {
    const res = await verifyAdminPassword(password.value)
    if (!(res.code === 0 && res.data)) {
      return ElMessage.error(res.message || '管理员密码验证失败')
    }
    step.value = 3
    // 密码通过后自动发一次验证码
    if (!smsCode.value) sendSms()
    return
  }
  if (step.value === 3) {
    await execute()
  }
}

// ---- 发送验证码（后端仅允许发至当前管理员绑定手机） ----
async function sendSms() {
  if (sending.value || smsCountdown.value > 0) return
  sending.value = true
  const res = await sendCleanupCode('')
  sending.value = false
  if (res.code === 0) {
    maskedPhone.value = res.data?.phone || ''
    smsCountdown.value = 60
    const timer = setInterval(() => {
      smsCountdown.value--
      if (smsCountdown.value <= 0) clearInterval(timer)
    }, 1000)
    ElMessage.success(`验证码已发送至管理员绑定手机 ${maskedPhone.value || ''}`)
  } else {
    ElMessage.error(res.message || '验证码发送失败')
  }
}

// ---- 最终执行 ----
async function execute() {
  await ElMessageBox.confirm(
    '这是最终确认！执行后所有用户业务数据将被清除（执行前自动全库备份），请再次确认。',
    '最终确认执行',
    { type: 'error', confirmButtonText: '确认执行', confirmButtonClass: 'el-button--danger' }
  )
  executing.value = true
  const res = await executeCleanup({ code: smsCode.value.trim(), reason: reason.value.trim() })
  executing.value = false
  if (res.code === 0) {
    result.value = res.data || {}
    step.value = 4
    load()        // 刷新预览（数据已清零）
    loadLogs()    // 刷新日志
  } else {
    ElMessage.error(res.message || '执行失败')
  }
}

function finish() {
  step.value = 0
}

function onLogsPage(p) {
  logsPage.value = p
  loadLogs()
}

const LOG_STATUS = { 1: '成功', 2: '失败' }
</script>

<template>
  <div class="adm-page cl">
    <el-skeleton v-if="loading" :rows="6" animated style="padding: 20px" />
    <template v-else>
      <!-- 说明卡片 -->
      <div class="adm-card">
        <div class="adm-card__title">平台数据清除（最高风险操作）</div>
        <el-alert
          type="error"
          :closable="false"
          show-icon
          :title="`该操作将永久清除全部用户业务数据，不可恢复！执行前系统自动全库备份，操作人 / IP / 时间 / 原因完整记录审计日志。当前待清除数据共 ${preview?.totalRows ?? 0} 行。`"
          class="cl__alert"
        />

        <div class="cl__grid">
          <div class="cl__panel is-danger">
            <div class="cl__panel-title">清除范围（共 {{ preview?.totalRows ?? 0 }} 行业务数据）</div>
            <div class="cl__tables">
              <div v-for="t in (preview?.tables || []).slice(0, 18)" :key="t.table" class="cl__table-row">
                <code>{{ t.table }}</code>
                <span>{{ t.rows }} 行</span>
              </div>
              <div v-if="(preview?.tables || []).length > 18" class="t-tertiary" style="font-size:12px">
                … 及其余 {{ (preview?.tables || []).length - 18 }} 张业务表
              </div>
            </div>
          </div>
          <div class="cl__panel is-keep">
            <div class="cl__panel-title">受保护配置（绝不触碰）</div>
            <ul class="cl__list">
              <li v-for="p in (preview?.protected || [])" :key="p">{{ p }}</li>
            </ul>
            <div class="cl__sms-tip">
              <el-tag v-if="preview?.smsRequired" type="warning" effect="plain" size="small">
                短信验证码强制开启
              </el-tag>
              <el-tag v-else type="info" effect="plain" size="small">
                短信验证码已关闭（测试环境）
              </el-tag>
            </div>
          </div>
        </div>

        <div class="cl__actions">
          <el-button type="danger" size="large" @click="start">进入清除流程</el-button>
        </div>
      </div>

      <!-- 清库日志 -->
      <div class="adm-card">
        <div class="adm-card__title">清库执行日志</div>
        <el-table :data="logs" size="default">
          <el-table-column label="时间" prop="createdAt" width="170" />
          <el-table-column label="操作人" prop="adminName" width="110" />
          <el-table-column label="手机号" prop="adminPhone" width="130" />
          <el-table-column label="原因" prop="reason" min-width="180" show-overflow-tooltip />
          <el-table-column label="影响用户" prop="affectedUsers" width="90" align="right" />
          <el-table-column label="影响订单" prop="affectedOrders" width="90" align="right" />
          <el-table-column label="耗时" width="80" align="right">
            <template #default="{ row }">{{ row.executionTime }}s</template>
          </el-table-column>
          <el-table-column label="状态" width="80">
            <template #default="{ row }">
              <el-tag :type="row.status === 1 ? 'success' : 'danger'" effect="plain" size="small">
                {{ LOG_STATUS[row.status] || row.status }}
              </el-tag>
            </template>
          </el-table-column>
          <el-table-column label="备份文件" prop="backupPath" min-width="200" show-overflow-tooltip>
            <template #default="{ row }">
              <span class="cl__backup">{{ row.backupPath }}</span>
            </template>
          </el-table-column>
          <template #empty>
            <el-empty description="暂无清库记录" :image-size="60" />
          </template>
        </el-table>
        <div v-if="logsTotal > 10" class="cl__pager">
          <el-pagination
            layout="total, prev, pager, next"
            :total="logsTotal"
            :page-size="10"
            :current-page="logsPage"
            @current-change="onLogsPage"
          />
        </div>
      </div>
    </template>

    <!-- 四重安全确认弹窗 -->
    <el-dialog
      :model-value="step > 0 && step < 4"
      title="平台数据清除 · 安全确认"
      width="500px"
      :close-on-click-modal="false"
      :show-close="!executing"
    >
      <!-- Step 1：确认文本 + 清库原因 -->
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
          <el-form-item label="清库原因">
            <el-input
              v-model="reason"
              type="textarea"
              :rows="2"
              placeholder="不少于 5 字，将写入清库日志与审计记录"
              maxlength="255"
              show-word-limit
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
        <div class="cl__step-tip">
          第 3 / 3 步：短信验证码将发送至当前管理员绑定手机
          <b v-if="maskedPhone">{{ maskedPhone }}</b>（仅限本人手机，不可指定其他号码）。
        </div>
        <el-form label-width="110px">
          <el-form-item label="短信验证码">
            <div class="cl__sms">
              <el-input v-model="smsCode" placeholder="6 位验证码" maxlength="6" />
              <el-button :disabled="smsCountdown > 0 || sending" :loading="sending" @click="sendSms">
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
    <el-dialog :model-value="step === 4" title="清除完成" width="460px" :close-on-click-modal="false">
      <el-result icon="success" title="平台数据已清除" sub-title="执行结果已写入清库日志与审计日志，备份文件已生成">
        <template #extra>
          <div class="cl__result">
            <div class="cl__kv"><span>清除用户</span><b>{{ result?.affectedUsers ?? 0 }} 个</b></div>
            <div class="cl__kv"><span>清除订单</span><b>{{ result?.affectedOrders ?? 0 }} 笔</b></div>
            <div class="cl__kv"><span>执行耗时</span><b>{{ result?.executionTime ?? 0 }}s</b></div>
            <div class="cl__kv"><span>全库备份</span><b class="cl__backup">{{ result?.backupPath || '—' }}</b></div>
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

.cl__tables {
  max-height: 260px;
  overflow-y: auto;
}

.cl__table-row {
  display: flex;
  justify-content: space-between;
  font-size: 12px;
  padding: 3px 0;
  color: $color-text-secondary;

  code {
    font-family: 'JetBrains Mono', Consolas, monospace;
    font-size: 11px;
  }
}

.cl__list {
  margin: 0;
  padding-left: 18px;
  font-size: 12px;
  color: $color-text-secondary;
  line-height: 1.9;
}

.cl__sms-tip {
  margin-top: 10px;
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
  word-break: break-all;
}

.cl__pager {
  display: flex;
  justify-content: flex-end;
  margin-top: 12px;
}

@media (max-width: 768px) {
  .cl__grid { grid-template-columns: 1fr; }
}
</style>
