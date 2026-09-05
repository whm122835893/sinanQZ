<script setup lang="ts">
// 用户详情（文档 9.2：基础/钱包/藏品/盲盒/优先购/邀请 六 Tab）
// 藏品/盲盒 Tab 内含强制回收（#27/#28，reason + password）
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  fetchUserDetail,
  fetchUserWallet,
  fetchUserCollectibles,
  fetchUserBlindboxes,
  fetchUserQualifications,
  fetchUserInvites,
  freezeUser,
  unfreezeUser,
  resetTxPassword,
  forceLogoutUser,
  addBlacklist,
  removeBlacklist,
  recoverCollectible,
  recoverBlindbox
} from '@/api/user'
import PasswordVerify from '@/components/PasswordVerify.vue'
import type {
  PageData,
  UserBlindboxRow,
  UserCollectibleRow,
  UserDetail,
  UserInviteResult,
  UserQualificationResult,
  UserWalletResult
} from '@/types/api'

const route = useRoute()
const router = useRouter()
const userId = route.params.id as string

const loading = ref(false)
const detail = ref<UserDetail | null>(null)
const loadError = ref('')
const activeTab = ref('base')

async function load(): Promise<void> {
  loading.value = true
  loadError.value = ''
  try {
    detail.value = await fetchUserDetail(userId)
  } catch (e) {
    loadError.value = e instanceof Error ? e.message : '加载失败'
  } finally {
    loading.value = false
  }
}

/** 资产概览卡片 */
const assetCards = computed(() => {
  const d = detail.value
  return [
    { label: '钱包余额', value: d ? `¥${d.balance}` : '—', icon: 'Wallet', tone: 'gold' },
    { label: '持有藏品', value: d ? String(d.heldCollectibles) : '—', icon: 'Picture', tone: 'primary' },
    { label: '持有盲盒', value: d ? String(d.heldBlindboxes) : '—', icon: 'Box', tone: 'ink' },
    { label: '累计订单', value: d ? String(d.orderCount) : '—', icon: 'Document', tone: 'info' }
  ] as const
})

// ============================================================================
// Tab：钱包（#22）
// ============================================================================
const walletLoading = ref(false)
const walletData = ref<UserWalletResult | null>(null)
const walletPage = ref(1)
const walletPageSize = ref(10)
const walletType = ref('')

async function loadWallet(): Promise<void> {
  walletLoading.value = true
  try {
    walletData.value = await fetchUserWallet(userId, {
      page: walletPage.value,
      pageSize: walletPageSize.value,
      type: walletType.value || undefined
    })
  } catch {
    // 拦截器已提示
  } finally {
    walletLoading.value = false
  }
}

function searchWallet(): void {
  walletPage.value = 1
  loadWallet()
}

const TRANS_TYPE_MAP: Record<string, string> = {
  recharge: '充值',
  buy: '购买',
  reward: '奖励',
  refund: '退款',
  sell: '寄售结算',
  withdraw: '提现'
}

// ============================================================================
// Tab：藏品（#23 + 回收 #27）
// ============================================================================
const ucLoading = ref(false)
const ucList = ref<UserCollectibleRow[]>([])
const ucTotal = ref(0)
const ucPage = ref(1)
const ucPageSize = ref(10)

async function loadCollectibles(): Promise<void> {
  ucLoading.value = true
  try {
    const data = (await fetchUserCollectibles(userId, {
      page: ucPage.value,
      pageSize: ucPageSize.value
    })) as PageData<UserCollectibleRow>
    ucList.value = data.list
    ucTotal.value = data.total
  } catch {
    // 拦截器已提示
  } finally {
    ucLoading.value = false
  }
}

const recoverCRef = ref<InstanceType<typeof PasswordVerify>>()
let recoverCTarget: UserCollectibleRow | null = null

async function openRecoverCollectible(row: UserCollectibleRow): Promise<void> {
  recoverCTarget = row
  const ok = await recoverCRef.value?.open({
    title: '强制回收藏品',
    reasonLabel: '回收原因',
    hint: `资产 #${row.id}（${row.serial}）回收前校验持有状态：寄售中/转赠冻结/已转赠将被拦截（文档 5.5）。`
  })
  if (!ok || !recoverCTarget) return
  try {
    const res = await recoverCollectible(Number(userId), {
      userCollectibleId: recoverCTarget.id,
      reason: ok.reason,
      password: ok.password
    })
    ElMessage.success(`藏品已回收（${res.revert.counter}）`)
    load()
    loadCollectibles()
  } catch {
    // 拦截器已提示
  }
}

// ============================================================================
// Tab：盲盒（#24 + 回收 #28）
// ============================================================================
const bbLoading = ref(false)
const bbList = ref<UserBlindboxRow[]>([])
const bbTotal = ref(0)
const bbPage = ref(1)
const bbPageSize = ref(10)

async function loadBlindboxes(): Promise<void> {
  bbLoading.value = true
  try {
    const data = (await fetchUserBlindboxes(userId, {
      page: bbPage.value,
      pageSize: bbPageSize.value
    })) as PageData<UserBlindboxRow>
    bbList.value = data.list
    bbTotal.value = data.total
  } catch {
    // 拦截器已提示
  } finally {
    bbLoading.value = false
  }
}

const recoverBRef = ref<InstanceType<typeof PasswordVerify>>()
let recoverBTarget: UserBlindboxRow | null = null

async function openRecoverBlindbox(row: UserBlindboxRow): Promise<void> {
  if (row.opened) {
    ElMessage.warning('已开启的盲盒不可回收')
    return
  }
  recoverBTarget = row
  const ok = await recoverBRef.value?.open({
    title: '强制回收盲盒',
    reasonLabel: '回收原因',
    hint: `盲盒 #${row.id}（${row.name}）回收前校验未开启状态，已开启盲盒不可回收（文档 8.7 #64）。`
  })
  if (!ok || !recoverBTarget) return
  try {
    const res = await recoverBlindbox(Number(userId), {
      userBlindboxId: recoverBTarget.id,
      reason: ok.reason,
      password: ok.password
    })
    ElMessage.success(`盲盒已回收（${res.revert.counter}）`)
    load()
    loadBlindboxes()
  } catch {
    // 拦截器已提示
  }
}

// ============================================================================
// Tab：优先购（#25）
// ============================================================================
const quLoading = ref(false)
const quData = ref<UserQualificationResult | null>(null)
const quPage = ref(1)
const quPageSize = ref(10)

async function loadQualifications(): Promise<void> {
  quLoading.value = true
  try {
    quData.value = await fetchUserQualifications(userId, {
      page: quPage.value,
      pageSize: quPageSize.value
    })
  } catch {
    // 拦截器已提示
  } finally {
    quLoading.value = false
  }
}

// ============================================================================
// Tab：邀请（#26）
// ============================================================================
const invLoading = ref(false)
const invData = ref<UserInviteResult | null>(null)
const invPage = ref(1)
const invPageSize = ref(10)

async function loadInvites(): Promise<void> {
  invLoading.value = true
  try {
    invData.value = await fetchUserInvites(userId, {
      page: invPage.value,
      pageSize: invPageSize.value
    })
  } catch {
    // 拦截器已提示
  } finally {
    invLoading.value = false
  }
}

// ============================================================================
// Tab 切换懒加载
// ============================================================================
const loadedTabs = new Set<string>(['base'])

function onTabChange(tab: string | number): void {
  const name = String(tab)
  if (loadedTabs.has(name)) return
  loadedTabs.add(name)
  if (name === 'wallet') loadWallet()
  else if (name === 'collectibles') loadCollectibles()
  else if (name === 'blindboxes') loadBlindboxes()
  else if (name === 'priority') loadQualifications()
  else if (name === 'invites') loadInvites()
}

// ============================================================================
// 基础 Tab：管理操作（#16~#21）
// ============================================================================
const actionSubmitting = ref(false)

async function runAction(action: (reason: string) => Promise<null>, tip: string): Promise<void> {
  try {
    const { value: reason } = await ElMessageBox.prompt(`请输入${tip}原因（必填）`, tip, {
      confirmButtonText: '确 定',
      cancelButtonText: '取 消',
      inputPattern: /\S+/,
      inputErrorMessage: '原因不能为空'
    })
    actionSubmitting.value = true
    await action(reason.trim())
    ElMessage.success(`${tip}成功`)
    load()
  } catch {
    // 取消或拦截器已提示
  } finally {
    actionSubmitting.value = false
  }
}

function doFreeze(): void {
  const d = detail.value
  if (!d) return
  if (d.status === 1) {
    runAction((reason) => freezeUser(d.id, reason), '冻结账号')
  } else {
    runAction(() => unfreezeUser(d.id), '解冻账号')
  }
}

const txPwdRef = ref<InstanceType<typeof PasswordVerify>>()

async function doResetTxPassword(): Promise<void> {
  const ok = await txPwdRef.value?.open({ title: '重置交易密码', reasonLabel: '操作原因', requireReason: true })
  if (!ok) return
  try {
    await resetTxPassword(userId, ok.reason)
    ElMessage.success('交易密码已重置')
  } catch {
    // 拦截器已提示
  }
}

const logoutPwdRef = ref<InstanceType<typeof PasswordVerify>>()

async function doForceLogout(): Promise<void> {
  const ok = await logoutPwdRef.value?.open({ title: '强制登出', reasonLabel: '操作原因', requireReason: true })
  if (!ok) return
  try {
    await forceLogoutUser(userId, ok.reason)
    ElMessage.success('已踢出该用户全部登录态')
  } catch {
    // 拦截器已提示
  }
}

const blacklistVisible = ref(false)
const blacklistSubmitting = ref(false)
const blacklistForm = reactive({ reason: '', expiresAt: '' })

function openBlacklist(): void {
  blacklistForm.reason = ''
  blacklistForm.expiresAt = ''
  blacklistVisible.value = true
}

async function submitBlacklist(): Promise<void> {
  if (!blacklistForm.reason.trim()) {
    ElMessage.warning('请输入黑名单原因')
    return
  }
  blacklistSubmitting.value = true
  try {
    await addBlacklist(userId, {
      reason: blacklistForm.reason.trim(),
      expiresAt: blacklistForm.expiresAt || null
    })
    ElMessage.success('已加入黑名单')
    blacklistVisible.value = false
    load()
  } catch {
    // 拦截器已提示
  } finally {
    blacklistSubmitting.value = false
  }
}

async function doRemoveBlacklist(): Promise<void> {
  runAction((reason) => removeBlacklist(userId, reason), '移出黑名单')
}

onMounted(load)
</script>

<template>
  <div v-loading="loading" class="page-container">
    <!-- 顶部：返回 + 标题 -->
    <div class="sn-card page-head">
      <div class="head-left">
        <el-button text :icon="'ArrowLeft'" @click="router.push('/user')">返回列表</el-button>
        <div class="head-user">
          <el-avatar :size="40" :src="detail?.avatar || undefined">
            {{ (detail?.username || 'U').slice(0, 1).toUpperCase() }}
          </el-avatar>
          <div class="head-meta">
            <div class="head-name">
              {{ detail?.username || '—' }}
              <el-tag v-if="detail" :type="detail.status === 1 ? 'success' : 'danger'" size="small">
                {{ detail.status === 1 ? '正常' : '冻结' }}
              </el-tag>
            </div>
            <div class="head-uid din">{{ detail?.uid || '—' }}</div>
          </div>
        </div>
      </div>
      <el-button text :icon="'Refresh'" @click="load">刷新</el-button>
    </div>

    <template v-if="detail">
      <!-- 资产概览 -->
      <div class="asset-grid">
        <div v-for="card in assetCards" :key="card.label" class="sn-card asset-card">
          <div class="asset-icon" :class="`tone-${card.tone}`">
            <el-icon :size="20"><component :is="card.icon" /></el-icon>
          </div>
          <div>
            <div class="asset-label">{{ card.label }}</div>
            <div class="asset-value din">{{ card.value }}</div>
          </div>
        </div>
      </div>

      <!-- Tab 区（基础/钱包/藏品/盲盒/优先购/邀请） -->
      <div class="sn-card">
        <el-tabs v-model="activeTab" @tab-change="onTabChange">
          <!-- 基础 -->
          <el-tab-pane label="基础" name="base">
            <div class="tab-actions">
              <el-button v-if="detail.status === 1" v-permission="'user:freeze'" type="danger" plain @click="doFreeze">冻结账号</el-button>
              <el-button v-else v-permission="'user:freeze'" type="success" plain @click="doFreeze">解冻账号</el-button>
              <el-button v-permission="'user:manage'" @click="doResetTxPassword">重置交易密码</el-button>
              <el-button v-permission="'user:manage'" @click="doForceLogout">强制登出</el-button>
              <el-button v-permission="'user:blacklist'" type="danger" plain @click="openBlacklist">加入黑名单</el-button>
              <el-button v-permission="'user:blacklist'" type="success" plain @click="doRemoveBlacklist">移出黑名单</el-button>
            </div>

            <el-descriptions :column="3" border>
              <el-descriptions-item label="用户名">{{ detail.username }}</el-descriptions-item>
              <el-descriptions-item label="手机号">{{ detail.phone || '—' }}</el-descriptions-item>
              <el-descriptions-item label="实名状态">
                <el-tag :type="detail.isRealname ? 'success' : 'info'" size="small">
                  {{ detail.isRealname ? '已实名' : '未实名' }}
                </el-tag>
              </el-descriptions-item>
              <el-descriptions-item label="邀请码">
                <span v-if="detail.inviteCode" class="din">{{ detail.inviteCode }}</span>
                <span v-else>—</span>
              </el-descriptions-item>
              <el-descriptions-item label="登录次数">{{ detail.loginCount }}</el-descriptions-item>
              <el-descriptions-item label="最后登录">{{ detail.lastLoginAt || '—' }}</el-descriptions-item>
              <el-descriptions-item label="注册时间">{{ detail.createdAt }}</el-descriptions-item>
              <el-descriptions-item label="更新时间">{{ detail.updatedAt }}</el-descriptions-item>
              <el-descriptions-item label="用户ID">{{ detail.id }}</el-descriptions-item>
            </el-descriptions>
          </el-tab-pane>

          <!-- 钱包 -->
          <el-tab-pane label="钱包" name="wallet">
            <div v-loading="walletLoading">
              <template v-if="walletData">
                <div class="wallet-grid">
                  <div class="wallet-stat">
                    <span class="wallet-label">总余额</span>
                    <span class="wallet-value din amount">¥{{ walletData.wallet.balance }}</span>
                  </div>
                  <div class="wallet-stat">
                    <span class="wallet-label">可用余额</span>
                    <span class="wallet-value din">¥{{ walletData.wallet.available }}</span>
                  </div>
                  <div class="wallet-stat">
                    <span class="wallet-label">冻结金额</span>
                    <span class="wallet-value din">¥{{ walletData.wallet.frozen }}</span>
                  </div>
                  <div class="wallet-stat">
                    <span class="wallet-label">积分</span>
                    <span class="wallet-value din">{{ walletData.wallet.points }}</span>
                  </div>
                  <div class="wallet-stat">
                    <span class="wallet-label">累计流入</span>
                    <span class="wallet-value din">¥{{ walletData.stats.totalInflow }}</span>
                  </div>
                  <div class="wallet-stat">
                    <span class="wallet-label">累计流出</span>
                    <span class="wallet-value din">¥{{ walletData.stats.totalOutflow }}</span>
                  </div>
                </div>

                <div class="tab-toolbar">
                  <span class="tab-toolbar-title">资金流水（{{ walletData.transactions.total }}）</span>
                  <el-select v-model="walletType" placeholder="全部类型" clearable style="width: 140px" @change="searchWallet">
                    <el-option v-for="(label, key) in TRANS_TYPE_MAP" :key="key" :label="label" :value="key" />
                  </el-select>
                </div>
                <el-table :data="walletData.transactions.list" size="small">
                  <el-table-column label="类型" width="90">
                    <template #default="{ row }">{{ TRANS_TYPE_MAP[row.transType] ?? row.transType }}</template>
                  </el-table-column>
                  <el-table-column label="说明" prop="title" min-width="180" show-overflow-tooltip />
                  <el-table-column label="方向" width="70" align="center">
                    <template #default="{ row }">
                      <el-tag :type="row.direction === 1 ? 'success' : 'danger'" size="small" effect="plain">
                        {{ row.direction === 1 ? '收入' : '支出' }}
                      </el-tag>
                    </template>
                  </el-table-column>
                  <el-table-column label="金额" width="110" align="right">
                    <template #default="{ row }">
                      <span class="din" :class="row.direction === 1 ? 'in-green' : 'out-red'">
                        {{ row.direction === 1 ? '+' : '-' }}¥{{ row.amount }}
                      </span>
                    </template>
                  </el-table-column>
                  <el-table-column label="余额快照" width="110" align="right">
                    <template #default="{ row }">
                      <span class="din">¥{{ row.balanceAfter }}</span>
                    </template>
                  </el-table-column>
                  <el-table-column label="业务单号" prop="bizNo" min-width="170">
                    <template #default="{ row }">{{ row.bizNo || '—' }}</template>
                  </el-table-column>
                  <el-table-column label="时间" prop="createdAt" width="165" />
                </el-table>
                <div class="table-pagination">
                  <el-pagination
                    v-model:current-page="walletPage"
                    v-model:page-size="walletPageSize"
                    :total="walletData.transactions.total"
                    layout="total, prev, pager, next"
                    @current-change="loadWallet"
                  />
                </div>
              </template>
              <el-empty v-else-if="!walletLoading" description="暂无钱包数据" :image-size="60" />
            </div>
          </el-tab-pane>

          <!-- 藏品 -->
          <el-tab-pane :label="`藏品（${detail.heldCollectibles}）`" name="collectibles">
            <el-table v-loading="ucLoading" :data="ucList" size="small">
              <el-table-column label="藏品" min-width="200">
                <template #default="{ row }">
                  <div class="cell-asset">
                    <el-image :src="row.image" fit="cover" class="asset-cover">
                      <template #error>
                        <div class="asset-cover asset-cover--fallback"><el-icon><Picture /></el-icon></div>
                      </template>
                    </el-image>
                    <div class="asset-meta">
                      <span class="asset-name">{{ row.name }}</span>
                      <span class="asset-sub">#{{ row.collectibleId }} · {{ row.serial }}</span>
                    </div>
                  </div>
                </template>
              </el-table-column>
              <el-table-column label="状态" width="90">
                <template #default="{ row }">
                  <el-tag :type="row.status === 'held' ? 'success' : row.status === 'consigned' ? 'warning' : 'info'" size="small">
                    {{ row.statusText }}
                  </el-tag>
                </template>
              </el-table-column>
              <el-table-column label="来源" width="80">
                <template #default="{ row }">{{ row.sourceText }}</template>
              </el-table-column>
              <el-table-column label="获得价" width="100" align="right">
                <template #default="{ row }">
                  <span class="din">¥{{ row.acquiredPrice }}</span>
                </template>
              </el-table-column>
              <el-table-column label="获得时间" prop="acquiredAt" width="165" />
              <el-table-column label="操作" width="100" fixed="right">
                <template #default="{ row }">
                  <el-button
                    v-permission="'user:recover'"
                    text
                    type="danger"
                    :disabled="row.status !== 'held'"
                    @click="openRecoverCollectible(row)"
                  >
                    强制回收
                  </el-button>
                </template>
              </el-table-column>
            </el-table>
            <div class="table-pagination">
              <el-pagination
                v-model:current-page="ucPage"
                v-model:page-size="ucPageSize"
                :total="ucTotal"
                layout="total, prev, pager, next"
                @current-change="loadCollectibles"
              />
            </div>
          </el-tab-pane>

          <!-- 盲盒 -->
          <el-tab-pane :label="`盲盒（${detail.heldBlindboxes}）`" name="blindboxes">
            <el-table v-loading="bbLoading" :data="bbList" size="small">
              <el-table-column label="盲盒" min-width="200">
                <template #default="{ row }">
                  <div class="cell-asset">
                    <el-image :src="row.image" fit="cover" class="asset-cover">
                      <template #error>
                        <div class="asset-cover asset-cover--fallback"><el-icon><Box /></el-icon></div>
                      </template>
                    </el-image>
                    <div class="asset-meta">
                      <span class="asset-name">{{ row.name }}</span>
                      <span class="asset-sub">#{{ row.collectibleId }} · {{ row.serial }}</span>
                    </div>
                  </div>
                </template>
              </el-table-column>
              <el-table-column label="状态" width="90">
                <template #default="{ row }">
                  <el-tag :type="row.status === 'held' ? 'success' : 'info'" size="small">{{ row.statusText }}</el-tag>
                </template>
              </el-table-column>
              <el-table-column label="是否开启" width="90" align="center">
                <template #default="{ row }">
                  <el-tag :type="row.opened ? 'info' : 'success'" size="small" effect="plain">
                    {{ row.opened ? '已开启' : '未开启' }}
                  </el-tag>
                </template>
              </el-table-column>
              <el-table-column label="来源" prop="source" width="80" />
              <el-table-column label="获得价" width="100" align="right">
                <template #default="{ row }">
                  <span class="din">¥{{ row.acquiredPrice }}</span>
                </template>
              </el-table-column>
              <el-table-column label="获得时间" prop="acquiredAt" width="165" />
              <el-table-column label="操作" width="100" fixed="right">
                <template #default="{ row }">
                  <el-button
                    v-permission="'user:recover'"
                    text
                    type="danger"
                    :disabled="row.opened"
                    @click="openRecoverBlindbox(row)"
                  >
                    强制回收
                  </el-button>
                </template>
              </el-table-column>
            </el-table>
            <div class="table-pagination">
              <el-pagination
                v-model:current-page="bbPage"
                v-model:page-size="bbPageSize"
                :total="bbTotal"
                layout="total, prev, pager, next"
                @current-change="loadBlindboxes"
              />
            </div>
          </el-tab-pane>

          <!-- 优先购 -->
          <el-tab-pane label="优先购" name="priority">
            <div v-loading="quLoading">
              <template v-if="quData">
                <div class="wallet-grid">
                  <div class="wallet-stat">
                    <span class="wallet-label">有效资格</span>
                    <span class="wallet-value din qu-valid">{{ quData.summary.valid }}</span>
                  </div>
                  <div class="wallet-stat">
                    <span class="wallet-label">已过期</span>
                    <span class="wallet-value din">{{ quData.summary.expired }}</span>
                  </div>
                  <div class="wallet-stat">
                    <span class="wallet-label">已用完</span>
                    <span class="wallet-value din">{{ quData.summary.usedUp }}</span>
                  </div>
                  <div class="wallet-stat">
                    <span class="wallet-label">已禁用</span>
                    <span class="wallet-value din">{{ quData.summary.disabled }}</span>
                  </div>
                </div>

                <el-table :data="quData.list.list" size="small">
                  <el-table-column label="活动" min-width="200">
                    <template #default="{ row }">
                      <div class="asset-meta">
                        <span class="asset-name">{{ row.activityName }}</span>
                        <span class="asset-sub">#{{ row.activityId }} · {{ row.collectibleName }}</span>
                      </div>
                    </template>
                  </el-table-column>
                  <el-table-column label="剩余/上限" width="100" align="center">
                    <template #default="{ row }">
                      <span class="din">{{ row.remaining }}</span> / <span class="din">{{ row.maxQuantity }}</span>
                    </template>
                  </el-table-column>
                  <el-table-column label="状态" width="90">
                    <template #default="{ row }">
                      <el-tag
                        :type="row.state === 'valid' ? 'success' : row.state === 'expired' ? 'info' : 'warning'"
                        size="small"
                      >
                        {{ row.stateText }}
                      </el-tag>
                    </template>
                  </el-table-column>
                  <el-table-column label="活动窗口" min-width="300">
                    <template #default="{ row }">{{ row.activityWindow.start }} ~ {{ row.activityWindow.end }}</template>
                  </el-table-column>
                  <el-table-column label="过期时间" width="165">
                    <template #default="{ row }">{{ row.expiresAt || '不限' }}</template>
                  </el-table-column>
                </el-table>
                <div class="table-pagination">
                  <el-pagination
                    v-model:current-page="quPage"
                    v-model:page-size="quPageSize"
                    :total="quData.list.total"
                    layout="total, prev, pager, next"
                    @current-change="loadQualifications"
                  />
                </div>
              </template>
              <el-empty v-else-if="!quLoading" description="暂无优先购资格" :image-size="60" />
            </div>
          </el-tab-pane>

          <!-- 邀请 -->
          <el-tab-pane label="邀请" name="invites">
            <div v-loading="invLoading">
              <template v-if="invData">
                <div class="wallet-grid">
                  <div class="wallet-stat">
                    <span class="wallet-label">邀请码</span>
                    <span class="wallet-value din">{{ invData.stats.inviteCode || '—' }}</span>
                  </div>
                  <div class="wallet-stat">
                    <span class="wallet-label">累计邀请</span>
                    <span class="wallet-value din">{{ invData.stats.totalInvites }}</span>
                  </div>
                  <div class="wallet-stat">
                    <span class="wallet-label">已注册</span>
                    <span class="wallet-value din">{{ invData.stats.registered }}</span>
                  </div>
                  <div class="wallet-stat">
                    <span class="wallet-label">已发奖</span>
                    <span class="wallet-value din">{{ invData.stats.rewarded }}</span>
                  </div>
                </div>

                <el-descriptions v-if="invData.invitedBy" :column="2" border size="small" class="invited-by">
                  <el-descriptions-item label="我的邀请人">
                    {{ invData.invitedBy.inviterName }}（ID {{ invData.invitedBy.inviterId }}）
                  </el-descriptions-item>
                  <el-descriptions-item label="邀请人手机号">{{ invData.invitedBy.inviterPhone }}</el-descriptions-item>
                </el-descriptions>

                <el-table :data="invData.list.list" size="small">
                  <el-table-column label="被邀请人" min-width="160">
                    <template #default="{ row }">
                      <div class="asset-meta">
                        <span class="asset-name">{{ row.inviteeName }}</span>
                        <span class="asset-sub">ID {{ row.inviteeId }} · {{ row.inviteePhone }}</span>
                      </div>
                    </template>
                  </el-table-column>
                  <el-table-column label="注册状态" width="90">
                    <template #default="{ row }">
                      <el-tag :type="row.status === 'registered' ? 'success' : 'info'" size="small">{{ row.statusText }}</el-tag>
                    </template>
                  </el-table-column>
                  <el-table-column label="邀请人奖励" width="90" align="center">
                    <template #default="{ row }">
                      <el-tag :type="row.inviterRewarded ? 'success' : 'info'" size="small" effect="plain">
                        {{ row.inviterRewarded ? '已发放' : '未发放' }}
                      </el-tag>
                    </template>
                  </el-table-column>
                  <el-table-column label="被邀人奖励" width="90" align="center">
                    <template #default="{ row }">
                      <el-tag :type="row.inviteeRewarded ? 'success' : 'info'" size="small" effect="plain">
                        {{ row.inviteeRewarded ? '已发放' : '未发放' }}
                      </el-tag>
                    </template>
                  </el-table-column>
                  <el-table-column label="邀请时间" prop="createdAt" width="165" />
                </el-table>
                <div class="table-pagination">
                  <el-pagination
                    v-model:current-page="invPage"
                    v-model:page-size="invPageSize"
                    :total="invData.list.total"
                    layout="total, prev, pager, next"
                    @current-change="loadInvites"
                  />
                </div>
              </template>
              <el-empty v-else-if="!invLoading" description="暂无邀请记录" :image-size="60" />
            </div>
          </el-tab-pane>
        </el-tabs>
      </div>
    </template>

    <!-- 加载失败 -->
    <div v-else-if="!loading" class="sn-card error-card">
      <el-empty :description="loadError || '用户不存在'">
        <el-button type="primary" @click="router.back()">返回列表</el-button>
      </el-empty>
    </div>

    <!-- 高风险：重置交易密码 / 强制登出（reason 确认） -->
    <PasswordVerify ref="txPwdRef" title="重置交易密码" reason-label="操作原因" hint="重置后用户交易密码清空，需在 C 端重新设置（文档 8.4 #18）。" />
    <PasswordVerify ref="logoutPwdRef" title="强制登出" reason-label="操作原因" hint="将踢出该用户全部登录态，用户需重新登录（文档 8.4 #19）。" />
    <!-- 高风险：回收 -->
    <PasswordVerify ref="recoverCRef" title="强制回收藏品" reason-label="回收原因" />
    <PasswordVerify ref="recoverBRef" title="强制回收盲盒" reason-label="回收原因" />

    <!-- 加入黑名单 -->
    <el-dialog v-model="blacklistVisible" title="加入黑名单" width="460px" :close-on-click-modal="false">
      <el-form label-position="top" @submit.prevent>
        <el-form-item label="黑名单原因" required>
          <el-input v-model="blacklistForm.reason" type="textarea" :rows="2" maxlength="200" show-word-limit placeholder="必填，写入审计日志" />
        </el-form-item>
        <el-form-item label="过期时间（可选，到期自动移出）">
          <el-date-picker
            v-model="blacklistForm.expiresAt"
            type="datetime"
            value-format="YYYY-MM-DD HH:mm:ss"
            placeholder="留空表示永久"
            style="width: 100%"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="blacklistVisible = false">取 消</el-button>
        <el-button type="danger" :loading="blacklistSubmitting" @click="submitBlacklist">加入黑名单</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped lang="scss">
@use '@/assets/styles/table-common' as *;

.page-head {
  display: flex;
  align-items: center;
  justify-content: space-between;

  .head-left {
    display: flex;
    align-items: center;
    gap: 12px;
  }

  .head-user {
    display: flex;
    align-items: center;
    gap: 10px;
  }

  .head-meta {
    .head-name {
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 16px;
      font-weight: 600;
      color: $sn-text-main;
    }

    .head-uid {
      font-size: 12px;
      color: $sn-text-muted;
      margin-top: 2px;
    }
  }
}

.asset-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 12px;
}

.asset-card {
  display: flex;
  align-items: center;
  gap: 12px;

  .asset-icon {
    width: 42px;
    height: 42px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;

    &.tone-primary {
      background: $sn-gradient-primary;
    }

    &.tone-gold {
      background: $sn-gradient-gold;
    }

    &.tone-ink {
      background: $sn-gradient-ink;
    }

    &.tone-info {
      background: linear-gradient(135deg, #94a3b8 0%, #64748b 100%);
    }
  }

  .asset-label {
    font-size: 12px;
    color: $sn-text-sub;
  }

  .asset-value {
    font-size: 20px;
    font-weight: 600;
    color: $sn-text-main;
    margin-top: 2px;
  }
}

.tab-actions {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  margin-bottom: 16px;
}

.wallet-grid {
  display: grid;
  grid-template-columns: repeat(6, 1fr);
  gap: 10px;
  margin-bottom: 16px;

  .wallet-stat {
    background: $sn-bg;
    border-radius: 8px;
    padding: 12px;
    display: flex;
    flex-direction: column;
    gap: 4px;

    .wallet-label {
      font-size: 12px;
      color: $sn-text-muted;
    }

    .wallet-value {
      font-size: 17px;
      font-weight: 600;
      color: $sn-text-main;
    }

    .qu-valid {
      color: $sn-success;
    }
  }
}

.tab-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 10px;

  .tab-toolbar-title {
    font-size: 13px;
    font-weight: 600;
    color: $sn-text-main;
  }
}

.cell-asset {
  display: flex;
  align-items: center;
  gap: 10px;

  .asset-cover {
    width: 40px;
    height: 40px;
    border-radius: 6px;
    flex-shrink: 0;
    background: $sn-surface;

    &--fallback {
      display: flex;
      align-items: center;
      justify-content: center;
      color: $sn-text-muted;
    }
  }

  .asset-meta {
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 2px;

    .asset-name {
      font-weight: 500;
      color: $sn-text-main;
      @include ellipsis;
    }

    .asset-sub {
      font-size: 12px;
      color: $sn-text-muted;
      @include ellipsis;
    }
  }
}

.invited-by {
  margin-bottom: 16px;
}

.in-green {
  color: $sn-success;
}

.out-red {
  color: $sn-danger;
}

.error-card {
  min-height: 240px;
  display: flex;
  align-items: center;
  justify-content: center;
}

@media (max-width: 1365px) {
  .asset-grid {
    grid-template-columns: repeat(2, 1fr);
  }

  .wallet-grid {
    grid-template-columns: repeat(3, 1fr);
  }
}
</style>
