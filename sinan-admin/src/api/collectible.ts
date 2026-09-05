// ============================================================================
// 藏品管理接口（文档 8.6，18 个，#33~#50）
// 高风险操作统一走 PasswordVerify 弹窗注入 password 字段（文档 11.1）
// ============================================================================
import { get, post, put, del } from '@/utils/request'
import type {
  AirdropRecordRow,
  AirdropResult,
  CollectibleDetail,
  CollectibleRow,
  DestroyRecordRow,
  InventoryAuditResult,
  PageData,
  QuotaRow
} from '@/types/api'

export interface CollectibleQuery {
  page?: number
  pageSize?: number
  name?: string
  status?: string
  categoryId?: number
  isResaleable?: string
  isTransferable?: string
  qualification?: string
}

/** #33 藏品列表 */
export function fetchCollectibles(params: CollectibleQuery): Promise<PageData<CollectibleRow>> {
  return get<PageData<CollectibleRow>>('/collectibles', params as Record<string, unknown>)
}

/** #34 创建藏品（草稿） */
export function createCollectible(data: Record<string, unknown>): Promise<{ id: number }> {
  return post<{ id: number }>('/collectibles', data)
}

/** #35 藏品详情（含库存五数/配额/资格购） */
export function fetchCollectibleDetail(id: number | string): Promise<CollectibleDetail> {
  return get<CollectibleDetail>(`/collectibles/${id}`)
}

/** #36 编辑藏品基础信息（仅 draft） */
export function updateCollectible(id: number | string, data: Record<string, unknown>): Promise<null> {
  return put<null>(`/collectibles/${id}`, data)
}

/** #37 发售配置（price/onsaleAt/offSaleAt/perUserLimit/releaseQuantity） */
export function releaseCollectible(
  id: number | string,
  data: {
    price: string
    onsaleAt: string
    offSaleAt?: string
    releaseQuantity?: number | null
    perUserLimit?: number
  }
): Promise<{ status: string }> {
  return post<{ status: string }>(`/collectibles/${id}/release`, data as unknown as Record<string, unknown>)
}

/** #38 配额配置（quotas[]，校验库存池） */
export function configQuotas(
  id: number | string,
  quotas: Array<Record<string, unknown>>
): Promise<null> {
  return post<null>(`/collectibles/${id}/quotas`, { quotas })
}

/** #39 修改配额（planned ≥ used，password） */
export function updateQuota(
  id: number | string,
  quotaId: number | string,
  data: {
    quotaName?: string
    plannedQuantity?: number
    status?: number
    remark?: string
    password: string
  }
): Promise<null> {
  return put<null>(`/collectibles/${id}/quotas/${quotaId}`, data as unknown as Record<string, unknown>)
}

/** #40 重新上架（releaseQuantity ≤ 库存池，password） */
export function relistCollectible(
  id: number | string,
  data: { releaseQuantity: number; onsaleAt?: string; password: string }
): Promise<{ status: string; stockPool: number }> {
  return post<{ status: string; stockPool: number }>(`/collectibles/${id}/relist`, data as unknown as Record<string, unknown>)
}

/** #41 强制售罄（reason + password，不清零计数器） */
export function forceSoldoutCollectible(
  id: number | string,
  data: { reason: string; password: string }
): Promise<null> {
  return post<null>(`/collectibles/${id}/force-soldout`, data as unknown as Record<string, unknown>)
}

/** #42 销毁库存（quantity ≤ 库存池 + reason + password，不可逆） */
export function destroyCollectible(
  id: number | string,
  data: { quantity: number; reason: string; password: string }
): Promise<{ destroyed: number; stockPoolAfter: number }> {
  return post<{ destroyed: number; stockPoolAfter: number }>(`/collectibles/${id}/destroy`, data as unknown as Record<string, unknown>)
}

/** #43 删除藏品（仅草稿无关联，password） */
export function deleteCollectible(id: number | string, password: string): Promise<null> {
  return del<null>(`/collectibles/${id}`, { password })
}

/** #44 独立空投（quantity + phones[] + password） */
export function airdropCollectible(
  id: number | string,
  data: { quantity: number; phones: string[] | string; reason?: string; password: string }
): Promise<AirdropResult> {
  return post<AirdropResult>(`/collectibles/${id}/airdrop`, data as unknown as Record<string, unknown>)
}

/** #45 寄售开关（isResaleable + reason + password，关闭联动强制下架） */
export function toggleResale(
  id: number | string,
  data: { isResaleable: number; reason: string; password: string }
): Promise<{ isResaleable: boolean; delistedListings: number }> {
  return post<{ isResaleable: boolean; delistedListings: number }>(`/collectibles/${id}/resale-toggle`, data as unknown as Record<string, unknown>)
}

/** #46 寄售价格管控（password） */
export function priceControl(
  id: number | string,
  data: {
    resalePriceMode: number
    resalePriceMin?: string | null
    resalePriceMax?: string | null
    reason: string
    password: string
  }
): Promise<null> {
  return post<null>(`/collectibles/${id}/price-control`, data as unknown as Record<string, unknown>)
}

/** #47 资格购配置（条件组合/有效期/白名单手机号） */
export function configQualification(
  id: number | string,
  data: Record<string, unknown>
): Promise<null> {
  return post<null>(`/collectibles/${id}/qualification`, data)
}

/** #48 库存审计（守恒校验，文档 4.3.1） */
export function fetchCollectibleAudit(id: number | string): Promise<InventoryAuditResult> {
  return get<InventoryAuditResult>(`/collectibles/${id}/audit`)
}

/** #49 空投发放记录 */
export function fetchAirdropRecords(
  id: number | string,
  params?: { page?: number; pageSize?: number; status?: string; source?: string }
): Promise<PageData<AirdropRecordRow>> {
  return get<PageData<AirdropRecordRow>>(`/collectibles/${id}/airdrop-records`, params as Record<string, unknown>)
}

/** #50 销毁记录 */
export function fetchDestroyRecords(
  id: number | string,
  params?: { page?: number; pageSize?: number }
): Promise<PageData<DestroyRecordRow>> {
  return get<PageData<DestroyRecordRow>>(`/collectibles/${id}/destroy-records`, params as Record<string, unknown>)
}

/** 配额行类型别名（页面直接引用） */
export type { QuotaRow }
