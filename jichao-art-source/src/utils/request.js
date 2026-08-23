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
    // 实际接入后端时在此统一 Toast
    return Promise.reject(error)
  }
)

export default request
