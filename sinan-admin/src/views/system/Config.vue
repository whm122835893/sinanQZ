<script setup>
import { ref, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { getSiteConfig, saveSiteConfig } from '@/api'

const loading = ref(true)
const saving = ref(false)
const activeTab = ref('basic')

const form = ref({
  siteName: '',
  announcement: '',
  maintenance: 0,
  icp: '',
  kefuQr: '/images/brand-logo.png',
  // 支付配置
  feeMode: 'rate',
  feeRate: 0.05,
  feeFixed: 5,
  alipayEnabled: 1,
  wechatEnabled: 1,
  unionpayEnabled: 0,
  cryptoEnabled: 0,
  // 区块链节点
  chainRpc: '',
  chainId: 1,
  gasStrategy: 'medium',
  // 存储
  storageType: 'oss'
})

onMounted(async () => {
  const res = await getSiteConfig()
  form.value = { ...form.value, ...res.data }
  loading.value = false
})

async function onSave() {
  // 修改支付配置需二次确认（触发审批工作流）
  if (activeTab.value === 'payment') {
    await ElMessageBox.confirm(
      '修改支付配置属于敏感操作，保存后将发起审批工作流，审批通过后生效。',
      '支付配置变更',
      { type: 'warning' }
    )
  }
  saving.value = true
  const res = await saveSiteConfig({ ...form.value })
  saving.value = false
  if (res.code === 0) ElMessage.success('配置已保存（已写入审计日志）')
}
</script>

<template>
  <div class="adm-page sc">
    <el-skeleton v-if="loading" :rows="8" animated style="padding: 20px" />
    <template v-else>
      <el-tabs v-model="activeTab">
        <!-- 基础配置 -->
        <el-tab-pane label="基础配置" name="basic">
          <div class="adm-card">
            <div class="adm-card__title">站点信息</div>
            <el-form label-width="110px" class="sc__form">
              <el-form-item label="站点名称">
                <el-input v-model="form.siteName" placeholder="C 端标题栏展示名称" maxlength="20" show-word-limit />
              </el-form-item>
              <el-form-item label="全局滚动公告">
                <el-input
                  v-model="form.announcement"
                  type="textarea"
                  :rows="2"
                  maxlength="60"
                  show-word-limit
                  placeholder="C 端首页顶部滚动公告"
                />
              </el-form-item>
              <el-form-item label="维护模式">
                <el-switch v-model="form.maintenance" :active-value="1" :inactive-value="0" />
                <span class="t-tertiary sc__hint">开启后 C 端展示维护页，仅管理员可正常访问</span>
              </el-form-item>
            </el-form>
          </div>

          <div class="adm-card">
            <div class="adm-card__title">备案与合规</div>
            <el-form label-width="110px" class="sc__form">
              <el-form-item label="ICP 备案号">
                <el-input v-model="form.icp" placeholder="如：京ICP备2026000000号-1" />
              </el-form-item>
              <el-form-item label="客服二维码">
                <el-input v-model="form.kefuQr" placeholder="客服企微二维码图片地址" />
                <img v-if="form.kefuQr" class="sc__qr-preview" :src="form.kefuQr" alt="客服二维码" />
                <div class="t-tertiary sc__hint">「我的 · 联系客服」处展示</div>
              </el-form-item>
            </el-form>
          </div>
        </el-tab-pane>

        <!-- 支付配置 -->
        <el-tab-pane label="支付配置" name="payment" lazy>
          <div class="adm-card">
            <div class="adm-card__title">支付渠道</div>
            <el-form label-width="110px" class="sc__form">
              <el-form-item label="支付宝">
                <el-switch v-model="form.alipayEnabled" :active-value="1" :inactive-value="0" />
                <span class="t-tertiary sc__hint">商户号在密钥管理处配置</span>
              </el-form-item>
              <el-form-item label="微信支付">
                <el-switch v-model="form.wechatEnabled" :active-value="1" :inactive-value="0" />
              </el-form-item>
              <el-form-item label="银联支付">
                <el-switch v-model="form.unionpayEnabled" :active-value="1" :inactive-value="0" />
              </el-form-item>
              <el-form-item label="数字货币">
                <el-switch v-model="form.cryptoEnabled" :active-value="1" :inactive-value="0" />
              </el-form-item>
            </el-form>
          </div>

          <div class="adm-card">
            <div class="adm-card__title">交易手续费</div>
            <el-form label-width="110px" class="sc__form">
              <el-form-item label="收费模式">
                <el-radio-group v-model="form.feeMode">
                  <el-radio value="rate">按比例</el-radio>
                  <el-radio value="fixed">固定金额</el-radio>
                </el-radio-group>
              </el-form-item>
              <el-form-item v-if="form.feeMode === 'rate'" label="手续费比例">
                <el-input-number v-model="form.feeRate" :min="0" :max="0.3" :step="0.01" :precision="2" />
                <span class="t-tertiary sc__hint">{{ (form.feeRate * 100).toFixed(0) }}% （二级市场成交时收取）</span>
              </el-form-item>
              <el-form-item v-else label="固定金额">
                <el-input-number v-model="form.feeFixed" :min="0" :max="1000" :step="1" />
                <span class="t-tertiary sc__hint">元 / 笔</span>
              </el-form-item>
            </el-form>
          </div>
        </el-tab-pane>

        <!-- 区块链节点 -->
        <el-tab-pane label="区块链节点" name="chain" lazy>
          <div class="adm-card">
            <div class="adm-card__title">节点与 Gas 策略</div>
            <el-form label-width="110px" class="sc__form">
              <el-form-item label="RPC URL">
                <el-input v-model="form.chainRpc" placeholder="如 https://mainnet.infura.io/v3/xxx" />
              </el-form-item>
              <el-form-item label="链 ID">
                <el-input-number v-model="form.chainId" :min="1" :max="99999" />
                <span class="t-tertiary sc__hint">1=以太坊主网，5=Goerli 测试网</span>
              </el-form-item>
              <el-form-item label="Gas 策略">
                <el-radio-group v-model="form.gasStrategy">
                  <el-radio value="low">低速（省费）</el-radio>
                  <el-radio value="medium">中速（推荐）</el-radio>
                  <el-radio value="high">高速（急速确认）</el-radio>
                </el-radio-group>
              </el-form-item>
            </el-form>
          </div>
        </el-tab-pane>

        <!-- 存储配置 -->
        <el-tab-pane label="存储配置" name="storage" lazy>
          <div class="adm-card">
            <div class="adm-card__title">藏品元数据与图片存储</div>
            <el-form label-width="110px" class="sc__form">
              <el-form-item label="存储方式">
                <el-radio-group v-model="form.storageType">
                  <el-radio value="oss">阿里云 OSS</el-radio>
                  <el-radio value="ipfs">IPFS</el-radio>
                </el-radio-group>
              </el-form-item>
              <el-alert
                type="info"
                :closable="false"
                show-icon
                title="OSS 模式下在密钥管理处配置 AccessKey；IPFS 模式下配置网关节点地址"
              />
            </el-form>
          </div>
        </el-tab-pane>
      </el-tabs>

      <div class="sc__submit">
        <el-button type="primary" :loading="saving" @click="onSave">保存配置</el-button>
      </div>
    </template>
  </div>
</template>

<style scoped lang="scss">
.sc__form {
  max-width: 640px;
}

.sc__hint {
  font-size: 12px;
  margin-left: 12px;
}

.sc__qr-preview {
  margin-top: 8px;
  width: 72px;
  height: 72px;
  border-radius: 8px;
  border: 1px solid $color-border;
  object-fit: cover;
  display: block;
}

.sc__submit {
  padding: 4px 2px 20px;
}
</style>
