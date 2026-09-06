/**
 * 藏品 / 盲盒 API
 */
import { get, post, put, del } from './http'
import type { PageResult } from '@/utils/useListPage'

// ===== 藏品 =====
export function fetchCollectibles(params: Record<string, any>): Promise<PageResult> {
  return get<PageResult>('/collectibles', params)
}

export function fetchCollectibleDetail(id: number): Promise<any> {
  return get(`/collectibles/${id}`)
}

export function createCollectible(data: Record<string, any>): Promise<any> {
  return post('/collectibles', data)
}

export function updateCollectible(id: number, data: Record<string, any>): Promise<any> {
  return put(`/collectibles/${id}`, data)
}

export function releaseCollectible(id: number, data: Record<string, any>): Promise<any> {
  return post(`/collectibles/${id}/release`, data)
}

export function saveCollectibleQuota(id: number, data: Record<string, any>): Promise<any> {
  return post(`/collectibles/${id}/quota`, data)
}

export function manageCollectible(id: number, data: Record<string, any>): Promise<any> {
  return post(`/collectibles/${id}/manage`, data)
}

export function destroyCollectibleStock(id: number, data: Record<string, any>): Promise<any> {
  return post(`/collectibles/${id}/destroy`, data)
}

export function deleteCollectible(id: number): Promise<any> {
  return del(`/collectibles/${id}`)
}

export function airdropCollectible(data: Record<string, any>): Promise<any> {
  return post('/collectibles/airdrop', data)
}

export function saveMarketConfig(id: number, data: Record<string, any>): Promise<any> {
  return put(`/collectibles/${id}/market-config`, data)
}

export function saveQualification(id: number, data: Record<string, any>): Promise<any> {
  return post(`/collectibles/${id}/qualification`, data)
}

export function fetchCollectibleAudit(): Promise<any> {
  return get('/collectibles/audit')
}

// ===== 盲盒 =====
export function fetchBlindBoxes(params: Record<string, any>): Promise<PageResult> {
  return get<PageResult>('/blind-boxes', params)
}

export function fetchBlindBoxDetail(id: number): Promise<any> {
  return get(`/blind-boxes/${id}`)
}

export function createBlindBox(data: Record<string, any>): Promise<any> {
  return post('/blind-boxes', data)
}

export function updateBlindBox(id: number, data: Record<string, any>): Promise<any> {
  return put(`/blind-boxes/${id}`, data)
}

export function saveBlindBoxConfig(id: number, data: Record<string, any>): Promise<any> {
  return put(`/blind-boxes/${id}/config`, data)
}

export function releaseBlindBox(id: number, data: Record<string, any>): Promise<any> {
  return post(`/blind-boxes/${id}/release`, data)
}

export function manageBlindBox(id: number, data: Record<string, any>): Promise<any> {
  return post(`/blind-boxes/${id}/manage`, data)
}

export function destroyBlindBoxStock(id: number, data: Record<string, any>): Promise<any> {
  return post(`/blind-boxes/${id}/destroy`, data)
}

export function airdropBlindBox(data: Record<string, any>): Promise<any> {
  return post('/blind-boxes/airdrop', data)
}

export function fetchBlindBoxAudit(): Promise<any> {
  return get('/blind-boxes/audit')
}
