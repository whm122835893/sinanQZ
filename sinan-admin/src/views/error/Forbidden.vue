<script setup lang="ts">
// 403 无权限页
import { useRouter } from 'vue-router'

const router = useRouter()
</script>

<template>
  <div class="error-page">
    <div class="error-box">
      <div class="error-code">403</div>
      <div class="error-title">无权访问</div>
      <div class="error-desc">当前角色没有该页面的访问权限，请联系超级管理员开通</div>
      <el-button type="primary" @click="router.push('/dashboard')">返回首页</el-button>
    </div>
  </div>
</template>

<style scoped lang="scss">
.error-page {
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  background: $sn-bg;
}

.error-box {
  text-align: center;

  .error-code {
    font-size: 88px;
    font-weight: 700;
    line-height: 1;
    background: linear-gradient(135deg, #d00000 0%, #a00000 100%);
    -webkit-background-clip: text;
    background-clip: text;
    -webkit-text-fill-color: transparent;
    font-family: 'DIN Alternate', 'Bahnschrift', 'Arial Narrow', sans-serif;
  }

  .error-title {
    font-size: 20px;
    font-weight: 600;
    color: $sn-text-main;
    margin: 16px 0 8px;
  }

  .error-desc {
    font-size: 13px;
    color: $sn-text-muted;
    margin-bottom: 24px;
  }
}
</style>
