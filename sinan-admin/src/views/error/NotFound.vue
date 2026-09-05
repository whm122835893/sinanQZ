<script setup lang="ts">
// 404 页面不存在
import { useRouter } from 'vue-router'

const router = useRouter()
</script>

<template>
  <div class="error-page">
    <div class="error-box">
      <div class="error-code">404</div>
      <div class="error-title">页面不存在</div>
      <div class="error-desc">您访问的页面可能已被移除或地址有误</div>
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
    background: linear-gradient(135deg, #2a2f3a 0%, #1a1a1a 100%);
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
