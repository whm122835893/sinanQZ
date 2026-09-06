/**
 * 营销活动 API（优先购/签到/邀请/抽奖/合成/空投/注册福利）
 */
import { get, post } from './http'
import type { PageResult } from '@/utils/useListPage'

// ===== 优先购 =====
export function fetchPriorityList(params?: Record<string, any>): Promise<PageResult> {
  return get<PageResult>('/marketing/priority', params)
}

export function savePriority(data: Record<string, any>): Promise<any> {
  return post('/marketing/priority', data)
}

// ===== 签到 =====
export function fetchCheckinConfig(): Promise<any> {
  return get('/marketing/checkin')
}

export function saveCheckinConfig(data: Record<string, any>): Promise<any> {
  return post('/marketing/checkin', data)
}

// ===== 邀请 =====
export function fetchInviteConfig(): Promise<any> {
  return get('/marketing/invite')
}

export function saveInviteConfig(data: Record<string, any>): Promise<any> {
  return post('/marketing/invite', data)
}

// ===== 抽奖 =====
export function fetchLuckyList(params?: Record<string, any>): Promise<PageResult> {
  return get<PageResult>('/marketing/lucky', params)
}

export function saveLucky(data: Record<string, any>): Promise<any> {
  return post('/marketing/lucky', data)
}

// ===== 合成 =====
export function fetchSynthesisList(params?: Record<string, any>): Promise<PageResult> {
  return get<PageResult>('/marketing/synthesis', params)
}

export function saveSynthesis(data: Record<string, any>): Promise<any> {
  return post('/marketing/synthesis', data)
}

// ===== 活动空投 =====
export function fetchAirdropList(params?: Record<string, any>): Promise<PageResult> {
  return get<PageResult>('/marketing/airdrop', params)
}

export function saveAirdrop(data: Record<string, any>): Promise<any> {
  return post('/marketing/airdrop', data)
}

export function issueAirdrop(data: Record<string, any>): Promise<any> {
  return post('/marketing/airdrop/issue', data)
}

// ===== 注册福利 =====
export function fetchRegisterConfig(): Promise<any> {
  return get('/marketing/register')
}

export function saveRegisterConfig(data: Record<string, any>): Promise<any> {
  return post('/marketing/register', data)
}
