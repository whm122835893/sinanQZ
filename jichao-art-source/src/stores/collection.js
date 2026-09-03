import { defineStore } from 'pinia'
import { ref, computed } from 'vue'

// 藏品状态：列表 / 详情 / 筛选
export const useCollectionStore = defineStore('collection', () => {
  // 首页发售区藏品（mock）—— 含封面图 + 发售时间
  const _now = Date.now()
  const _2h = 2 * 60 * 60 * 1000
  const _5h = 5 * 60 * 60 * 1000
  const _24h = 24 * 60 * 60 * 1000
  const featured = ref([
    { id: '1', name: '龙纹罗盘', tag: '首发', price: '0.10', total: '500份', coverImage: '/images/collections/cover-1.jpg', saleTime: _now + _2h, saleEndTime: _now + _2h + _24h, soldOut: false, stock: 500 },
    { id: '2', name: '虎纹卡牌', tag: '优先购', price: '0.10', total: '800份', coverImage: '/images/collections/cover-2.jpg', saleTime: _now + _5h, saleEndTime: _now + _5h + _24h, soldOut: false, stock: 800 },
    { id: '3', name: '水晶菱形', tag: '资格购', price: '0.10', total: '300份', coverImage: '/images/collections/cover-3.jpg', saleTime: _now - _5h, saleEndTime: _now + _24h, soldOut: true, stock: 0 },
    { id: '4', name: '司南青龙', tag: '首发', price: '0.20', total: '1000份', coverImage: '/images/collections/cover-4.jpg', saleTime: _now - _2h, saleEndTime: _now + _24h, soldOut: false, stock: 1000 },
    { id: 'bb1', name: '神秘盲盒', tag: '盲盒', price: '0.50', total: '200份', coverImage: '/images/collections/cover-5.jpg', saleTime: _now - _2h, saleEndTime: _now + _24h, soldOut: false, stock: 200, type: 'blindbox', reveals: { id: '4', name: '司南青龙', coverImage: '/images/collections/cover-4.jpg', price: '0.20' }, items: [
      { id: '1', name: '龙纹罗盘', coverImage: '/images/collections/cover-1.jpg', rarity: '普通', probability: '35%' },
      { id: '2', name: '虎纹卡牌', coverImage: '/images/collections/cover-2.jpg', rarity: '普通', probability: '30%' },
      { id: '3', name: '水晶菱形', coverImage: '/images/collections/cover-3.jpg', rarity: '稀有', probability: '20%' },
      { id: '4', name: '司南青龙', coverImage: '/images/collections/cover-4.jpg', rarity: '史诗', probability: '10%' },
      { id: '5', name: '司南暴富', coverImage: '/images/collections/cover-5.jpg', rarity: '传说', probability: '5%' }
    ] }
  ])

  // 市场寄售藏品（mock）—— 点击进入寄售详情
  const resaleCollection = ref({
    id: '5', name: '司南暴富', tag: '寄售', price: '0.50', total: '200份', coverImage: '/images/collections/cover-5.jpg',
    issueCount: '200000',
    circulationCount: '53283',
    todayCount: '0',
    limitPrice: '10000'
  })

  // 市场列表（按规范默认空状态）
  const collections = ref([])
  const filters = ref({ category: 'all', keyword: '' })
  const detail = ref(null)

  // 市场视图模式：'list' 横条 | 'grid' 卡片（一行两个）
  const marketViewMode = ref('grid')

  // 市场藏品列表（多条，用于价格排序筛选）
  // orders 为该藏品在市场的寄售挂单价格，列表价格取其中的最低价
  const marketCollections = ref([
    { id: '1', name: '龙纹罗盘', coverImage: '/images/collections/cover-1.jpg', issueCount: '1000000', circulationCount: '328400', todayCount: '128', limitPrice: '100', payment: '微信', orders: ['0.12', '0.10', '0.09', '0.08'] },
    { id: '2', name: '虎纹卡牌', coverImage: '/images/collections/cover-2.jpg', issueCount: '800000', circulationCount: '263100', todayCount: '96', limitPrice: '100', payment: '支付宝', orders: ['0.13', '0.11', '0.10', '0.09'] },
    { id: '3', name: '水晶菱形', coverImage: '/images/collections/cover-3.jpg', issueCount: '600000', circulationCount: '187500', todayCount: '64', limitPrice: '100', payment: '汇', orders: ['0.10', '0.09', '0.08', '0.07'] },
    { id: '4', name: '司南青龙', coverImage: '/images/collections/cover-4.jpg', issueCount: '1000000', circulationCount: '512000', todayCount: '210', limitPrice: '100', payment: '微信', orders: ['0.25', '0.22', '0.20', '0.18'] },
    { id: '5', name: '司南暴富', coverImage: '/images/collections/cover-5.jpg', issueCount: '200000', circulationCount: '53283', todayCount: '0', limitPrice: '10000', payment: '支付宝', orders: ['0.55', '0.52', '0.50', '0.48', '0.46', '0.45'] },
    { id: '6', name: '敦煌飞天', coverImage: '/images/collections/cover-1.jpg', issueCount: '500000', circulationCount: '142000', todayCount: '38', limitPrice: '100', payment: '微信', orders: ['1.30', '1.25', '1.20', '1.15'] },
    { id: '7', name: '青铜面具', coverImage: '/images/collections/cover-2.jpg', issueCount: '300000', circulationCount: '98000', todayCount: '52', limitPrice: '100', payment: '汇', orders: ['0.35', '0.32', '0.30', '0.28'] },
    { id: '8', name: '九霄环佩', coverImage: '/images/collections/cover-3.jpg', issueCount: '150000', circulationCount: '47200', todayCount: '17', limitPrice: '100', payment: '支付宝', orders: ['2.20', '2.10', '2.00', '1.95'] }
  ])

  // 市场价格排序：'price-asc' 升序 | 'price-desc' 降序
  // 价格取该藏品寄售挂单的最低价（price = min(orders)），并叠加关键词过滤（按藏品名）
  const marketSort = ref('price-asc')
  const sortedMarketCollections = computed(() => {
    const kw = (filters.value.keyword || '').trim().toLowerCase()
    return marketCollections.value
      .filter(c => !kw || c.name.toLowerCase().includes(kw))
      .map(c => ({ ...c, price: Math.min(...c.orders.map(Number)).toFixed(2) }))
      .sort((a, b) => {
        const pa = parseFloat(a.price)
        const pb = parseFloat(b.price)
        return marketSort.value === 'price-asc' ? pa - pb : pb - pa
      })
  })
  function toggleMarketSort() {
    marketSort.value = marketSort.value === 'price-asc' ? 'price-desc' : 'price-asc'
  }

  function fetchList() {
    collections.value = []
    return Promise.resolve([])
  }

  const stories = {
    '1': '龙纹罗盘以东方祥龙为引，盘心暗藏玄机，寓意藏家在数字藏海之中寻得方向与财富。',
    '2': '虎纹卡牌取猛虎之威，每一张都铭刻独一无二的链上纹路，象征勇气与守护。',
    '3': '水晶菱形凝练水之灵动，切面折射数字光晕，如冰晶般剔透且不可复制。',
    '4': '司南青龙承袭司南文创核心意象，东方苍龙腾云驾雾，为藏家开启一场国潮 digital 之旅。',
    'bb1': '神秘盲盒蕴含未知惊喜，开启即随机获得一款精选数字藏品，好运藏在每一次开启之中。'
  }

  function fetchDetail(id) {
    const item = [...featured.value, resaleCollection.value].find(f => f.id === id)
    const meta = item || { name: '司南暴富', total: '500份', price: '0.10' }
    const mk = marketCollections.value.find(m => m.id === id)
    detail.value = {
      id,
      title: meta.name,
      total: meta.total,
      price: meta.price,
      coverImage: meta.coverImage || `/images/collections/cover-${id}.jpg`,
      story: stories[id] || '',
      issueCount: mk ? mk.issueCount : '1000000',
      // 流通量 = 基础值 + 已支付成功的增量
      circulationCount: String(Number(mk ? mk.circulationCount : '328400') + (circulationDelta.value[id] || 0)),
      notice: [
        '藏品为数字藏品，一经购买不支持退换，请确认后再下单。',
        '本平台数字藏品仅供收藏与欣赏，不具备投资属性。',
        '请妥善保管您的账户与操作密码，谨防泄露。'
      ]
    }
    return Promise.resolve(detail.value)
  }

  // 寄售藏品编号 + 寄售挂单（mock）
  const resaleOrders = ref([])
  function fetchResale(id) {
    // 市场藏品优先（含 orders/issueCount 等完整字段），其次首页发售，最后兜底 resaleCollection
    const meta = [...marketCollections.value, ...featured.value, resaleCollection.value].find(f => f.id === id) || resaleCollection.value
    // 用该藏品的市场挂单价格生成详情页挂单：编号 + 价格 + 支付方式（mock）
    const paymentPool = ['微信', '支付宝', '汇']
    const prices = (meta.orders && meta.orders.length) ? meta.orders : ['0.55', '0.52', '0.50', '0.48', '0.46', '0.45']
    resaleOrders.value = prices.map((p, i) => ({
      no: 'SN-' + id + '-' + String(i + 1).padStart(4, '0'),
      price: p,
      payment: paymentPool[i % paymentPool.length],
      name: meta.name,
      cover: meta.coverImage
    }))
    return Promise.resolve({ meta, orders: resaleOrders.value })
  }

  // 发售状态：'countdown' 倒计时 | 'selling' 发售中 | 'soldout' 已售罄
  function getSaleStatus(item) {
    if (!item) return 'soldout'
    if (item.soldOut) return 'soldout'
    // 库存售罄（待支付订单锁定库存，支付成功消耗，超时释放）
    if (typeof item.stock === 'number' && item.stock <= 0) return 'soldout'
    const now = Date.now()
    if (item.saleTime && now < item.saleTime) return 'countdown'
    if (item.saleEndTime && now >= item.saleEndTime) return 'soldout'
    return 'selling'
  }

  // ---- 发售库存 / 流通量（mock 内存态）----
  // 待支付订单锁定/释放库存：delta 负数为锁定，正数为释放
  function changeStock(id, delta) {
    const item = featured.value.find(f => String(f.id) === String(id))
    if (!item || typeof item.stock !== 'number') return
    item.stock = Math.max(0, item.stock + delta)
  }

  // 流通量增量：支付成功入库 +qty（挂单购买已在流通，不变化）
  const circulationDelta = ref({})
  function changeCirculation(id, delta) {
    const key = String(id)
    circulationDelta.value[key] = (circulationDelta.value[key] || 0) + delta
  }

  // 倒计时文案
  function getCountdownText(item) {
    if (!item || !item.saleTime) return ''
    const diff = item.saleTime - Date.now()
    if (diff <= 0) return ''
    const h = Math.floor(diff / 3600000)
    const m = Math.floor((diff % 3600000) / 60000)
    const s = Math.floor((diff % 60000) / 1000)
    const pad = (n) => String(n).padStart(2, '0')
    return pad(h) + ':' + pad(m) + ':' + pad(s)
  }

  // 按 id 获取 featured 藏品
  function getFeaturedById(id) {
    return featured.value.find(f => f.id === String(id)) || null
  }

  return { featured, resaleCollection, collections, filters, detail, resaleOrders, marketViewMode, marketCollections, marketSort, sortedMarketCollections, toggleMarketSort, fetchList, fetchDetail, fetchResale, getSaleStatus, getCountdownText, getFeaturedById, changeStock, changeCirculation }
})
