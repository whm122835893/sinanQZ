<?php
declare(strict_types=1);

namespace app\admin\controller;

use think\facade\Db;

/**
 * 操作日志 / 登录日志控制器（权限模块 P0 子集）
 * GET /admin/api/v1/permission/operation-logs  操作日志列表
 * GET /admin/api/v1/permission/login-logs      登录日志列表
 */
class Logs extends AdminBase
{
    /**
     * 操作日志列表（adminId/module/action/时间范围）
     */
    public function operation()
    {
        ['page' => $page, 'pageSize' => $pageSize, 'offset' => $offset] = $this->pagination();

        $query = Db::name('operation_logs');

        $adminId = $this->strParam('adminId');
        if ($adminId !== null && $adminId !== '') {
            $query->where('admin_id', (int) $adminId);
        }
        $module = trim((string) $this->strParam('module'));
        if ($module !== '') {
            $query->where('module', $module);
        }
        $action = trim((string) $this->strParam('action'));
        if ($action !== '') {
            $query->where('action', 'like', "%{$action}%");
        }
        $createdAtStart = $this->strParam('createdAtStart');
        if ($createdAtStart) {
            $query->where('created_at', '>=', $createdAtStart . ' 00:00:00');
        }
        $createdAtEnd = $this->strParam('createdAtEnd');
        if ($createdAtEnd) {
            $query->where('created_at', '<=', $createdAtEnd . ' 23:59:59');
        }

        $total = (clone $query)->count();
        $list  = $query
            ->field('id,admin_id,admin_name,module,action,target_type,target_id,target_desc,reason,ip,created_at')
            ->order('id', 'desc')
            ->limit($offset, $pageSize)
            ->select()
            ->toArray();

        foreach ($list as &$row) {
            $row['adminId']    = (int) $row['admin_id'];
            $row['adminName']  = $row['admin_name'];
            $row['targetType'] = $row['target_type'];
            $row['targetId']   = $row['target_id'];
            $row['targetDesc'] = $row['target_desc'];
            $row['createdAt']  = $row['created_at'];
            unset(
                $row['admin_id'],
                $row['admin_name'],
                $row['target_type'],
                $row['target_id'],
                $row['target_desc'],
                $row['created_at']
            );
        }

        return $this->paginate($list, $total, $page, $pageSize);
    }

    /**
     * 登录日志列表（username/成功与否/时间范围）
     */
    public function login()
    {
        ['page' => $page, 'pageSize' => $pageSize, 'offset' => $offset] = $this->pagination();

        $query = Db::name('admin_login_logs');

        $username = trim((string) $this->strParam('username'));
        if ($username !== '') {
            $query->where('username', 'like', "%{$username}%");
        }
        $success = $this->strParam('success');
        if ($success !== null && $success !== '') {
            $query->where('success', (int) $success);
        }
        $ip = trim((string) $this->strParam('ip'));
        if ($ip !== '') {
            $query->where('ip', 'like', "%{$ip}%");
        }
        $createdAtStart = $this->strParam('createdAtStart');
        if ($createdAtStart) {
            $query->where('created_at', '>=', $createdAtStart . ' 00:00:00');
        }
        $createdAtEnd = $this->strParam('createdAtEnd');
        if ($createdAtEnd) {
            $query->where('created_at', '<=', $createdAtEnd . ' 23:59:59');
        }

        $total = (clone $query)->count();
        $list  = $query
            ->field('id,admin_id,username,ip,user_agent,success,fail_reason,created_at')
            ->order('id', 'desc')
            ->limit($offset, $pageSize)
            ->select()
            ->toArray();

        foreach ($list as &$row) {
            $row['adminId']    = $row['admin_id'] !== null ? (int) $row['admin_id'] : null;
            $row['userAgent']  = $row['user_agent'];
            $row['success']    = (int) $row['success'] === 1;
            $row['failReason'] = $row['fail_reason'];
            $row['createdAt']  = $row['created_at'];
            unset($row['admin_id'], $row['user_agent'], $row['fail_reason'], $row['created_at']);
        }

        return $this->paginate($list, $total, $page, $pageSize);
    }
}
