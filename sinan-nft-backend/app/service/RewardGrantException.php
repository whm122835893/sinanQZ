<?php
declare(strict_types=1);

namespace app\service;

/**
 * 奖励发放业务异常（配额/库存不足、配置非法等）
 */
class RewardGrantException extends \Exception
{
}
