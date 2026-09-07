<?php
declare(strict_types=1);

namespace app\admin\controller;

use think\facade\Db;

/**
 * 分解/熔炼管理（admin/decompose:*）
 *
 * 业务：指定藏品可分解为指定藏品 1~N 个（B 端配置分解公式）
 */
class DecomposeController extends BaseController
{
    /**
     * GET /admin/decompose/rules
     */
    public function ruleList()
    {
        [$page, $pageSize] = $this->pageParams();

        $query = Db::name('decompose_rules')->alias('dr')->whereNull('dr.deleted_at')
            ->join('collectibles c', 'c.id = dr.source_collectible_id', 'LEFT')
            ->field('dr.*, c.name AS source_name, c.image AS source_image');

        $keyword = trim((string) $this->request->param('keyword', ''));
        if ($keyword !== '') {
            $query->whereLike('dr.name', "%{$keyword}%");
        }
        $enabled = $this->enumParam('enabled', ['0', '1']);
        if ($enabled !== null) $query->where('dr.enabled', (int) $enabled);

        $total = (clone $query)->count();
        $rows = $query->order('dr.id', 'desc')->page($page, $pageSize)->select()->toArray();

        foreach ($rows as &$row) {
            $row['items'] = Db::name('decompose_items')->alias('di')
                ->join('collectibles c2', 'c2.id = di.result_collectible_id', 'LEFT')
                ->field('di.id, di.quantity_per AS quantityPer, c2.name, c2.image, c2.id AS result_collectible_id')
                ->where('di.rule_id', $row['id'])
                ->select()->toArray();
        }

        return $this->paginate(camelize_keys($rows), $total, $page, $pageSize);
    }

    /**
     * POST /admin/decompose/rules  新建 / 编辑规则
     */
    public function ruleSave()
    {
        $missing = $this->missingParams(['sourceCollectibleId', 'name', 'items']);
        if ($missing) return $this->failMissing($missing);

        $sourceId = (int) $this->request->param('sourceCollectibleId');
        $items = $this->request->param('items', []); // [{collectibleId, quantityPer}]

        if (!is_array($items) || empty($items)) return $this->fail(4220, '产出明细不能为空');

        Db::startTrans();
        try {
            // 空时间字符串统一存 NULL（datetime 列不接受空串）
            $startTime = trim((string) $this->request->param('startTime', ''));
            $endTime   = trim((string) $this->request->param('endTime', ''));

            $payload = [
                'name'                  => (string) $this->request->param('name'),
                'source_collectible_id' => $sourceId,
                'enabled'               => (int) $this->request->param('enabled', 1),
                'per_user_limit'        => (int) $this->request->param('perUserLimit', 0),
                'daily_limit'           => (int) $this->request->param('dailyLimit', 0),
                'start_time'            => $startTime !== '' ? $startTime : null,
                'end_time'              => $endTime !== '' ? $endTime : null,
            ];

            $id = $this->request->param('id');
            if ($id) {
                Db::name('decompose_rules')->where('id', (int) $id)->update($payload);
                $ruleId = (int) $id;
                Db::name('decompose_items')->where('rule_id', $ruleId)->delete();
            } else {
                $ruleId = (int) Db::name('decompose_rules')->insertGetId($payload);
            }

            foreach ($items as $item) {
                Db::name('decompose_items')->insert([
                    'rule_id'                 => $ruleId,
                    'result_collectible_id'   => (int) ($item['collectibleId'] ?? 0),
                    'quantity_per'            => (int) ($item['quantityPer'] ?? 1),
                ]);
            }

            $this->audit('分解规则', 'save', '保存', ['ruleId' => $ruleId, 'items' => count($items)]);
            Db::commit();
            return $this->success(['id' => $ruleId]);
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(5000, $e->getMessage());
        }
    }

    /**
     * POST /admin/decompose/rules/:id/toggle  开关
     */
    public function ruleToggle()
    {
        $id = $this->positiveInt('id');
        if ($id === null) return $this->failMissing(['id']);
        $cur = Db::name('decompose_rules')->where('id', $id)->value('enabled');
        Db::name('decompose_rules')->where('id', $id)->update(['enabled' => $cur ? 0 : 1]);
        $this->audit('分解规则', 'toggle', '切换开关', ['id' => $id]);
        return $this->success(['id' => $id]);
    }

    /**
     * DELETE /admin/decompose/rules/:id
     */
    public function ruleDelete()
    {
        $id = $this->positiveInt('id');
        if ($id === null) return $this->failMissing(['id']);
        Db::name('decompose_rules')->where('id', $id)->update(['deleted_at' => date('Y-m-d H:i:s')]);
        $this->audit('分解规则', 'delete', '删除', ['id' => $id]);
        return $this->success(['id' => $id]);
    }

    /**
     * GET /admin/decompose/records  分解执行记录
     */
    public function records()
    {
        [$page, $pageSize] = $this->pageParams();
        $query = Db::name('decompose_records')->alias('dr')
            ->join('users u', 'u.id = dr.user_id', 'LEFT')
            ->join('collectibles c', 'c.id = dr.source_collectible_id', 'LEFT')
            ->field('dr.*, u.username, c.name AS source_name');

        $userId = $this->positiveInt('userId');
        if ($userId !== null) $query->where('dr.user_id', $userId);

        $total = (clone $query)->count();
        $rows = $query->order('dr.id', 'desc')->page($page, $pageSize)->select()->toArray();
        return $this->paginate(camelize_keys($rows), $total, $page, $pageSize);
    }
}
