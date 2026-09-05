// ============================================================================
// v-permission 指令：按钮级权限控制
// 用法：v-permission="'user:freeze'" 或 v-permission="['user:freeze','user:manage']"
// 无权限时移除元素（比 disabled 更严格，文档 7.2）
// ============================================================================
import type { Directive, DirectiveBinding } from 'vue'
import { useAdminStore } from '@/stores/admin'

type PermValue = string | string[]

function check(el: HTMLElement, binding: DirectiveBinding<PermValue>): void {
  const adminStore = useAdminStore()
  const value = binding.value
  if (!value) return

  const required = Array.isArray(value) ? value : [value]
  // 满足任一即通过
  const ok = required.some((perm) => adminStore.permissions.includes(perm))
  if (!ok && el.parentNode) {
    el.parentNode.removeChild(el)
  }
}

export const permission: Directive<HTMLElement, PermValue> = {
  mounted(el, binding) {
    check(el, binding)
  },
  updated(el, binding) {
    check(el, binding)
  }
}

export function setupPermissionDirective(app: { directive: (name: string, directive: unknown) => void }): void {
  app.directive('permission', permission)
}

/** 命令式判断（模板 v-if 场景） */
export function hasPermission(perm: string | string[]): boolean {
  const adminStore = useAdminStore()
  const required = Array.isArray(perm) ? perm : [perm]
  return required.some((p) => adminStore.permissions.includes(p))
}
