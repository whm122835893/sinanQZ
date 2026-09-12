<script setup>
import { ref, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import { getCategories, saveCategory, removeCategory } from '@/api'

// scene：market 市场二级分类（C 端市场页）/ artifact 文物展览分类（C 端文物展馆页）
const SCENES = [
  { value: 'market', label: '市场分类', desc: 'C 端「市场」页二级分类（水墨 / 国潮 …），藏品创建时按此分类归属' },
  { value: 'artifact', label: '文物展览分类', desc: 'C 端「文物展览」区分类（青铜 / 陶瓷 / 书画 / 玉器 …）' }
]

const scene = ref('market')
const sceneMeta = computed(() => SCENES.find((x) => x.value === scene.value))
const loading = ref(true)
const list = ref([])

const editShow = ref(false)
const editing = ref(null)
const submitting = ref(false)
const form = ref({ name: '', code: '', sortOrder: 1 })

onMounted(load)

async function load() {
  loading.value = true
  const res = await getCategories(scene.value)
  if (res.code === 0) list.value = res.data
  loading.value = false
}

function switchScene(v) {
  scene.value = v
  load()
}

function openCreate() {
  editing.value = null
  form.value = { name: '', code: '', sortOrder: list.value.length + 1 }
  editShow.value = true
}

function openEdit(row) {
  editing.value = row
  form.value = { name: row.name, code: row.code, sortOrder: row.sortOrder }
  editShow.value = true
}

async function onSave() {
  const f = form.value
  if (!f.name.trim()) return ElMessage.warning('请输入分类名称')
  if (!/^[a-z0-9_-]{1,20}$/.test(f.code)) {
    return ElMessage.warning('编码仅支持小写字母 / 数字 / 中划线 / 下划线，1~20 位')
  }
  submitting.value = true
  const res = await saveCategory({
    id: editing.value?.id,
    name: f.name.trim(),
    code: f.code,
    scene: scene.value,
    sortOrder: f.sortOrder
  })
  submitting.value = false
  if (res.code === 0) {
    ElMessage.success('已保存')
    editShow.value = false
    load()
  }
}

async function onRemove(row) {
  try {
    await ElMessageBox.confirm(
      row.collectibleCount > 0
        ? `「${row.name}」下还有 ${row.collectibleCount} 个藏品，无法删除`
        : `确定删除分类「${row.name}」？删除后 C 端对应分类入口立即消失。`,
      '删除分类',
      { type: 'warning', confirmButtonText: '删除', cancelButtonText: '取消' }
    )
  } catch {
    return
  }
  if (row.collectibleCount > 0) return
  const res = await removeCategory(row.id)
  if (res.code === 0) {
    ElMessage.success('已删除')
    load()
  }
}
</script>

<template>
  <div class="adm-page cat">
    <div class="adm-card">
      <div class="adm-card__title">
        分类管理
        <div class="cat__extra">
          <el-button type="primary" :icon="Plus" @click="openCreate">新增分类</el-button>
        </div>
      </div>

      <el-tabs :model-value="scene" @tab-change="switchScene">
        <el-tab-pane v-for="s in SCENES" :key="s.value" :name="s.value" :label="s.label" />
      </el-tabs>
      <div class="cat__desc t-tertiary">{{ sceneMeta.desc }}</div>

      <el-skeleton v-if="loading" :rows="5" animated />
      <el-table v-else :data="list">
        <el-table-column label="排序" width="80" align="center">
          <template #default="{ row }">#{{ row.sortOrder }}</template>
        </el-table-column>
        <el-table-column label="分类名称" prop="name" min-width="140">
          <template #default="{ row }">
            <span class="cat__name">{{ row.name }}</span>
          </template>
        </el-table-column>
        <el-table-column label="编码" prop="code" min-width="120">
          <template #default="{ row }">
            <el-tag effect="plain" size="small">{{ row.code }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column v-if="scene === 'market'" label="藏品数" width="100" align="center">
          <template #default="{ row }">
            <span :class="row.collectibleCount > 0 ? '' : 't-tertiary'">{{ row.collectibleCount }}</span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="140" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" size="small" @click="openEdit(row)">编辑</el-button>
            <el-button link type="danger" size="small" :disabled="row.collectibleCount > 0" @click="onRemove(row)">
              删除
            </el-button>
          </template>
        </el-table-column>
        <template #empty>
          <el-empty description="暂无分类，点击右上角新增" :image-size="80" />
        </template>
      </el-table>
    </div>

    <!-- 编辑弹窗 -->
    <el-dialog v-model="editShow" :title="editing ? '编辑分类' : `新增${sceneMeta.label}`" width="480px" :close-on-click-modal="false">
      <el-form label-width="90px">
        <el-form-item label="分类名称" required>
          <el-input v-model="form.name" placeholder="如：水墨 / 青铜" maxlength="20" show-word-limit />
        </el-form-item>
        <el-form-item label="分类编码" required>
          <el-input v-model="form.code" placeholder="如：ink-wash / bronze" maxlength="20" :disabled="!!editing" />
          <div class="t-tertiary cat__tip">小写字母 / 数字 / 中划线 / 下划线，创建后不可修改</div>
        </el-form-item>
        <el-form-item label="排序">
          <el-input-number v-model="form.sortOrder" :min="1" :max="99" />
          <span class="t-tertiary" style="margin-left: 10px; font-size: 12px">数字越小越靠前</span>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="editShow = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="onSave">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped lang="scss">
.cat__extra {
  margin-left: auto;
}

.cat__desc {
  font-size: 12px;
  margin: -6px 0 14px;
}

.cat__name {
  font-weight: 600;
}

.cat__tip {
  font-size: 12px;
  line-height: 1.6;
  width: 100%;
}
</style>
