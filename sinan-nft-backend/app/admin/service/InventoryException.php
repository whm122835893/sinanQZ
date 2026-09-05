<?php
declare(strict_types=1);

namespace app\admin\service;

use RuntimeException;

/**
 * 库存业务冲突异常（409 语义，文档 8.1）
 * 消息必须携带具体数值与原因（文档 11.2-20：禁止静默失败）
 */
class InventoryException extends RuntimeException
{
}
