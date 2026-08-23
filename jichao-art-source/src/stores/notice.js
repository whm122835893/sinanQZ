import { defineStore } from 'pinia'
import { ref, computed } from 'vue'

// 公告状态：列表 / 分类 / 搜索
export const useNoticeStore = defineStore('notice', () => {
  const categories = ref([
    { key: 'all', label: '全部' },
    { key: 'activity', label: '活动公告' },
    { key: 'compose', label: '合成公告' },
    { key: 'operation', label: '运营公告' }
  ])

  // mock 公告列表（截图展示有内容的列表项）
  const notices = ref([
    {
      id: 1,
      title: '司南艺术·全域“生态星推官”共建招募，共创价值！',
      summary: '司南艺术诚邀共建者加入生态星推官计划，享专属权益与成长激励。',
      category: 'activity',
      categoryLabel: '活动公告',
      time: '2026-07-03 18:18:18',
      content: [
        '为共建司南艺术数字生态，平台正式发起全域“生态星推官”共建招募计划。我们诚邀热爱数字艺术、具备传播能力的共建者加入，与司南一同成长。',
        '成为生态星推官后，您将享有专属成长激励、首发藏品优先购资格、社群身份标识等权益，并有机会参与平台线下活动与共创。',
        '招募长期开放，符合条件的用户可通过“我的—邀请裂变”入口提交申请，审核通过后即可解锁星推官身份。'
      ]
    },
    {
      id: 2,
      title: '关于司南商城实物兑换上线公告',
      summary: '司南商城“实物兑换”专区正式上线，使用司南币即可兑换专属周边。',
      category: 'operation',
      categoryLabel: '运营公告',
      time: '2026-07-01 10:00:00',
      content: [
        '司南商城“实物兑换”专区于 2026 年 7 月 1 日正式上线。用户可使用持有的司南币，兑换平台专属数字周边与实物礼品。',
        '兑换专区涵盖文创周边、联名藏品、限定礼盒等多类商品，商品库存有限，兑完即止。',
        '实物兑换订单将在 7 个工作日内安排发货，物流信息可在“我的订单”中查询。如有疑问请联系在线客服。'
      ]
    },
    {
      id: 3,
      title: '【司南艺术】《司南暴富》合成活动',
      summary: '集齐指定藏品即可参与合成，限量发行，先到先得。',
      category: 'compose',
      categoryLabel: '合成公告',
      time: '2026-08-23 10:00:00',
      richContent: [
        { type: 'text', value: '<span class="label">尊敬的司南艺术用户：</span>' },
        { type: 'text', value: '您好！' },
        { type: 'text', value: '<span class="label">「司南暴富」合成活动</span>' },
        { type: 'text', value: '消耗藏品：<span class="highlight">「青铜罗盘」*1 + 「虎符令」*1</span>' },
        { type: 'text', value: '合成藏品：<span class="highlight">「司南暴富」*1</span>' },
        { type: 'text', value: '最大合成份数为<span class="highlight">888份</span>' },
        { type: 'text', value: '注：未参与合成活动的「青铜罗盘」，将不再参与后续合成活动。' },
        { type: 'text', value: '<span class="label">合成时间：</span><span class="highlight">2026年8月23日15:00—15:30</span>' },
        { type: 'text', value: '<span class="section-title">藏品简介：「司南暴富」</span>' },
        { type: 'text', value: '简介：司南暴富，以古代司南为灵感，融合现代数字艺术手法铸造而成。藏品寓意招财纳福、指引方向，是司南艺术发行的限量合成数字藏品之一。' },
        { type: 'image', value: '/images/collections/cover-1.jpg' },
        { type: 'signature', value: '<p>敬请悉知</p><p>司南艺术运营组</p><p>2026年8月23日</p>' },
        { type: 'warning', value: '<p class="warn-title">【司南艺术风险提示】：</p><p>司南艺术发售的数字藏品仅具备收藏欣赏价值，官方对藏品价格不构成任何指导意义，请谨慎购买，严防炒作。</p><p class="warn-title">【司南艺术郑重提醒广大用户】：</p><p>平台对于使用第三方工具锁单、抢单；以平台的名义进行承诺收益、承诺回购、非法集资、非法吸收公共存款；为了非法目的进行拉人头、要求缴纳入门费、团队计酬返利等涉嫌传销的行为或故意从事旨在对平台、平台用户或服务的履行造成不利影响的等行为秉持零容忍态度，一经发现将采取封号、冻结等措施。请大家规范抢购，不要轻信此类第三方工具或任何非官方的抢购信息，避免造成个人财产损失！</p><p>根据国家反洗钱、反诈骗等法律法规规定，特提醒广大用户严格遵守国家法律法规，禁止在司南艺术平台进行洗钱、诈骗、赌博、开设赌场、非法集资、非法融资、传销等违法行为，否则一经平台查实，将对您的司南艺术账号作出封禁、冻结等处理，并移交国家司法机关追究其法律责任。</p>' }
      ]
    }
  ])

  const searchKeyword = ref('')
  const activeCategory = ref('all')

  const filteredNotices = computed(() => {
    let list = notices.value
    if (activeCategory.value !== 'all') {
      list = list.filter((n) => n.category === activeCategory.value)
    }
    if (searchKeyword.value.trim()) {
      const k = searchKeyword.value.trim()
      list = list.filter((n) => n.title.includes(k) || n.summary.includes(k))
    }
    return list
  })

  function fetchNotices() {
    return Promise.resolve(notices.value)
  }

  function search(keyword) {
    searchKeyword.value = keyword
    return Promise.resolve(filteredNotices.value)
  }

  return {
    categories,
    notices,
    searchKeyword,
    activeCategory,
    filteredNotices,
    fetchNotices,
    search
  }
})
