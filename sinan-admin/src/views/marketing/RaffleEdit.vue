<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import request from '@/utils/request'

const route = useRoute()
const router = useRouter()

const id = ref(route.params.id || null)
const isEdit = ref(!!id.value)
const saving = ref(false)
const collectibles = ref([])

const form = ref({
  collectibleId: null,
  name: '',
  description: '',
  ticketPrice: 0,
  drawCodeEnabled: false,
  drawCodePrice: 0,
  buyCodeLimit: 5,     // 购买抽签码上限（drawCodeEnabled 开启时生效）
  inviteEnabled: false,  // 邀请好友参与抽签得码开关
  inviteCodeLimit: 5,    // 邀请可得码上限（inviteEnabled 开启时生效）
  inviteUserNeeded: 1,   // 每邀请 N 名好友参与得 1 码
  winnerCount: 10,
  // ---- 新增：抽签体系核心配置 ----
  totalSupply: 0,      // 藏品总发行量
  drawWinCount: 10,    // 本次抽签中签名额（按「签」计）
  maxWinsPerUser: 1,   // 单用户最大中签数（1 = 每人最多1签；0 = 不限，按持有码数封顶）
  saleQuantity: 1,
  salePrice: 0,
  registrationStart: '',
  registrationEnd: '',
  drawTime: '',
  // 购买窗口不再有 purchaseStart：口径固定为「中签时刻 + 5 分钟」，
  // 由后端 RaffleService::purchaseGate 统一判定，这里只保留选填的截止时间
  purchaseEnd: '',
})

// 单用户码总量 = 基础 1 + 邀请上限（开时）+ 购买上限（开时）
const userCodeTotal = computed(() =>
  1 + (form.value.inviteEnabled ? form.value.inviteCodeLimit : 0) + (form.value.drawCodeEnabled ? form.value.buyCodeLimit : 0)
)

// 开放购买时刻预览：中签（开奖）时刻 + 5 分钟。
// 300 秒与后端 RaffleService::PURCHASE_DELAY_SECONDS 同口径，仅用于给运营看，
// 真正判定在后端 gate；这里不写库、也不作为参数提交。
const PURCHASE_DELAY_SECONDS = 300
const purchaseOpenPreview = computed(() => {
  const d = form.value.drawTime
  if (!d) return ''
  const ts = new Date(String(d).replace(' ', 'T')).getTime()
  if (Number.isNaN(ts)) return ''
  return formatDT(new Date(ts + PURCHASE_DELAY_SECONDS * 1000))
})

onMounted(async () => {
  // 加载藏品下拉
  const cRes = await request.get('/collectibles', { params: { page: 1, pageSize: 200 } })
  collectibles.value = cRes.list || []

  if (isEdit.value) {
    const res = await request.get('/raffle/' + id.value)
    Object.assign(form.value, {
      collectibleId: res.collectibleId,
      name: res.name,
      description: res.description,
      ticketPrice: res.ticketPrice,
      drawCodeEnabled: !!res.drawCodeEnabled,
      drawCodePrice: res.drawCodePrice || 0,
      buyCodeLimit: res.buyCodeLimit || 5,
      inviteEnabled: !!res.inviteEnabled,
      inviteCodeLimit: res.inviteCodeLimit || 5,
      inviteUserNeeded: res.inviteUserNeeded || 1,
      winnerCount: res.winnerCount,
      totalSupply: res.totalSupply || res.collectibleEdition || 0,
      drawWinCount: res.drawWinCount || res.winnerCount,
      maxWinsPerUser: res.maxWinsPerUser ?? 1,
      saleQuantity: res.saleQuantity,
      salePrice: res.salePrice,
      registrationStart: res.registrationStart?.substring(0, 16),
      registrationEnd: res.registrationEnd?.substring(0, 16),
      drawTime: res.drawTime?.substring(0, 16),
      // 「开放购买」没有可配字段：口径是「中签时刻 + 5 分钟」，由后端 RaffleService::purchaseGate 统一算
      purchaseEnd: res.purchaseEnd ? res.purchaseEnd.substring(0, 16) : '',
    })
  } else {
    // 默认时间区间
    const now = new Date()
    const regEnd = new Date(now.getTime() + 3 * 24 * 3600 * 1000)
    const draw = new Date(regEnd.getTime() + 3600 * 1000)
    form.value.registrationStart = formatDT(now)
    form.value.registrationEnd = formatDT(regEnd)
    form.value.drawTime = formatDT(draw)
    // 购买截止默认留空 = 不限。
    // 旧实现在这里硬编码「抽签时间 + 24 小时」，那是按"计划抽签时间"算的死线：
    // 一旦抽签延后或补开，24 小时窗口会在中签者还没看到按钮时就过期。
    form.value.purchaseEnd = ''
  }
})

function formatDT(d) {
  const pad = (n) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}`
}

async function save() {
  if (!form.value.collectibleId) return ElMessage.warning('请选择藏品')
  if (!form.value.name) return ElMessage.warning('请填写活动名称')
  if (!form.value.winnerCount || form.value.winnerCount <= 0) return ElMessage.warning('中签数量必须大于 0')
  if (!form.value.saleQuantity || form.value.saleQuantity <= 0) return ElMessage.warning('每人限购必须大于 0')
  if (!form.value.drawWinCount || form.value.drawWinCount <= 0) return ElMessage.warning('本次抽签中签名额必须大于 0')
  if (form.value.maxWinsPerUser === null || form.value.maxWinsPerUser === undefined || form.value.maxWinsPerUser < 0) {
    return ElMessage.warning('单用户最大中签数不能为空（0 = 不限）')
  }
  if (form.value.totalSupply > 0) {
    const maxSell = form.value.drawWinCount * form.value.saleQuantity
    if (maxSell > form.value.totalSupply) {
      return ElMessage.warning(`中签总购买上限（名额 ${form.value.drawWinCount} × 每人限购 ${form.value.saleQuantity} = ${maxSell}）不能大于藏品总发行量（${form.value.totalSupply}），否则部分中签者会库存不足买不了`)
    }
  }
  if (form.value.drawCodeEnabled && (!form.value.buyCodeLimit || form.value.buyCodeLimit <= 0)) {
    return ElMessage.warning('开启购买抽签码后，每人可购码上限必须大于 0')
  }
  if (form.value.drawCodeEnabled && (!form.value.drawCodePrice || form.value.drawCodePrice <= 0)) {
    return ElMessage.warning('开启购买抽签码后，抽签码单价必须大于 0')
  }
  if (form.value.inviteEnabled && (!form.value.inviteCodeLimit || form.value.inviteCodeLimit <= 0)) {
    return ElMessage.warning('开启邀请好友得码后，每人邀请可得码上限必须大于 0')
  }
  if (form.value.inviteEnabled && (!form.value.inviteUserNeeded || form.value.inviteUserNeeded <= 0)) {
    return ElMessage.warning('开启邀请好友得码后，邀请人数门槛必须大于 0')
  }

  saving.value = true
  try {
    const payload = { ...form.value }
    if (isEdit.value) payload.id = id.value
    await request.post('/raffle/save', payload)
    ElMessage.success('保存成功')
    router.push('/marketing/raffle')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="page">
    <div class="page__head">
      <h2>{{ isEdit ? '编辑抽签活动' : '新建抽签活动' }}</h2>
      <el-button link @click="router.push('/marketing/raffle')">← 返回列表</el-button>
    </div>

    <el-form :model="form" label-width="140px" style="max-width: 720px">
      <el-form-item label="活动名称" required>
        <el-input v-model="form.name" placeholder="例：司南·青铜神兽 首发抽签" maxlength="60" />
      </el-form-item>
      <el-form-item label="关联藏品" required>
        <el-select v-model="form.collectibleId" filterable placeholder="选择藏品" style="width: 100%">
          <el-option v-for="c in collectibles" :key="c.id" :value="c.id" :label="c.name" />
        </el-select>
      </el-form-item>
      <el-form-item label="中签名额" required>
        <el-input-number v-model="form.winnerCount" :min="1" />
      </el-form-item>
      <el-form-item label="藏品总发行量" required>
        <el-input-number v-model="form.totalSupply" :min="1" />
        <span style="margin-left:10px;color:#999;font-size:12px">本次抽签可发售的藏品总量（名额校验基准）</span>
      </el-form-item>
      <el-form-item label="本次抽签中签名额" required>
        <el-input-number v-model="form.drawWinCount" :min="1" />
        <span style="margin-left:10px;color:#999;font-size:12px">本次最多产出中签资格；名额 × 每人限购 不能大于藏品剩余库存，确保每位中签者都能买满限购</span>
      </el-form-item>
      <el-form-item label="邀请好友得码">
        <el-switch v-model="form.inviteEnabled" />
        <span style="margin-left:10px;color:#999;font-size:12px">开启后 C 端展示「邀请好友获取抽签码」入口，好友参与抽签即可为邀请人赚码</span>
      </el-form-item>
      <el-form-item label="邀请可得码上限" v-if="form.inviteEnabled" required>
        <el-input-number v-model="form.inviteCodeLimit" :min="1" />
        <span style="margin-left:10px;color:#999;font-size:12px">每人最多通过邀请好友获得的抽签码数量</span>
      </el-form-item>
      <el-form-item label="邀请人数门槛" v-if="form.inviteEnabled" required>
        <el-input-number v-model="form.inviteUserNeeded" :min="1" />
        <span style="margin-left:10px;color:#999;font-size:12px">每邀请 {{ form.inviteUserNeeded }} 名好友参与抽签得 1 个码（例：3 = 每邀请 3 人参与得 1 码）</span>
      </el-form-item>
      <el-form-item label="单用户最大中签数" required>
        <el-input-number v-model="form.maxWinsPerUser" :min="0" />
        <span style="margin-left:10px;color:#999;font-size:12px">每人最多可中签次数：每个码都是一颗抽签球（每码最多中 1 次），一人 5 个码最多中 5 签；1 = 每人最多中 1 签（默认），0 = 不限（按持有码数封顶）；每中 1 签可购「中签后限购」数量件</span>
      </el-form-item>
      <el-form-item label="中签后限购" required>
        <el-input-number v-model="form.saleQuantity" :min="1" />
        <span style="margin-left:10px;color:#999;font-size:12px">每签最多买几件；用户总可购 = 中签次数 × 本值</span>
      </el-form-item>
      <el-form-item label="中签购买价" required>
        <el-input-number v-model="form.salePrice" :min="0" :precision="2" />
      </el-form-item>
      <el-form-item label="购买抽签码">
        <el-switch v-model="form.drawCodeEnabled" />
        <span style="margin-left:10px;color:#999;font-size:12px">开启后 C 端在「邀请好友获取抽签码」下方展示「购买抽签码」入口</span>
      </el-form-item>
      <el-form-item label="抽签码单价" v-if="form.drawCodeEnabled" required>
        <el-input-number v-model="form.drawCodePrice" :min="0" :precision="2" />
        <span style="margin-left:10px;color:#999;font-size:12px">与中签购买价（藏品价）分离</span>
      </el-form-item>
      <el-form-item label="购买码上限" v-if="form.drawCodeEnabled" required>
        <el-input-number v-model="form.buyCodeLimit" :min="1" />
        <span style="margin-left:10px;color:#999;font-size:12px">每人最多可购买的抽签码数量</span>
      </el-form-item>
      <el-form-item label="单用户码总量提示">
        <span style="color:#67c23a;font-size:13px">每人最多持有 {{ userCodeTotal }} 个抽签码（基础 1{{ form.inviteEnabled ? ' + 邀请上限 ' + form.inviteCodeLimit : '' }}{{ form.drawCodeEnabled ? ' + 购买上限 ' + form.buyCodeLimit : '' }}），每个码都是一颗抽签球</span>
      </el-form-item>
      <el-form-item label="报名开始" required>
        <el-date-picker v-model="form.registrationStart" type="datetime" format="YYYY-MM-DD HH:mm" value-format="YYYY-MM-DD HH:mm" />
      </el-form-item>
      <el-form-item label="报名截止" required>
        <el-date-picker v-model="form.registrationEnd" type="datetime" format="YYYY-MM-DD HH:mm" value-format="YYYY-MM-DD HH:mm" />
      </el-form-item>
      <el-form-item label="抽签时间" required>
        <el-date-picker v-model="form.drawTime" type="datetime" format="YYYY-MM-DD HH:mm" value-format="YYYY-MM-DD HH:mm" />
        <span style="margin-left:10px;color:#999;font-size:12px">到点自动执行（需 crontab 运行 ScheduleDispatch）</span>
      </el-form-item>
      <el-form-item label="开放购买">
        <div style="display:flex;gap:10px;align-items:center">
          <el-tag type="info" effect="plain">{{ purchaseOpenPreview || '中签后 5 分钟' }}</el-tag>
          <span style="color:#999;font-size:12px">固定口径，不可配置</span>
        </div>
        <div style="color:#999;font-size:12px;margin-top:4px">
          中签时刻（实际开奖时间）+ 5 分钟后才出现「购买」按钮，未中签的人不显示按钮。
          上面按抽签计划时间预估，实际以开奖那一刻为准；C 端显隐与下单校验走后端同一道闸门（RaffleService::purchaseGate），这里改不了。
        </div>
      </el-form-item>
      <el-form-item label="购买截止">
        <el-date-picker v-model="form.purchaseEnd" type="datetime" format="YYYY-MM-DD HH:mm" value-format="YYYY-MM-DD HH:mm" placeholder="留空 = 不限" clearable />
        <div style="color:#999;font-size:12px;margin-top:4px">
          选填。过期未买视为自动放弃；填在「开放购买」时刻之前或同一点的无效（按不限处理），别把窗口配成死的
        </div>
      </el-form-item>
      <el-form-item label="活动规则">
        <el-input v-model="form.description" type="textarea" :rows="4" placeholder="描述抽签规则、中签后操作指引" />
      </el-form-item>
      <el-form-item>
        <el-button type="primary" :loading="saving" @click="save">保存</el-button>
        <el-button @click="router.push('/marketing/raffle')">取消</el-button>
      </el-form-item>
    </el-form>
  </div>
</template>

<style scoped>
.page { padding: 16px; }
.page__head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
.page__head h2 { margin: 0; font-size: 18px; }
</style>
