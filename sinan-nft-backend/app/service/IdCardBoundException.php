<?php
declare(strict_types=1);

namespace app\service;

use RuntimeException;

/**
 * 「一证一号」冲突异常
 *
 * 语义：这张身份证已经被另一个账号认证通过，本次认证不能再占用它。
 * $otherUid 只给管理端提示用（运营/客服需要知道撞在谁身上）；
 * C 端一律不透出对方 UID，避免用报错接口探测他人实名情况。
 */
class IdCardBoundException extends RuntimeException
{
    /** @var string 已占用该证号的账号 UID（可能为空串，如存量数据无指纹时） */
    public string $otherUid = '';
}
