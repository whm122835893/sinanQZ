<script setup>
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { UploadFilled } from '@element-plus/icons-vue'
import { getCollectibleDetail, saveCollectible, getChainNetworks, uploadImage, getCategories } from '@/api'

const route = useRoute()
const router = useRouter()
const id = route.params.id ? Number(route.params.id) : null
const submitting = ref(false)
const formRef = ref(null)
const uploading = ref(false)

const form = ref({
  name: '',
  subtitle: '',
  categoryId: null,
  price: null,
  edition: null,
  saleTime: '',
  tag: '首发',
  issuer: '司南数字藏品',
  creator: '',
  royaltyRate: null,
  description: '',
  featured: false,
  cover: '',
  chainType: ''
})

const rules = {
  name: [{ required: true, message: '请输入藏品名称', trigger: 'blur' }],
  price: [{ required: true, message: '请输入售价', trigger: 'blur' }],
  edition: [{ required: true, message: '请输入发行总量', trigger: 'blur' }],
  cover: [{ required: true, message: '请上传藏品图', trigger: 'change' }]
}

// 分类选项（动态：内容管理 → 分类管理，market 场景）
const categories = ref([])

// 上链链选择（三链）：显示后台启用的链，未配置时可留空（不上链）
const CHAIN_LABELS = { wenchang: '文昌链', consortium: '联盟链', antchain: '蚂蚁链' }
const chains = ref([])   // [{ code, name, status, isDefault, configured }]

onMounted(async () => {
  // 加载分类选项（失败不阻塞表单）
  const cat = await getCategories('market')
  if (cat.code === 0) {
    categories.value = cat.data
    if (!id && cat.data.length) form.value.categoryId = cat.data[0].id
  }

  // 加载链网络（展示启用状态；默认链用于新建时的初始选择）
  const net = await getChainNetworks()
  if (net.code === 0) {
    chains.value = (net.data?.networks || []).map((c) => ({
      code: c.chainCode,
      name: CHAIN_LABELS[c.chainCode] || c.chainName || c.chainCode,
      status: c.status,
      isDefault: !!c.isDefault,
      hasKey: !!c.hasKey,
      hasSecret: !!c.hasSecret,
      hasRpc: !!c.rpcUrl,
      isConsortium: c.chainCode === 'consortium'
    }))
    if (!id) {
      const def = chains.value.find((c) => c.isDefault && c.status === 1)
      if (def) form.value.chainType = def.code
    }
  }

  if (id) {
    const res = await getCollectibleDetail(id)
    const c = res.data
    form.value = {
      name: c.name, subtitle: c.subtitle, categoryId: c.categoryId || null,
      price: c.price, edition: c.edition, saleTime: c.saleTime,
      tag: c.tag, issuer: c.issuer, creator: c.creator || '',
      royaltyRate: c.royaltyRate ?? null,
      description: c.description,
      featured: c.featured, cover: c.cover,
      chainType: c.chainType || ''
    }
  }
})

// 链配置完备性：文昌链需 Key；联盟链需 RPC；蚂蚁链需 Key + Secret
function chainReady(c) {
  if (!c.status) return false
  if (c.isConsortium) return !!c.hasRpc
  if (c.code === 'antchain') return !!c.hasKey && !!c.hasSecret
  return !!c.hasKey
}

// ---- 藏品图上传（JPG/PNG/WEBP/GIF，≤5MB）----
async function onUploadCover({ file }) {
  if (!file) return
  if (file.size > 5 * 1024 * 1024) {
    return ElMessage.warning('图片大小不能超过 5MB')
  }
  uploading.value = true
  const res = await uploadImage(file, 'collection')
  uploading.value = false
  if (res.code === 0 && res.data?.url) {
    form.value.cover = res.data.url
    formRef.value?.clearValidate('cover')
    ElMessage.success('藏品图已上传')
  } else {
    ElMessage.error(res.message || '上传失败，请重试')
  }
}

async function onSubmit() {
  await formRef.value.validate()
  const f = form.value
  submitting.value = true
  const res = await saveCollectible({
    id,
    name: f.name.trim(),
    subtitle: f.subtitle.trim(),
    categoryId: f.categoryId,
    price: Number(f.price) || 0,
    edition: Number(f.edition) || 0,
    saleTime: f.saleTime,
    tag: f.tag,
    issuer: f.issuer,
    creator: f.creator,
    royaltyRate: f.royaltyRate,
    description: f.description,
    featured: f.featured,
    cover: f.cover,
    chainType: f.chainType || ''
  })
  submitting.value = false
  if (res.code === 0) {
    ElMessage.success(id ? '保存成功' : '创建成功（当前为待发售状态，请到藏品列表开启上架售卖）')
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
          <el-radio-group v-model="form.categoryId">
            <el-radio v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</el-radio>
          </el-radio-group>
          <div v-if="!categories.length" class="t-tertiary" style="font-size: 12px; width: 100%">
            暂无分类，请先到「内容 → 分类管理」新增市场分类
          </div>
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
          <el-input v-model="form.saleTime" placeholder="2026-09-07 18:00（可留空，上架后即时开售）" style="width: 280px" />
          <div class="t-tertiary" style="font-size: 12px; margin-top: 4px; width: 100%">
            藏品创建后为「待发售」状态，需在藏品列表或详情中开启上架售卖后 C 端才可见
          </div>
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

        <el-form-item label="上链链选择">
          <el-radio-group v-model="form.chainType">
            <el-radio v-for="c in chains" :key="c.code" :value="c.code">
              {{ c.name }}
              <el-tag v-if="c.isDefault" type="warning" effect="plain" size="small" style="margin-left: 4px">默认</el-tag>
              <el-tag
                :type="c.status === 1 ? (chainReady(c) ? 'success' : 'danger') : 'info'"
                effect="plain"
                size="small"
                style="margin-left: 4px"
              >
                {{ c.status === 1 ? (chainReady(c) ? '可上链' : '配置不全') : '未启用' }}
              </el-tag>
            </el-radio>
          </el-radio-group>
          <div class="t-tertiary" style="font-size: 12px; margin-top: 4px; width: 100%">
            藏品售出后按所选链铸造链上凭证；链网络参数在「区块链 → 上链配置」中维护
          </div>
        </el-form-item>

        <el-form-item label="首页推荐">
          <el-switch v-model="form.featured" />
        </el-form-item>

        <el-alert
          type="info"
          :closable="false"
          show-icon
          title="寄售/转赠开关在藏品创建后于「藏品列表 / 藏品详情」中按需开启"
          style="margin-bottom: 18px"
        />

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

        <el-form-item label="藏品图" prop="cover">
          <div class="ce__cover-edit">
            <div class="ce__cover-preview">
              <img v-if="form.cover" :src="form.cover" alt="藏品图预览" />
              <div v-else class="ce__cover-empty">
                <el-icon><UploadFilled /></el-icon>
                <span>暂无图片</span>
              </div>
            </div>
            <div class="ce__cover-ops">
              <el-upload
                :show-file-list="false"
                :http-request="onUploadCover"
                accept="image/jpeg,image/png,image/webp,image/gif"
              >
                <el-button type="primary" plain :loading="uploading">
                  {{ uploading ? '上传中…' : form.cover ? '重新上传' : '上传藏品图' }}
                </el-button>
              </el-upload>
              <div class="t-tertiary ce__cover-tip">
                支持 JPG / PNG / WEBP / GIF，大小不超过 5MB；上传后立即保存生效
              </div>
              <div v-if="id" class="t-tertiary ce__cover-tip">
                编辑已有藏品时上传新图将替换原图
              </div>
            </div>
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
.ce__cover-edit {
  display: flex;
  gap: 16px;
  align-items: flex-start;
}

.ce__cover-preview {
  width: 140px;
  aspect-ratio: 1;
  border-radius: 8px;
  border: 1px dashed $color-border;
  overflow: hidden;
  flex-shrink: 0;
  background: $color-surface;

  img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
  }
}

.ce__cover-empty {
  width: 100%;
  height: 100%;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 6px;
  color: $color-text-tertiary;
  font-size: 12px;

  .el-icon { font-size: 28px; }
}

.ce__cover-ops { flex: 1; min-width: 220px; }

.ce__cover-tip {
  font-size: 12px;
  margin-top: 8px;
  line-height: 1.6;
}

@media (max-width: 600px) {
  .ce__cover-edit { flex-direction: column; }
}
</style>
