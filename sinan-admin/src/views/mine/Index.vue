<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useAdminStore } from '@/stores/admin'
import { logout } from '@/api'
import { ROLE_MAP } from '@/utils/maps'

const router = useRouter()
const admin = useAdminStore()

const pwdShow = ref(false)
const pwdForm = ref({ oldPwd: '', newPwd: '', confirmPwd: '' })
const pwdSubmitting = ref(false)

async function onLogout() {
  await ElMessageBox.confirm('确认退出管理后台？', '退出登录', { type: 'warning' })
  await logout()
  admin.clearSession()
  router.replace('/login')
}

function onChangePwd() {
  pwdForm.value = { oldPwd: '', newPwd: '', confirmPwd: '' }
  pwdShow.value = true
}

async function onPwdSubmit() {
  const { oldPwd, newPwd, confirmPwd } = pwdForm.value
  if (!oldPwd || !newPwd) return ElMessage.warning('请填写完整')
  if (newPwd.length < 8) return ElMessage.warning('新密码至少 8 位')
  if (newPwd !== confirmPwd) return ElMessage.warning('两次输入的新密码不一致')
  pwdSubmitting.value = true
  await new Promise((r) => setTimeout(r, 400))
  pwdSubmitting.value = false
  pwdShow.value = false
  ElMessage.success('密码已修改（Mock），下次登录生效')
}

const securityItems = [
  { label: '登录密码', desc: '定期修改密码可提升账号安全性', action: '修改', handler: onChangePwd },
  { label: '两步验证（2FA）', desc: 'TOTP 动态口令，登录时二次校验（联调后端后启用）', action: '开启', handler: () => ElMessage.info('2FA 配置联调后端后开放') },
  { label: 'IP 白名单', desc: '限制仅白名单内 IP 可登录后台（联调后端后启用）', action: '配置', handler: () => ElMessage.info('IP 白名单联调后端后开放') }
]
</script>

<template>
  <div class="adm-page mine">
    <div class="mine__split">
      <!-- 左：账号信息 -->
      <div class="adm-card mine__profile">
        <div class="adm-card__title">账号信息</div>
        <div class="mine__avatar-row">
          <img class="mine__avatar" :src="admin.info?.avatar || '/images/platform-logo.png'" alt="avatar" />
          <div>
            <div class="mine__name">{{ admin.displayName }}</div>
            <div class="mine__role">
              <el-tag :type="ROLE_MAP[admin.info?.role]?.type || 'primary'" effect="plain" round size="small">
                {{ admin.roleLabel }}
              </el-tag>
            </div>
          </div>
        </div>

        <div class="adm-kv"><span class="k">登录账号</span><span class="v">{{ admin.info?.username }}</span></div>
        <div class="adm-kv"><span class="k">管理员角色</span><span class="v">{{ admin.roleLabel }}</span></div>
        <div class="adm-kv"><span class="k">权限码数量</span><span class="v">{{ admin.permissions?.length || 0 }} 项</span></div>

        <el-button type="danger" plain class="mine__logout" @click="onLogout">退出登录</el-button>
      </div>

      <!-- 右：安全设置 -->
      <div class="adm-card">
        <div class="adm-card__title">安全设置</div>
        <div v-for="s in securityItems" :key="s.label" class="mine__sec">
          <div class="mine__sec-body">
            <div class="mine__sec-label">{{ s.label }}</div>
            <div class="mine__sec-desc">{{ s.desc }}</div>
          </div>
          <el-button link type="primary" @click="s.handler">{{ s.action }}</el-button>
        </div>

        <el-alert
          type="info"
          :closable="false"
          show-icon
          title="当前为纯前端 Mock 演示版本，安全能力（2FA / IP 白名单 / 登录锁定）将在联调后端后生效"
          style="margin-top: 6px"
        />
      </div>
    </div>

    <div class="mine__version t-tertiary">
      司南珍藏管理后台 · v0.2.0（Mock 演示，未联调后端）
    </div>

    <!-- 修改密码 -->
    <el-dialog v-model="pwdShow" title="修改登录密码" width="420px" :close-on-click-modal="false">
      <el-form label-width="90px" @submit.prevent>
        <el-form-item label="当前密码">
          <el-input v-model="pwdForm.oldPwd" type="password" show-password placeholder="请输入当前密码" />
        </el-form-item>
        <el-form-item label="新密码">
          <el-input v-model="pwdForm.newPwd" type="password" show-password placeholder="至少 8 位，含字母与数字" />
        </el-form-item>
        <el-form-item label="确认新密码">
          <el-input v-model="pwdForm.confirmPwd" type="password" show-password placeholder="再次输入新密码" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="pwdShow = false">取消</el-button>
        <el-button type="primary" :loading="pwdSubmitting" @click="onPwdSubmit">确认修改</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped lang="scss">
.mine__split {
  display: grid;
  grid-template-columns: 340px 1fr;
  gap: 14px;
  align-items: start;

  @media (max-width: 992px) {
    grid-template-columns: 1fr;
  }
}

.mine__avatar-row {
  display: flex;
  align-items: center;
  gap: 14px;
  margin-bottom: 16px;
}

.mine__avatar {
  width: 56px;
  height: 56px;
  border-radius: 50%;
  object-fit: cover;
  border: 2px solid rgba(192, 0, 0, 0.2);
  flex-shrink: 0;
}

.mine__name {
  font-size: 17px;
  font-weight: 700;
}

.mine__role { margin-top: 6px; }

.mine__logout { width: 100%; margin-top: 18px; }

.mine__sec {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14px 0;
  border-bottom: 1px solid $color-border;

  &:last-of-type { border-bottom: none; }
}

.mine__sec-label { font-size: 14px; font-weight: 600; color: $color-text-primary; }
.mine__sec-desc { font-size: 12px; color: $color-text-tertiary; margin-top: 4px; }

.mine__version {
  text-align: center;
  font-size: 11px;
  padding: 10px 0 4px;
}
</style>
