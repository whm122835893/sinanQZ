<script setup>
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useUserStore } from '@/stores/user'
import AppNavBar from '@/components/AppNavBar.vue'
import AppInput from '@/components/AppInput.vue'
import AppButton from '@/components/AppButton.vue'
import AppIcon from '@/components/AppIcon.vue'
import { showToast } from 'vant'

const router = useRouter()
const user = useUserStore()

// 已认证则默认展示认证结果，可再次编辑
const editing = ref(!user.userInfo.isRealName)
const realName = ref(user.userInfo.realName || '')
const idCard = ref(user.userInfo.idCard || '')

const nameValid = computed(() => /^[\u4e00-\u9fa5·a-zA-Z]{2,15}$/.test(realName.value.trim()))
const idValid = computed(() => /^\d{17}[\dXx]$/.test(idCard.value.trim()))
const canSubmit = computed(() => nameValid.value && idValid.value)

function nameError() {
  if (!realName.value) return ''
  return nameValid.value ? '' : '请输入 2-15 位真实姓名'
}
function idError() {
  if (!idCard.value) return ''
  return idValid.value ? '' : '请输入 18 位有效身份证号'
}

const maskedName = computed(() => {
  const n = user.userInfo.realName || realName.value
  if (!n) return ''
  return n.length <= 1 ? n : n[0] + '*'.repeat(n.length - 1)
})
const maskedId = computed(() => {
  const id = user.userInfo.idCard || idCard.value
  if (!id || id.length < 8) return id
  return id.slice(0, 4) + ' ********** ' + id.slice(-4)
})

function onSubmit() {
  if (!canSubmit.value) {
    if (!nameValid.value) showToast(nameError())
    else showToast(idError())
    return
  }
  user.setUserInfo({
    isRealName: true,
    realName: realName.value.trim(),
    idCard: idCard.value.trim().toUpperCase()
  })
  showToast('认证提交成功')
  editing.value = false
}
function onEdit() {
  editing.value = true
}
</script>

<template>
  <div class="realname page--no-tabbar">
    <AppNavBar title="实名认证" @click-left="$router.back()" />

    <!-- 已认证结果 -->
    <template v-if="!editing">
      <div class="realname-done">
        <div class="realname-done__icon">
          <AppIcon name="shield" :size="28" color="#fff" />
        </div>
        <p class="realname-done__title">已通过实名认证</p>
        <p class="realname-done__desc">实名信息已加密存储，仅用于钱包开通与提现校验</p>
      </div>

      <div class="realname-result">
        <div class="realname-result__row">
          <span>真实姓名</span><b>{{ maskedName }}</b>
        </div>
        <div class="realname-result__row">
          <span>身份证号</span><b>{{ maskedId }}</b>
        </div>
        <div class="realname-result__row">
          <span>认证状态</span><b class="ok">已认证</b>
        </div>
      </div>

      <div class="realname-actions">
        <AppButton type="outline" @click="onEdit">修改认证信息</AppButton>
      </div>
    </template>

    <!-- 认证表单 -->
    <template v-else>
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
  }
}
.realname-actions { padding: 20px 16px 0; }
</style>
