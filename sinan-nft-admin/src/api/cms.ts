/**
 * 内容管理 API（轮播图/公告/协议/文物/站点装修）
 */
import { get, post, put, del } from './http'
import type { PageResult } from '@/utils/useListPage'

// ===== 轮播图 =====
export function fetchBanners(params?: Record<string, any>): Promise<PageResult> {
  return get<PageResult>('/cms/banners', params)
}

export function createBanner(data: Record<string, any>): Promise<any> {
  return post('/cms/banners', data)
}

export function updateBanner(id: number, data: Record<string, any>): Promise<any> {
  return put(`/cms/banners/${id}`, data)
}

export function deleteBanner(id: number): Promise<any> {
  return del(`/cms/banners/${id}`)
}

export function toggleBanner(id: number): Promise<any> {
  return post(`/cms/banners/${id}/toggle`)
}

// ===== 公告 =====
export function fetchAnnouncements(params?: Record<string, any>): Promise<PageResult> {
  return get<PageResult>('/cms/announcements', params)
}

export function createAnnouncement(data: Record<string, any>): Promise<any> {
  return post('/cms/announcements', data)
}

export function updateAnnouncement(id: number, data: Record<string, any>): Promise<any> {
  return put(`/cms/announcements/${id}`, data)
}

export function deleteAnnouncement(id: number): Promise<any> {
  return del(`/cms/announcements/${id}`)
}

export function toggleAnnouncementTop(id: number): Promise<any> {
  return post(`/cms/announcements/${id}/toggle-top`)
}

// ===== 协议 =====
export function fetchAgreements(): Promise<any[]> {
  return get<any[]>('/cms/agreements')
}

export function saveAgreement(key: string, data: Record<string, any>): Promise<any> {
  return put(`/cms/agreements/${key}`, data)
}

// ===== 文物展馆 =====
export function fetchArtifacts(params?: Record<string, any>): Promise<PageResult> {
  return get<PageResult>('/cms/artifacts', params)
}

export function createArtifact(data: Record<string, any>): Promise<any> {
  return post('/cms/artifacts', data)
}

export function updateArtifact(id: number, data: Record<string, any>): Promise<any> {
  return put(`/cms/artifacts/${id}`, data)
}

export function deleteArtifact(id: number): Promise<any> {
  return del(`/cms/artifacts/${id}`)
}

// ===== 站点装修 =====
export function fetchDecoration(): Promise<any> {
  return get('/cms/decoration')
}

export function saveDecoration(data: Record<string, any>): Promise<any> {
  return post('/cms/decoration', data)
}
