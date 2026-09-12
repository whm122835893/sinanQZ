<script setup>
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useUserStore } from '@/stores/user'
import request from '@/utils/request'
import AppNavBar from '@/components/AppNavBar.vue'
import AppInput from '@/components/AppInput.vue'
import AppButton from '@/components/AppButton.vue'
import AppIcon from '@/components/AppIcon.vue'
import { showToast } from 'vant'

const router = useRouter()
const user = useUserStore()

// 实名状态（后端 realnameStatus）：0未提交 1待审核 2已通过 3已驳回
const status = computed(() => user.userInfo.realnameStatus ?? 0)
// 已认证则默认展示认证结果；驳回时展示原因并允许重新提交
const editing = ref(status.value !== 2)
const realName = ref('')
const idCard = ref('')
const submitting = ref(false)

const nameValid = computed(() => /^[\u4e00-\u9fa5·a-zA-Z]{2,15}$/.test(realName.value.trim()))
const idValid = computed(() => /^\d{17}[\dXx]$/.test(idCard.value.trim()))
const canSubmit = computed(() => nameValid.value && idValid.value && !submitting.value)

function nameError() {
  if (!realName.value) return ''
  return nameValid.value ? '' : '请输入 2-15 位真实姓名'
}

function idError() {
  if (!idCard.value) return ''
  return idValid.value ? '' : '请输入 18 位有效身份证号'
}

// 提交实名认证（真实接口：POST /api/user/realname，提交后进入待审核，管理端审核通过后 is_realname=1）
async function onSubmit() {
  if (!canSubmit.value) {
    if (!nameValid.value) showToast(nameError())
    else showToast(idError())
    return
  }
  submitting.value = true
  try {
    await request.post('/user/realname', {
      realName: realName.value.trim(),
      idCard: idCard.value.trim().toUpperCase()
    })
    showToast('已提交，等待审核')
    editing.value = false
    await user.fetchUserInfo()
  } catch (e) {
    showToast(e.message || '提交失败')
  } finally {
    submitting.value = false
  }
}
function onEdit() {
  editing.value = true
}
</script>

<template>
  <div class="realname page--no-tabbar">
    <AppNavBar title="实名认证" @click-left="$router.back()" />

    <!-- 已认证 / 审核中 结果 -->
    <template v-if="!editing">
      <div class="realname-done">
        <div class="realname-done__icon">
          <AppIcon name="shield" :size="28" color="#fff" />
        </div>
        <p class="realname-done__title">{{ status === 2 ? '已通过实名认证' : '实名认证审核中' }}</p>
        <p class="realname-done__desc">
          {{ status === 2 ? '实名信息已加密存储，仅用于钱包开通与提现校验' : '工作人员正在审核您的实名信息，审核通过后即可参与中签购买' }}
        </p>
      </div>

      <div class="realname-result">
        <div class="realname-result__row">
          <span>认证状态</span>
          <b :class="status === 2 ? 'ok' : 'pending'">{{ status === 2 ? '已认证' : '审核中' }}</b>
        </div>
        <div v-if="status === 3 && user.userInfo.realnameRejectReason" class="realname-result__row">
          <span>驳回原因</span><b class="fail">{{ user.userInfo.realnameRejectReason }}</b>
        </div>
      </div>

      <div v-if="status === 2" class="realname-actions">
        <AppButton type="outline" @click="onEdit">修改认证信息</AppButton>
      </div>
    </template>

    <!-- 认证表单 -->
    <template v-else>
      <p v-if="status === 3 && user.userInfo.realnameRejectReason" class="realname-reject">
        驳回原因：{{ user.userInfo.realnameRejectReason }}，请修改后重新提交
      </p>
      <p class="realname-tip">
        实名认证用于开通钱包与提现，请填写本人真实信息，信息提交后不可随意更改。
      </p>

      <div class="realname-form">
        <AppInput
          v-model="realName"
          label="真实姓名"
          placeholder="请输入真实姓名"
          :error="nameError()"
        />
        <AppInput
          v-model="idCard"
          label="身份证号"
          placeholder="请输入 18 位身份证号"
          maxlength="18"
          style="margin-top:16px"
          :error="idError()"
        />
        <AppButton :disabled="!canSubmit" style="margin-top:24px" @click="onSubmit">提交认证</AppButton>
      </div>

      <div class="realname-notice">
        <h3 class="realname-notice__title">认证须知</h3>
        <p>1. 实名信息须与本人身份证件一致，虚假信息将导致提现失败。</p>
        <p>2. 平台采用加密存储，不会向第三方泄露您的实名信息。</p>
        <p>3. 每个账号仅可绑定一个实名身份，认证后如需修改请联系客服。</p>
      </div>
    </template>
  </div>
</template>

<style scoped lang="scss">
.realname-tip {
  margin: 16px; font-size: 13px; color: $color-text-secondary; line-height: 1.6;
}
.realname-reject {
  margin: 16px 16px 0; padding: 10px 12px; font-size: 13px; line-height: 1.6;
  color: #e54d42; background: rgba(229, 77, 66, 0.08); border-radius: $radius-md;
}
.realname-form { padding: 0 16px; }

.realname-notice {
  padding: 16px; margin: 16px; background: $color-card; border-radius: $radius-lg;
  &__title { margin: 0 0 12px; font-size: 15px; font-weight: 700; color: $color-text-primary; }
  p { margin: 0 0 8px; font-size: 13px; color: $color-text-secondary; line-height: 1.6; }
}

/* 已认证 */
.realname-done {
  margin: 24px 16px 0; text-align: center;
  &__icon {
    width: 56px; height: 56px; border-radius: 50%; margin: 0 auto 14px;
    background: $color-primary; display: flex; align-items: center; justify-content: center;
  }
  &__title { margin: 0 0 6px; font-size: 17px; font-weight: 700; color: $color-text-primary; }
  &__desc { margin: 0; font-size: 12px; color: $color-text-tertiary; line-height: 1.5; }
}
.realname-result {
  margin: 20px 16px 0; background: $color-card; border-radius: $radius-lg; padding: 4px 16px;
  &__row {
    display: flex; align-items: center; justify-content: space-between;
    min-height: 52px; font-size: 14px; color: $color-text-secondary;
    &:not(:last-child) { border-bottom: 1px solid $color-border; }
    b { color: $color-text-primary; font-weight: 600; }
    .ok { color: $color-primary; }
    .pending { color: #ff976a; }
    .fail { color: #e54d42; }
  }
}
.realname-actions { padding: 20px 16px 0; }
</style>
