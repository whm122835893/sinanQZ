<script setup>
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useCollectionStore } from '@/stores/collection'
import AppNavBar from '@/components/AppNavBar.vue'
import AppTag from '@/components/AppTag.vue'
import AppButton from '@/components/AppButton.vue'

const route = useRoute()
const router = useRouter()
const store = useCollectionStore()

const exhibit = computed(() => store.fetchExhibit(route.params.id))

function back() { router.back() }
</script>

<template>
  <div class="exhibit-detail page">
    <AppNavBar title="文物详情" />

    <template v-if="exhibit">
      <!-- 主图 -->
      <div class="exhibit-detail__hero">
        <img class="exhibit-detail__image" :src="exhibit.image" :alt="exhibit.name" draggable="false" @contextmenu.prevent />
        <div class="exhibit-detail__tags">
          <AppTag type="primary">{{ exhibit.dynasty }}</AppTag>
          <AppTag>{{ exhibit.material }}</AppTag>
          <AppTag type="warn">{{ exhibit.level }}</AppTag>
        </div>
      </div>

      <!-- 标题区 -->
      <section class="exhibit-detail__card">
        <h1 class="exhibit-detail__name">{{ exhibit.name }}</h1>
        <div class="exhibit-detail__meta">
          <span>藏于 · {{ exhibit.location }}</span>
        </div>
        <p class="exhibit-detail__desc">{{ exhibit.desc }}</p>
      </section>

      <!-- 文物档案 -->
      <section class="exhibit-detail__card">
        <header class="exhibit-detail__section-title">
          <span class="exhibit-detail__bar"></span>文物档案
        </header>
        <table class="exhibit-detail__specs">
          <tbody>
            <tr v-for="s in exhibit.specs" :key="s[0]">
              <th>{{ s[0] }}</th>
              <td>{{ s[1] }}</td>
            </tr>
            <tr>
              <th>年代</th>
              <td>{{ exhibit.age }}</td>
            </tr>
          </tbody>
        </table>
      </section>

      <!-- 详细介绍 -->
      <section class="exhibit-detail__card">
        <header class="exhibit-detail__section-title">
          <span class="exhibit-detail__bar"></span>文物介绍
        </header>
        <div class="exhibit-detail__content">
          <p v-for="(p, i) in exhibit.detail" :key="i" class="exhibit-detail__p">{{ p }}</p>
        </div>
      </section>

      <div class="exhibit-detail__foot">
        <AppButton @click="back">返回展览区</AppButton>
      </div>
    </template>

    <div v-else class="exhibit-detail__empty">
      文物不存在或已撤展
      <div class="exhibit-detail__empty-btn">
        <AppButton @click="back">返回</AppButton>
      </div>
    </div>
  </div>
</template>

<style scoped lang="scss">
.exhibit-detail {
  min-height: 100vh;
  background: $color-bg;
  padding-bottom: calc(#{$tabbar-height} + env(safe-area-inset-bottom) + 16px);

  &__hero {
    position: relative;
    margin: 12px $page-padding 0;
    border-radius: $radius-lg;
    overflow: hidden;
    background: #141415;
  }
  &__image {
    display: block;
    width: 100%;
    aspect-ratio: 1 / 1;
    object-fit: cover;
    -webkit-user-drag: none; -webkit-touch-callout: none; user-select: none;
  }
  &__tags {
    position: absolute;
    left: 12px;
    bottom: 12px;
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
  }

  &__card {
    margin: 12px $page-padding 0;
    background: $color-card;
    border-radius: $radius-lg;
    padding: 16px;
  }

  &__name {
    margin: 0;
    font-size: 22px;
    font-weight: 700;
    color: $color-text-primary;
    line-height: 1.35;
  }
  &__meta {
    margin-top: 8px;
    font-size: 13px;
    color: $color-primary;
    font-weight: 500;
  }
  &__desc {
    margin: 12px 0 0;
    padding: 12px;
    background: linear-gradient(180deg, rgba(212,165,116,0.08), transparent);
    border-left: 3px solid $color-primary;
    border-radius: 0 8px 8px 0;
    font-size: 14px;
    color: $color-text-secondary;
    line-height: 1.75;
  }

  &__section-title {
    display: flex; align-items: center; gap: 10px;
    font-size: 16px; font-weight: 700; color: $color-text-primary;
    margin-bottom: 12px;
  }
  &__bar {
    width: 4px; height: 16px; border-radius: 2px; background: $color-primary;
  }

  &__specs {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
    th, td {
      padding: 10px 12px;
      border-bottom: 1px solid $color-border;
      text-align: left;
    }
    th {
      width: 32%;
      color: $color-text-secondary;
      font-weight: 500;
      background: transparent;
    }
    td {
      color: $color-text-primary;
      font-weight: 600;
    }
    tr:last-child th, tr:last-child td { border-bottom: none; }
  }

  &__content { font-size: 14px; line-height: 1.9; color: $color-text-primary; text-align: justify; }
  &__p { margin: 0 0 12px; }
  &__p:last-child { margin-bottom: 0; }

  &__foot { margin: 16px $page-padding 0; }

  &__empty {
    text-align: center;
    color: $color-text-tertiary;
    font-size: 14px;
    margin-top: 80px;
  }
  &__empty-btn { margin-top: 20px; padding: 0 48px; }
}
</style>
