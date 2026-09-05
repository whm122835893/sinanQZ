<script setup>
import { useRouter } from 'vue-router'

const router = useRouter()
</script>

<template>
  <div class="nf safe-top">
    <div class="nf__deco" />
    <div class="nf__card">
      <div class="nf__code calligraphy">肆零肆</div>
      <div class="nf__title">页面不存在</div>
      <div class="nf__desc">您访问的页面已下架或地址有误</div>
      <van-button round type="primary" block style="margin-top: 24px" @click="router.replace('/dashboard')">
        返回数据看板
      </van-button>
    </div>
  </div>
</template>

<style scoped lang="scss">
.nf {
  min-height: 100vh;
  @include flex-center;
  background:
    radial-gradient(600px 300px at 85% -5%, rgba(212, 165, 116, 0.14), transparent 60%),
    radial-gradient(500px 260px at 0% 100%, rgba(192, 0, 0, 0.05), transparent 55%),
    $color-bg;
  position: relative;
  overflow: hidden;
}

.nf__deco {
  position: absolute;
  width: 220px;
  height: 220px;
  border-radius: 50%;
  border: 34px solid rgba(192, 0, 0, 0.04);
  top: -80px;
  right: -60px;
}

.nf__card {
  width: 100%;
  max-width: 340px;
  background: $color-card;
  border-radius: 18px;
  padding: 36px 24px;
  box-shadow: 0 18px 50px rgba(26, 26, 26, 0.08);
  text-align: center;
  position: relative;
  z-index: 1;
}

.nf__code {
  font-size: 56px;
  color: $color-primary;
  line-height: 1;
}

.nf__title {
  font-size: 17px;
  font-weight: 700;
  margin-top: 14px;
}

.nf__desc {
  font-size: 12px;
  color: $color-text-tertiary;
  margin-top: 6px;
}
</style>
