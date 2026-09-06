<script setup lang="ts">
/**
 * 邀请活动配置（表单页，单活动配置）
 *
 * - 邀请人奖励 / 被邀请人奖励（空投藏品 + 数量）
 * - 奖励发放上限、发放方式、活动开关与活动时间
 * - 启用校验：邀请人 / 被邀请人至少配置一方空投藏品
 *
 * 接口：GET / POST /admin/marketing/invite（列表返回活动数组，取首条作为当前配置）
 * 权限：marketing:invite:config
 */
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import { fetchInviteConfig, saveInviteConfig } from '@/api/marketing'
import { fetchCollectibles } from '@/api/collectible'
import { datetime, money } from '@/utils/format'

const loading = ref(false)
const saving = ref(false)
const formRef = ref<FormInstance>()

/** 当前配置的活动 ID（不存在时保存将新建） */
const configId = ref<number | undefined>()
/** 平台累计邀请人数（后端 invitee_count） */
const inviteeCount = ref(0)
/** 最近一次保存时间 */
const updatedAt = ref('')
/** 当前活动状态 */
const status = ref<'disabled' | 'enabled'>('disabled')

const form = reactive({
  name: '邀请好友活动',
  timeRange: [] as string[],
  /** 邀请人奖励 */
  inviterCollectibleId: undefined as number | undefined,
  inviterQuantity: 1,
  /** 被邀请人奖励 */
  inviteeCollectibleId: undefined as number | undefined,
  inviteeQuantity: 1,
  /** 发放方式：realtime 实时 / batch 批量 */
  airdropMode: 'realtime',
  /** 奖励发放总量上限（份），0 表示不限制 */
  totalLimit: 0,
  description: '',
})

const rules: FormRules = {
  name: [{ required: true, message: '请输入活动名称', trigger: 'blur' }],
  inviterQuantity: [{ required: true, message: '请输入邀请人奖励数量', trigger: 'blur' }],
  inviteeQuantity: [{ required: true, message: '请输入被邀请人奖励数量', trigger: 'blur' }],
}

async function load() {
  loading.value = true
  try {
    const rows = (await fetchInviteConfig()) || []
    const row = rows[0] || {}
    configId.value = row.id
    inviteeCount.value = Number(row.inviteeCount ?? 0)
    updatedAt.value = row.updatedAt || ''
    status.value = row.status === 'enabled' ? 'enabled' : 'disabled'

    form.name = row.name || '邀请好友活动'
    form.timeRange = row.startTime || row.endTime ? [row.startTime || '', row.endTime || ''] : []
    form.inviterCollectibleId = row.inviterCollectibleId || undefined
    form.inviterQuantity = Number(row.inviterQuantity ?? 1) || 1
    form.inviteeCollectibleId = row.inviteeCollectibleId || undefined
    form.inviteeQuantity = Number(row.inviteeQuantity ?? 1) || 1
    form.airdropMode = row.airdropMode === 'batch' ? 'batch' : 'realtime'
    form.totalLimit = Number(row.totalLimit ?? 0) || 0
    form.description = row.description || ''

    // 回显当前已配置的空投藏品
    ensureCollectibleOption(row.inviterCollectibleId, row.inviterCollectibleName)
    ensureCollectibleOption(row.inviteeCollectibleId, row.inviteeCollectibleName)
    await searchCollectibles()
  } finally {
    loading.value = false
  }
}

async function save() {
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) return

  // 与后端一致：启用需至少配置一方空投藏品
  if (status.value === 'enabled' && !form.inviterCollectibleId && !form.inviteeCollectibleId) {
    ElMessage.warning('启用邀请活动需配置邀请人 / 被邀请人至少一方的空投藏品')
    return
  }

  saving.value = true
  try {
    const range = form.timeRange || []
    await saveInviteConfig({
      ...(configId.value ? { id: configId.value } : {}),
      name: form.name.trim(),
      status: status.value,
      start_time: range[0] || '',
      end_time: range[1] || '',
      inviter_collectible_id: form.inviterCollectibleId || 0,
      inviter_quantity: form.inviterQuantity,
      invitee_collectible_id: form.inviteeCollectibleId || 0,
      invitee_quantity: form.inviteeQuantity,
      airdrop_mode: form.airdropMode,
      total_limit: form.totalLimit,
      description: form.description,
    })
    ElMessage.success('邀请活动配置已保存')
    load()
  } finally {
    saving.value = false
  }
}

// ==================== 藏品远程搜索 ====================
const collectibleOptions = ref<any[]>([])
const collectibleLoading = ref(false)
const collectibleCache = new Map<number, any>()
let collectibleSeq = 0

function ensureCollectibleOption(id?: number | null, name?: string) {
  if (!id) return
  if (!collectibleCache.has(id)) collectibleCache.set(id, { id, name: name || `藏品 #${id}`, price: 0 })
  if (!collectibleOptions.value.some((o) => o.id === id)) {
    collectibleOptions.value.unshift(collectibleCache.get(id))
  }
}

async function searchCollectibles(keyword = '') {
  const seq = ++collectibleSeq
  collectibleLoading.value = true
  try {
    const res = await fetchCollectibles({ page: 1, pageSize: 50, keyword })
    if (seq !== collectibleSeq) return
    const rows = res?.list ?? []
    rows.forEach((r: any) => collectibleCache.set(r.id, r))
    collectibleOptions.value = [...rows]
    ensureCollectibleOption(form.inviterCollectibleId)
    ensureCollectibleOption(form.inviteeCollectibleId)
  } catch {
    /* 错误已全局提示 */
  } finally {
    if (seq === collectibleSeq) collectibleLoading.value = false
  }
}

onMounted(load)
</script>

<template>
  <div v-loading="loading" class="page-container">
    <!-- 邀请统计 -->
    <div class="stat-grid">
      <div class="stat-card">
        <div class="stat-info">
          <div class="stat-label">累计邀请人数</div>
          <div class="stat-value">{{ inviteeCount }}</div>
          <div class="stat-sub">平台全部邀请记录</div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-info">
          <div class="stat-label">活动开关</div>
          <div class="stat-value">
            <el-tag :type="status === 'enabled' ? 'success' : 'info'">
              {{ status === 'enabled' ? '已启用' : '已停用' }}
            </el-tag>
          </div>
          <div class="stat-sub">上次更新：{{ datetime(updatedAt) }}</div>
        </div>
      </div>
    </div>

    <!-- 配置表单 -->
    <div class="table-card">
      <div class="card-header">
        <div>
          <div class="card-title">邀请活动配置</div>
          <div class="card-sub">邀请成功后向邀请人 / 被邀请人空投指定藏品，至少配置一方后方可启用</div>
        </div>
        <el-button v-permission="'marketing:invite:config'" type="primary" :loading="saving" @click="save">
          保存配置
        </el-button>
      </div>

      <el-form
        ref="formRef"
        :model="form"
        :rules="rules"
        label-width="140px"
        class="invite-form"
        @submit.prevent="save"
      >
        <el-form-item label="活动开关">
          <el-switch
            v-model="status"
            active-value="enabled"
            inactive-value="disabled"
            active-text="已启用"
            inactive-text="已停用"
            inline-prompt
          />
          <span class="form-tip inline">启用后邀请关系达成即按下方配置发放奖励</span>
        </el-form-item>
        <el-form-item label="活动名称" prop="name">
          <el-input v-model="form.name" maxlength="100" show-word-limit placeholder="请输入活动名称" />
        </el-form-item>
        <el-form-item label="活动时间">
          <el-date-picker
            v-model="form.timeRange"
            type="datetimerange"
            value-format="YYYY-MM-DD HH:mm:ss"
            range-separator="至"
            start-placeholder="开始时间（选填）"
            end-placeholder="结束时间（选填）"
            style="width: 100%"
          />
        </el-form-item>

        <el-divider content-position="left">邀请人奖励</el-divider>
        <el-form-item label="空投藏品">
          <el-select
            v-model="form.inviterCollectibleId"
            filterable
            remote
            clearable
            :remote-method="searchCollectibles"
            :loading="collectibleLoading"
            placeholder="输入藏品名称搜索（可不配置）"
            style="width: 100%"
          >
            <el-option v-for="o in collectibleOptions" :key="o.id" :label="`#${o.id} ${o.name}`" :value="o.id">
              <span>{{ o.name }}</span>
              <span class="option-sub">#{{ o.id }} · ¥{{ money(o.price) }}</span>
            </el-option>
          </el-select>
        </el-form-item>
        <el-form-item label="发放数量" prop="inviterQuantity">
          <el-input-number v-model="form.inviterQuantity" :min="1" :max="99" />
          <span class="form-tip inline">每成功邀请 1 位好友，向邀请人发放的藏品份数</span>
        </el-form-item>

        <el-divider content-position="left">被邀请人奖励</el-divider>
        <el-form-item label="空投藏品">
          <el-select
            v-model="form.inviteeCollectibleId"
            filterable
            remote
            clearable
            :remote-method="searchCollectibles"
            :loading="collectibleLoading"
            placeholder="输入藏品名称搜索（可不配置）"
            style="width: 100%"
          >
            <el-option v-for="o in collectibleOptions" :key="o.id" :label="`#${o.id} ${o.name}`" :value="o.id">
              <span>{{ o.name }}</span>
              <span class="option-sub">#{{ o.id }} · ¥{{ money(o.price) }}</span>
            </el-option>
          </el-select>
        </el-form-item>
        <el-form-item label="发放数量" prop="inviteeQuantity">
          <el-input-number v-model="form.inviteeQuantity" :min="1" :max="99" />
          <span class="form-tip inline">被邀请人完成注册后发放的藏品份数</span>
        </el-form-item>

        <el-divider content-position="left">发放规则</el-divider>
        <el-form-item label="发放方式">
          <el-radio-group v-model="form.airdropMode">
            <el-radio value="realtime">实时发放</el-radio>
            <el-radio value="batch">批量发放</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="奖励上限">
          <el-input-number v-model="form.totalLimit" :min="0" :max="999999" />
          <span class="form-tip inline">活动期间奖励发放总量上限（份），0 表示不限制</span>
        </el-form-item>
        <el-form-item label="活动说明">
          <el-input
            v-model="form.description"
            type="textarea"
            :rows="3"
            maxlength="500"
            placeholder="展示给用户的活动规则说明（选填）"
          />
        </el-form-item>
      </el-form>
    </div>
  </div>
</template>

<style scoped lang="scss">
.card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 14px;

  .card-title {
    font-size: 15px;
    font-weight: 600;
  }
  .card-sub {
    margin-top: 4px;
    font-size: 12px;
    color: var(--sn-text-secondary);
  }
}

.invite-form {
  max-width: 720px;
}

.form-tip {
  font-size: 12px;
  color: var(--sn-text-secondary);
  line-height: 1.7;
  margin-top: 4px;

  &.inline {
    display: inline;
    margin-left: 10px;
  }
}

.option-sub {
  float: right;
  font-size: 12px;
  color: var(--sn-text-secondary);
}
</style>
