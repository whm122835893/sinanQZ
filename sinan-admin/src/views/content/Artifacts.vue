<script setup>
import { ref } from 'vue'
import { showSuccessToast } from 'vant'
import { getArtifacts, saveArtifact } from '@/api'
import AdminListPage from '@/components/AdminListPage.vue'
import DetailSheet from '@/components/DetailSheet.vue'
import { fmtNumber } from '@/utils/format'

const filters = [
  {
    field: 'status',
    label: '状态',
    options: [
      { value: 1, label: '展示中' },
      { value: 0, label: '已隐藏' }
    ]
  },
  {
    field: 'dynasty',
    label: '朝代',
    options: [
      { value: '战国', label: '战国' },
      { value: '西周', label: '西周' },
      { value: '东汉', label: '东汉' }
    ]
  }
]

const listRef = ref(null)
const editShow = ref(false)
const editing = ref(null)
const form = ref({ name: '', dynasty: '战国', museum: '', level: '国家一级文物', material: '', status: 1 })

const dynasties = ['新石器时代', '商', '西周', '春秋', '战国', '秦', '西汉', '东汉', '唐', '宋', '元', '明', '清']

function openEdit(a) {
  editing.value = a
  form.value = { name: a.name, dynasty: a.dynasty, museum: a.museum, level: a.level, material: a.material, status: a.status }
  editShow.value = true
}

async function onSave() {
  if (!form.value.name.trim()) return
  const res = await saveArtifact({ id: editing.value?.id, ...form.value })
  if (res.code === 0) {
    showSuccessToast('已保存')
    editShow.value = false
    listRef.value?.reset()
  }
}
</script>

<template>
  <div class="adm-page af">
    <div class="adm-toolbar">
      <div class="t-secondary" style="font-size: 12px">C 端「文物展馆」页的国宝数字档案</div>
    </div>

    <AdminListPage ref="listRef" :fetch="getArtifacts" :filters="filters" search-placeholder="搜索文物名称 / 博物馆">
      <template #default="{ items }">
        <div class="af__grid">
          <div v-for="a in items" :key="a.id" class="adm-card af__card" @click="openEdit(a)">
            <div class="af__img-wrap">
              <img class="af__img" :src="a.image" :alt="a.name" />
              <van-tag v-if="a.status === 0" class="af__hide" type="default" plain round size="medium">已隐藏</van-tag>
            </div>
            <div class="af__name">{{ a.name }}</div>
            <div class="af__meta">
              <van-tag plain round size="medium" type="primary">{{ a.dynasty }}</van-tag>
              <span class="t-tertiary">{{ a.material }}</span>
            </div>
            <div class="af__museum t-secondary">{{ a.museum }}</div>
            <div class="af__level t-gold">{{ a.level }}</div>
          </div>
        </div>
      </template>
    </AdminListPage>

    <DetailSheet v-model:show="editShow" :title="editing ? '编辑文物' : '新增文物'">
      <van-field v-model="form.name" label="文物名称" placeholder="文物全名" required />
      <van-field v-model="form.museum" label="收藏博物馆" placeholder="如：河北博物院" required />
      <van-field name="dynasty" label="朝代">
        <template #input>
          <select v-model="form.dynasty" class="af__select">
            <option v-for="d in dynasties" :key="d" :value="d">{{ d }}</option>
          </select>
        </template>
      </van-field>
      <van-field v-model="form.material" label="材质" placeholder="如：青铜 / 铜鎏金 / 绢本" />
      <van-field v-model="form.level" label="文物等级" placeholder="如：国家一级文物" />
      <van-field name="status" label="展示状态">
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
.af__grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 12px;
}

@media (min-width: 769px) {
  .af__grid { grid-template-columns: repeat(4, 1fr); }
}

.af__card {
  cursor: pointer;
  transition: transform 0.15s ease;

  &:active { transform: scale(0.97); }
}

.af__img-wrap { position: relative; }

.af__img {
  width: 100%;
  aspect-ratio: 3/4;
  object-fit: cover;
  border-radius: $radius-md;
  background: $color-surface;
}

.af__hide {
  position: absolute;
  top: 8px;
  right: 8px;
  background: rgba(255, 255, 255, 0.9);
}

.af__name {
  font-size: 14px;
  font-weight: 700;
  margin-top: 10px;
  @include ellipsis;
}

.af__meta {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-top: 6px;
  font-size: 11px;
}

.af__museum { font-size: 12px; margin-top: 4px; }
.af__level { font-size: 11px; margin-top: 2px; }

.af__select {
  flex: 1;
  border: none;
  font-size: 14px;
  color: $color-text-primary;
  background: transparent;
  outline: none;
}
</style>
