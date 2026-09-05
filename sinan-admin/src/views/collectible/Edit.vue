<script setup>
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { getCollectibleDetail, saveCollectible } from '@/api'

const route = useRoute()
const router = useRouter()
const id = route.params.id ? Number(route.params.id) : null
const submitting = ref(false)
const formRef = ref(null)

const form = ref({
  name: '',
  subtitle: '',
  category: '青铜',
  price: null,
  edition: null,
  saleTime: '',
  tag: '首发',
  issuer: '司南数字藏品',
  creator: '',
  royaltyRate: null,
  description: '',
  featured: false,
  cover: '/images/collections/cover-1.jpg'
})

const rules = {
  name: [{ required: true, message: '请输入藏品名称', trigger: 'blur' }],
  price: [{ required: true, message: '请输入售价', trigger: 'blur' }],
  edition: [{ required: true, message: '请输入发行总量', trigger: 'blur' }]
}

const categories = ['青铜', '水墨', '国潮', '限定']

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

onMounted(async () => {
  if (id) {
    const res = await getCollectibleDetail(id)
    const c = res.data
    form.value = {
      name: c.name, subtitle: c.subtitle, category: c.category,
      price: c.price, edition: c.edition, saleTime: c.saleTime,
      tag: c.tag, issuer: c.issuer, creator: c.creator || '',
      royaltyRate: c.royaltyRate ?? null,
      description: c.description,
      featured: c.featured, cover: c.cover
    }
  }
})

async function onSubmit() {
  await formRef.value.validate()
  const f = form.value
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
    creator: f.creator,
    royaltyRate: f.royaltyRate,
    description: f.description,
    featured: f.featured,
    cover: f.cover
  })
  submitting.value = false
  if (res.code === 0) {
    ElMessage.success(id ? '保存成功' : '创建成功')
    router.back()
  }
}
</script>

<template>
  <div class="adm-page ce">
    <div class="adm-card">
      <div class="adm-card__title">{{ id ? '编辑藏品' : '新建藏品' }}</div>

      <el-form ref="formRef" :model="form" :rules="rules" label-width="110px" style="max-width: 640px">
        <el-form-item label="藏品名称" prop="name">
          <el-input v-model="form.name" placeholder="请输入藏品名称" maxlength="30" show-word-limit />
        </el-form-item>

        <el-form-item label="副标题">
          <el-input v-model="form.subtitle" placeholder="系列 / 描述" />
        </el-form-item>

        <el-form-item label="分类">
          <el-radio-group v-model="form.category">
            <el-radio v-for="c in categories" :key="c" :value="c">{{ c }}</el-radio>
          </el-radio-group>
        </el-form-item>

        <el-form-item label="售价（元）" prop="price">
          <el-input-number v-model="form.price" :min="0.01" :precision="2" :step="10" style="width: 200px" />
        </el-form-item>

        <el-form-item label="发行总量" prop="edition">
          <el-input-number v-model="form.edition" :min="1" :step="100" style="width: 200px" />
          <div class="t-tertiary" style="font-size: 12px; margin-top: 4px; width: 100%">
            发行总量创建时设定，全局唯一基准值，不可变更
          </div>
        </el-form-item>

        <el-form-item label="发售时间">
          <el-input v-model="form.saleTime" placeholder="2026-09-07 18:00（发售配置中设定）" style="width: 280px" />
        </el-form-item>

        <el-form-item label="标签">
          <el-input v-model="form.tag" placeholder="首发 / 热销 / 爆款" style="width: 200px" />
        </el-form-item>

        <el-form-item label="发行方">
          <el-input v-model="form.issuer" placeholder="发行方名称" style="width: 280px" />
        </el-form-item>

        <el-form-item label="创作者">
          <el-input v-model="form.creator" placeholder="创作者名称（可选）" style="width: 280px" />
        </el-form-item>

        <el-form-item label="版税比例（%）">
          <el-input-number v-model="form.royaltyRate" :min="0" :max="30" :precision="1" style="width: 200px" />
        </el-form-item>

        <el-form-item label="首页推荐">
          <el-switch v-model="form.featured" />
        </el-form-item>

        <el-form-item label="藏品描述">
          <el-input
            v-model="form.description"
            type="textarea"
            :rows="4"
            maxlength="200"
            show-word-limit
            placeholder="藏品介绍（C 端详情页展示）"
          />
        </el-form-item>

        <el-form-item label="封面图">
          <div class="ce__covers">
            <img
              v-for="c in coverOptions"
              :key="c"
              :src="c"
              :class="{ 'is-active': form.cover === c }"
              @click="form.cover = c"
            />
          </div>
        </el-form-item>

        <el-form-item>
          <el-button type="primary" :loading="submitting" @click="onSubmit">
            {{ id ? '保存修改' : '创建藏品' }}
          </el-button>
          <el-button @click="router.back()">取消</el-button>
        </el-form-item>
      </el-form>
    </div>
  </div>
</template>

<style scoped lang="scss">
.ce__covers {
  display: grid;
  grid-template-columns: repeat(5, 1fr);
  gap: 8px;
  width: 420px;

  @media (max-width: 768px) {
    width: 100%;
    grid-template-columns: repeat(4, 1fr);
  }

  img {
    width: 100%;
    aspect-ratio: 1;
    object-fit: cover;
    border-radius: 8px;
    border: 2px solid transparent;
    cursor: pointer;
    transition: border-color 0.15s;

    &:hover { border-color: rgba(192, 0, 0, 0.3); }

    &.is-active { border-color: $color-primary; }
  }
}
</style>
