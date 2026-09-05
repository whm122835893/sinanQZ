// ============================================================================
// 动态权限路由表（P0 期实现范围；P1~P3 按模块扩充，命名遵循文档 9.2）
// meta 约定：title 菜单名 / icon 图标名 / permission 权限码 / hidden 不进菜单
// ============================================================================
import type { RouteRecordRaw } from 'vue-router'
import MainLayout from '@/layouts/MainLayout.vue'

export interface RouteMetaExtra {
  title?: string
  icon?: string
  permission?: string
  hidden?: boolean
  affix?: boolean
}

declare module 'vue-router' {
  interface RouteMeta extends RouteMetaExtra {}
}

export const DYNAMIC_ROUTES: RouteRecordRaw[] = [
  {
    path: '/',
    name: 'root',
    component: MainLayout,
    redirect: '/dashboard',
    children: [
      {
        path: 'dashboard',
        name: 'dashboard',
        component: () => import('@/views/dashboard/Index.vue'),
        meta: { title: '数据大盘', icon: 'Odometer', permission: 'dashboard:view', affix: true }
      },
      {
        path: 'user',
        name: 'user',
        component: () => import('@/views/user/Index.vue'),
        meta: { title: '用户管理', icon: 'User', permission: 'user:list' }
      },
      {
        path: 'user/:id',
        name: 'user-detail',
        component: () => import('@/views/user/Detail.vue'),
        meta: { title: '用户详情', permission: 'user:detail', hidden: true }
      },
      // ---------------- 实名认证管理（文档 9.2，只读） ----------------
      {
        path: 'realname',
        name: 'realname',
        component: () => import('@/views/realname/Index.vue'),
        meta: { title: '实名认证', icon: 'UserFilled', permission: 'realname:list' }
      },
      // ---------------- 藏品管理（文档 9.2） ----------------
      {
        path: 'collectible',
        name: 'collectible',
        component: () => import('@/views/collectible/Index.vue'),
        meta: { title: '藏品管理', icon: 'Collection', permission: 'collectible:list' }
      },
      {
        path: 'collectible/create',
        name: 'collectible-create',
        component: () => import('@/views/collectible/Create.vue'),
        meta: { title: '新建藏品', permission: 'collectible:create', hidden: true }
      },
      {
        path: 'collectible/:id',
        name: 'collectible-detail',
        component: () => import('@/views/collectible/Detail.vue'),
        meta: { title: '藏品详情', permission: 'collectible:detail', hidden: true }
      },
      {
        path: 'collectible/:id/edit',
        name: 'collectible-edit',
        component: () => import('@/views/collectible/Edit.vue'),
        meta: { title: '编辑藏品', permission: 'collectible:edit', hidden: true }
      },
      {
        path: 'collectible/:id/release',
        name: 'collectible-release',
        component: () => import('@/views/collectible/ReleaseConfig.vue'),
        meta: { title: '发售配置', permission: 'collectible:release', hidden: true }
      },
      {
        path: 'collectible/:id/quota',
        name: 'collectible-quota',
        component: () => import('@/views/collectible/QuotaConfig.vue'),
        meta: { title: '配额配置', permission: 'collectible:quota', hidden: true }
      },
      {
        path: 'collectible/:id/airdrop',
        name: 'collectible-airdrop',
        component: () => import('@/views/collectible/Airdrop.vue'),
        meta: { title: '独立空投', permission: 'collectible:airdrop', hidden: true }
      },
      {
        path: 'collectible/:id/destroy',
        name: 'collectible-destroy',
        component: () => import('@/views/collectible/Destroy.vue'),
        meta: { title: '销毁库存', permission: 'collectible:destroy', hidden: true }
      },
      {
        path: 'collectible/:id/audit',
        name: 'collectible-audit',
        component: () => import('@/views/collectible/Audit.vue'),
        meta: { title: '库存审计', permission: 'collectible:audit', hidden: true }
      },
      // ---------------- 盲盒管理（文档 9.2） ----------------
      {
        path: 'blindbox',
        name: 'blindbox',
        component: () => import('@/views/blindbox/Index.vue'),
        meta: { title: '盲盒管理', icon: 'Box', permission: 'blindbox:list' }
      },
      {
        path: 'blindbox/create',
        name: 'blindbox-create',
        component: () => import('@/views/blindbox/Create.vue'),
        meta: { title: '新建盲盒', permission: 'blindbox:create', hidden: true }
      },
      {
        path: 'blindbox/:id',
        name: 'blindbox-detail',
        component: () => import('@/views/blindbox/Detail.vue'),
        meta: { title: '盲盒详情', permission: 'blindbox:detail', hidden: true }
      },
      {
        path: 'blindbox/:id/edit',
        name: 'blindbox-edit',
        component: () => import('@/views/blindbox/Edit.vue'),
        meta: { title: '编辑盲盒', permission: 'blindbox:edit', hidden: true }
      },
      {
        path: 'blindbox/:id/items',
        name: 'blindbox-items',
        component: () => import('@/views/blindbox/ItemConfig.vue'),
        meta: { title: '子藏品与概率', permission: 'blindbox:config', hidden: true }
      },
      {
        path: 'blindbox/:id/release',
        name: 'blindbox-release',
        component: () => import('@/views/blindbox/ReleaseConfig.vue'),
        meta: { title: '发售配置', permission: 'blindbox:release', hidden: true }
      },
      {
        path: 'blindbox/:id/airdrop',
        name: 'blindbox-airdrop',
        component: () => import('@/views/blindbox/Airdrop.vue'),
        meta: { title: '独立空投', permission: 'blindbox:airdrop', hidden: true }
      },
      {
        path: 'blindbox/:id/destroy',
        name: 'blindbox-destroy',
        component: () => import('@/views/blindbox/Destroy.vue'),
        meta: { title: '销毁库存', permission: 'blindbox:destroy', hidden: true }
      },
      {
        path: 'blindbox/:id/audit',
        name: 'blindbox-audit',
        component: () => import('@/views/blindbox/Audit.vue'),
        meta: { title: '库存审计', permission: 'blindbox:audit', hidden: true }
      },
      // ---------------- 订单/退款管理（文档 9.2） ----------------
      {
        path: 'order',
        name: 'order',
        component: () => import('@/views/order/Index.vue'),
        meta: { title: '订单管理', icon: 'Document', permission: 'order:list' }
      },
      {
        path: 'order/abnormal',
        name: 'order-abnormal',
        component: () => import('@/views/order/Abnormal.vue'),
        meta: { title: '异常订单', permission: 'order:audit', hidden: true }
      },
      {
        path: 'order/:id',
        name: 'order-detail',
        component: () => import('@/views/order/Detail.vue'),
        meta: { title: '订单详情', permission: 'order:detail', hidden: true }
      },
      {
        path: 'refund',
        name: 'refund',
        component: () => import('@/views/refund/Index.vue'),
        meta: { title: '退款管理', icon: 'Money', permission: 'refund:list' }
      },
      {
        path: 'permission',
        name: 'permission',
        redirect: '/permission/admin',
        meta: { title: '权限管理', icon: 'Key' },
        children: [
          {
            path: 'admin',
            name: 'permission-admin',
            component: () => import('@/views/permission/Admins.vue'),
            meta: { title: '管理员账号', permission: 'permission:admin:list' }
          },
          {
            path: 'operation-log',
            name: 'permission-operation-log',
            component: () => import('@/views/permission/OperationLogs.vue'),
            meta: { title: '操作日志', permission: 'permission:log:list' }
          },
          {
            path: 'login-log',
            name: 'permission-login-log',
            component: () => import('@/views/permission/LoginLogs.vue'),
            meta: { title: '登录日志', permission: 'permission:log:login' }
          }
        ]
      }
    ]
  }
]
