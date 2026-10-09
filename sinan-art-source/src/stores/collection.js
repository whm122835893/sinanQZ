import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import request from '@/utils/request'
import { toTs } from '@/utils/datetime'
import { useSiteStore } from './site'

// 藏品状态：列表 / 详情 / 筛选
export const useCollectionStore = defineStore('collection', () => {
  // 限购兜底与后端 perUserLimit 同源：purchase_limit_per_user 系统配置（/api/config）
  const site = useSiteStore()
  // 后端时间串 → 时间戳：统一走 utils/datetime 的 toTs（iOS Safari 解析不了 "…:00:00.000"）

  // 首页发售区藏品（真实接口：GET /api/collections/featured）
  const featured = ref([])
  async function fetchFeatured() {
    const res = await request.get('/collections/featured', { params: { page: 1, pageSize: 50 } })
    featured.value = (res.list || []).map((c) => ({
      id: String(c.id),
      name: c.name,
      tag: ({ blindbox: '盲盒', priority: '优先购', eligibility: '资格购' }[c.saleType]) || c.tag || '首发',
      price: Number(c.price).toFixed(2),
      total: `${c.edition}份`,
      coverImage: c.image,
      saleTime: toTs(c.saleTime),
      saleEndTime: toTs(c.saleEndTime),
      soldOut: c.status === 'soldout' || c.stock <= 0,
      stock: c.stock,
      type: c.isBlindBox ? 'blindbox' : 'release',
      raw: c
    }))
    return featured.value
  }

  // 发售日历（真实接口：GET /api/collections/calendar）
  // 与 featured 数据源不同：含历史售罄/已结束记录（首页发售区只展示可购藏品）
  const calendar = ref([])
  async function fetchCalendar() {
    const list = await request.get('/collections/calendar')
    calendar.value = (list || []).map((c) => ({
      id: String(c.id),
      name: c.name,
      price: Number(c.price).toFixed(2),
      total: `${c.edition}份`,
      coverImage: c.image,
      saleTime: toTs(c.saleTime),
      saleEndTime: toTs(c.saleEndTime),
      soldOut: c.status === 'soldout' || c.stock <= 0,
      stock: c.stock
    }))
    return calendar.value
  }

  const filters = ref({ category: 'all', keyword: '' })
  const detail = ref(null)

  // ---- 分类（真实接口：GET /api/collections/categories?scene=market|artifact）----
  // scene=market 市场二级分类（水墨/国潮…）/ artifact 文物展览分类（青铜/陶瓷/书画/玉器…）
  // 管理端「内容 → 分类管理」维护；后端返回首项固定为「全部」(id 0 / code all)
  const marketCategories = ref([{ id: 0, name: '全部', code: 'all' }])
  const artifactCategories = ref([{ id: 0, name: '全部', code: 'all' }])
  async function fetchCategories(scene = 'market') {
    const list = await request.get('/collections/categories', { params: { scene } })
    const mapped = (list || []).map((c) => ({
      id: Number(c.id),
      name: c.name,
      code: c.code
    }))
    if (scene === 'artifact') artifactCategories.value = mapped
    else marketCategories.value = mapped
    return mapped
  }

  // 市场视图模式：'list' 横条 | 'grid' 卡片（一行两个）
  const marketViewMode = ref('grid')

  // ---- 市场分栏（2026-10-09）----
  // 归属市场：activity 活动市场 / free 自由市场 / all 不限（「我的关注」跨两个市场看）
  // 两个市场的藏品由后端 nft_collectibles.market_type 区分，数据互不相通，
  // 所以顶部 tab 切换必须重新拉取（旧版两个 tab 共用同一份列表）。
  const marketType = ref('activity')
  // 只看「推荐」：仅活动市场有推荐分类，自由市场/我的关注恒为 false
  const marketRecommend = ref(false)

  // 市场藏品列表（真实接口：GET /api/market/collections）
  // 展示门槛在后台「寄售开关」上：开关开 + 已发售的藏品就会出现在这里，
  // 与当前有没有人挂寄售无关。后端把挂单最低价放在 price 上，无人挂单时返回 null，
  // 前端据此显示「暂无寄售」（issuePrice 是发售价，仅作排序参照，不直接展示）。
  const marketCollections = ref([])
  async function fetchMarket(params = {}) {
    const q = {
      category: filters.value.category,
      keyword: filters.value.keyword,
      sort: marketSort.value,
      marketType: marketType.value,
      recommend: marketRecommend.value ? 1 : 0,
      page: 1,
      pageSize: 50,
      ...params
    }
    const res = await request.get('/market/collections', { params: q })
    marketCollections.value = (res.list || []).map((c) => ({
      id: String(c.id),
      name: c.name,
      coverImage: c.image,
      issueCount: String(c.issueCount),
      circulationCount: String(c.circulationCount),
      todayCount: String(c.todayCount),
      limitPrice: String(Number(c.resalePriceMin) || 0), // 寄售限价下限（后端 resale_price_min，0 = 不限）
      // 后端 price = 在售挂单最低价；null/缺省 = 当前无人寄售 → 卡片显示「暂无寄售」
      price: c.price === null || c.price === undefined ? '' : Number(c.price).toFixed(2),
      listingCount: Number(c.ordersCount) || 0,
      issuePrice: Number(c.issuePrice || 0).toFixed(2),
      orders: c.price === null || c.price === undefined ? [] : [Number(c.price).toFixed(2)],
      isFavorite: !!c.isFavorite,
      // 归属市场 + 是否上推荐（后端 market_type / is_market_recommended）
      marketType: c.marketType || 'activity',
      recommended: !!c.recommended
    }))
    // 关注态同步：**只增不删**。市场列表只覆盖"开了寄售开关"的那部分藏品，
    // 拿它当全集去删，会把用户从首页/其他市场关注、当前不在本列表里的藏品误抹掉
    // （这正是"刷新一次关注就没了"的成因）。全集由 fetchFavorites() 从 /user/favorites 取。
    marketCollections.value.forEach((c) => {
      if (c.isFavorite && !favorites.value.includes(c.id)) favorites.value.push(c.id)
    })
    return marketCollections.value
  }

  /**
   * 进入某个市场（首次进页 / 切顶部 tab）并决定默认落点（2026-10-10 口径）：
   * - 后台「推荐」分类总开关开着 且 当前在活动市场 → 默认停在「推荐」；
   *   但一件推荐藏品都没有（当前分类/关键词下查空）就自动退回「全部」，
   *   不让用户一进市场就看到空列表（用户选定：空了就退回，不是显示空态）。
   * - 开关关着 / 自由市场 / 我的关注 → 默认「全部」。
   */
  function setMarketType(type) {
    marketType.value = ['activity', 'free', 'all'].includes(type) ? type : 'activity'
    marketRecommend.value = false
    if (marketType.value !== 'activity' || !site.marketRecommendTabEnabled) return fetchMarket()
    marketRecommend.value = true
    return fetchMarket().then((list) => {
      if (list.length) return list
      marketRecommend.value = false // 推荐位是空的 → 退回「全部」再拉一次
      return fetchMarket()
    })
  }

  /** 只看推荐（仅活动市场生效，其他市场调用即等于取消） */
  function setMarketRecommend(on) {
    marketRecommend.value = !!on && marketType.value === 'activity'
    return fetchMarket()
  }

  // ---- 藏品关注（真实接口：POST /api/collections/:id/favorite，对应 user_favorites 表）----
  const favorites = ref([])
  // /user/favorites 返回的关注全集（精简行）：市场里没有的藏品靠它出现在「我的关注」
  const favoriteRows = ref([])
  function isFavorite(id) {
    return favorites.value.includes(String(id))
  }
  /**
   * 拉取"我关注的藏品"全集（GET /api/user/favorites，需登录）。
   * 这是关注态的权威来源：市场列表只覆盖在架藏品，不能反过来当全集用。
   */
  async function fetchFavorites() {
    if (!localStorage.getItem('jc_token')) return favoriteRows.value // 未登录：不打 401 的请求
    try {
      const res = await request.get('/user/favorites', { params: { page: 1, pageSize: 100 } })
      const list = (res.list || []).map((f) => ({
        id: String(f.id),
        name: f.name,
        coverImage: f.image,
        // 关注行没有挂单信息：价格留空 → 卡片显示「暂无寄售」，发售价用于排序兜底
        price: '',
        listingCount: 0,
        orders: [],
        issuePrice: Number(f.price || 0).toFixed(2),
        issueCount: String(f.issueCount ?? 0),
        circulationCount: String(f.circulationCount ?? 0),
        todayCount: '0',
        limitPrice: '0',
        marketType: f.marketType || 'activity',
        recommended: false,
        isFavorite: true
      }))
      favoriteRows.value = list
      favorites.value = list.map((f) => f.id)
    } catch { /* 拉取失败保留现有态，不打断页面 */ }
    return favoriteRows.value
  }
  function clearFavorites() {
    favorites.value = []
    favoriteRows.value = []
  }
  async function toggleFavorite(id) {
    const key = String(id)
    const target = !favorites.value.includes(key)
    // 先调接口，成功后才改本地态；失败直接抛出，卡片上的心形保持原样（调用方 toast）
    await request.post(`/collections/${key}/favorite`, { favorite: target })
    if (target) {
      if (!favorites.value.includes(key)) favorites.value.push(key)
      fetchFavorites() // 关注行可能不在市场列表里（如首页发售卡）→ 回源补全「我的关注」
    } else {
      favorites.value.splice(favorites.value.indexOf(key), 1)
      favoriteRows.value = favoriteRows.value.filter((f) => f.id !== key)
    }
    return target
  }

  // 市场价格排序：'price-asc' 升序 | 'price-desc' 降序（后端同口径，这里只对本页 50 条再排一次）
  // 排序键：有挂单用挂单最低价，无挂单用发售价——与后端 COALESCE(mp.min_price, c.price) 一致，
  // 不能拿空挂单的 price='' 参与比较（parseFloat 得 NaN，顺序会乱）。
  const marketSort = ref('price-asc')
  const marketSortKey = (c) =>
    c.listingCount > 0 ? parseFloat(c.price) : parseFloat(c.issuePrice || 0)
  const sortedMarketCollections = computed(() => {
    const kw = (filters.value.keyword || '').trim().toLowerCase()
    return marketCollections.value
      .filter((c) => !kw || c.name.toLowerCase().includes(kw))
      .sort((a, b) => {
        const pa = marketSortKey(a)
        const pb = marketSortKey(b)
        return marketSort.value === 'price-asc' ? pa - pb : pb - pa
      })
  })
  /**
   * 「我的关注」列表：关注过的藏品都在，且跟随市场的关键词筛选与价格排序。
   * 同一个藏品优先用市场行（有挂单最低价、归属市场），市场里查不到（寄售开关关着、
   * 或不在本页 50 条里）就用 /user/favorites 的精简行兜底 → 卡片显示「暂无寄售」。
   */
  const followedCollections = computed(() => {
    const byId = new Map()
    favoriteRows.value.forEach((r) => { if (favorites.value.includes(r.id)) byId.set(r.id, r) })
    marketCollections.value.forEach((c) => { if (favorites.value.includes(c.id)) byId.set(c.id, c) })
    const kw = (filters.value.keyword || '').trim().toLowerCase()
    return [...byId.values()]
      .filter((c) => !kw || c.name.toLowerCase().includes(kw))
      .sort((a, b) => {
        const pa = marketSortKey(a)
        const pb = marketSortKey(b)
        return marketSort.value === 'price-asc' ? pa - pb : pb - pa
      })
  })

  function toggleMarketSort() {
    marketSort.value = marketSort.value === 'price-asc' ? 'price-desc' : 'price-asc'
    // 后端已支持 sort 参数，重新拉取
    return fetchMarket()
  }

  // 藏品详情（真实接口：GET /api/collections/:id，含 myOwned）
  async function fetchDetail(id) {
    const d = await request.get(`/collections/${id}`)
    detail.value = {
      id: String(d.id),
      title: d.name,
      total: `${d.edition}份`,
      price: Number(d.price).toFixed(2),
      coverImage: d.image,
      story: d.description || '',
      issueCount: String(d.issueCount),
      circulationCount: String(d.circulationCount),
      todayCount: String(d.todayCount),
      myOwned: d.myOwned || 0,
      saleLimit: d.saleLimit ?? site.purchaseLimitPerUser ?? 5,
      isBuyRequestEnabled: d.isBuyRequestEnabled !== false,
      // 转赠开关（后端 is_transferable，控制「转赠」入口显隐）
      isTransferable: !!d.isTransferable,
      raw: d
    }
    return detail.value
  }

  // 寄售挂单（真实接口：GET /api/resale/listings?collectibleId=）
  const resaleOrders = ref([])
  const resaleLockedCount = ref(0)
  async function fetchResale(id) {
    // 详情与藏品名/封面（挂单项内不再重复返回）
    const d = detail.value && String(detail.value.id) === String(id)
      ? detail.value
      : await fetchDetail(id)
    const res = await request.get('/resale/listings', { params: { collectibleId: id, page: 1, pageSize: 50 } })
    resaleOrders.value = (res.list || []).map((l) => ({
      listingId: l.listingId,
      no: l.no,
      price: Number(l.price).toFixed(2),
      payment: '余额',
      name: d.title,
      cover: d.coverImage,
      locked: !!l.locked
    }))
    resaleLockedCount.value = Number(res.lockedCount) || 0
    return {
      meta: {
        id: d.id,
        name: d.title,
        coverImage: d.coverImage,
        price: d.price,
        total: d.total,
        // 发行量/流通量：详情页已取到，交易页需与详情页同源展示
        issueCount: d.issueCount,
        circulationCount: d.circulationCount,
        isBuyRequestEnabled: d.isBuyRequestEnabled !== false
      },
      orders: resaleOrders.value,
      lockedCount: resaleLockedCount.value
    }
  }

  // 发售状态：'countdown' 倒计时 | 'selling' 发售中 | 'soldout' 已售罄
  function getSaleStatus(item) {
    if (!item) return 'soldout'
    if (item.soldOut) return 'soldout'
    if (typeof item.stock === 'number' && item.stock <= 0) return 'soldout'
    const now = Date.now()
    if (item.saleTime && now < item.saleTime) return 'countdown'
    if (item.saleEndTime && now >= item.saleEndTime) return 'soldout'
    return 'selling'
  }

  // 按 id 获取 featured 藏品
  function getFeaturedById(id) {
    return featured.value.find((f) => f.id === String(id)) || null
  }

  // ----------------------------- 文物展览区（真实接口：GET /api/artifacts）-----------------------------
  const exhibits = ref([])
  async function fetchExhibits() {
    const res = await request.get('/artifacts', { params: { page: 1, pageSize: 50 } })
    exhibits.value = (res.list || []).map((e) => ({
      id: e.id,
      name: e.name,
      char: e.name.charAt(0),
      dynasty: e.dynasty,
      material: e.material,
      category: e.tags?.[0] || e.material,
      image: e.image,
      desc: e.story || e.tags?.join('，'),
      location: e.location,
      age: e.dynasty,
      level: e.level
    }))
    return exhibits.value
  }

  async function fetchExhibit(id) {
    if (!exhibits.value.length) await fetchExhibits().catch(() => {})
    const local = exhibits.value.find((e) => e.id === Number(id))
    try {
      // 优先走详情接口（含 story/specs）
      const d = await request.get(`/artifacts/${id}`)
      return {
        id: d.id,
        name: d.name,
        char: d.name.charAt(0),
        dynasty: d.dynasty,
        material: d.material,
        category: d.tags?.[0] || d.material,
        image: d.image,
        desc: d.story || '',
        location: d.location || d.origin,
        age: d.period || d.dynasty,
        level: d.level,
        detail: d.story ? String(d.story).split(/\n+/).filter(Boolean) : [],
        specs: Array.isArray(d.specs) ? d.specs : []
      }
    } catch {
      return local || null
    }
  }

  return {
    featured,
    calendar,
    fetchCalendar,
    filters,
    detail,
    resaleOrders,
    resaleLockedCount,
    marketViewMode,
    marketCollections,
    marketType,
    marketRecommend,
    setMarketType,
    setMarketRecommend,
    marketSort,
    sortedMarketCollections,
    toggleMarketSort,
    marketCategories,
    artifactCategories,
    fetchCategories,
    favorites,
    favoriteRows,
    followedCollections,
    isFavorite,
    toggleFavorite,
    fetchFavorites,
    clearFavorites,
    fetchFeatured,
    fetchMarket,
    fetchDetail,
    fetchResale,
    getSaleStatus,
    getFeaturedById,
    exhibits,
    fetchExhibits,
    fetchExhibit
  }
})
