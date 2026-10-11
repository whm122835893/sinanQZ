<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use think\facade\Db;

/**
 * C 端收件箱：到账弹窗数据源 + 标记已读
 *
 * - GET  /api/inbox/pending         未读到账通知（弹窗数据源；已读返回空）
 * - POST /api/inbox/:id/read        标记已读（关闭弹窗时调用）
 *
 * type 由 app/service/InboxService.php 统一归类：
 *   airdrop 空投 / transfer 转赠 / reward 活动奖励 —— 异步到账，保持未读等弹窗；
 *   blindbox 开盒 / lottery 抽奖中奖 —— 接口同页已弹结果，回传 inboxId 由前端就地标记，
 *   通知照写留档但不会被 15s 轮询再弹第二次。
 */
class Inbox extends BaseController
{
    /**
     * GET /api/inbox/pending
     * 返回当前用户所有未读 inbox 项，按 created_at 倒序
     */
    public function pending()
    {
        $userId = $this->userId();
        if (!$userId) return $this->fail(2001, '未登录');

        $rows = Db::name('inbox')
            ->where('user_id', $userId)
            ->where('status', 0)
            ->order('created_at', 'desc')
            ->limit(50)
            ->select()->toArray();

        $items = array_map(function ($r) {
            return [
                'id'            => (int) $r['id'],
                'type'          => $r['type'],
                'title'         => $r['title'],
                'name'          => $r['name'],
                'image'         => $r['image'],
                'createdAt'     => $r['created_at'],
                'extra'         => $r['extra'] ? json_decode((string) $r['extra'], true) : [],
            ];
        }, $rows);

        return $this->success(['list' => $items, 'count' => count($items)]);
    }

    /**
     * POST /api/inbox/:id/read  { confirmed?: 0|1 }
     * confirmed=1 表示用户点击了弹窗上的"我知道了/去查看"（弹窗关闭）
     */
    public function read()
    {
        $userId = $this->userId();
        if (!$userId) return $this->fail(2001, '未登录');

        $id = $this->request->param('id');
        if (!$id) return $this->fail(1001, '参数不正确');

        $confirmed = (int) $this->request->post('confirmed', 1);

        $now = date('Y-m-d H:i:s.v');
        $update = ['status' => $confirmed ? 2 : 1, 'read_at' => $now];
        if ($confirmed) $update['confirmed_at'] = $now;

        Db::name('inbox')
            ->where('id', $id)
            ->where('user_id', $userId)
            ->where('status', 0)
            ->update($update);

        return $this->success(null, 'ok');
    }
}
