<script setup>
import { ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { View } from '@element-plus/icons-vue'
import { getUserList, auditRealname } from '@/api'
import AdminTablePage from '@/components/AdminTablePage.vue'
import StatusTag from '@/components/StatusTag.vue'
import PasswordVerify from '@/components/PasswordVerify.vue'
import { REALNAME_STATUS } from '@/utils/maps'
import { maskPhone, maskName, maskIdNo } from '@/utils/format'

// 实名模块只读 + 审核；查看完整信息需密码验证（写审计日志）
const drawerShow = ref(false)
const detail = ref(null)
const revealed = ref(false)
const pwdShow = ref(false)
const rejectShow = ref(false)
const rejectReason = ref('')

const filters = [
  {
    field: 'realnameStatus',
    label: '状态',
    options: [
      { value: 'pending', label: '待审核' },
      { value: 'approved', label: '已实名' },
      { value: 'rejected', label: '已驳回' }
    ]
  }
]

function openDetail(u) {
  detail.value = u
  revealed.value = false
  drawerShow.value = true
}

function onReveal() {
  pwdShow.value = true
}

function onRevealed() {
  revealed.value = true
  ElMessage.success('已查看完整信息，本次查看已写入审计日志')
}

async function audit(pass) {
  const u = detail.value
  if (pass) {
    await ElMessageBox.confirm(`确认通过「${u.realnameName}」的实名审核？`, '通过实名审核', { type: 'warning' })
  } else {
    rejectShow.value = true
    return
  }
  const res = await auditRealname(u.id, true)
  if (res.code === 0) {
    u.realnameStatus = 'approved'
    ElMessage.success('已通过')
    drawerShow.value = false
  }
}

async function onRejectConfirm() {
  if (!rejectReason.value.trim()) return ElMessage.warning('请填写驳回原因')
  const res = await auditRealname(detail.value.id, false)
  if (res.code === 0) {
    detail.value.realnameStatus = 'rejected'
    ElMessage.success('已驳回')
    rejectShow.value = false
    drawerShow.value = false
  }
}
</script>

<template>
  <div class="adm-page">
    <AdminTablePage
      :fetch="getUserList"
      :filters="filters"
      :defaults="{ realnameStatus: 'pending' }"
      search-placeholder="搜索昵称 / 手机号"
    >
      <template #default="{ items }">
        <el-table-column label="申请人" min-width="180">
          <template #default="{ row }">
            <div class="rn-cell" @click="openDetail(row)">
              <img class="rn-avatar" :src="row.avatar" :alt="row.nickname" />
              <div>
                <div class="rn-name">{{ maskName(row.realnameName) }}（{{ row.nickname }}）</div>
                <div class="rn-sub">身份证 {{ maskIdNo(row.realnameIdNo) }}</div>
              </div>
            </div>
          </template>
        </el-table-column>

        <el-table-column label="绑定手机" width="130">
          <template #default="{ row }">{{ maskPhone(row.phone) }}</template>
        </el-table-column>

        <el-table-column label="审核状态" width="100">
          <template #default="{ row }">
            <StatusTag :value="row.realnameStatus" :map="REALNAME_STATUS" />
          </template>
        </el-table-column>

        <el-table-column label="最近登录" width="160" prop="lastLoginTime" />

        <el-table-column label="操作" width="80" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" size="small" @click="openDetail(row)">审核</el-button>
          </template>
        </el-table-column>
      </template>
    </AdminTablePage>

    <!-- 审核抽屉 -->
    <el-drawer
      v-model="drawerShow"
      :title="detail?.realnameName ? `实名审核 · ${maskName(detail.realnameName)}` : '实名审核'"
      size="420px"
    >
      <template v-if="detail">
        <div class="adm-card" style="box-shadow: none">
          <div class="adm-card__title">审核材料</div>
          <div class="rn__idcard">
            <img :src="detail.avatar" alt="证件照片" />
          </div>
          <div class="adm-kv">
            <span class="k">真实姓名</span>
            <span class="v">
              {{ revealed ? detail.realnameName : maskName(detail.realnameName) }}
            </span>
          </div>
          <div class="adm-kv">
            <span class="k">身份证号</span>
            <span class="v">
              {{ revealed ? detail.realnameIdNo : maskIdNo(detail.realnameIdNo) }}
              <el-button v-if="!revealed" link type="primary" size="small" :icon="View" @click="onReveal">
                查看完整
              </el-button>
            </span>
          </div>
          <div class="adm-kv">
            <span class="k">绑定手机</span>
            <span class="v">{{ revealed ? detail.phone : maskPhone(detail.phone) }}</span>
          </div>
          <div class="adm-kv"><span class="k">账号昵称</span><span class="v">{{ detail.nickname }}</span></div>
          <div class="adm-kv"><span class="k">注册时间</span><span class="v">{{ detail.registerTime }}</span></div>

          <el-alert
            type="info"
            :closable="false"
            show-icon
            title="请核对姓名与身份证号一致、证件清晰无遮挡；每次查看完整信息均写入审计日志"
            style="margin-top: 10px"
          />

          <div v-if="detail.realnameStatus === 'pending'" class="rn__actions">
            <el-button type="danger" plain @click="audit(false)">驳回</el-button>
            <el-button type="primary" @click="audit(true)">通过审核</el-button>
          </div>
        </div>
      </template>
    </el-drawer>

    <!-- 驳回原因 -->
    <el-dialog v-model="rejectShow" title="驳回实名申请" width="420px">
      <el-alert
        type="warning"
        :closable="false"
        show-icon
        title="驳回后用户可重新提交实名认证"
        style="margin-bottom: 14px"
      />
      <el-input
        v-model="rejectReason"
        type="textarea"
        :rows="3"
        placeholder="请填写驳回原因（必填，将通知用户）"
      />
      <template #footer>
        <el-button @click="rejectShow = false">取消</el-button>
        <el-button type="danger" @click="onRejectConfirm">确认驳回</el-button>
      </template>
    </el-dialog>

    <!-- 查看完整信息密码验证 -->
    <PasswordVerify
      v-model="pwdShow"
      title="查看完整实名信息"
      tip="查看用户完整实名信息（含身份证号全量）属敏感操作，需管理员密码验证并记录审计日志"
      @verified="onRevealed"
    />
  </div>
</template>

<style scoped lang="scss">
.rn-cell {
  display: flex;
  align-items: center;
  gap: 10px;
  cursor: pointer;
}

.rn-avatar {
  width: 38px;
  height: 38px;
  border-radius: 50%;
  object-fit: cover;
  flex-shrink: 0;
  border: 1px solid $color-border;
}

.rn-name { font-size: 13px; font-weight: 600; color: $color-text-primary; }
.rn-sub { font-size: 12px; color: $color-text-tertiary; margin-top: 2px; }

.rn__idcard {
  height: 150px;
  border-radius: 8px;
  overflow: hidden;
  margin-bottom: 12px;
  background: $color-surface;
  display: flex;
  align-items: center;
  justify-content: center;

  img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }
}

.rn__actions {
  display: flex;
  gap: 10px;
  margin-top: 16px;

  .el-button { flex: 1; }
}
</style>
