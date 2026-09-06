<?php
// ============================================================================
// 司南数字藏品平台 · 管理后台通用上传（admin 应用）
// POST /admin/upload/image  multipart/form-data: file
// 用途：藏品封面 / 盲盒封面 / 合成结果图 / 活动素材等运营图片
// 约束：jpg/jpeg/png/webp/gif，≤5MB，按业务目录归档（collection/blindbox/marketing）
// 安全：仅限登录管理员；按 MIME 与扩展双重校验；随机文件名防覆盖与路径穿越
// ============================================================================
declare(strict_types=1);

namespace app\admin\controller;

use think\facade\Db;

class UploadController extends BaseController
{
    /** 允许的图片 MIME 与扩展映射 */
    private const ALLOWED = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];

    /** 业务子目录白名单（biz 参数） */
    private const BIZ_DIRS = ['collection', 'blindbox', 'marketing', 'content', 'misc'];

    private const MAX_SIZE = 5 * 1024 * 1024; // 5MB

    /**
     * POST /admin/upload/image { file, biz? }
     * 返回 { url } —— 相对站点根的 URL（如 /uploads/collection/202609/xxx.png）
     */
    public function image()
    {
        $file = $this->request->file('file');
        if (!$file) {
            return $this->fail(4220, '请选择要上传的图片文件（字段名 file）');
        }

        // ---- 业务目录 ----
        $biz = strtolower(trim((string) $this->request->param('biz', 'misc')));
        if (!in_array($biz, self::BIZ_DIRS, true)) {
            return $this->fail(4220, 'biz 仅允许 ' . implode('/', self::BIZ_DIRS));
        }

        // ---- 大小校验 ----
        if ($file->getSize() > self::MAX_SIZE) {
            return $this->fail(4220, '图片大小不能超过 5MB（当前 ' . round($file->getSize() / 1048576, 2) . 'MB）');
        }

        // ---- MIME + 扩展双重校验 ----
        $mime = strtolower((string) $file->getMime());
        if (!isset(self::ALLOWED[$mime])) {
            return $this->fail(4220, '仅支持 JPG / PNG / WEBP / GIF 图片（当前 ' . $mime . '）');
        }
        $ext = self::ALLOWED[$mime];
        $origExt = strtolower($file->getOriginalExtension() ?: '');
        $aliasMap = ['jpg' => 'jpeg', 'jpeg' => 'jpg'];
        $normExt = $aliasMap[$origExt] ?? $origExt;
        $normRef = $aliasMap[$ext] ?? $ext;
        if ($origExt !== '' && $normExt !== $normRef) {
            return $this->fail(4220, '文件扩展名与实际内容不一致，拒绝上传');
        }

        // ---- 真实图片内容校验（getimagesize 防 polyglot）----
        $path = $file->getPathname();
        $info = @getimagesize($path);
        if ($info === false) {
            return $this->fail(4220, '文件内容不是有效图片');
        }

        // ---- 归档目录：public/uploads/{biz}/{YYYYMM}/ ----
        $subDir = 'uploads/' . $biz . '/' . date('Ym');
        $fullDir = public_path() . $subDir;
        if (!is_dir($fullDir) && !mkdir($fullDir, 0755, true)) {
            return $this->fail(5000, '创建上传目录失败');
        }

        // ---- 随机文件名防覆盖 ----
        $filename = bin2hex(random_bytes(16)) . '.' . $ext;
        $size = $file->getSize(); // move 后临时文件被删除，须先取
        try {
            $file->move($fullDir, $filename);
        } catch (\Throwable $e) {
            return $this->fail(5000, '文件保存失败：' . $e->getMessage());
        }

        $url = '/' . $subDir . '/' . $filename;

        // ---- 审计 ----
        $this->audit('upload', 'image', '上传图片（' . $biz . '）', [
            'url'  => $url,
            'size' => $size,
            'mime' => $mime,
        ]);

        return $this->success(['url' => $url], '上传成功');
    }
}
