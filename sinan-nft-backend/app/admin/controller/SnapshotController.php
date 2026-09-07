<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\admin\service\SnapshotService;
use think\facade\Db;

/**
 * 数据快照（admin/report:snapshot）：用户持仓快照 + 交易快照，手动触发、幂等重跑
 */
class SnapshotController extends BaseController
{
    /**
     * POST /admin/snapshots/generate
     * body: { date?: 'Y-m-d'（默认今天）, userId?: int（指定用户，缺省=全部用户） }
     */
    public function generate()
    {
        $date = trim((string) $this->request->param('date', date('Y-m-d')));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $this->fail(4220, '日期格式需为 Y-m-d');
        }
        if (strtotime($date) === false) {
            return $this->fail(4220, '日期不合法');
        }
        $userId = $this->positiveInt('userId');

        try {
            $result = (new SnapshotService())->generate($date, $userId);
        } catch (\InvalidArgumentException $e) {
            return $this->fail(4220, $e->getMessage());
        } catch (\Throwable $e) {
            return $this->fail(5000, '快照生成失败：' . $e->getMessage());
        }

        return $this->success([
            'date'        => $date,
            'userId'      => $userId,
            'holdings'    => $result['holdings'],  // 持仓快照行数（用户×藏品）
            'trades'      => $result['trades'],    // 交易快照行数（当日有交易的用户数）
            'generatedAt' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * GET /admin/snapshots/holdings 持仓快照列表（page/pageSize/date/userId/collectibleId）
     */
    public function holdings()
    {
        [$page, $pageSize] = $this->pageParams();

        $query = Db::name('holdings_snapshots')->alias('hs')
            ->join('users u', 'u.id = hs.user_id', 'LEFT')
            ->field('hs.*, u.username AS user_name, u.phone AS user_phone');

        $date = trim((string) $this->request->param('date', ''));
        if ($date !== '') $query->where('hs.snapshot_date', $date);
        $userId = $this->positiveInt('userId');
        if ($userId !== null) $query->where('hs.user_id', $userId);
        $collectibleId = $this->positiveInt('collectibleId');
        if ($collectibleId !== null) $query->where('hs.collectible_id', $collectibleId);

        $total = (clone $query)->count();
        $rows = $query->order('hs.snapshot_date', 'desc')
            ->order('hs.user_id', 'asc')->order('hs.total_count', 'desc')
            ->page($page, $pageSize)->select()->toArray();

        return $this->paginate(camelize_keys($rows), $total, $page, $pageSize);
    }

    /**
     * GET /admin/snapshots/trades 交易快照列表（page/pageSize/date/userId）
     */
    public function trades()
    {
        [$page, $pageSize] = $this->pageParams();

        $query = Db::name('trade_snapshots')->alias('ts')
            ->join('users u', 'u.id = ts.user_id', 'LEFT')
            ->field('ts.*, u.username AS user_name, u.phone AS user_phone');

        $date = trim((string) $this->request->param('date', ''));
        if ($date !== '') $query->where('ts.snapshot_date', $date);
        $userId = $this->positiveInt('userId');
        if ($userId !== null) $query->where('ts.user_id', $userId);

        $total = (clone $query)->count();
        $rows = $query->order('ts.snapshot_date', 'desc')
            ->order('ts.user_id', 'asc')
            ->page($page, $pageSize)->select()->toArray();

        return $this->paginate(camelize_keys($rows), $total, $page, $pageSize);
    }

    /**
     * GET /admin/snapshots/dates 已生成快照的日期清单（前端日期快捷选择）
     */
    public function dates()
    {
        $rows = Db::name('holdings_snapshots')->field('snapshot_date, COUNT(*) AS holdings')
            ->group('snapshot_date')->order('snapshot_date', 'desc')->limit(90)->select()->toArray();
        $trades = Db::name('trade_snapshots')->field('snapshot_date, COUNT(*) AS trades')
            ->group('snapshot_date')->order('snapshot_date', 'desc')->limit(90)->select()->toArray();

        $map = [];
        foreach ($rows as $r) $map[$r['snapshot_date']] = ['date' => $r['snapshot_date'], 'holdings' => (int) $r['holdings'], 'trades' => 0];
        foreach ($trades as $r) {
            if (isset($map[$r['snapshot_date']])) {
                $map[$r['snapshot_date']]['trades'] = (int) $r['trades'];
            } else {
                $map[$r['snapshot_date']] = ['date' => $r['snapshot_date'], 'holdings' => 0, 'trades' => (int) $r['trades']];
            }
        }

        $list = array_values($map);
        usort($list, fn ($a, $b) => strcmp($b['date'], $a['date']));

        return $this->success($list);
    }
}
