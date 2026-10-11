<?php
declare(strict_types=1);

namespace app\service;

use RuntimeException;

/**
 * 编号业务冲突异常
 * 消息必须带具体数字与原因（与 InventoryException 同规范，禁止静默失败）
 */
class SerialException extends RuntimeException
{
}
