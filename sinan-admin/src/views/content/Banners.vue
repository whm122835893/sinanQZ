<script setup>
import { ref, onMounted } from 'vue'
import { showSuccessToast } from 'vant'
import { getBanners, saveBanner } from '@/api'
import DetailSheet from '@/components/DetailSheet.vue'

const loading = ref(true)
const banners = ref([])

const editShow = ref(false)
const editing = ref(null)
const form = ref({ title: '', image: '', link: '', sort: 1, status: 1 })

const imageOptions = [
  '/images/hero/slide-1.jpg',
  '/images/hero/slide-2.jpg',
  '/images/hero/slide-3.jpg'
]

onMounted(load)

async function load() {
  loading.value = true
  const res = await getBanners()
  banners.value = res.data
  loading.value = false
}

function openEdit(b) {
  editing.value = b
  form.value = { title: b.title, image: b.image, link: b.link, sort: b.sort, status: b.status }
  editShow.value = true
}

async function onSave() {
  const res = await saveBanner({ id: editing.value?.id, ...form.value })
  if (res.code === 0) {
    showSuccessToast('已保存')
    editShow.value = false
    load()
  }
}

async function onToggle(b) {
  const res = await saveBanner({ id: b.id, status: b.status === 1 ? 0 : 1 })
  if (res.code === 0) {
    b.status = b.status === 1 ? 0 : 1
    showSuccessToast(b.status === 1 ? '已上架' : '已下架')
  }
}
</script>

<template>
  <div class="adm-page bn">
    <div class="adm-toolbar">
      <div class="t-secondary" style="font-size: 12px">C 端首页轮播图（按 sort 升序播放）</div>
    </div>

    <van-skeleton v-if="loading" title :row="4" style="padding: 16px" />
    <div v-else class="adm-card" v-for="b in banners" :key="b.id">
      <div class="bn__main">
        <img class="bn__img" :src="b.image" :alt="b.title" />
        <div class="adm-item__body">
          <div class="adm-item__title">
            {{ b.title }}
            <van-tag v-if="b.status === 1" type="success" plain round size="medium">上架中</van-tag>
            <van-tag v-else type="default" plain round size="medium">已下架</van-tag>
          </div>
          <div class="adm-item__desc">跳转：{{ b.link }}</div>
          <div class="adm-item__desc">排序 #{{ b.sort }}</div>
        </div>
        <van-switch :model-value="b.status === 1" size="22px" @click="onToggle(b)" />
      </div>
      <div class="bn__ops">
        <van-button size="small" round plain type="primary" @click="openEdit(b)">编辑</van-button>
      </div>
    </div>

    <DetailSheet v-model:show="editShow" :title="editing ? '编辑轮播图' : '新增轮播图'">
      <van-field v-model="form.title" label="标题" placeholder="轮播图标题" required />
      <van-field v-model="form.link" label="跳转链接" placeholder="如 /collection/9001 或 /lottery" />
      <van-field v-model="form.sort" type="digit" label="排序" placeholder="数字越小越靠前" />
      <van-field name="image" label="轮播图">
        <template #input>
          <div class="bn__opts">
            <img
              v-for="img in imageOptions"
              :key="img"
              :src="img"
              :class="{ 'is-active': form.image === img }"
              @click="form.image = img"
            />
          </div>
        </template>
      </van-field>
      <van-field name="status" label="上架状态">
        <template #input>
          <van-switch v-model="form.status" :active-value="1" :inactive-value="0" size="20px" />
        </template>
      </van-field>
      <template #actions>
        <van-button block round type="primary" @click="onSave">保存</van-button>
      </template>
    </DetailSheet>
  </div>
</template>

<style scoped lang="scss">
.bn__main {
  display: flex;
  gap: 12px;
  align-items: center;
}

.bn__img {
  width: 110px;
  height: 56px;
  border-radius: $radius-md;
  object-fit: cover;
  flex-shrink: 0;
  background: $color-surface;
}

.bn__ops {
  display: flex;
  justify-content: flex-end;
  margin-top: 10px;
}

.bn__opts {
  display: flex;
  gap: 8px;
  width: 100%;

  img {
    width: 64px;
    height: 40px;
    object-fit: cover;
    border-radius: 6px;
    border: 2px solid transparent;
    cursor: pointer;

    &.is-active { border-color: $color-primary; }
  }
}
</style>
