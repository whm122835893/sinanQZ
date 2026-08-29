import { ref } from 'vue'
import { useUserStore } from '@/stores/user'
import router from '@/router'

// 全局登录提示弹窗单例：任何页面调用 requireLogin() 即可统一拦截未登录操作
const visible = ref(false)
let redirectAfter = null // 登录成功后需要回跳的路径

export function useLoginGate() {
  const user = useUserStore()

  // 已登录返回 true；未登录弹出全局登录提示弹窗并返回 false
  function requireLogin(redirect) {
    if (user.isLoggedIn) return true
    redirectAfter = redirect || null
    visible.value = true
    return false
  }

  function closeLoginModal() {
    visible.value = false
  }

  function goLogin() {
    visible.value = false
    const query = redirectAfter ? { redirect: redirectAfter } : {}
    router.push({ path: '/auth/login', query })
  }

  return { visible, requireLogin, closeLoginModal, goLogin }
}
