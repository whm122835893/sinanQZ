<script setup>
import { ref, onMounted } from 'vue'
import { showSuccessToast } from 'vant'
import { getCommunityGroups, saveCommunityGroup } from '@/api'
import DetailSheet from '@/components/DetailSheet.vue'
import { fmtNumber } from '@/utils/format'

const loading = ref(true)
const groups = ref([])

const editShow = ref(false)
const editing = ref(null)
const form = ref({ name: '', description: '', icon: '', qrCode: '', members: 0, isActive: 1, sort: 1 })

const iconOptions = ['/images/tab/tab-bell.png', '/images/tab/tab-person.png']

onMounted(load)

async function load() {
  loading.value = true
  const res = await getCommunityGroups()
  groups.value = res.data
  loading.value = false
}

function openEdit(g) {
  editing.value = g
  form.value = { ...g }
  editShow.value = true
}

async function onSave() {
  if (!form.value.name.trim()) return
  const res = await saveCommunityGroup({ id: editing.value?.id, ...form.value })
  if (res.code === 0) {
    showSuccessToast('已保存')
    editShow.value = false
    load()
  }
}
</script>

<template>
  <div class="adm-page cm">
    <div class="adm-toolbar">
      <div class="t-secondary" style="font-size: 12px">C 端「社区」页展示的官方社群入口</div>
    </div>

    <van-skeleton v-if="loading" title :row="4" style="padding: 16px" />
    <div v-else v-for="g in groups" :key="g.id" class="adm-card">
      <div class="adm-item" style="padding: 0" @click="openEdit(g)">
        <img class="cm__icon" :src="g.icon" :alt="g.name" />
        <div class="adm-item__body">
          <div class="adm-item__title">
            {{ g.name }}
            <van-tag v-if="g.isActive === 1" type="success" plain round size="medium">展示中</van-tag>
            <van-tag v-else type="default" plain round size="medium">已隐藏</van-tag>
          </div>
          <div class="adm-item__desc">{{ g.description }}</div>
          <div class="adm-item__desc">{{ fmtNumber(g.members) }} 成员 · 排序 #{{ g.sort }}</div>
        </div>
        <van-icon name="arrow" color="#999" />
      </div>
      <div class="cm__qr" v-if="g.qrCode">
        <img :src="g.qrCode" alt="群二维码" />
        <span class="t-tertiary" style="font-size: 11px">群二维码</span>
      </div>
    </div>

    <DetailSheet v-model:show="editShow" :title="editing ? '编辑社群' : '新增社群'">
      <van-field v-model="form.name" label="社群名称" placeholder="如：司南官方社群" required />
      <van-field v-model="form.description" label="社群描述" placeholder="C 端展示的一句话简介" />
      <van-field v-model="form.members" type="digit" label="成员数" placeholder="展示用成员数量" />
      <van-field v-model="form.sort" type="digit" label="排序" placeholder="数字越小越靠前" />
      <van-field name="icon" label="社群图标">
        <template #input>
          <div class="cm__icons">
            <img
              v-for="i in iconOptions"
              :key="i"
              :src="i"
              :class="{ 'is-active': form.icon === i }"
              @click="form.icon = i"
            />
          </div>
        </template>
      </van-field>
      <van-field
        v-model="form.qrCode"
        label="二维码"
        placeholder="二维码图片地址，留空则不展示"
      />
      <van-field name="isActive" label="展示状态">
        <template #input>
          <van-switch v-model="form.isActive" :active-value="1" :inactive-value="0" size="20px" />
        </template>
      </van-field>
      <template #actions>
        <van-button block round type="primary" @click="onSave">保存</van-button>
      </template>
    </DetailSheet>
  </div>
</template>

<style scoped lang="scss">
.cm__icon {
  width: 44px;
  height: 44px;
  border-radius: 10px;
  object-fit: cover;
  flex-shrink: 0;
  background: $color-surface;
}

.cm__qr {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: 10px;
  padding-top: 10px;
  border-top: 1px dashed $color-border;

  img {
    width: 52px;
    height: 52px;
    border-radius: 8px;
    border: 1px solid $color-border;
    object-fit: cover;
  }
}

.cm__icons {
  display: flex;
  gap: 10px;

  img {
    width: 40px;
    height: 40px;
    border-radius: 8px;
    border: 2px solid transparent;
    cursor: pointer;
    object-fit: cover;

    &.is-active { border-color: $color-primary; }
  }
}
</style>
