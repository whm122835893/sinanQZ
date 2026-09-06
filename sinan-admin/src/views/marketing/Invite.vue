<script setup>
import { ref, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import {
  getInviteList,
  saveInviteActivity,
  toggleInviteActivity,
  getCollectibleList
} from '@/api'
import StatusTag from '@/components/StatusTag.vue'
import { ACTIVITY_STATUS } from '@/utils/maps'
import { fmtNumber } from '@/utils/format'

const loading = ref(true)
const list = ref([])

// ---- 藏品下拉 ----
const collectibles = ref([])

// ---- 新建/编辑 ----
const editShow = ref(false)
const editing = ref(null)
const submitting = ref(false)
const form = ref({
  name: '',
  status: 'disabled',
  mode: 'realtime',
  inviterCollectibleId: null,
  inviterQuantity: 1,
  inviteeCollectibleId: null,
  inviteeQuantity: 1,
  totalLimit: null,
  startTime: '',
  endTime: '',
  description: ''
})

onMounted(load)

async function load() {
  loading.value = true
  const [res, col] = await Promise.all([
    getInviteList(),
    getCollectibleList({ page: 1, pageSize: 200 })
  ])
  if (res.code === 0) list.value = Array.isArray(res.data) ? res.data : []
  else list.value = []
  if (col.code === 0) collectibles.value = col.data.list || []
  loading.value = false
}

// ---- 活动开关 ----
async function onToggle(a) {
  const enabling = a.status !== 'enabled'
  await ElMessageBox.confirm(
    enabling
      ? `确认开启邀请活动「${a.name}」？开启后 C 端将展示邀请入口。`
      : `确认停用邀请活动「${a.name}」？停用后 C 端邀请入口隐藏。`,
    '活动启停',
    { type: 'warning' }
  )
  const res = await toggleInviteActivity(a)
  if (res.code === 0) {
    a.status = enabling ? 'enabled' : 'disabled'
    ElMessage.success(enabling ? '已开启' : '已停用')
  }
}

// ---- 新建/编辑 ----
function openCreate() {
  editing.value = null
  form.value = {
    name: '',
    status: 'disabled',
    mode: 'realtime',
    inviterCollectibleId: null,
    inviterQuantity: 1,
    inviteeCollectibleId: null,
    inviteeQuantity: 1,
    totalLimit: null,
    startTime: '',
    endTime: '',
    description: ''
  }
  editShow.value = true
}

function openEdit(a) {
  editing.value = a
  form.value = {
    name: a.name,
    status: a.status,
    mode: a.mode || 'realtime',
    inviterCollectibleId: a.inviterReward?.collectibleId || null,
    inviterQuantity: a.inviterReward?.quantity || 1,
    inviteeCollectibleId: a.inviteeReward?.collectibleId || null,
    inviteeQuantity: a.inviteeReward?.quantity || 1,
    totalLimit: a.totalLimit ?? null,
    startTime: a.startTime || '',
    endTime: a.endTime || '',
    description: a.description || ''
  }
  editShow.value = true
}

async function onSave() {
  const f = form.value
  if (!f.name.trim()) return ElMessage.warning('请输入活动名称')
  if (f.status === 'enabled' && !f.inviterCollectibleId && !f.inviteeCollectibleId) {
    return ElMessage.warning('启用活动需配置邀请方/被邀请方至少一方的空投藏品')
  }
  submitting.value = true
  const res = await saveInviteActivity({
    id: editing.value?.id,
    name: f.name.trim(),
    status: f.status,
    mode: f.mode,
    inviterReward: { collectibleId: f.inviterCollectibleId || null, quantity: f.inviterQuantity },
    inviteeReward: { collectibleId: f.inviteeCollectibleId || null, quantity: f.inviteeQuantity },
    totalLimit: f.totalLimit,
    startTime: f.startTime,
    endTime: f.endTime,
    description: f.description
  })
  submitting.value = false
  if (res.code === 0) {
    ElMessage.success(editing.value ? '活动已更新' : '活动已创建')
    editShow.value = false
    load()
  }
}

const cname = (id) => collectibles.value.find((c) => c.id === id)?.name || `藏品 #${id}`
</script>

<template>
  <div class="adm-page iv">
    <el-skeleton v-if="loading" :rows="8" animated style="padding: 20px" />
    <template v-else>
      <div class="iv__toolbar">
        <div class="t-tertiary" style="font-size: 12px">
          邀请活动支持多期并存，同一时间仅一个启用中的活动对 C 端生效
        </div>
        <el-button type="primary" :icon="Plus" @click="openCreate">新建活动</el-button>
      </div>

      <el-empty v-if="!list.length" description="暂无邀请活动，点击右上角「新建活动」创建" />

      <div v-for="a in list" :key="a.id" class="adm-card iv__card">
        <div class="iv__head">
          <div class="iv__title">
            <span class="iv__name">{{ a.name }}</span>
            <StatusTag :value="a.status" :map="ACTIVITY_STATUS" />
          </div>
          <div class="iv__ops">
            <el-button link type="primary" size="small" @click="openEdit(a)">编辑</el-button>
            <el-switch :model-value="a.status === 'enabled'" @change="onToggle(a)" />
          </div>
        </div>

        <div class="iv__meta">
          <span>发放模式：{{ a.mode === 'batch' ? '次日发放' : '实时发放' }}</span>
          <span v-if="a.startTime">开始 {{ a.startTime }}</span>
          <span v-if="a.endTime">截止 {{ a.endTime }}</span>
          <span>总量限额：{{ a.totalLimit == null ? '不限' : fmtNumber(a.totalLimit) }}（已邀 {{ fmtNumber(a.stats.invitedCount) }}）</span>
        </div>

        <div class="iv__rewards">
          <div class="iv__reward">
            <div class="iv__reward-label">邀请方奖励</div>
            <div class="iv__reward-body">
              <template v-if="a.inviterReward.collectibleId">
                <div class="iv__reward-name">{{ a.inviterReward.name || cname(a.inviterReward.collectibleId) }}</div>
                <div class="t-tertiary" style="font-size: 12px">每成功邀请 1 人 ×{{ a.inviterReward.quantity }}</div>
              </template>
              <div v-else class="t-tertiary" style="font-size: 12px">未配置</div>
            </div>
          </div>
          <div class="iv__reward">
            <div class="iv__reward-label">被邀请方奖励</div>
            <div class="iv__reward-body">
              <template v-if="a.inviteeReward.collectibleId">
                <div class="iv__reward-name">{{ a.inviteeReward.name || cname(a.inviteeReward.collectibleId) }}</div>
                <div class="t-tertiary" style="font-size: 12px">注册并实名后 ×{{ a.inviteeReward.quantity }}</div>
              </template>
              <div v-else class="t-tertiary" style="font-size: 12px">未配置</div>
            </div>
          </div>
        </div>

        <div v-if="a.description" class="iv__desc">{{ a.description }}</div>

        <el-alert
          type="info"
          :closable="false"
          show-icon
          class="iv__tip"
          title="奖励发放时动态校验藏品配额预留 / 盲盒库存池，不足则挂起待补发并生成异常日志，禁止超发"
        />
      </div>
    </template>

    <!-- 新建/编辑活动弹窗 -->
    <el-dialog
      v-model="editShow"
      :title="editing ? `编辑邀请活动 · ${editing.name}` : '新建邀请活动'"
      width="560px"
      :close-on-click-modal="false"
    >
      <el-form label-width="110px">
        <el-form-item label="活动名称">
          <el-input v-model="form.name" placeholder="如：青铜纪事 · 好友邀请第一期" maxlength="50" show-word-limit />
        </el-form-item>

        <el-form-item label="活动状态">
          <el-switch
            v-model="form.status"
            active-value="enabled"
            inactive-value="disabled"
            active-text="启用"
            inactive-text="停用"
          />
          <div class="t-tertiary" style="font-size: 12px; margin-top: 4px; width: 100%">
            启用需至少配置一方空投奖励；新建建议先停用，配好奖励后再开启
          </div>
        </el-form-item>

        <el-form-item label="邀请方奖励">
          <el-select
            v-model="form.inviterCollectibleId"
            clearable
            filterable
            placeholder="选择空投藏品（可留空）"
            style="width: 100%"
          >
            <el-option
              v-for="c in collectibles"
              :key="c.id"
              :label="c.name"
              :value="c.id"
            />
          </el-select>
          <el-input-number
            v-if="form.inviterCollectibleId"
            v-model="form.inviterQuantity"
            :min="1"
            :max="99"
            size="small"
            style="margin-top: 6px"
          />
          <span v-if="form.inviterCollectibleId" class="t-tertiary" style="font-size: 12px; margin-left: 6px">份 / 每成功邀请 1 人</span>
        </el-form-item>

        <el-form-item label="被邀请方奖励">
          <el-select
            v-model="form.inviteeCollectibleId"
            clearable
            filterable
            placeholder="选择空投藏品（可留空）"
            style="width: 100%"
          >
            <el-option
              v-for="c in collectibles"
              :key="c.id"
              :label="c.name"
              :value="c.id"
            />
          </el-select>
          <el-input-number
            v-if="form.inviteeCollectibleId"
            v-model="form.inviteeQuantity"
            :min="1"
            :max="99"
            size="small"
            style="margin-top: 6px"
          />
          <span v-if="form.inviteeCollectibleId" class="t-tertiary" style="font-size: 12px; margin-left: 6px">份 / 注册并实名后</span>
        </el-form-item>

        <el-form-item label="发放模式">
          <el-radio-group v-model="form.mode">
            <el-radio value="realtime">实时发放</el-radio>
            <el-radio value="batch">次日批量发放</el-radio>
          </el-radio-group>
        </el-form-item>

        <el-form-item label="总量限额">
          <el-input-number v-model="form.totalLimit" :min="1" :step="100" placeholder="留空不限" style="width: 180px" />
          <div class="t-tertiary" style="font-size: 12px; margin-top: 4px; width: 100%">
            留空表示不限制邀请总量
          </div>
        </el-form-item>

        <el-form-item label="起止时间">
          <el-input v-model="form.startTime" placeholder="开始时间 2026-09-01 00:00:00（可留空）" style="width: 46%; margin-right: 4px" />
          <el-input v-model="form.endTime" placeholder="截止时间（可留空）" style="width: 48%" />
        </el-form-item>

        <el-form-item label="活动说明">
          <el-input
            v-model="form.description"
            type="textarea"
            :rows="2"
            maxlength="200"
            show-word-limit
            placeholder="C 端活动页展示的说明文案"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="editShow = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="onSave">
          {{ editing ? '保存修改' : '创建活动' }}
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped lang="scss">
.iv__toolbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  margin-bottom: 14px;
}

.iv__card + .iv__card { margin-top: 14px; }

.iv__head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
}

.iv__title { display: flex; align-items: center; gap: 8px; min-width: 0; }
.iv__name { font-size: 15px; font-weight: 700; color: $color-text-primary; }
.iv__ops { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }

.iv__meta {
  display: flex;
  flex-wrap: wrap;
  gap: 6px 18px;
  font-size: 12px;
  color: $color-text-secondary;
  margin-top: 8px;
}

.iv__rewards {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 10px;
  margin-top: 12px;

  @media (max-width: 768px) {
    grid-template-columns: 1fr;
  }
}

.iv__reward {
  padding: 10px 12px;
  border-radius: 8px;
  background: $color-surface;
}

.iv__reward-label { font-size: 11px; color: $color-text-tertiary; margin-bottom: 4px; }
.iv__reward-name { font-size: 13px; font-weight: 600; color: $color-text-primary; }

.iv__desc {
  margin-top: 10px;
  font-size: 12px;
  color: $color-text-secondary;
}

.iv__tip { margin-top: 10px; }
</style>
