import axios from 'axios'

// Axios 封装：请求拦截注入 Token，响应拦截统一处理
const request = axios.create({
  baseURL: import.meta.env.VITE_API_BASE || '/api',
  timeout: 15000
})

// 请求拦截：注入 Token
request.interceptors.request.use(
  (config) => {
    const token = localStorage.getItem('jc_token')
    if (token) {
      config.headers.Authorization = `Bearer ${token}`
    }
    return config
  },
  (error) => Promise.reject(error)
)

// 响应拦截：统一解包与错误提示
request.interceptors.response.use(
  (response) => {
    const data = response.data
    if (data && data.code !== undefined && data.code !== 0 && data.code !== 200) {
      return Promise.reject(new Error(data.message || '请求失败'))
    }
    return data?.data !== undefined ? data.data : data
  },
  (error) => {
    // 统一将错误信息转为中文
    let message = '请求失败，请稍后重试'
    if (error.response) {
      // 有响应但状态码非 2xx
      const data = error.response.data
      if (data && data.message) {
        message = data.message
      } else {
        const map = {
          400: '请求参数错误',
          401: '登录已过期，请重新登录',
          403: '无访问权限',
          404: '请求的资源不存在',
          422: '参数校验失败',
          429: '请求过于频繁，请稍后再试',
          500: '服务器内部错误',
          502: '网关错误',
          503: '服务暂不可用',
          504: '网关超时'
        }
        message = map[error.response.status] || `请求失败（${error.response.status}）`
      }
    } else if (error.code === 'ECONNABORTED') {
      message = '请求超时，请检查网络后重试'
    } else if (error.message?.includes('Network Error')) {
      message = '网络连接异常，请检查网络或稍后重试'
    }
    return Promise.reject(new Error(message))
  }
)

export default request
