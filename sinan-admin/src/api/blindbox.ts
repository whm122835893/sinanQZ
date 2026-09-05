// ============================================================================
// 盲盒管理接口（文档 8.7，17 个，#51~#67）
// 注意：#55 items[] 子项键为 snake_case（collectible_id / planned_quantity）
// ============================================================================
import { get, post, put, del } from '@/utils/request'
import type {
  AirdropResult,
  BlindboxAuditResult,
  BlindboxDetail,
  BlindboxOpenRecordRow,
  BlindboxRow,
  DestroyRecordRow,
  PageData,
  RecoverRevert
} from '@/types/api'

export interface BlindboxQuery {
  page?: number
  pageSize?: number
  name?: string
  status?: string
  isOpenable?: string
}

/** #51 盲盒列表（展示盲盒发行量与流通量） */
export function fetchBlindboxes(params: BlindboxQuery): Promise<PageData<BlindboxRow>> {
  return get<PageData<BlindboxRow>>('/blindboxes', params as Record<string, unknown>)
}

/** #52 创建盲盒（草稿；产出藏品行 + 盲盒扩展行） */
export function createBlindbox(data: Record<string, unknown>): Promise<{ id: number; collectibleId: number }> {
  return post<{ id: number; collectibleId: number }>('/blindboxes', data)
}

/** #53 盲盒详情（含子藏品与概率、库存五数） */
export function fetchBlindboxDetail(id: number | string): Promise<BlindboxDetail> {
  return get<BlindboxDetail>(`/blindboxes/${id}`)
}

/** #54 编辑盲盒（仅 draft/off） */
export function updateBlindbox(id: number | string, data: Record<string, unknown>): Promise<null> {
  return put<null>(`/blindboxes/${id}`, data)
}

/** #55 配置子藏品（items[]，概率总和 ≤ 100%，password） */
export function configBlindboxItems(
  id: number | string,
  data: {
    items: Array<{ collectible_id: number; probability: number; planned_quantity?: number | null }>
    password: string
  }
): Promise<{ items: BlindboxDetail['items']; probabilitySum: number }> {
  return post<{ items: BlindboxDetail['items']; probabilitySum: number }>(`/blindboxes/${id}/items`, data as unknown as Record<string, unknown>)
}

/** #56 修改子藏品（概率/数量，password） */
export function updateBlindboxItem(
  id: number | string,
  itemId: number | string,
  data: { probability?: number; planned_quantity?: number | null; password: string }
): Promise<null> {
  return put<null>(`/blindboxes/${id}/items/${itemId}`, data as unknown as Record<string, unknown>)
}

/** #57 删除子藏品（仅 draft/off 且未发放，无密码要求） */
export function deleteBlindboxItem(
  id: number | string,
  itemId: number | string
): Promise<null> {
  return del<null>(`/blindboxes/${id}/items/${itemId}`)
}

/** #58 发售配置 */
export function releaseBlindbox(
  id: number | string,
  data: {
    price: string
    onsaleAt: string
    offSaleAt?: string
    releaseQuantity?: number | null
    perUserLimit?: number
  }
): Promise<{ status: string }> {
  return post<{ status: string }>(`/blindboxes/${id}/release`, data as unknown as Record<string, unknown>)
}

/** #59 重新上架（≤ 盲盒库存池，password） */
export function relistBlindbox(
  id: number | string,
  data: { releaseQuantity: number; onsaleAt?: string; password: string }
): Promise<{ status: string; stockPool: number }> {
  return post<{ status: string; stockPool: number }>(`/blindboxes/${id}/relist`, data as unknown as Record<string, unknown>)
}

/** #60 强制售罄（reason + password，不清零计数器） */
export function forceSoldoutBlindbox(
  id: number | string,
  data: { reason: string; password: string }
): Promise<null> {
  return post<null>(`/blindboxes/${id}/force-soldout`, data as unknown as Record<string, unknown>)
}

/** #61 销毁盲盒库存（quantity ≤ 库存池 + password，不可逆） */
export function destroyBlindbox(
  id: number | string,
  data: { quantity: number; reason: string; password: string }
): Promise<{ destroyed: number; stockPoolAfter: number }> {
  return post<{ destroyed: number; stockPoolAfter: number }>(`/blindboxes/${id}/destroy`, data as unknown as Record<string, unknown>)
}

/** #62 删除盲盒（仅草稿无关联，password） */
export function deleteBlindbox(id: number | string, password: string): Promise<null> {
  return del<null>(`/blindboxes/${id}`, { password })
}

/** #63 独立空投盲盒（quantity + phones[] + password） */
export function airdropBlindbox(
  id: number | string,
  data: { quantity: number; phones: string[] | string; reason?: string; password: string }
): Promise<AirdropResult> {
  return post<AirdropResult>(`/blindboxes/${id}/airdrop`, data as unknown as Record<string, unknown>)
}

/** #64 强制回收盲盒（userBlindboxId + reason + password，校验未开启） */
export function recoverBlindbox(
  id: number | string,
  data: { userBlindboxId: number; reason: string; password: string }
): Promise<{ revert: RecoverRevert }> {
  return post<{ revert: RecoverRevert }>(`/blindboxes/${id}/recover`, data as unknown as Record<string, unknown>)
}

/** #65 开盒记录 */
export function fetchOpenRecords(
  id: number | string,
  params?: { page?: number; pageSize?: number }
): Promise<PageData<BlindboxOpenRecordRow>> {
  return get<PageData<BlindboxOpenRecordRow>>(`/blindboxes/${id}/open-records`, params as Record<string, unknown>)
}

/** #66 盲盒库存审计（守恒校验） */
export function fetchBlindboxAudit(id: number | string): Promise<BlindboxAuditResult> {
  return get<BlindboxAuditResult>(`/blindboxes/${id}/audit`)
}

/** #67 盲盒销毁记录 */
export function fetchBlindboxDestroyRecords(
  id: number | string,
  params?: { page?: number; pageSize?: number }
): Promise<PageData<DestroyRecordRow>> {
  return get<PageData<DestroyRecordRow>>(`/blindboxes/${id}/destroy-records`, params as Record<string, unknown>)
}
