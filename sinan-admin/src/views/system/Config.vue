<script setup>
import { ref, onMounted } from 'vue'
import { showSuccessToast } from 'vant'
import { getSiteConfig, saveSiteConfig } from '@/api'

const loading = ref(true)
const saving = ref(false)
const form = ref({
  siteName: '',
  announcement: '',
  maintenance: 0,
  icp: '',
  kefuQr: '/images/brand-logo.png'
})

onMounted(async () => {
  const res = await getSiteConfig()
  form.value = { ...res.data }
  loading.value = false
})

async function onSave() {
  saving.value = true
  const res = await saveSiteConfig({ ...form.value })
  saving.value = false
  if (res.code === 0) showSuccessToast('配置已保存')
}
</script>

<template>
  <div class="adm-page sc">
    <van-skeleton v-if="loading" title :row="6" style="padding: 16px" />
    <template v-else>
      <div class="adm-card">
        <div class="adm-card__title">基础配置</div>
        <van-field v-model="form.siteName" label="站点名称" placeholder="C 端标题栏展示名称" />
        <van-field
          v-model="form.announcement"
          type="textarea"
          rows="2"
          maxlength="60"
          show-word-limit
          label="全局公告"
          placeholder="C 端首页顶部滚动公告"
        />
        <van-field name="maintenance" label="维护模式">
          <template #input>
            <van-switch v-model="form.maintenance" :active-value="1" :inactive-value="0" size="20px" />
          </template>
        </van-field>
        <div class="t-tertiary" style="font-size: 11px; padding: 6px 16px 0">
          开启维护模式后 C 端将展示维护页，仅管理员可正常访问
        </div>
      </div>

      <div class="adm-card">
        <div class="adm-card__title">备案与合规</div>
        <van-field v-model="form.icp" label="ICP 备案号" placeholder="如：京ICP备2026000000号-1" />
        <van-field
          v-model="form.kefuQr"
          label="客服二维码"
          placeholder="客服企微二维码图片地址"
          right-icon="qr"
        />
        <div class="sc__qr-preview">
          <img :src="form.kefuQr" alt="客服二维码" />
          <span class="t-tertiary" style="font-size: 11px">「我的 · 联系客服」处展示</span>
        </div>
      </div>

      <div class="sc__submit">
        <van-button block round type="primary" :loading="saving" @click="onSave">保存配置</van-button>
      </div>
    </template>
  </div>
</template>

<style scoped lang="scss">
.sc__qr-preview {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 16px 4px;

  img {
    width: 64px;
    height: 64px;
    border-radius: 8px;
    border: 1px solid $color-border;
    object-fit: cover;
  }
}

.sc__submit {
  padding: 4px 2px 20px;
}
</style>
