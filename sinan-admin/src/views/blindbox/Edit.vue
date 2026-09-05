<script setup>
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { getBlindBoxDetail, saveBlindBox } from '@/api'

const route = useRoute()
const router = useRouter()
const id = route.params.id ? Number(route.params.id) : null
const submitting = ref(false)
const formRef = ref(null)

const form = ref({
  name: '',
  description: '',
  edition: null,
  price: null,
  perUserLimit: 5,
  cover: '/images/collections/cover-collection-bb1.jpg'
})

const rules = {
  name: [{ required: true, message: '请输入盲盒名称', trigger: 'blur' }],
  price: [{ required: true, message: '请输入发售价格', trigger: 'blur' }],
  edition: [{ required: true, message: '请输入发行总量', trigger: 'blur' }]
}

const coverOptions = [
  '/images/collections/cover-collection-bb1.jpg',
  '/images/collections/cover-collection-5.jpg',
  '/images/collections/cover-collection-4.jpg',
  '/images/collections/cover-collection-6.jpg',
  '/images/collections/cover-1.jpg'
]

onMounted(async () => {
  if (id) {
    const res = await getBlindBoxDetail(id)
    const b = res.data
    form.value = {
      name: b.name,
      description: b.description || '',
      edition: b.edition, // 发行总量不可变更
      price: b.price,
      perUserLimit: b.perUserLimit || 5,
      cover: b.cover
    }
  }
})

async function onSubmit() {
  await formRef.value.validate()
  const f = form.value
  submitting.value = true
  const res = await saveBlindBox({
    id,
    name: f.name.trim(),
    description: f.description,
    price: Number(f.price) || 0,
    edition: Number(f.edition) || 0,
    perUserLimit: f.perUserLimit,
    cover: f.cover
  })
  submitting.value = false
  if (res.code === 0) {
    ElMessage.success(id ? '保存成功' : '创建成功，请在详情页配置子藏品奖池')
    router.back()
  }
}
</script>

<template>
  <div class="adm-page be">
    <div class="adm-card">
      <div class="adm-card__title">{{ id ? '编辑盲盒' : '新建盲盒' }}</div>

      <el-form ref="formRef" :model="form" :rules="rules" label-width="110px" style="max-width: 640px">
        <el-form-item label="盲盒名称" prop="name">
          <el-input v-model="form.name" placeholder="请输入盲盒名称" maxlength="30" show-word-limit />
        </el-form-item>

        <el-form-item label="发行总量" prop="edition">
          <el-input-number
            v-model="form.edition"
            :min="1"
            :step="100"
            :disabled="!!id"
            style="width: 200px"
          />
          <div class="t-tertiary" style="font-size: 12px; margin-top: 4px; width: 100%">
            盲盒发行总量创建时设定，固定不变；盲盒库存池 = 发行总量 - 已售出发售 - 已独立空投 - 已销毁
          </div>
        </el-form-item>

        <el-form-item label="发售价格（元）" prop="price">
          <el-input-number v-model="form.price" :min="0.01" :precision="2" :step="10" style="width: 200px" />
        </el-form-item>

        <el-form-item label="每人限购">
          <el-input-number v-model="form.perUserLimit" :min="1" style="width: 200px" />
        </el-form-item>

        <el-form-item label="盲盒描述">
          <el-input
            v-model="form.description"
            type="textarea"
            :rows="4"
            maxlength="200"
            show-word-limit
            placeholder="盲盒介绍（C 端详情页展示）"
          />
        </el-form-item>

        <el-form-item label="封面图">
          <div class="be__covers">
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
            {{ id ? '保存修改' : '创建盲盒' }}
          </el-button>
          <el-button @click="router.back()">取消</el-button>
        </el-form-item>
      </el-form>

      <el-alert
        type="info"
        :closable="false"
        show-icon
        title="创建后请在盲盒详情页配置子藏品奖池：每个盲盒关联 2~N 个子藏品，独立配置中奖概率与计划数量，概率之和 <= 100%"
      />
    </div>
  </div>
</template>

<style scoped lang="scss">
.be__covers {
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
