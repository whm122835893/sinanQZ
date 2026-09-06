<script setup lang="ts">
/**
 * 注册福利配置（表单页）
 *
 * - 注册奖励：司南币 + 藏品（类型 / 数量），二者可叠加、均可不配置
 * - 福利开关：关闭后保存将清零全部注册奖励（后端以 0 值表示不发放）
 *
 * 接口：GET / POST /admin/marketing/register
 *      GET 返回 { points, collectibleId, collectibleName, quantity }
 *      POST 提交 { points, collectible_id, quantity }
 * 权限：marketing:register:config
 */
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { fetchRegisterConfig, saveRegisterConfig } from '@/api/marketing'
import { fetchCollectibles } from '@/api/collectible'
import { money } from '@/utils/format'

const loading = ref(false)
const saving = ref(false)

/** 福利开关（开启 = 至少发放司南币或藏品之一） */
const enabled = ref(false)

const form = reactive({
  /** 注册赠送司南币（0~100000，0 = 不赠送） */
  points: 0,
  /** 注册赠送藏品（选填） */
  collectibleId: undefined as number | undefined,
  /** 注册赠送藏品数量（0~10，0 = 不赠送） */
  quantity: 0,
})

async function load() {
  loading.value = true
  try {
    const res = await fetchRegisterConfig()
    form.points = Number(res?.points ?? 0) || 0
    const cid = Number(res?.collectibleId ?? 0) || 0
    form.collectibleId = cid || undefined
    form.quantity = Number(res?.quantity ?? 0) || 0
    enabled.value = form.points > 0 || (cid > 0 && form.quantity > 0)
    ensureCollectibleOption(cid, res?.collectibleName)
    await searchCollectibles()
  } finally {
    loading.value = false
  }
}

/** 配置预览 */
const preview = computed(() => {
  if (!enabled.value) return '当前为关闭状态：新注册用户无注册奖励'
  const parts: string[] = []
  if (form.points > 0) parts.push(`司南币 × ${form.points}`)
  const cid = form.collectibleId || 0
  if (cid > 0 && form.quantity > 0) {
    parts.push(`藏品「${collectibleCache.get(cid)?.name ?? `#${cid}`}」× ${form.quantity}`)
  }
  return parts.length ? `新注册用户将获得：${parts.join(' + ')}` : '尚未配置任何奖励内容（等同于关闭）'
})

async function save() {
  if (enabled.value) {
    if (form.points < 0 || form.points > 100000) {
      ElMessage.warning('注册赠送司南币需在 0~100000')
      return
    }
    if (form.quantity < 0 || form.quantity > 10) {
      ElMessage.warning('注册赠送藏品数量需在 0~10')
      return
    }
    if (form.collectibleId && form.quantity < 1) {
      ElMessage.warning('已选择注册赠送藏品，赠送数量需至少为 1')
      return
    }
  }

  saving.value = true
  try {
    // 关闭开关：提交全 0，即后端不再发放任何注册奖励
    const payload = enabled.value
      ? {
          points: Math.trunc(form.points),
          collectible_id: form.collectibleId || 0,
          quantity: Math.trunc(form.quantity),
        }
      : { points: 0, collectible_id: 0, quantity: 0 }
    await saveRegisterConfig(payload)
    ElMessage.success('注册福利配置已保存')
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
    ensureCollectibleOption(form.collectibleId)
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
    <div class="table-card">
      <div class="card-header">
        <div>
          <div class="card-title">注册福利配置</div>
          <div class="card-sub">新用户完成注册后自动发放的奖励，司南币与藏品可同时配置</div>
        </div>
        <el-button v-permission="'marketing:register:config'" type="primary" :loading="saving" @click="save">
          保存配置
        </el-button>
      </div>

      <el-form label-width="140px" class="register-form" @submit.prevent="save">
        <el-form-item label="福利开关">
          <el-switch
            v-model="enabled"
            active-text="已开启"
            inactive-text="已关闭"
            inline-prompt
          />
          <div class="form-tip">关闭后保存将清零全部注册奖励，新注册用户不再获得任何奖励</div>
        </el-form-item>

        <template v-if="enabled">
          <el-divider content-position="left">司南币奖励</el-divider>
          <el-form-item label="赠送数量">
            <el-input-number v-model="form.points" :min="0" :max="100000" :step="100" />
            <span class="form-tip inline">注册成功后赠送的司南币数量，0 表示不赠送</span>
          </el-form-item>

          <el-divider content-position="left">藏品奖励</el-divider>
          <el-form-item label="赠送藏品">
            <el-select
              v-model="form.collectibleId"
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
          <el-form-item label="赠送数量">
            <el-input-number v-model="form.quantity" :min="0" :max="10" />
            <span class="form-tip inline">每位新用户赠送该藏品的份数（0~10），0 表示不赠送</span>
          </el-form-item>
        </template>

        <el-form-item label="配置预览">
          <el-alert :title="preview" :closable="false" :type="enabled ? 'info' : 'warning'" show-icon />
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

.register-form {
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
