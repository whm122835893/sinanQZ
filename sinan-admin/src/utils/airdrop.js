// ============================================================
// 空投活动：状态/类型映射 + 「批量发放」能不能点的唯一判定
// ============================================================
//
// 为什么单独拎出来（2026-10-11 第三批）：
//   缺陷单里那句「全量空投投不了、名单为 0」，根因是页面上用
//   v-if="row.status === 'active'" 把「批量发放」按钮直接藏掉了。
//   运营看到的是"这个页面没有发放功能"，而不是"现在还不能发，原因是 X"，
//   于是既报不出问题、也没法自己解决。
//   现在按钮常驻、置灰，原因写成一句人话放在 tooltip 上；
//   判定放这儿是为了能被单测钉住（这个仓库不装 @vue/test-utils，
//   留在 .vue 里就只能靠读源码猜）。
//   同时后端把「保存活动」改成自动圈一次资格名单，名单为 0 只可能是
//   真没人匹配 —— 所以那句提示也一并写进原因里。

export const AIRDROP_STATUS = {
  draft:  { label: '草稿', type: 'info' },
  active: { label: '进行中', type: 'success' },
  paused: { label: '已暂停', type: 'warning' },
  ended:  { label: '已结束', type: 'info' }
}

export const AIRDROP_TYPES = {
  direct:    { label: '全量直投', tag: 'primary', snapshottable: true, desc: '向全部有效用户（未删除、非黑名单）生成名单' },
  hold:      { label: '持有快照', tag: 'warning', snapshottable: true, desc: '向持有指定快照藏品的用户生成名单' },
  condition: { label: '条件筛选', tag: 'danger',  snapshottable: true, desc: '按手机尾号/注册时间/实名状态/持有藏品组合筛选生成名单' },
  checkin:   { label: '累计签到', tag: 'success', snapshottable: true, desc: '按累计签到天数圈定名单（生成时按阈值筛选）' },
  register:  { label: '注册行为', tag: 'info',    snapshottable: true, desc: '按活动起止时间内注册的新用户圈定名单' },
  login:     { label: '登录行为', tag: 'info',    snapshottable: true, desc: '按累计登录次数圈定名单' },
  invite:    { label: '邀请行为', tag: 'info',    snapshottable: true, desc: '按累计成功邀请人数圈定名单' }
}

/**
 * 「批量发放」当前不能点的原因；返回空串表示可以发。
 * 顺序即优先级：先说类型，再说状态，最后说名单 —— 运营按这个顺序操作就能解开。
 * @param {object} row 列表行（status / type / eligibleCount，均来自后端 camelize 后的字段）
 * @returns {string} 置灰原因文案（同时用作 tooltip 内容）
 */
export function issueBlockReason(row) {
  const type = AIRDROP_TYPES[row?.type]
  if (!type?.snapshottable) return '该资格类型不支持批量发放'
  if (row.status !== 'active') {
    const label = AIRDROP_STATUS[row.status]?.label || row.status || '未知'
    return `当前状态是「${label}」，只有「进行中」的活动能发放；点「编辑」把活动状态改成进行中再发`
  }
  if (!Number(row.eligibleCount)) {
    return '名单里没有待发放用户：保存活动时会自动圈一次名单，也可以点「生成名单」按当前条件重圈'
  }
  return ''
}
