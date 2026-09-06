// ============================================================
// v-permission 自定义指令
// 用法：v-permission="'user.freeze'" 或 v-permission="['user.freeze', 'user.blacklist']"
// 满足其一即显示；超级管理员（permissions 含 *）全部放行
// ============================================================

import { useAdminStore } from '@/stores/admin'

function check(el, binding) {
  const admin = useAdminStore()
  const required = Array.isArray(binding.value) ? binding.value : [binding.value]
  const owned = admin.permissions
  const ok = owned.includes('*') || required.some((p) => owned.includes(p))
  if (!ok && el.parentNode) el.parentNode.removeChild(el)
}

export default {
  install(app) {
    app.directive('permission', {
      mounted: check,
      updated: check
    })
  }
}
