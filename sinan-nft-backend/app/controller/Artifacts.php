<?php
declare(strict_types=1);

namespace app\controller;
use app\BaseController;

use think\facade\Db;

/**
 * 文物展馆控制器
 */
class Artifacts extends BaseController
{
    /**
     * GET /api/artifacts
     * 展品列表
     */
    public function index()
    {
        $p       = $this->pagination();
        $dynasty = $this->strParam('dynasty');
        $sort    = $this->strParam('sort', 'time-desc');

        $query = Db::name('artifacts')->whereNull('deleted_at');
        if ($dynasty) $query->where('dynasty', $dynasty);

        switch ($sort) {
            case 'time-asc':  $query->order('created_at', 'asc');  break;
            default:          $query->order('created_at', 'desc'); break;
        }

        $total = $query->count();
        $list  = $query->limit($p['offset'], $p['pageSize'])->select()->toArray();

        return $this->paginate(array_map(fn ($a) => [
            'id'        => (int) $a['id'],
            'name'      => $a['name'],
            'dynasty'   => $a['dynasty'],
            'material'  => $a['material'],
            'image'     => $a['image'],
            'imgHeight' => (int) $a['img_height'],
            'location'  => $a['origin'],
            'level'     => $a['level'],
            'tags'      => $a['tags'] ? json_decode($a['tags'], true) : [],
        ], $list), $total, $p['page'], $p['pageSize']);
    }

    /**
     * GET /api/artifacts/:id
     * 展品详情
     */
    public function detail()
    {
        $id = $this->intParam('id');
        $a  = Db::name('artifacts')->where('id', $id)->whereNull('deleted_at')->find();
        if (!$a) return $this->fail(1002, '展品不存在');

        return $this->success([
            'id'       => (int) $a['id'],
            'name'     => $a['name'],
            'dynasty'  => $a['dynasty'],
            'material' => $a['material'],
            'image'    => $a['image'],
            'period'   => $a['period'],
            'origin'   => $a['origin'],
            'location' => $a['origin'],
            'level'    => $a['level'],
            'specs'    => $a['specs'] ? json_decode($a['specs'], true) : [],
            'story'    => $a['story'],
            'tags'     => $a['tags'] ? json_decode($a['tags'], true) : [],
        ]);
    }
}
