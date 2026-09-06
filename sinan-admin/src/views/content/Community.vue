<script setup>
import { ref, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import { getCommunityGroups, saveCommunityGroup } from '@/api'
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

function openCreate() {
  editing.value = null
  form.value = { name: '', description: '', icon: iconOptions[0], qrCode: '', members: 0, isActive: 1, sort: groups.value.length + 1 }
  editShow.value = true
}

function openEdit(g) {
  editing.value = g
  form.value = { ...g }
  editShow.value = true
}

async function onSave() {
  if (!form.value.name.trim()) return ElMessage.warning('请输入社群名称')
  const res = await saveCommunityGroup({ id: editing.value?.id, ...form.value })
  if (res.code === 0) {
    ElMessage.success('已保存')
    editShow.value = false
    load()
  }
}

async function onToggle(g) {
  const enabling = g.isActive !== 1
  const res = await saveCommunityGroup({ id: g.id, isActive: enabling ? 1 : 0 })
  if (res.code === 0) {
    g.isActive = enabling ? 1 : 0
    ElMessage.success(enabling ? '已展示' : '已隐藏')
  }
}
</script>

<template>
  <div class="adm-page cm">
    <el-skeleton v-if="loading" :rows="6" animated style="padding: 20px" />

    <div v-else class="adm-card">
      <div class="adm-card__title">
        官方社群入口（C 端「社区」页展示）
        <div class="cm__extra">
          <el-button type="primary" :icon="Plus" @click="openCreate">新增社群</el-button>
        </div>
      </div>

      <el-table :data="groups">
        <el-table-column label="社群" min-width="220">
          <template #default="{ row }">
            <div class="cm__cell">
              <img class="cm__icon" :src="row.icon" :alt="row.name" />
              <div>
                <div class="cm__name">{{ row.name }}</div>
                <div class="t-tertiary" style="font-size: 12px">{{ row.description }}</div>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="成员数" width="100" align="right">
          <template #default="{ row }">{{ fmtNumber(row.members) }}</template>
        </el-table-column>
        <el-table-column label="排序" width="80" align="center">
          <template #default="{ row }">#{{ row.sort }}</template>
        </el-table-column>
        <el-table-column label="展示状态" width="110" align="center">
          <template #default="{ row }">
            <el-tag :type="row.isActive === 1 ? 'success' : 'info'" effect="plain" size="small">
              {{ row.isActive === 1 ? '展示中' : '已隐藏' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="快捷启停" width="100" align="center">
          <template #default="{ row }">
            <el-switch :model-value="row.isActive === 1" @change="onToggle(row)" />
          </template>
        </el-table-column>
        <el-table-column label="操作" width="90" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" size="small" @click="openEdit(row)">编辑</el-button>
          </template>
        </el-table-column>
      </el-table>
    </div>

    <!-- 编辑弹窗 -->
    <el-dialog v-model="editShow" :title="editing ? '编辑社群' : '新增社群'" width="520px" :close-on-click-modal="false">
      <el-form label-width="90px">
        <el-form-item label="社群名称" required>
          <el-input v-model="form.name" placeholder="如：司南官方社群" maxlength="20" show-word-limit />
        </el-form-item>
        <el-form-item label="社群描述">
          <el-input v-model="form.description" type="textarea" :rows="2" maxlength="60" show-word-limit placeholder="C 端展示的一句话简介" />
        </el-form-item>
        <el-form-item label="社群图标">
          <div class="cm__icons">
            <img
              v-for="i in iconOptions"
              :key="i"
              :src="i"
              :class="{ 'is-active': form.icon === i }"
              @click="form.icon = i"
            />
          </div>
        </el-form-item>
        <el-form-item label="群二维码">
          <el-input v-model="form.qrCode" placeholder="二维码图片地址（可留空）" />
          <img v-if="form.qrCode" class="cm__qr-preview" :src="form.qrCode" alt="群二维码" />
        </el-form-item>
        <el-form-item label="成员数">
          <el-input-number v-model="form.members" :min="0" />
          <span class="t-tertiary" style="margin-left: 10px; font-size: 12px">展示用数量</span>
        </el-form-item>
        <el-form-item label="排序">
          <el-input-number v-model="form.sort" :min="1" :max="99" />
        </el-form-item>
        <el-form-item label="展示状态">
          <el-switch v-model="form.isActive" :active-value="1" :inactive-value="0" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="editShow = false">取消</el-button>
        <el-button type="primary" @click="onSave">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped lang="scss">
.cm__extra {
  margin-left: auto;
}

.cm__cell {
  display: flex;
  align-items: center;
  gap: 10px;
}

.cm__icon {
  width: 40px;
  height: 40px;
  border-radius: 10px;
  object-fit: cover;
  background: $color-surface;
  flex-shrink: 0;
}

.cm__name {
  font-weight: 600;
  color: $color-text-primary;
}

.cm__icons {
  display: flex;
  gap: 10px;
  width: 100%;

  img {
    width: 44px;
    height: 44px;
    border-radius: 10px;
    object-fit: cover;
    border: 2px solid transparent;
    cursor: pointer;
    transition: border-color 0.2s;

    &:hover { border-color: $color-primary-light; }
    &.is-active { border-color: $color-primary; }
  }
}

.cm__qr-preview {
  margin-top: 8px;
  width: 72px;
  height: 72px;
  border-radius: 8px;
  border: 1px solid $color-border;
  object-fit: cover;
}
</style>
