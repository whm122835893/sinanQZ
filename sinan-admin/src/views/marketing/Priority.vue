<script setup>
import { ref, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus, Delete, Download, Edit, Upload } from '@element-plus/icons-vue'
import {
  getPrioritySales,
  getPriorityWhitelist,
  addWhitelist,
  removePriorityWhitelist,
  cleanExpiredPriority,
  savePriority,
  getCollectibleList,
  importPriorityWhitelist
} from '@/api'
import StatusTag from '@/components/StatusTag.vue'
import { ACTIVITY_STATUS } from '@/utils/maps'
import { downloadCsv } from '@/utils/csv'

// ---- 可选藏品下拉（新建活动时选择） ----
const collectibles = ref([])
const editShow = ref(false)
const editing = ref(null)
const editLoadingWl = ref(false)
const editWhitelists = ref([])       // 编辑模式下当前白名单只读展示
const editSyncWhitelist = ref(false) // 是否在保存时覆盖白名单（默认 false → 只改活动信息，不动名单）
const editForm = ref({
  collectibleId: null,
  name: '',
  status: 'enabled',
  startTime: '',
  endTime: '',
  remark: ''
})

async function openEdit(s) {
  editing.value = s || null
  if (s) {
    editForm.value = {
      collectibleId: s.collectibleId,
      name: s.name,
      status: s.status,
      startTime: s.startTime,
      endTime: s.endTime,
      remark: s.remark || ''
    }
    // 拉取当前白名单只读展示
    editLoadingWl.value = true
    editWhitelists.value = (s.whitelists && s.whitelists.length) ? s.whitelists : []
    try {
      const w = await getPriorityWhitelist(s.id)
      if (w.code === 0) editWhitelists.value = w.data
    } finally {
      editLoadingWl.value = false
    }
    // 编辑模式默认不覆盖白名单，避免误操作
    editSyncWhitelist.value = false
  } else {
    editForm.value = { collectibleId: null, name: '', status: 'enabled', startTime: '', endTime: '', remark: '' }
    editWhitelists.value = []
    editSyncWhitelist.value = true // 新建时没有名单可保留
  }
  editShow.value = true
}

async function onSaveEdit() {
  const f = editForm.value
  if (!f.collectibleId) return ElMessage.warning('请选择藏品')
  if (!f.name?.trim()) return ElMessage.warning('请填写活动名称')
  const payload = {
    id: editing.value?.id,
    collectibleId: f.collectibleId,
    name: f.name.trim(),
    status: f.status,
    startTime: f.startTime,
    endTime: f.endTime,
    remark: f.remark
  }
  // 只有显式勾选「同步白名单」才覆盖传值（后端 has('whitelist') 判定）
  if (editSyncWhitelist.value) {
    payload.whitelist = editWhitelists.value.map((w) => w.userId)
  }
  const res = await savePriority(payload)
  if (res.code === 0) {
    ElMessage.success(editing.value ? '优先购活动已更新' : '优先购活动已创建（后端自动同步白名单镜像）')
    editShow.value = false
    load()
  } else {
    ElMessage.error(res.message)
  }
}

const loading = ref(true)
const sales = ref([])

// ---- 导出白名单 CSV ----
function exportWl(s) {
  const rows = (s.whitelists || []).map((w, i) => [
    i + 1,
    w.nickname,
    w.phone,
    w.maxQuantity,
    w.usedQuantity,
    w.expiresAt || '跟随活动',
    isExpired(w.expiresAt) ? '已过期' : '生效中'
  ])
  if (!rows.length) return ElMessage.warning('该活动暂无白名单数据')
  downloadCsv(`优先购白名单_${s.name}`, ['序号', '用户', '手机号', '最大购买量', '已用配额', '有效期至', '状态'], rows)
  ElMessage.success(`已导出「${s.name}」白名单 ${rows.length} 条`)
}

// ---- 加白名单 ----
const addShow = ref(false)
const currentSale = ref(null)
const form = ref({ phone: '', quantity: 1, expiresAt: '' })

// ---- 批量导入白名单 ----
const importShow = ref(false)
const importTarget = ref(null) // 目标优先购活动
const importForm = ref({ phones: '', maxQuantity: 2, expiresAt: '' })

function openImport(s) {
  importTarget.value = s
  importForm.value = { phones: '', maxQuantity: 2, expiresAt: s.endTime || '' }
  importShow.value = true
}

async function onImport() {
  const f = importForm.value
  const phones = (f.phones || '').split(/[\s,，]+/).filter(Boolean)
  if (!phones.length) return ElMessage.warning('请至少输入一个手机号')
  if (phones.length > 500) return ElMessage.warning('单次最多导入 500 人')

  const res = await importPriorityWhitelist({
    saleId: importTarget.value.id,
    phones: phones.join('\n'),
    maxQuantity: f.maxQuantity,
    expiresAt: f.expiresAt
  })

  // 后端统一返回 code:0 成功，data.imported 实际导入数；即使全部已存在/无效也返回 code:0
  if (res.code === 0) {
    const d = res.data || {}
    const msg = d.imported === 0
      ? `全部已存在或无效，未新增任何成员`
      : `已导入 ${d.imported} 人`
    ElMessage.success(msg)
    importShow.value = false
    load()
  } else {
    ElMessage.error(res.message)
  }
}

onMounted(async () => {
  await Promise.all([
    load(),
    (async () => {
      // 优先购需要：在售中 + 有库存池 + 未下架（盲盒也可以做优先购）
      const c = await getCollectibleList({ page: 1, pageSize: 200 })
      collectibles.value = (c.data?.list || []).filter(
        (x) => x.status !== 'soldout' && x.status !== 'off' && x.availablePool > 0
      )
    })()
  ])
})

async function load() {
  loading.value = true
  const res = await getPrioritySales()
  sales.value = res.data
  loading.value = false
  // 白名单明细单独拉取（列表接口只给 whitelistCount，明细接口给全量字段）
  await Promise.all(
    sales.value
      .filter((s) => s.whitelistCount > 0)
      .map(async (s) => {
        const w = await getPriorityWhitelist(s.id)
        s.whitelists = w.code === 0 ? w.data : []
      })
  )
}

// ---- 移除白名单（写审计日志） ----
async function onRemoveWl(s, w) {
  await ElMessageBox.confirm(
    `确认移除「${w.nickname}（${w.phone}）」的优先购白名单？移除后该用户不再享有优先购买资格。`,
    '移除白名单',
    { type: 'warning' }
  )
  const res = await removePriorityWhitelist(w.id)
  if (res.code === 0) {
    ElMessage.success('已移除（已写入审计日志）')
    load()
  }
}

function openAdd(s) {
  currentSale.value = s
  form.value = { phone: '', quantity: 1, expiresAt: s.endTime }
  addShow.value = true
}

async function onAdd() {
  const f = form.value
  if (!/^1\d{10}$/.test(f.phone)) return ElMessage.warning('请输入正确的手机号')
  if (!Number.isInteger(f.quantity) || f.quantity < 1) return ElMessage.warning('请输入有效份数')
  const res = await addWhitelist({
    saleId: currentSale.value.id,
    phone: f.phone,
    quantity: f.quantity,
    expiresAt: f.expiresAt
  })
  if (res.code === 0) {
    ElMessage.success('已加入白名单并写入审计日志')
    addShow.value = false
    load()
  } else {
    ElMessage.error(res.message)
  }
}

// ---- 批量清理过期资格（二次确认） ----
async function onClean(s) {
  await ElMessageBox.confirm(
    `确认清理「${s.name}」的过期优先购资格？过期资格（有效期早于当前时间）将被批量移除，未过期的白名单不受影响。`,
    '清理过期资格',
    { type: 'warning' }
  )
  const res = await cleanExpiredPriority(s.id)
  if (res.code === 0) {
    ElMessage.success(res.data.cleaned > 0 ? `已清理 ${res.data.cleaned} 条过期资格` : '暂无过期资格')
    load()
  }
}

// 是否已过期
const isExpired = (t) => new Date(t).getTime() < Date.now()
</script>

<template>
  <div class="adm-page pr">
    <el-skeleton v-if="loading" :rows="8" animated style="padding: 20px" />
    <template v-else>
      <div class="adm-page-header">
        <div class="adm-page-title">优先购管理</div>
        <div class="adm-page-sub">为藏品配置「时间优先」购买通道，白名单用户可在正式开售前提前买入</div>
        <el-button type="primary" :icon="Plus" @click="openEdit(null)">新建优先购活动</el-button>
      </div>

      <el-empty v-if="!sales.length" description="暂无优先购活动" class="adm-empty">
        <template #default>
          <el-button type="primary" :icon="Plus" @click="openEdit(null)">立即创建</el-button>
        </template>
      </el-empty>

      <div v-for="s in sales" :key="s.id" class="adm-card pr__card">
        <div class="pr__head">
          <img class="pr__cover" :src="s.cover" :alt="s.collectibleName" />
          <div class="pr__head-body">
            <div class="pr__title">
              {{ s.name }}
              <el-tag type="warning" effect="plain" size="small">优先购</el-tag>
              <StatusTag :value="s.status" :map="ACTIVITY_STATUS" />
            </div>
            <div class="pr__desc">目标藏品：{{ s.collectibleName }} · 白名单 {{ s.whitelistCount }} 人</div>
            <div class="pr__desc t-tertiary">{{ s.startTime }} ~ {{ s.endTime }}</div>
          </div>
          <div class="pr__head-ops">
            <el-button size="small" :icon="Edit" @click="openEdit(s)">编辑</el-button>
            <el-button type="primary" size="small" :icon="Plus" @click="openAdd(s)">加白名单</el-button>
            <el-button size="small" :icon="Upload" @click="openImport(s)">导入名单</el-button>
            <el-button size="small" :icon="Download" @click="exportWl(s)">导出名单</el-button>
            <el-button size="small" :icon="Delete" @click="onClean(s)">清理过期</el-button>
          </div>
        </div>

        <el-table :data="s.whitelists" class="pr__table">
          <el-table-column label="用户" min-width="140">
            <template #default="{ row }">{{ row.nickname }}</template>
          </el-table-column>
          <el-table-column label="手机号" prop="phone" width="130" />
          <el-table-column label="最大购买量" width="100" align="center">
            <template #default="{ row }">
              <span class="price">{{ row.maxQuantity }}</span>
            </template>
          </el-table-column>
          <el-table-column label="已用配额" width="90" align="center">
            <template #default="{ row }">{{ row.usedQuantity }}</template>
          </el-table-column>
          <el-table-column label="有效期" min-width="150">
            <template #default="{ row }">
              <span :class="{ 'pr__expired': isExpired(row.expiresAt) }">{{ row.expiresAt || '跟随活动' }}</span>
            </template>
          </el-table-column>
          <el-table-column label="状态" width="90" align="center">
            <template #default="{ row }">
              <el-tag :type="isExpired(row.expiresAt) ? 'info' : 'success'" effect="plain" size="small">
                {{ isExpired(row.expiresAt) ? '已过期' : '生效中' }}
              </el-tag>
            </template>
          </el-table-column>
          <el-table-column label="操作" width="80" fixed="right">
            <template #default="{ row }">
              <el-button link type="danger" size="small" :icon="Delete" @click="onRemoveWl(s, row)" />
            </template>
          </el-table-column>
        </el-table>
        <div v-if="s.whitelistCount > s.whitelists.length" class="t-tertiary pr__more">
          其余 {{ s.whitelistCount - s.whitelists.length }} 人加载中或加载失败，可刷新重试
        </div>

        <el-alert
          type="info"
          :closable="false"
          show-icon
          class="pr__tip"
          title="优先购与资格购完全独立：白名单用户在公售时间前可提前购买（优先购权限覆盖资格购限制），购买成功后 used_quantity +1、订单 source = priority"
        />
      </div>

      <!-- 加白名单弹窗 -->
      <el-dialog v-model="addShow" :title="`加入白名单 · ${currentSale?.name || ''}`" width="440px" :close-on-click-modal="false">
        <el-form label-width="100px">
          <el-form-item label="手机号">
            <el-input v-model="form.phone" placeholder="平台已注册用户手机号" maxlength="11" />
          </el-form-item>
          <el-form-item label="最大购买量">
            <el-input-number v-model="form.quantity" :min="1" style="width: 180px" />
          </el-form-item>
          <el-form-item label="有效期至">
            <el-input v-model="form.expiresAt" placeholder="YYYY-MM-DD HH:mm（精确到时分秒）" />
          </el-form-item>
        </el-form>
        <el-alert
          type="info"
          :closable="false"
          show-icon
          title="加入白名单的用户在活动期间享有优先购买资格（expires_at > 当前时间 且 used < max 时可购买），超出限购份数后回落普通购买"
        />
        <template #footer>
          <el-button @click="addShow = false">取消</el-button>
          <el-button type="primary" @click="onAdd">确认添加</el-button>
        </template>
      </el-dialog>

      <!-- 批量导入白名单弹窗 -->
      <el-dialog
        v-model="importShow"
        :title="`批量导入白名单 · ${importTarget?.name || ''}`"
        width="520px"
        :close-on-click-modal="false"
      >
        <el-form label-width="110px">
          <el-form-item label="手机号列表" required>
            <el-input
              v-model="importForm.phones"
              type="textarea"
              :rows="6"
              placeholder="批量手机号，换行分隔，每行一个；也支持逗号、空格分隔。格式错误 / 非注册用户自动拦截，已存在自动跳过"
            />
            <div class="pr__import-tip">
              支持 Ctrl+V 从 Excel / TXT 直接粘贴；单次最多 500 人
            </div>
          </el-form-item>
          <el-form-item label="统一限购份数">
            <el-input-number v-model="importForm.maxQuantity" :min="1" :max="999" style="width: 180px" />
            <div class="pr__import-tip">同一活动不同用户限购一致，如需差异化请使用「加白名单」逐个配置</div>
          </el-form-item>
          <el-form-item label="有效期至">
            <el-input v-model="importForm.expiresAt" placeholder="YYYY-MM-DD HH:mm:ss（可选，留空跟随活动窗口）" />
          </el-form-item>
        </el-form>
        <el-alert
          type="info"
          :closable="false"
          show-icon
          title="后端幂等处理：已存在的用户自动跳过、格式错误和非注册用户拦截不影响有效名单导入；导入操作写入审计日志并同步 C 端镜像表"
        />
        <template #footer>
          <el-button @click="importShow = false">取消</el-button>
          <el-button type="primary" @click="onImport">确认导入</el-button>
        </template>
      </el-dialog>

      <!-- 新建/编辑优先购活动弹窗 -->
      <el-dialog
        v-model="editShow"
        :title="editing ? `编辑优先购活动 · ${editing.name}` : '新建优先购活动'"
        width="640px"
        :close-on-click-modal="false"
      >
        <el-form label-width="110px">
          <el-form-item label="目标藏品" required>
            <el-select
              v-model="editForm.collectibleId"
              filterable
              :disabled="!!editing"
              placeholder="选择要开启优先购的藏品"
              style="width: 100%"
            >
              <el-option
                v-for="c in collectibles"
                :key="c.id"
                :label="c.name"
                :value="c.id"
              >
                <div style="display: flex; justify-content: space-between">
                  <span>{{ c.name }}</span>
                  <span class="t-tertiary">价格 ¥{{ c.price || '0.00' }}</span>
                </div>
              </el-option>
            </el-select>
            <div v-if="editing" class="t-tertiary" style="font-size: 12px; margin-top: 4px">
              藏品已绑定，优先购活动一物一藏品不允许更换
            </div>
          </el-form-item>
          <el-form-item label="活动名称" required>
            <el-input v-model="editForm.name" placeholder="如：藏品首发优先购通道" maxlength="100" />
          </el-form-item>
          <el-form-item label="活动状态">
            <el-select v-model="editForm.status" style="width: 160px">
              <el-option label="启用" value="enabled" />
              <el-option label="已停用" value="disabled" />
              <el-option label="已结束" value="ended" />
            </el-select>
          </el-form-item>
          <el-form-item label="开始时间">
            <el-input v-model="editForm.startTime" placeholder="YYYY-MM-DD HH:mm:ss（可选）" />
          </el-form-item>
          <el-form-item label="结束时间">
            <el-input v-model="editForm.endTime" placeholder="YYYY-MM-DD HH:mm:ss（可选）" />
          </el-form-item>
          <el-form-item label="备注">
            <el-input v-model="editForm.remark" type="textarea" :rows="2" maxlength="255" placeholder="可选，内部说明" />
          </el-form-item>
        </el-form>

        <!-- 编辑模式：白名单只读展示 + 同步开关 -->
        <template v-if="editing">
          <div class="pr__edit-wl-head">
            <div class="pr__edit-wl-title">
              当前白名单 <span class="t-tertiary">（{{ editWhitelists.length }} 人）</span>
            </div>
            <el-switch
              v-model="editSyncWhitelist"
              active-text="保存时同步"
              inactive-text="保存时保留"
              inline-prompt
            />
          </div>
          <div
            v-if="editSyncWhitelist"
            class="pr__edit-wl-tip"
          >
            ⚠️ 已开启同步：本次保存将用下方列表覆盖白名单（清空或增删）。建议先在活动卡片上用「加白名单 / 移除」操作，再回来保存活动信息。
          </div>
          <div class="pr__edit-wl-table">
            <el-table
              v-loading="editLoadingWl"
              :data="editWhitelists"
              size="small"
              empty-text="当前活动暂无白名单"
            >
              <el-table-column label="用户" prop="nickname" width="100" />
              <el-table-column label="手机号" prop="phone" width="140" />
              <el-table-column label="最大购买量" prop="maxQuantity" width="90" />
              <el-table-column label="已用" prop="usedQuantity" width="70" />
              <el-table-column label="有效期" prop="expiresAt" min-width="140" />
            </el-table>
          </div>
        </template>

        <el-alert
          v-if="!editing"
          type="info"
          :closable="false"
          show-icon
          title="保存后自动同步到 C 端镜像表（priority_sales），一物一活动；如需加白名单请在活动卡片上点击「加白名单」"
        />
        <template #footer>
          <el-button @click="editShow = false">取消</el-button>
          <el-button type="primary" @click="onSaveEdit">{{ editing ? '保存修改' : '创建活动' }}</el-button>
        </template>
      </el-dialog>
    </template>
  </div>
</template>

<style scoped lang="scss">
.pr__card + .pr__card { margin-top: 14px; }

.pr__head {
  display: flex;
  gap: 12px;
  align-items: flex-start;
  margin-bottom: 12px;
}

.pr__cover {
  width: 48px;
  height: 48px;
  border-radius: 8px;
  object-fit: cover;
  flex-shrink: 0;
  background: $color-surface;
}

.pr__head-body { flex: 1; min-width: 0; }

.pr__title {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 15px;
  font-weight: 600;
  color: $color-text-primary;
}

.pr__desc { font-size: 12px; color: $color-text-secondary; margin-top: 3px; }

.pr__head-ops { display: flex; gap: 8px; flex-shrink: 0; }

.pr__expired {
  color: $color-text-tertiary;
  text-decoration: line-through;
}

.pr__more {
  font-size: 11px;
  text-align: center;
  padding: 6px 0;
}

.pr__tip { margin-top: 10px; }

.pr__edit-wl-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin: 10px 0 6px;
  padding-top: 12px;
  border-top: 1px solid var(--el-border-color-lighter);
}

.pr__edit-wl-title { font-weight: 600; font-size: 13px; }

.pr__edit-wl-tip {
  font-size: 12px;
  color: var(--el-color-warning);
  background: var(--el-color-warning-light-9);
  padding: 6px 10px;
  border-radius: 4px;
  margin-bottom: 6px;
}

.pr__edit-wl-table {
  max-height: 200px;
  overflow: auto;
  border: 1px solid var(--el-border-color-lighter);
  border-radius: 4px;
}
</style>
