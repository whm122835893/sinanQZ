<script setup>
import { ref, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { User, Delete } from '@element-plus/icons-vue'
import {
  getQualifications,
  saveQualification,
  addQualificationWhitelist,
  removeQualificationWhitelist,
  getCollectibleList
} from '@/api'
import { QUALIFY_CONDITION_TYPE } from '@/utils/maps'

const loading = ref(true)
const list = ref([])
const collectibles = [] // 可选资格藏品缓存

// ---- 条件编辑 ----
const editShow = ref(false)
const editing = ref(null)
const form = ref({
  isEnabled: 1,
  conditionType: 1,
  requiredCollectibleIds: [],
  requiredCheckinDays: 0,
  requiredInviteCount: 0,
  validStartAt: '',
  validEndAt: ''
})

// ---- 白名单导入 ----
const wlShow = ref(false)
const wlTarget = ref(null)
const wlForm = ref({ phones: '', expiresAt: '' })

onMounted(load)

async function load() {
  loading.value = true
  const [q, c] = await Promise.all([getQualifications(), getCollectibleList({ page: 1, size: 100 })])
  list.value = q.data
  collectibles.length = 0
  collectibles.push(...(c.data.list || []).filter((x) => x.circulate > 0))
  loading.value = false
}

// ---- 开关（独立于优先购） ----
async function onToggle(q, val) {
  await ElMessageBox.confirm(
    val
      ? `确认开启「${q.collectibleName}」的资格购？未获得资格的用户在 C 端将看到「未获得购买资格」提示。`
      : `确认关闭「${q.collectibleName}」的资格购？关闭后该藏品恢复正常公售（无资格限制）。`,
    '资格购开关',
    { type: 'warning' }
  )
  const res = await saveQualification({ id: q.id, isEnabled: val ? 1 : 0 })
  if (res.code === 0) {
    q.isEnabled = val ? 1 : 0
    ElMessage.success(val ? '已开启资格购' : '已关闭资格购')
  } else {
    q.isEnabled = val ? 0 : 1
  }
}

// ---- 条件编辑 ----
function openEdit(q) {
  editing.value = q
  form.value = {
    isEnabled: q.isEnabled,
    conditionType: q.conditionType,
    requiredCollectibleIds: q.requiredCollectibles.map((c) => c.collectibleId),
    requiredCheckinDays: q.requiredCheckinDays,
    requiredInviteCount: q.requiredInviteCount,
    validStartAt: q.validStartAt,
    validEndAt: q.validEndAt
  }
  editShow.value = true
}

const conditionCount = computed(() => {
  const f = form.value
  return (f.requiredCollectibleIds.length ? 1 : 0) + (f.requiredCheckinDays > 0 ? 1 : 0) + (f.requiredInviteCount > 0 ? 1 : 0)
})

async function onSave() {
  const f = form.value
  if (f.conditionType === 2 && conditionCount.value < 2) {
    return ElMessage.warning('「满足全部」需至少配置 2 个条件')
  }
  const res = await saveQualification({
    id: editing.value.id,
    isEnabled: f.isEnabled,
    conditionType: f.conditionType,
    requiredCollectibles: f.requiredCollectibleIds.map((id) => {
      const c = collectibles.find((x) => x.id === id)
      return { collectibleId: id, name: c?.name, cover: c?.cover }
    }),
    requiredCheckinDays: f.requiredCheckinDays,
    requiredInviteCount: f.requiredInviteCount,
    validStartAt: f.validStartAt,
    validEndAt: f.validEndAt
  })
  if (res.code === 0) {
    ElMessage.success('资格购配置已保存（不占库存、不占配额）')
    editShow.value = false
    load()
  }
}

// ---- 白名单导入 ----
function openWhitelist(q) {
  wlTarget.value = q
  wlForm.value = { phones: '', expiresAt: q.validEndAt }
  wlShow.value = true
}

async function onImport() {
  const phones = wlForm.value.phones.split(/[\n,，\s]+/).filter(Boolean)
  if (!phones.length) return ElMessage.warning('请输入至少一个手机号')
  if (phones.some((p) => !/^1\d{10}$/.test(p))) return ElMessage.warning('存在格式错误的手机号')
  const res = await addQualificationWhitelist({
    qualificationId: wlTarget.value.id,
    phones: phones.join('\n'),
    expiresAt: wlForm.value.expiresAt
  })
  if (res.code === 0) {
    ElMessage.success(`已导入 ${res.data.added} 位白名单用户（无需满足条件即可购买）`)
    wlShow.value = false
    load()
  } else {
    ElMessage.error(res.message)
  }
}

async function onRemoveWl(q, w) {
  await ElMessageBox.confirm(`确认移除「${w.nickname}」的资格购白名单？移除后需满足条件才可购买。`, '移除白名单', { type: 'warning' })
  const res = await removeQualificationWhitelist(q.id, w.id)
  if (res.code === 0) {
    ElMessage.success('已移除')
    load()
  }
}

// ---- 有效期状态 ----
const isExpired = (t) => new Date(t).getTime() < Date.now()
</script>

<template>
  <div class="adm-page ql">
    <el-skeleton v-if="loading" :rows="8" animated style="padding: 20px" />
    <template v-else>
      <el-alert
        type="info"
        :closable="false"
        show-icon
        class="ql__intro"
        title="资格购 = 购买门槛/条件限制（可完全不对公售），与优先购（时间优先通道）完全独立、可同时配置。优先购资格可绕过资格购限制；资格购不冻结库存、不占用配额，仅作购买门槛"
      />

      <div v-for="q in list" :key="q.id" class="adm-card ql__card">
        <div class="ql__head">
          <img class="ql__cover" :src="q.cover" :alt="q.collectibleName" />
          <div class="ql__head-body">
            <div class="ql__title">
              {{ q.collectibleName }}
              <el-tag :type="q.isEnabled ? 'success' : 'info'" effect="plain" size="small">
                {{ q.isEnabled ? '资格购已开启' : '资格购已关闭' }}
              </el-tag>
              <el-tag :type="q.conditionType === 2 ? 'danger' : 'primary'" effect="plain" size="small">
                {{ QUALIFY_CONDITION_TYPE[q.conditionType] }}
              </el-tag>
            </div>
            <div class="ql__desc">
              有效期 {{ q.validStartAt }} ~ {{ q.validEndAt }}
              <el-tag v-if="isExpired(q.validEndAt)" type="info" effect="plain" size="small">已过期</el-tag>
            </div>
            <div class="ql__desc t-tertiary">已获资格用户 {{ q.qualifiedCount }} 人 · 白名单 {{ q.whitelist.length }} 人</div>
          </div>
          <div class="ql__head-ops">
            <el-button type="primary" size="small" :icon="User" @click="openWhitelist(q)">导入白名单</el-button>
            <el-button size="small" @click="openEdit(q)">条件配置</el-button>
            <el-switch
              :model-value="q.isEnabled === 1"
              @change="(v) => onToggle(q, v)"
            />
          </div>
        </div>

        <!-- 条件概览 -->
        <div class="ql__conditions">
          <div class="ql__cond">
            <div class="ql__cond-label">资格藏品</div>
            <div class="ql__cond-body">
              <template v-if="q.requiredCollectibles.length">
                <el-tag
                  v-for="c in q.requiredCollectibles"
                  :key="c.collectibleId"
                  type="primary"
                  effect="plain"
                  size="small"
                >{{ c.name }}</el-tag>
              </template>
              <span v-else class="t-tertiary">未配置</span>
            </div>
          </div>
          <div class="ql__cond">
            <div class="ql__cond-label">累计签到</div>
            <div class="ql__cond-body">
              <span v-if="q.requiredCheckinDays > 0">≥ <b class="price">{{ q.requiredCheckinDays }}</b> 天</span>
              <span v-else class="t-tertiary">未配置</span>
            </div>
          </div>
          <div class="ql__cond">
            <div class="ql__cond-label">累计邀请</div>
            <div class="ql__cond-body">
              <span v-if="q.requiredInviteCount > 0">≥ <b class="price">{{ q.requiredInviteCount }}</b> 人</span>
              <span v-else class="t-tertiary">未配置</span>
            </div>
          </div>
        </div>

        <!-- 白名单 -->
        <div v-if="q.whitelist.length" class="ql__whitelist">
          <div class="ql__wl-title">额外资格白名单（免条件）</div>
          <el-table :data="q.whitelist" size="small">
            <el-table-column label="用户" prop="nickname" min-width="120" />
            <el-table-column label="手机号" prop="phone" width="130" />
            <el-table-column label="有效期至" min-width="150">
              <template #default="{ row }">{{ row.expiresAt }}</template>
            </el-table-column>
            <el-table-column label="操作" width="80" fixed="right">
              <template #default="{ row }">
                <el-button link type="danger" size="small" :icon="Delete" @click="onRemoveWl(q, row)" />
              </template>
            </el-table-column>
          </el-table>
        </div>
      </div>

      <!-- 条件配置弹窗 -->
      <el-dialog v-model="editShow" :title="`资格购条件配置 · ${editing?.collectibleName || ''}`" width="520px" :close-on-click-modal="false">
        <el-form label-width="110px">
          <el-form-item label="资格购开关">
            <el-switch v-model="form.isEnabled" :active-value="1" :inactive-value="0" />
          </el-form-item>
          <el-form-item label="条件组合方式">
            <el-radio-group v-model="form.conditionType">
              <el-radio :value="1">满足任一</el-radio>
              <el-radio :value="2">满足全部</el-radio>
            </el-radio-group>
          </el-form-item>
          <el-form-item label="资格藏品">
            <el-select v-model="form.requiredCollectibleIds" multiple filterable placeholder="仅可选择流通量 > 0 的藏品" style="width: 100%">
              <el-option
                v-for="c in collectibles"
                :key="c.id"
                :label="c.name"
                :value="c.id"
              >
                <div class="ql__option">
                  <span>{{ c.name }}</span>
                  <span class="t-tertiary">流通 {{ c.circulate }}</span>
                </div>
              </el-option>
            </el-select>
            <div class="t-tertiary" style="font-size: 12px; margin-top: 4px; width: 100%">
              用户持有至少 1 个所选藏品即满足该条件
            </div>
          </el-form-item>
          <el-form-item label="累计签到天数">
            <el-input-number v-model="form.requiredCheckinDays" :min="0" :step="1" style="width: 180px" />
            <div class="t-tertiary" style="font-size: 12px; margin-top: 4px; width: 100%">0 表示不要求</div>
          </el-form-item>
          <el-form-item label="累计邀请人数">
            <el-input-number v-model="form.requiredInviteCount" :min="0" :step="1" style="width: 180px" />
            <div class="t-tertiary" style="font-size: 12px; margin-top: 4px; width: 100%">
              要求成功注册的邀请好友数；0 表示不要求
            </div>
          </el-form-item>
          <el-form-item label="有效期">
            <el-input v-model="form.validStartAt" placeholder="开始时间 YYYY-MM-DD HH:mm" style="width: 46%; margin-right: 8%" />
            <el-input v-model="form.validEndAt" placeholder="结束时间 YYYY-MM-DD HH:mm" style="width: 46%" />
            <div class="t-tertiary" style="font-size: 12px; margin-top: 4px; width: 100%">过期后资格自动失效</div>
          </el-form-item>
        </el-form>
        <template #footer>
          <el-button @click="editShow = false">取消</el-button>
          <el-button type="primary" @click="onSave">保存</el-button>
        </template>
      </el-dialog>

      <!-- 白名单导入弹窗 -->
      <el-dialog v-model="wlShow" :title="`导入白名单 · ${wlTarget?.collectibleName || ''}`" width="480px" :close-on-click-modal="false">
        <el-form label-width="100px">
          <el-form-item label="手机号">
            <el-input
              v-model="wlForm.phones"
              type="textarea"
              :rows="5"
              placeholder="批量手机号，换行分隔，每行一个（须为平台已注册用户，未注册直接拦截）"
            />
          </el-form-item>
          <el-form-item label="有效期至">
            <el-input v-model="wlForm.expiresAt" placeholder="YYYY-MM-DD HH:mm:ss（精确到时分秒）" />
          </el-form-item>
        </el-form>
        <el-alert
          type="info"
          :closable="false"
          show-icon
          title="白名单用户无需满足任何条件即可获得购买资格；购买时仍受 per_user_limit 约束"
        />
        <template #footer>
          <el-button @click="wlShow = false">取消</el-button>
          <el-button type="primary" @click="onImport">确认导入</el-button>
        </template>
      </el-dialog>
    </template>
  </div>
</template>

<style scoped lang="scss">
.ql__intro { margin-bottom: 14px; }

.ql__card + .ql__card { margin-top: 14px; }

.ql__head {
  display: flex;
  gap: 12px;
  align-items: flex-start;
}

.ql__cover {
  width: 48px;
  height: 48px;
  border-radius: 8px;
  object-fit: cover;
  flex-shrink: 0;
  background: $color-surface;
}

.ql__head-body { flex: 1; min-width: 0; }

.ql__title {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 15px;
  font-weight: 600;
  color: $color-text-primary;
}

.ql__desc {
  font-size: 12px;
  color: $color-text-secondary;
  margin-top: 3px;
  display: flex;
  align-items: center;
  gap: 6px;
}

.ql__head-ops { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }

.ql__conditions {
  display: grid;
  grid-template-columns: 2fr 1fr 1fr;
  gap: 10px;
  margin-top: 14px;

  @media (max-width: 768px) {
    grid-template-columns: 1fr;
  }
}

.ql__cond {
  padding: 10px 12px;
  border-radius: 8px;
  background: $color-surface;
}

.ql__cond-label {
  font-size: 11px;
  color: $color-text-tertiary;
  margin-bottom: 6px;
}

.ql__cond-body {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  font-size: 13px;

  b { font-size: 14px; }
}

.ql__whitelist { margin-top: 12px; padding-top: 10px; border-top: 1px dashed $color-border; }

.ql__wl-title {
  font-size: 12px;
  font-weight: 600;
  color: $color-text-secondary;
  margin-bottom: 6px;
}

.ql__option {
  display: flex;
  justify-content: space-between;
  gap: 12px;
}
</style>
