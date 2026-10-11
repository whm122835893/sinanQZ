<?php
// ============================================================================
// 司南数字藏品平台 · 管理后台通用上传（admin 应用）
// POST /admin/upload/image  multipart/form-data: file
// 用途：藏品封面 / 盲盒封面 / 合成结果图 / 活动素材等运营图片
// 约束：jpg/jpeg/png/webp/gif，≤5MB，按业务目录归档（collection/blindbox/marketing）
// 安全：仅限登录管理员；以服务端嗅探出的真实 MIME 为准（不比客户端文件名）；随机文件名防覆盖与路径穿越
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

    /**
     * HEIC/HEIF 家族（iPhone 默认拍照格式）
     * 它们不在 ALLOWED 里、后端也不打算收，单列出来只为了把拒绝话说得能照着做，
     * 而不是甩一句「仅支持 JPG / PNG / WEBP / GIF」让人猜自己错在哪。
     */
    private const HEIC_MIMES = ['image/heic', 'image/heif', 'image/heics', 'image/heifs', 'image/heic-sequence', 'image/heif-sequence'];

    /**
     * 业务子目录白名单（biz 参数）
     * artifact=文物展馆图 / announcement=富文本插图：后台已在用，必须同步放行，
     * 否则这两处上传会一律被 4220「biz 仅允许 …」拒绝。
     *
     * 2026-10-12 从 private 改成 public：清理服务要用这份权威清单算「哪些目录不参与清理」，
     * 再抄一份字面量就迟早会漂（多一个目录 = 多一批可能被误删的图）。
     */
    public const BIZ_DIRS = ['collection', 'blindbox', 'marketing', 'content', 'misc', 'custom', 'artifact', 'announcement'];

    private const MAX_SIZE = 5 * 1024 * 1024; // 5MB

    /**
     * POST /admin/upload/image { file, biz? }
     * 返回 { url } —— 相对站点根的 URL（如 /uploads/collection/202609/xxx.png）
     */
    public function image()
    {
        // ---- SEC-U1 修复（安全专项 7.5）：PHP 层上传错误先行检查 ----
        // 超过 php.ini upload_max_filesize 时框架 Request::file() 会直接抛异常
        //（think\Request::dealUploadFile → throwUploadFileError → 5001），
        // 必须在调用 $request->file() 之前检查原始 $_FILES 错误码，统一转为 4220 业务码
        $upErr = (int) ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($upErr !== UPLOAD_ERR_OK) {
            return $this->fail(4220, '图片上传失败：' . $this->uploadErrMsg($upErr));
        }

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

        // ---- 真实内容校验：只认服务端嗅探出来的类型 ----
        $mime = strtolower((string) $file->getMime());
        // 这里刻意「不再比对客户端文件名后缀」。原先有一句「文件扩展名与实际内容不一致，拒绝上传」，
        // 但它拦不到任何攻击：落盘文件名一直由服务端按嗅探类型生成（见下方 $filename），
        // 客户端后缀从头到尾没参与过存储与访问，改它也没有副作用；被它误伤的只有正常运营：
        // iPhone 相册导出、微信转发、设计稿导出这类「内容是 JPG 却挂着 .heic / .txt / 无后缀」的文件。
        // 2026-10-11 线上 13 次上传失败里 11 次卡在这两条内容检查上，零文件落盘。
        if (!self::isSupportedMime($mime)) {
            return $this->fail(4220, self::unsupportedImageMessage($mime, (int) $file->getSize()));
        }
        $ext = self::ALLOWED[$mime];

        // ---- 真图片内容校验（getimagesize 防 polyglot）----
        $path = $file->getPathname();
        $info = @getimagesize($path);
        if ($info === false) {
            return $this->fail(4220, self::unreadableImageMessage($mime, (int) $file->getSize()));
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

    /**
     * 后端认的图片类型（纯函数，便于脱离 HTTP 单测）
     * 口径与 ALLOWED 一一对应，不在这里多出任何格式：iPhone 的 HEIC 由后台前端
     * 在浏览器里转成 JPEG 后再发过来（sinan-admin/src/api/index.js 的 uploadImage 总闸），
     * 后端如果偷偷加格式，C 端的解码链路没人验过。
     */
    public static function isSupportedMime(string $mime): bool
    {
        return isset(self::ALLOWED[strtolower(trim($mime))]);
    }

    /** iPhone/新机默认的 HEIC/HEIF 家族：单独认出来，否则只会落进通用「不支持」那句，运营看不出下一步该干什么 */
    public static function isHeic(string $mime): bool
    {
        return in_array(strtolower(trim($mime)), self::HEIC_MIMES, true);
    }

    /** 类型不在白名单时的拒绝话术：带识别到的真实类型与体积，HEIC 另给一条照着做完就的路 */
    public static function unsupportedImageMessage(string $mime, int $bytes = 0): string
    {
        $detail = '（识别为 ' . ($mime !== '' ? $mime : '未知类型') . self::sizeText($bytes) . '）';
        if (self::isHeic($mime)) {
            return '这张图是 iPhone 的 HEIC 格式，平台暂不接收' . $detail
                . '。最省事：在 iPhone 上截个图、上传那张截图；长期解决：「设置 → 相机 → 格式」改成「兼容性最好」，之后拍的就是 JPG。';
        }
        return '仅支持 JPG / PNG / WEBP / GIF 图片' . $detail . '，请换一张这四种格式的图片。';
    }

    /**
     * 类型合格却读不出图片信息时的拒绝话术。
     * 这一档最常见的原因是「文件没传完」或「iCloud 相册里的图还没下载到本机」——
     * 本地只有一个几 KB 的占位文件，选得上、传得上，内容却是空的，说「不是有效图片」谁也看不懂。
     */
    public static function unreadableImageMessage(string $mime, int $bytes = 0): string
    {
        return '图片没读出来' . '（识别为 ' . ($mime !== '' ? $mime : '未知类型') . self::sizeText($bytes) . '，内容不完整）'
            . '。请这样试：① 重新选一次这个文件；② 图在 iPhone 相册里、还没从 iCloud 下载完的（名字后带云朵箭头），'
            . '先在相册里打开它、等图片加载完再传；③ 还不行就截个图，上传那张截图。';
    }

    /** 体积口语化：不到 1MB 用 KB，不足 1KB 说「不足1KB」——「0KB」会让人觉得文件是空的 */
    private static function sizeText(int $bytes): string
    {
        if ($bytes <= 0) return '';
        if ($bytes >= 1048576) return sprintf('，%.1fMB', $bytes / 1048576);
        if ($bytes < 1024)     return '，不足1KB';
        return sprintf('，%dKB', (int) round($bytes / 1024));
    }

    /** SEC-U1：PHP 上传错误码 → 用户可读信息 */
    private function uploadErrMsg(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => '文件超过大小限制（≤5MB）',
            UPLOAD_ERR_PARTIAL => '文件仅部分上传，请重试',
            UPLOAD_ERR_NO_FILE => '没有文件被上传',
            UPLOAD_ERR_NO_TMP_DIR => '服务器缺少临时目录',
            UPLOAD_ERR_CANT_WRITE => '文件写入失败',
            UPLOAD_ERR_EXTENSION => '上传被 PHP 扩展中止',
            default => '未知上传错误（' . $code . '）',
        };
    }
}
