<script setup>
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useUserStore } from '@/stores/user'
import AppNavBar from '@/components/AppNavBar.vue'
import AppListItem from '@/components/AppListItem.vue'
import AppInput from '@/components/AppInput.vue'
import { showToast } from 'vant'

const router = useRouter()
const user = useUserStore()

const realNameText = computed(() => (user.userInfo.isRealName ? '已认证' : '未认证'))

// 昵称行内编辑
const editingNick = ref(false)
const nickTemp = ref(user.userInfo.nickname)

function startEdit() {
  nickTemp.value = user.userInfo.nickname
  editingNick.value = true
}
function saveNick() {
  const v = nickTemp.value.trim()
  if (v.length < 2) { showToast('昵称至少 2 个字符'); return }
  user.setUserInfo({ nickname: v })
  editingNick.value = false
  showToast('昵称已更新')
}
</script>

<template>
  <div class="profile page--no-tabbar">
    <AppNavBar title="个人信息" @click-left="$router.back()" />

    <div class="profile-group">
      <AppListItem title="头像">
        <template #value>
          <img class="profile-avatar" src="/images/avatar-new.png" alt="" />
        </template>
      </AppListItem>

      <!-- 昵称：点击行内编辑 -->
      <AppListItem title="昵称" :arrow="!editingNick" border @click="!editingNick && startEdit()">
        <template #value>
          <template v-if="!editingNick">{{ user.userInfo.nickname }}</template>
        </template>
      </AppListItem>
      <div class="profile-nick-edit" v-if="editingNick">
        <AppInput v-model="nickTemp" placeholder="请输入昵称" maxlength="20" />
        <div class="profile-nick-edit__btns">
          <button class="ghost" @click="editingNick = false">取消</button>
          <button class="solid" @click="saveNick">保存</button>
        </div>
      </div>

      <AppListItem title="实名认证" :arrow="true" border @click="router.push('/user/realname')">
        <template #value>
          <span :class="user.userInfo.isRealName ? 'profile-ok' : 'profile-warn'">{{ realNameText }}</span>
        </template>
      </AppListItem>
      <AppListItem title="手机号" :value="user.userInfo.phone" border />
      <AppListItem title="钱包地址" :value="user.userInfo.walletAddress" :arrow="true" border />
      <AppListItem title="社交信息" value="未绑定" :arrow="true" border @click="showToast('社交账号绑定开发中')" />
    </div>
  </div>
</template>

<style scoped lang="scss">
.profile-group { margin: 12px $page-padding; border-radius: $radius-lg; overflow: hidden; }
.profile-avatar {
  width: 36px; height: 36px; border-radius: 50%; object-fit: cover; background: #fff;
}
.profile-nick-edit { padding: 12px 16px; background: $color-card; border-bottom: 1px solid $color-border; }
.profile-nick-edit__btns { display: flex; gap: 12px; margin-top: 12px; }
.profile-nick-edit__btns button {
  flex: 1; height: 38px; border-radius: $radius-pill; font-size: 14px; cursor: pointer;
  &.ghost { background: $color-surface; color: $color-text-primary; border: none; }
  &.solid { background: $color-primary; color: #fff; border: none; }
}
:deep(.app-list-item__value) { color: $color-text-tertiary; }
.profile-ok { color: $color-primary !important; }
.profile-warn { color: $color-text-tertiary; }
</style>
