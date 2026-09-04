import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import request from '@/utils/request'

// 藏品状态：列表 / 详情 / 筛选
// MOCK_REPLACED: 原数据来自本文件内联 mock 常量（featured/marketCollections/exhibits/resaleOrders），
// 现已全部接入真实接口：/api/collections/featured、/api/market/collections、
// /api/collections/:id、/api/resale/listings、/api/artifacts、/api/collections/:id/favorite
export const useCollectionStore = defineStore('collection', () => {
  // 后端时间字符串（YYYY-MM-DD HH:mm:ss）→ 时间戳
  const toTs = (s) => (s ? new Date(String(s).replace(/-/g, '/')).getTime() : 0)

  // 首页发售区藏品（真实接口：GET /api/collections/featured）
  const featured = ref([])
  async function fetchFeatured() {
    const res = await request.get('/collections/featured', { params: { page: 1, pageSize: 50 } })
    featured.value = (res.list || []).map((c) => ({
      id: String(c.id),
      name: c.name,
      tag: c.tag,
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

  // 市场寄售藏品（真实接口：GET /api/market/collections 聚合挂单最低价）
  const resaleCollection = ref(null)
  async function fetchResaleCollection(id) {
    const c = await request.get(`/collections/${id}`)
    resaleCollection.value = {
      id: String(c.id),
      name: c.name,
      tag: c.tag,
      price: Number(c.price).toFixed(2),
      total: `${c.edition}份`,
      coverImage: c.image,
      issueCount: String(c.issueCount),
      circulationCount: String(c.circulationCount),
      todayCount: String(c.todayCount),
      limitPrice: '10000'
    }
    return resaleCollection.value
  }

  const collections = ref([])
  const filters = ref({ category: 'all', keyword: '' })
  const detail = ref(null)

  // 市场视图模式：'list' 横条 | 'grid' 卡片（一行两个）
  const marketViewMode = ref('grid')

  // 市场藏品列表（真实接口：GET /api/market/collections）
  // orders 为该藏品寄售挂单价格，列表价格取其中的最低价（后端已聚合 min_price）
  const marketCollections = ref([])
  async function fetchMarket(params = {}) {
    const q = {
      category: filters.value.category,
      keyword: filters.value.keyword,
      sort: marketSort.value,
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
      limitPrice: '100',
      orders: [Number(c.price).toFixed(2)],
      isFavorite: !!c.isFavorite
    }))
    // 同步关注态（后端已按登录用户返回 isFavorite）
    marketCollections.value.forEach((c) => {
      const key = c.id
      const has = favorites.value.includes(key)
      if (c.isFavorite && !has) favorites.value.push(key)
      if (!c.isFavorite && has) favorites.value.splice(favorites.value.indexOf(key), 1)
    })
    return marketCollections.value
  }

  // ---- 藏品关注（真实接口：POST /api/collections/:id/favorite，对应 user_favorites 表）----
  const favorites = ref([])
  function isFavorite(id) {
    return favorites.value.includes(String(id))
  }
  async function toggleFavorite(id) {
    const key = String(id)
    const target = !favorites.value.includes(key)
    try {
      // 先调接口，成功后再改本地态（失败回滚）
      await request.post(`/collections/${key}/favorite`, { favorite: target })
    } catch (e) {
      throw e
    }
    if (target) favorites.value.push(key)
    else favorites.value.splice(favorites.value.indexOf(key), 1)
    return target
  }

  // 市场价格排序：'price-asc' 升序 | 'price-desc' 降序
  // 价格取该藏品寄售挂单的最低价（price = min(orders)）
  const marketSort = ref('price-asc')
  const sortedMarketCollections = computed(() => {
    const kw = (filters.value.keyword || '').trim().toLowerCase()
    return marketCollections.value
      .filter((c) => !kw || c.name.toLowerCase().includes(kw))
      .map((c) => ({ ...c, price: Math.min(...c.orders.map(Number)).toFixed(2) }))
      .sort((a, b) => {
        const pa = parseFloat(a.price)
        const pb = parseFloat(b.price)
        return marketSort.value === 'price-asc' ? pa - pb : pb - pa
      })
  })
  function toggleMarketSort() {
    marketSort.value = marketSort.value === 'price-asc' ? 'price-desc' : 'price-asc'
    // 后端已支持 sort 参数，重新拉取
    return fetchMarket()
  }

  async function fetchList() {
    await fetchMarket()
    collections.value = marketCollections.value
    return collections.value
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
      saleLimit: d.saleLimit || 5,
      raw: d
    }
    return detail.value
  }

  // 寄售挂单（真实接口：GET /api/resale/listings?collectibleId=）
  const resaleOrders = ref([])
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
      cover: d.coverImage
    }))
    return { meta: resaleCollection.value || { name: d.title, coverImage: d.coverImage }, orders: resaleOrders.value }
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

  // 发售库存由后端锁定/释放（locked_quantity），前端不再本地维护
  function changeStock() { /* 已由后端管理：GET featured 即最新库存 */ }

  // 流通量同理由后端维护（circulate 字段）
  function changeCirculation() { /* 已由后端管理 */ }

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
    fetchFeatured,
    fetchMarket,
    fetchResaleCollection,
    fetchList,
    fetchDetail,
    fetchResale,
    getSaleStatus,
    getCountdownText,
    getFeaturedById,
    changeStock,
    changeCirculation,
    exhibits,
    fetchExhibits,
    fetchExhibit
  }
})
