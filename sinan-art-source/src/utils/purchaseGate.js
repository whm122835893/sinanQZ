import { useUserStore } from '@/stores/user'
import router from '@/router'
import { showToast } from 'vant'

// ============================================================
// 购买 / 交易密码的前置拦截时机
// ------------------------------------------------------------
// 这两个闸门以前都堆在最后一步：用户填完 6 位支付密码提交，才被后端一句
// 「请先完成实名认证 / 请先设置交易密码」打回。体验上等于让人白输一遍密码，
// 而且密码键盘一旦弹出，用户会以为「能输密码就说明我资格没问题」。
//
// 约定（与产品口径一致）：
//   实名认证   → 在「点击购买」时就拦，直接把用户带去实名认证页；
//   交易密码   → 只在「进入密码输入」那一步拦，因为只想看看价格的人不该
//                在买之前就被催着设置操作密码。
//
// 两个函数都可能 await 一次 /user/profile（本地缓存为 false 时先跟服务端确认），
// 期间用模块级 busy 挡住连点，避免重复 push 同一个设置页。
//
// 前端拦截只是体验层，后端 Orders::create 仍是最终校验（并已把实名校验提到
// 交易密码之前，保证缓存过时时两边的报错顺序和这里一致）。
// ============================================================

let busy = false

async function once(check) {
  if (busy) return false
  busy = true
  try {
    return await check()
  } finally {
    busy = false
  }
}

// 点购买时按实名审核状态给不同说法，避免「明明提交了却还说我没认证」的困惑
function realnameTip(status) {
  if (status === 1) return '实名认证审核中，通过后方可购买'
  if (status === 3) return '实名认证未通过，请重新提交认证'
  return '请先完成实名认证'
}

/**
 * 购买前置：实名认证。
 * @returns {Promise<boolean>} true 才可继续购买；false 时已提示并跳转实名认证页
 */
export function ensureRealname() {
  return once(async () => {
    const user = useUserStore()
    if (user.userInfo.isRealName) return true
    // 缓存可能是后台过审前的旧值：拦下来之前先跟服务端确认一次
    await user.refreshQuietly()
    if (user.userInfo.isRealName) return true
    showToast(realnameTip(user.userInfo.realnameStatus))
    router.push('/user/realname')
    return false
  })
}

/**
 * 密码前置：是否已设置交易（操作）密码。
 * @returns {Promise<boolean>} true 才可弹出密码键盘；false 时已提示并跳转设置页
 */
export function ensureTradePassword() {
  return once(async () => {
    const user = useUserStore()
    if (user.userInfo.hasTransactionPassword) return true
    // 登录接口不返回这个字段，刚设置完也可能还是 false，同样先拉一次再拦
    await user.refreshQuietly()
    if (user.userInfo.hasTransactionPassword) return true
    showToast('请先设置操作密码')
    router.push('/auth/op-pwd')
    return false
  })
}
