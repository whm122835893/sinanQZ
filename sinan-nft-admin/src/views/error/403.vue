<template>
  <div class="error-page">
    <div class="code">403</div>
    <div class="text">抱歉，您没有访问该页面的权限</div>
    <el-button type="primary" @click="$router.push('/dashboard')">返回首页</el-button>
  </div>
</template>

<style scoped>
.error-page {
  height: 100%;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 16px;
  background: var(--sn-bg);
}
.code {
  font-size: 88px;
  font-weight: 700;
  color: var(--sn-red);
  line-height: 1;
}
.text {
  color: var(--sn-text-secondary);
}
</style>
