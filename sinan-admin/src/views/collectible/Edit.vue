<script setup>
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { showSuccessToast } from 'vant'
import { getCollectibleDetail, saveCollectible } from '@/api'

const route = useRoute()
const router = useRouter()
const id = route.params.id ? Number(route.params.id) : null
const submitting = ref(false)

const form = ref({
  name: '',
  subtitle: '',
  category: '青铜',
  price: '',
  edition: '',
  saleTime: '',
  tag: '首发',
  issuer: '司南数字藏品',
  description: '',
  featured: false,
  status: 'upcoming',
  cover: '/images/collections/cover-1.jpg'
})

const categories = ['青铜', '水墨', '国潮', '限定']
const statuses = [
  { value: 'upcoming', label: '待发售' },
  { value: 'onsale', label: '发售中' },
  { value: 'soldout', label: '已售罄' },
  { value: 'offline', label: '已下架' }
]

onMounted(async () => {
  if (id) {
    const res = await getCollectibleDetail(id)
    const c = res.data
    form.value = {
      name: c.name, subtitle: c.subtitle, category: c.category,
      price: String(c.price), edition: String(c.edition), saleTime: c.saleTime,
      tag: c.tag, issuer: c.issuer, description: c.description,
      featured: c.featured, status: c.status, cover: c.cover
    }
  }
})

const coverOptions = [
  '/images/collections/cover-1.jpg',
  '/images/collections/cover-2.jpg',
  '/images/collections/cover-3.jpg',
  '/images/collections/cover-4.jpg',
  '/images/collections/cover-5.jpg',
  '/images/collections/cover-collection-1.jpg',
  '/images/collections/cover-collection-2.jpg',
  '/images/collections/cover-collection-3.jpg',
  '/images/collections/cover-collection-4.jpg',
  '/images/collections/cover-collection-5.jpg'
]

async function onSubmit() {
  const f = form.value
  if (!f.name.trim()) return
  submitting.value = true
  const res = await saveCollectible({
    id,
    name: f.name.trim(),
    subtitle: f.subtitle.trim(),
    category: f.category,
    price: Number(f.price) || 0,
    edition: Number(f.edition) || 0,
    saleTime: f.saleTime,
    tag: f.tag,
    issuer: f.issuer,
    description: f.description,
    featured: f.featured,
    status: f.status,
    cover: f.cover
  })
  submitting.value = false
  if (res.code === 0) {
    showSuccessToast(id ? '保存成功' : '创建成功')
    router.back()
  }
}
</script>

<template>
  <div class="adm-page ce">
    <div class="adm-card">
      <div class="adm-card__title">{{ id ? '编辑藏品' : '新建藏品' }}</div>

      <van-field v-model="form.name" label="藏品名称" placeholder="请输入藏品名称" required />
      <van-field v-model="form.subtitle" label="副标题" placeholder="系列 / 描述" />
      <van-field v-model="form.price" type="number" label="售价（元）" placeholder="0.00" required />
      <van-field v-model="form.edition" type="digit" label="发行量（份）" placeholder="发行总量" required />
      <van-field v-model="form.saleTime" label="发售时间" placeholder="2026-09-07 18:00" />
      <van-field v-model="form.tag" label="标签" placeholder="首发 / 热销 / 爆款" />
      <van-field v-model="form.issuer" label="发行方" placeholder="发行方名称" />

      <van-field name="category" label="分类">
        <template #input>
          <van-radio-group v-model="form.category" direction="horizontal" style="flex-wrap: wrap; gap: 8px">
            <van-radio v-for="c in categories" :key="c" :name="c">{{ c }}</van-radio>
          </van-radio-group>
        </template>
      </van-field>

      <van-field name="status" label="状态">
        <template #input>
          <van-radio-group v-model="form.status" direction="horizontal" style="flex-wrap: wrap; gap: 8px">
            <van-radio v-for="s in statuses" :key="s.value" :name="s.value">{{ s.label }}</van-radio>
          </van-radio-group>
        </template>
      </van-field>

      <van-field name="featured" label="首页推荐">
        <template #input>
          <van-switch v-model="form.featured" size="20px" />
        </template>
      </van-field>

      <van-field
        v-model="form.description"
        type="textarea"
        rows="3"
        maxlength="200"
        show-word-limit
        label="藏品描述"
        placeholder="藏品介绍（C 端详情页展示）"
      />

      <van-field name="cover" label="封面图">
        <template #input>
          <div class="ce__covers">
            <img
              v-for="c in coverOptions"
              :key="c"
              :src="c"
              :class="{ 'is-active': form.cover === c }"
              @click="form.cover = c"
            />
          </div>
        </template>
      </van-field>
    </div>

    <div class="ce__submit">
      <van-button block round type="primary" :loading="submitting" @click="onSubmit">
        {{ id ? '保存修改' : '创建藏品' }}
      </van-button>
    </div>
  </div>
</template>

<style scoped lang="scss">
.ce__covers {
  display: grid;
  grid-template-columns: repeat(5, 1fr);
  gap: 6px;
  width: 100%;

  img {
    width: 100%;
    aspect-ratio: 1;
    object-fit: cover;
    border-radius: 8px;
    border: 2px solid transparent;
    cursor: pointer;

    &.is-active {
      border-color: $color-primary;
    }
  }
}

.ce__submit {
  padding: 4px 2px 20px;
}
</style>
