/**
 * 系统配置 API（全局参数/支付渠道/短信/安全策略）
 */
import { get, post, put } from './http'

// ===== 全局参数 =====
export function fetchConfigs(): Promise<any[]> {
  return get<any[]>('/system/configs')
}

export function saveConfig(key: string, configValue: string, description?: string): Promise<any> {
  return put(`/system/configs/${key}`, { config_value: configValue, description })
}

// ===== 支付渠道（第三方钱包配置）=====
export function fetchPaymentChannels(): Promise<any[]> {
  return get<any[]>('/system/payment-channels')
}

export function savePaymentChannel(id: number, data: Record<string, any>): Promise<any> {
  return put(`/system/payment-channels/${id}`, data)
}

// ===== 短信配置 =====
export function fetchSmsConfig(): Promise<any> {
  return get('/system/sms-config')
}

export function saveSmsConfig(data: Record<string, any>): Promise<any> {
  return put('/system/sms-config', data)
}

export function sendSmsTest(phone: string): Promise<any> {
  return post('/system/sms-config/test', { phone })
}

// ===== 安全策略 =====
export function fetchSecurityConfig(): Promise<any> {
  return get('/system/security-config')
}

export function saveSecurityConfig(key: string, value: string): Promise<any> {
  return put(`/system/security-config/${key}`, { value })
}
