<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\service\RaffleService;
use think\facade\Db;

/**
 * 抽签发售管理（admin/raffle:*）
 */
class RaffleController extends BaseController
{
    /**
     * GET /admin/raffle/list
     *
     * @openapi({"_path":"/admin/raffle","get":{"summary":"抽签活动列表","tags":["Raffle"],"security":[{"BearerAuth":[]}],"parameters":[{"name":"page","in":"query","schema":{"type":"integer","default":1}},{"name":"pageSize","in":"query","schema":{"type":"integer","default":20}},{"name":"status","in":"query","schema":{"type":"integer","enum":[0,1,2,3,4]}}]}})
     */
    public function list()
    {
        [$page, $pageSize] = $this->pageParams();

        $query = Db::name('raffle_activities')->alias('ra')->whereNull('ra.deleted_at')
            ->join('collectibles c', 'c.id = ra.collectible_id', 'LEFT')
            ->field('ra.*, c.name AS collectible_name, c.image AS collectible_image');

        $keyword = trim((string) $this->request->param('keyword', ''));
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('ra.name', "%{$keyword}%")->whereOrLike('c.name', "%{$keyword}%");
            });
        }
        $status = $this->enumParam('status', ['0','1','2','3','4']);
        if ($status !== null) $query->where('ra.status', (int) $status);
        $collectibleId = $this->positiveInt('collectibleId');
        if ($collectibleId !== null) $query->where('ra.collectible_id', $collectibleId);

        $total = (clone $query)->count();
        $rows = $query->order('ra.id', 'desc')->page($page, $pageSize)->select()->toArray();

        foreach ($rows as &$row) {
            $row['registration_count'] = Db::name('raffle_registrations')->where('activity_id', $row['id'])->count();
            $row['winner_count_done'] = Db::name('raffle_registrations')->where('activity_id', $row['id'])->where('draw_status', 1)->count();
        }

        return $this->paginate(camelize_keys($rows), $total, $page, $pageSize);
    }

    /**
     * GET /admin/raffle/detail/:id
     */
    public function detail()
    {
        $id = $this->positiveInt('id');
        if ($id === null) return $this->failMissing(['id']);

        $row = Db::name('raffle_activities')->alias('ra')->where('ra.id', $id)
            ->whereNull('ra.deleted_at')
            ->join('collectibles c', 'c.id = ra.collectible_id', 'LEFT')
            ->field('ra.*, c.name AS collectible_name, c.image AS collectible_image, c.price AS collectible_price')
            ->find();
        if (!$row) return $this->fail(4040, '活动不存在');

        $row['registrations'] = Db::name('raffle_registrations')
            ->where('activity_id', $id)
            ->join('users u', 'u.id = raffle_registrations.user_id', 'LEFT')
            ->field('raffle_registrations.*, u.username, u.phone')
            ->order('id', 'desc')
            ->select()->toArray();

        return $this->success(camelize_keys($row));
    }

    /**
     * POST /admin/raffle/save  新建 or 编辑
     */
    public function save()
    {
        $missing = $this->missingParams(['collectibleId', 'name', 'winnerCount', 'registrationStart', 'registrationEnd', 'drawTime', 'salePrice']);
        if ($missing) return $this->failMissing($missing);

        try {
            $result = RaffleService::saveActivity($this->adminId(), $this->request->param());
            $this->audit('抽签发售', 'save', '保存抽签活动', $result);
            return $this->success($result);
        } catch (\Throwable $e) {
            return $this->fail(4220, $e->getMessage());
        }
    }

    /**
     * POST /admin/raffle/:id/start  手动开启报名
     */
    public function start()
    {
        $id = $this->positiveInt('id');
        if ($id === null) return $this->failMissing(['id']);
        RaffleService::changeStatus($id, 1);
        $this->audit('抽签发售', 'start', '开启报名', ['id' => $id]);
        return $this->success(['id' => $id]);
    }

    /**
     * POST /admin/raffle/:id/draw  手动触发抽签
     */
    public function draw()
    {
        $id = $this->positiveInt('id');
        if ($id === null) return $this->failMissing(['id']);
        try {
            RaffleService::changeStatus($id, 2);
            $result = RaffleService::draw($id);
            $this->audit('抽签发售', 'draw', '执行抽签', ['id' => $id, 'winners' => count($result)]);
            return $this->success(['winners' => $result, 'count' => count($result)]);
        } catch (\Throwable $e) {
            return $this->fail(5000, $e->getMessage());
        }
    }

    /**
     * POST /admin/raffle/:id/cancel
     */
    public function cancel()
    {
        $id = $this->positiveInt('id');
        if ($id === null) return $this->failMissing(['id']);
        RaffleService::changeStatus($id, 4);
        $this->audit('抽签发售', 'cancel', '取消抽签活动', ['id' => $id]);
        return $this->success(['id' => $id]);
    }

    /**
     * DELETE /admin/raffle/:id  软删除
     */
    public function delete()
    {
        $id = $this->positiveInt('id');
        if ($id === null) return $this->failMissing(['id']);
        Db::name('raffle_activities')->where('id', $id)->update(['deleted_at' => date('Y-m-d H:i:s')]);
        $this->audit('抽签发售', 'delete', '删除活动', ['id' => $id]);
        return $this->success(['id' => $id]);
    }
}
