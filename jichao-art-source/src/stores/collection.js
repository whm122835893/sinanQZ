import { defineStore } from 'pinia'
import { ref, computed } from 'vue'

// ----------------------------- 首页发售藏品封面（AI 本地生成图）-----------------------------
const COVER = {
  1:   '/images/collections/cover-collection-1.jpg',   // 龙纹罗盘
  2:   '/images/collections/cover-collection-2.jpg',   // 虎纹卡牌
  3:   '/images/collections/cover-collection-3.jpg',   // 水晶菱形
  4:   '/images/collections/cover-collection-4.jpg',   // 司南青龙
  5:   '/images/collections/cover-collection-5.jpg',   // 司南暴富
  bb1: '/images/collections/cover-collection-bb1.jpg', // 神秘盲盒
  6:   '/images/collections/cover-collection-6.jpg',   // 敦煌飞天
  7:   '/images/collections/cover-collection-7.jpg',   // 青铜面具
  8:   '/images/collections/cover-collection-8.jpg'    // 九霄环佩
}

// ----------------------------- 文物展览区图片（AI 本地生成图）-----------------------------
const EXHIBIT = (id) => `/images/exhibits/exhibit-${id}.jpg`

// 藏品状态：列表 / 详情 / 筛选
export const useCollectionStore = defineStore('collection', () => {
  // 首页发售区藏品（mock）—— 含封面图 + 发售时间
  const _now = Date.now()
  const _2h = 2 * 60 * 60 * 1000
  const _5h = 5 * 60 * 60 * 1000
  const _24h = 24 * 60 * 60 * 1000
  const featured = ref([
    { id: '1', name: '龙纹罗盘', tag: '首发', price: '0.10', total: '500份', coverImage: COVER[1], saleTime: _now + _2h, saleEndTime: _now + _2h + _24h, soldOut: false, stock: 500 },
    { id: '2', name: '虎纹卡牌', tag: '优先购', price: '0.10', total: '800份', coverImage: COVER[2], saleTime: _now + _5h, saleEndTime: _now + _5h + _24h, soldOut: false, stock: 800 },
    { id: '3', name: '水晶菱形', tag: '资格购', price: '0.10', total: '300份', coverImage: COVER[3], saleTime: _now - _5h, saleEndTime: _now + _24h, soldOut: true, stock: 0 },
    { id: '4', name: '司南青龙', tag: '首发', price: '0.20', total: '1000份', coverImage: COVER[4], saleTime: _now - _2h, saleEndTime: _now + _24h, soldOut: false, stock: 1000 },
    { id: 'bb1', name: '神秘盲盒', tag: '盲盒', price: '0.50', total: '200份', coverImage: COVER['bb1'], saleTime: _now - _2h, saleEndTime: _now + _24h, soldOut: false, stock: 200, type: 'blindbox', reveals: { id: '4', name: '司南青龙', coverImage: COVER[4], price: '0.20' }, items: [
      { id: '1', name: '龙纹罗盘', coverImage: COVER[1], rarity: '普通', probability: '35%' },
      { id: '2', name: '虎纹卡牌', coverImage: COVER[2], rarity: '普通', probability: '30%' },
      { id: '3', name: '水晶菱形', coverImage: COVER[3], rarity: '稀有', probability: '20%' },
      { id: '4', name: '司南青龙', coverImage: COVER[4], rarity: '史诗', probability: '10%' },
      { id: '5', name: '司南暴富', coverImage: COVER[5], rarity: '传说', probability: '5%' }
    ] }
  ])

  // 市场寄售藏品（mock）—— 点击进入寄售详情
  const resaleCollection = ref({
    id: '5', name: '司南暴富', tag: '寄售', price: '0.50', total: '200份', coverImage: COVER[5],
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
    { id: '1', name: '龙纹罗盘', coverImage: COVER[1], issueCount: '1000000', circulationCount: '328400', todayCount: '128', limitPrice: '100', payment: '微信', orders: ['0.12', '0.10', '0.09', '0.08'] },
    { id: '2', name: '虎纹卡牌', coverImage: COVER[2], issueCount: '800000', circulationCount: '263100', todayCount: '96', limitPrice: '100', payment: '支付宝', orders: ['0.13', '0.11', '0.10', '0.09'] },
    { id: '3', name: '水晶菱形', coverImage: COVER[3], issueCount: '600000', circulationCount: '187500', todayCount: '64', limitPrice: '100', payment: '汇', orders: ['0.10', '0.09', '0.08', '0.07'] },
    { id: '4', name: '司南青龙', coverImage: COVER[4], issueCount: '1000000', circulationCount: '512000', todayCount: '210', limitPrice: '100', payment: '微信', orders: ['0.25', '0.22', '0.20', '0.18'] },
    { id: '5', name: '司南暴富', coverImage: COVER[5], issueCount: '200000', circulationCount: '53283', todayCount: '0', limitPrice: '10000', payment: '支付宝', orders: ['0.55', '0.52', '0.50', '0.48', '0.46', '0.45'] },
    { id: '6', name: '敦煌飞天', coverImage: COVER[6], issueCount: '500000', circulationCount: '142000', todayCount: '38', limitPrice: '100', payment: '微信', orders: ['1.30', '1.25', '1.20', '1.15'] },
    { id: '7', name: '青铜面具', coverImage: COVER[7], issueCount: '300000', circulationCount: '98000', todayCount: '52', limitPrice: '100', payment: '汇', orders: ['0.35', '0.32', '0.30', '0.28'] },
    { id: '8', name: '九霄环佩', coverImage: COVER[8], issueCount: '150000', circulationCount: '47200', todayCount: '17', limitPrice: '100', payment: '支付宝', orders: ['2.20', '2.10', '2.00', '1.95'] }
  ])

  // ---- 藏品关注（mock 内存态，对应 user_favorites 表）----
  const favorites = ref([])
  function isFavorite(id) {
    return favorites.value.includes(String(id))
  }
  function toggleFavorite(id) {
    const key = String(id)
    const idx = favorites.value.indexOf(key)
    if (idx >= 0) favorites.value.splice(idx, 1)
    else favorites.value.push(key)
    return idx < 0
  }

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

  // ----------------------------- 文物展览区 -----------------------------
  const exhibits = ref([
    {
      id: 1,
      name: '西周青铜鼎',
      char: '鼎',
      dynasty: '西周',
      material: '青铜',
      category: '青铜',
      image: EXHIBIT(1),
      desc: '饕餮纹方鼎，庄重威严，礼器之重。',
      location: '中国国家博物馆',
      age: '约公元前1046年－前771年',
      level: '国家一级文物',
      detail: [
        '鼎为青铜重器，原为烹饪与祭祀礼器，西周时期成为贵族等级与权力的象征。',
        '此鼎器身饰饕餮纹与夔龙纹，口沿宽厚，四足稳固，铸工精湛，是西周早期青铜礼器的代表之作。',
        '腹内铭文记事，是研究西周政治、礼制与文字的重要实物资料。'
      ],
      specs: [
        ['器型', '方鼎'],
        ['通高', '约 93 cm'],
        ['腹深', '约 52 cm'],
        ['重量', '约 201 kg'],
        ['材质', '青铜（锡铅铜合金）']
      ]
    },
    {
      id: 2,
      name: '战国谷纹玉璧',
      char: '璧',
      dynasty: '战国',
      material: '玉石',
      category: '玉器',
      image: EXHIBIT(2),
      desc: '苍璧礼天，温润有方，谷纹精美。',
      location: '故宫博物院',
      age: '公元前475年－前221年',
      level: '国家一级文物',
      detail: [
        '玉璧为古代"六器"之一，《周礼》有"以苍璧礼天"之说，象征天圆与丰收。',
        '璧面满饰突起的谷纹，排列规整，寓意五谷丰登、生生不息。',
        '玉质温润通透，琢工精细，是战国时期玉器工艺的巅峰之作。'
      ],
      specs: [
        ['形制', '圆形扁平璧'],
        ['外径', '约 16 cm'],
        ['好径（内径）', '约 5 cm'],
        ['厚度', '约 0.5 cm'],
        ['玉料', '和田青白玉']
      ]
    },
    {
      id: 3,
      name: '元青花缠枝罐',
      char: '青',
      dynasty: '元',
      material: '陶瓷',
      category: '陶瓷',
      image: EXHIBIT(3),
      desc: '釉下青花，缠枝莲纹，发色浓艳。',
      location: '景德镇中国陶瓷博物馆',
      age: '公元13世纪－14世纪',
      level: '国家一级文物',
      detail: [
        '元代景德镇创烧成熟的青花瓷，使用进口"苏麻离青"料，发色浓艳，带铁锈斑。',
        '器身通体绘缠枝莲纹，笔触奔放，层次丰富，代表元代青花的最高水准。',
        '此类大罐存世稀少，是全球藏家争相追逐的瓷器珍品。'
      ],
      specs: [
        ['器型', '直口鼓腹大罐'],
        ['通高', '约 28 cm'],
        ['口径', '约 20 cm'],
        ['足径', '约 19 cm'],
        ['窑口', '景德镇窑']
      ]
    },
    {
      id: 4,
      name: '宋水墨山水卷',
      char: '山',
      dynasty: '宋',
      material: '书画',
      category: '书画',
      image: EXHIBIT(4),
      desc: '水墨晕染，意境悠远，咫尺千里。',
      location: '台北故宫博物院',
      age: '公元960年－1279年',
      level: '国宝级',
      detail: [
        '宋代山水画进入黄金时期，讲求"天人合一"的文人意境，咫尺千里、气象万千。',
        '此卷以墨色浓淡表现远近层次，山石用披麻皴兼米点，云烟出没，极富诗意。',
        '卷尾留有历代名家题跋与鉴藏印记，流传有序。'
      ],
      specs: [
        ['形式', '绢本设色长卷'],
        ['纵', '约 32 cm'],
        ['横', '约 280 cm'],
        ['技法', '水墨淡设色'],
        ['装裱', '手卷']
      ]
    },
    {
      id: 5,
      name: '汉彩绘陶俑',
      char: '俑',
      dynasty: '汉',
      material: '陶瓷',
      category: '陶瓷',
      image: EXHIBIT(5),
      desc: '彩绘陶俑，衣袂翩然，生动传神。',
      location: '陕西历史博物馆',
      age: '公元前202年－公元220年',
      level: '国家一级文物',
      detail: [
        '汉代陶俑承秦代之制，更重生活气息，多见侍女、乐舞、武士等形象。',
        '此俑面容温婉，衣纹流畅，原有彩绘虽经岁月斑驳，仍可辨朱唇翠袖。',
        '是研究汉代服饰、妆容与社会生活的珍贵实物。'
      ],
      specs: [
        ['类型', '侍女立俑'],
        ['通高', '约 54 cm'],
        ['材质', '灰陶加彩'],
        ['工艺', '模制＋手修'],
        ['出土地', '陕西西安']
      ]
    },
    {
      id: 6,
      name: '商玉龙形佩',
      char: '龙',
      dynasty: '商',
      material: '玉石',
      category: '玉器',
      image: EXHIBIT(6),
      desc: '苍龙曲身，琢工古拙，神韵天成。',
      location: '中国社会科学院考古研究所',
      age: '约公元前1300年－前1046年',
      level: '国家一级文物',
      detail: [
        '商代玉器以殷墟妇好墓出土最精，龙形佩是贵族身份的重要标志。',
        '龙身卷曲，臣字眼、蘑菇形角为典型殷商风格，阴线双勾技法成熟。',
        '佩孔可穿系，既是随身佩饰，亦是沟通天地的礼玉。'
      ],
      specs: [
        ['形制', '曲身龙形佩'],
        ['长', '约 8 cm'],
        ['宽', '约 4.5 cm'],
        ['厚', '约 0.4 cm'],
        ['玉料', '墨碧玉']
      ]
    },
    {
      id: 7,
      name: '唐鎏金铜镜',
      char: '镜',
      dynasty: '唐',
      material: '青铜',
      category: '青铜',
      image: EXHIBIT(7),
      desc: '瑞兽葡萄，鎏金辉煌，照影千年。',
      location: '上海博物馆',
      age: '公元7世纪－8世纪',
      level: '国家一级文物',
      detail: [
        '唐代是中国铜镜发展的最高峰，瑞兽葡萄纹镜被誉为"盛唐第一名镜"。',
        '镜背以高浮雕表现瑞兽、葡萄与宝相花，繁缛富丽，外区鎏金保存完好。',
        '铜镜合金含锡量高，镜面光可鉴人，是唐代金属工艺的杰出代表。'
      ],
      specs: [
        ['形制', '圆形菱花镜'],
        ['直径', '约 17.5 cm'],
        ['厚度', '约 1.2 cm'],
        ['重量', '约 1.1 kg'],
        ['工艺', '青铜铸造＋鎏金']
      ]
    },
    {
      id: 8,
      name: '明文征明行书',
      char: '书',
      dynasty: '明',
      material: '书画',
      category: '书画',
      image: EXHIBIT(8),
      desc: '行云流水，法度谨严，文气盎然。',
      location: '苏州博物馆',
      age: '公元1470年－1559年',
      level: '国家一级文物',
      detail: [
        '文征明为"吴门四家"之一，行书初学黄庭坚，后融入二王，自成一格。',
        '此轴笔势舒展，结体端庄而不失灵动，字里行间可见儒雅文士之风。',
        '卷末钤有"文征明印"与鉴藏家多方印记，流传有绪。'
      ],
      specs: [
        ['形式', '纸本立轴'],
        ['纵', '约 135 cm'],
        ['横', '约 42 cm'],
        ['书体', '行书'],
        ['款识', '文征明']
      ]
    }
  ])

  function fetchExhibit(id) {
    const eid = Number(id)
    return exhibits.value.find(e => e.id === eid) || null
  }

  return {
    featured,
    resaleCollection,
    collections,
    filters,
    detail,
    resaleOrders,
    marketViewMode,
    marketCollections,
    marketSort,
    sortedMarketCollections,
    toggleMarketSort,
    favorites,
    isFavorite,
    toggleFavorite,
    fetchList,
    fetchDetail,
    fetchResale,
    getSaleStatus,
    getCountdownText,
    getFeaturedById,
    changeStock,
    changeCirculation,
    exhibits,
    fetchExhibit
  }
})
