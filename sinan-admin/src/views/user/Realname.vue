<script setup>
import { ref } from 'vue'
import { showSuccessToast, showConfirmDialog } from 'vant'
import { getUserList, auditRealname } from '@/api'
import AdminListPage from '@/components/AdminListPage.vue'
import DetailSheet from '@/components/DetailSheet.vue'
import StatusTag from '@/components/StatusTag.vue'
import { REALNAME_STATUS } from '@/utils/maps'

const sheetShow = ref(false)
const detail = ref(null)

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
  sheetShow.value = true
}

async function audit(pass) {
  const u = detail.value
  await showConfirmDialog({
    title: pass ? '通过实名审核' : '驳回实名申请',
    message: pass
      ? `确认通过「${u.realnameName}」的实名审核？`
      : `确认驳回「${u.realnameName}」的实名申请？驳回后用户可重新提交。`
  })
  const res = await auditRealname(u.id, pass)
  if (res.code === 0) {
    u.realnameStatus = pass ? 'approved' : 'rejected'
    showSuccessToast(pass ? '已通过' : '已驳回')
    sheetShow.value = false
  }
}
</script>

<template>
  <div class="adm-page">
    <AdminListPage
      :fetch="getUserList"
      :filters="filters"
      :defaults="{ realnameStatus: 'pending' }"
      search-placeholder="搜索昵称 / 手机号"
    >
      <template #default="{ items }">
        <div class="adm-card" style="padding: 0">
          <div
            v-for="u in items"
            :key="u.id"
            class="adm-item"
            style="padding: 12px 14px"
            @click="openDetail(u)"
          >
            <img class="rn__avatar" :src="u.avatar" :alt="u.nickname" />
            <div class="adm-item__body">
              <div class="adm-item__title">{{ u.nickname }}（{{ u.realnameName }}）</div>
              <div class="adm-item__desc">身份证 {{ u.realnameIdNo }}</div>
              <div class="adm-item__desc">{{ u.phone }}</div>
            </div>
            <div class="adm-item__side">
              <StatusTag :value="u.realnameStatus" :map="REALNAME_STATUS" />
              <div class="t-tertiary" style="font-size: 11px; margin-top: 6px">最近登录 {{ u.lastLoginTime }}</div>
            </div>
          </div>
        </div>
      </template>
    </AdminListPage>

    <DetailSheet v-model:show="sheetShow" :title="detail?.realnameName ? `实名审核 · ${detail.realnameName}` : '实名审核'">
      <template v-if="detail">
        <div class="adm-card" style="margin-bottom: 10px">
          <div class="adm-card__title">审核材料</div>
          <div class="rn__idcard">
            <img :src="detail.avatar" alt="证件照片" />
          </div>
          <div class="adm-kv"><span class="k">真实姓名</span><span class="v">{{ detail.realnameName }}</span></div>
          <div class="adm-kv"><span class="k">身份证号</span><span class="v">{{ detail.realnameIdNo }}</span></div>
          <div class="adm-kv"><span class="k">绑定手机</span><span class="v">{{ detail.phone }}</span></div>
          <div class="adm-kv"><span class="k">账号昵称</span><span class="v">{{ detail.nickname }}</span></div>
          <div class="adm-kv"><span class="k">注册时间</span><span class="v">{{ detail.registerTime }}</span></div>
          <van-notice-bar
            left-icon="info-o"
            text="请核对姓名与身份证号一致、证件清晰无遮挡（联调后此处展示实拍证件照）"
            style="margin-top: 8px"
          />
        </div>
      </template>

      <template #actions v-if="detail?.realnameStatus === 'pending'">
        <van-button block round plain type="danger" @click="audit(false)">驳回</van-button>
        <van-button block round type="primary" @click="audit(true)">通过审核</van-button>
      </template>
    </DetailSheet>
  </div>
</template>

<style scoped lang="scss">
.rn__avatar {
  width: 42px;
  height: 42px;
  border-radius: 50%;
  object-fit: cover;
  flex-shrink: 0;
  border: 1px solid $color-border;
}

.rn__idcard {
  height: 150px;
  border-radius: $radius-md;
  overflow: hidden;
  margin-bottom: 10px;
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
</style>
