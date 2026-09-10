<script setup>
import { ref, onMounted, watch } from 'vue'
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
  limitPerUser: 1,
  winnerCount: 10,
  saleQuantity: 1,
  salePrice: 0,
  registrationStart: '',
  registrationEnd: '',
  drawTime: '',
  purchaseStart: '',
  purchaseEnd: '',
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
      limitPerUser: res.limitPerUser,
      winnerCount: res.winnerCount,
      saleQuantity: res.saleQuantity,
      salePrice: res.salePrice,
      registrationStart: res.registrationStart?.substring(0, 16),
      registrationEnd: res.registrationEnd?.substring(0, 16),
      drawTime: res.drawTime?.substring(0, 16),
      purchaseStart: res.purchaseStart?.substring(0, 16),
      purchaseEnd: res.purchaseEnd?.substring(0, 16),
    })
  } else {
    // 默认时间区间
    const now = new Date()
    const regEnd = new Date(now.getTime() + 3 * 24 * 3600 * 1000)
    const draw = new Date(regEnd.getTime() + 3600 * 1000)
    form.value.registrationStart = formatDT(now)
    form.value.registrationEnd = formatDT(regEnd)
    form.value.drawTime = formatDT(draw)
    form.value.purchaseStart = formatDT(draw)
    const purEnd = new Date(draw.getTime() + 24 * 3600 * 1000)
    form.value.purchaseEnd = formatDT(purEnd)
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
      <el-form-item label="报名费">
        <el-input-number v-model="form.ticketPrice" :min="0" :precision="2" :step="1" />
        <span style="margin-left:10px;color:#999;font-size:12px">0 = 免费报名</span>
      </el-form-item>
      <el-form-item label="每人限报" required>
        <el-input-number v-model="form.limitPerUser" :min="1" />
        <span style="margin-left:10px;color:#999;font-size:12px">同一用户可报名的最大票数</span>
      </el-form-item>
      <el-form-item label="中签名额" required>
        <el-input-number v-model="form.winnerCount" :min="1" />
      </el-form-item>
      <el-form-item label="中签后限购" required>
        <el-input-number v-model="form.saleQuantity" :min="1" />
        <span style="margin-left:10px;color:#999;font-size:12px">每个中签用户最多买几件</span>
      </el-form-item>
      <el-form-item label="中签购买价" required>
        <el-input-number v-model="form.salePrice" :min="0" :precision="2" />
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
      <el-form-item label="中签有效期">
        <div style="display:flex;gap:10px;align-items:center">
          <el-date-picker v-model="form.purchaseStart" type="datetime" format="YYYY-MM-DD HH:mm" value-format="YYYY-MM-DD HH:mm" placeholder="中签购买开始" />
          <span>至</span>
          <el-date-picker v-model="form.purchaseEnd" type="datetime" format="YYYY-MM-DD HH:mm" value-format="YYYY-MM-DD HH:mm" placeholder="中签购买截止" />
        </div>
        <div style="color:#999;font-size:12px;margin-top:4px">过期未买视为自动放弃</div>
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
