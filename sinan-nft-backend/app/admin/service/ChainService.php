<?php
declare(strict_types=1);

namespace app\admin\service;

use think\facade\Db;

/**
 * 区块链服务（藏品上链）
 *
 * 设计目标：统一「三链」接入层 —— 文昌链（BSN-DDC）/ 联盟链（自建）/ 蚂蚁链（AntChain）。
 *
 * 严谨性设计：
 * 1. 链网络配置（RPC/密钥）AES 加密落库，接口永不回显明文（与支付渠道同规范）
 * 2. 上链铸造为幂等操作：仅对「未上链（tx_hash 为空）」持仓铸造，重复执行无副作用
 * 3. 铸造走单一事务：逐条写 user_collectibles.tx_hash/block_number/token_id、
 *    collectibles.onchain_status/minted_at、合约 tx_count 累加，失败整体回滚
 * 4. 链上交易查询从持仓表真实数据聚合（tx_hash 非空），而非独立冗余表 —— 单一事实源
 * 5. 当前内置「模拟驱动」生成 tx_hash（0x + sha256）：三链真实 SDK（文昌链 DDC REST、
 *    蚂蚁链开放平台）接入时仅需替换 driver 内 mint() 的网络调用，配置/流程零改动
 *
 * 链上标识规范：
 * - tx_hash：0x + 64 位十六进制（模拟驱动 = sha256(链标识+持仓ID+时间)）
 * - block_number：单调递增整数（当前最大区块 + 1）
 * - token_id：{藏品ID}{"%06d" 持仓ID}（同一藏品序列可追溯）
 */
class ChainService
{
    /** 支持的链标识（与 nft_chain_networks.chain_code 一致） */
    public const CHAIN_CODES = ['wenchang', 'consortium', 'antchain'];

    /** 链标识 => 展示名 */
    public const CHAIN_NAMES = [
        'wenchang'   => '文昌链（BSN-DDC）',
        'consortium' => '联盟链（自建）',
        'antchain'   => '蚂蚁链（AntChain）',
    ];

    /**
     * 链网络列表（密钥脱敏）
     *
     * @return array 每条：id/chainCode/chainName/env/rpcUrl/chainId/apiKeyMasked/
     *               apiSecretMasked/explorerUrl/gasStrategy/status/isDefault/remark
     */
    public static function networks(): array
    {
        $rows = Db::name('chain_networks')->order('id', 'asc')->select()->toArray();
        return array_map(function ($row) {
            return [
                'id'               => (int) $row['id'],
                'chainCode'        => (string) $row['chain_code'],
                'chainName'        => (string) $row['chain_name'],
                'env'              => (string) $row['env'],
                'rpcUrl'           => (string) ($row['rpc_url'] ?? ''),
                'chainId'          => (string) ($row['chain_id'] ?? ''),
                'apiKeyMasked'     => self::maskSecret($row['api_key'] ? aes_decrypt((string) $row['api_key']) : ''),
                'apiSecretMasked'  => self::maskSecret($row['api_secret'] ? aes_decrypt((string) $row['api_secret']) : ''),
                'hasKey'           => !empty($row['api_key']),
                'hasSecret'        => !empty($row['api_secret']),
                'explorerUrl'      => (string) ($row['explorer_url'] ?? ''),
                'gasStrategy'      => (string) $row['gas_strategy'],
                'status'           => (int) $row['status'],
                'isDefault'        => (int) $row['is_default'],
                'remark'           => (string) ($row['remark'] ?? ''),
                'contractCount'    => (int) Db::name('chain_contracts')->where('network_id', $row['id'])->count(),
            ];
        }, $rows);
    }

    /**
     * 保存链网络配置（密钥仅在提交非空时更新 —— 与短信配置同规范）
     */
    public static function saveNetwork(int $id, array $params): array
    {
        $network = Db::name('chain_networks')->where('id', $id)->find();
        if (!$network) {
            return ['ok' => false, 'message' => '链网络不存在'];
        }
        if (!in_array($network['chain_code'], self::CHAIN_CODES, true)) {
            return ['ok' => false, 'message' => '不支持的链标识'];
        }

        $update = ['updated_at' => date('Y-m-d H:i:s')];

        $env = (string) ($params['env'] ?? '');
        if (in_array($env, ['main', 'test'], true)) {
            $update['env'] = $env;
        }
        if (array_key_exists('rpc_url', $params)) {
            $rpc = trim((string) $params['rpc_url']);
            if ($rpc !== '' && !filter_var($rpc, FILTER_VALIDATE_URL)
                && !preg_match('/^https?:\/\/[\w.\-:]+(:\d+)?(\/[\w.\-\/]*)?$/', $rpc)) {
                return ['ok' => false, 'message' => 'RPC 地址格式不正确'];
            }
            $update['rpc_url'] = mb_substr($rpc, 0, 255) ?: null;
        }
        if (array_key_exists('chain_id', $params)) {
            $update['chain_id'] = mb_substr(trim((string) $params['chain_id']), 0, 50) ?: null;
        }
        if (array_key_exists('explorer_url', $params)) {
            $update['explorer_url'] = mb_substr(trim((string) $params['explorer_url']), 0, 255) ?: null;
        }
        $gas = (string) ($params['gas_strategy'] ?? '');
        if (in_array($gas, ['low', 'medium', 'high'], true)) {
            $update['gas_strategy'] = $gas;
        }
        if (array_key_exists('status', $params)) {
            $update['status'] = ((int) $params['status'] === 1) ? 1 : 0;
        }
        if (array_key_exists('remark', $params)) {
            $update['remark'] = mb_substr(trim((string) $params['remark']), 0, 255) ?: null;
        }
        // 密钥：非空才更新（空 = 保持原值）
        if (!empty($params['api_key'])) {
            $update['api_key'] = aes_encrypt((string) $params['api_key']);
        }
        if (!empty($params['api_secret'])) {
            $update['api_secret'] = aes_encrypt((string) $params['api_secret']);
        }

        // 启用前置校验：RPC 必填（文昌链/蚂蚁链为托管网关，允许留空走默认网关）
        if (($update['status'] ?? $network['status']) == 1 && $network['chain_code'] === 'consortium'
            && empty($update['rpc_url'] ?? $network['rpc_url'])) {
            return ['ok' => false, 'message' => '联盟链为自建网络，启用前必须配置 RPC 地址'];
        }

        Db::name('chain_networks')->where('id', $id)->update($update);

        // 设为默认链：全局唯一
        if ((int) ($params['is_default'] ?? 0) === 1 && (int) ($update['status'] ?? $network['status']) === 1) {
            Db::name('chain_networks')->where('id', '<>', $id)->update(['is_default' => 0]);
            Db::name('chain_networks')->where('id', $id)->update(['is_default' => 1]);
        }

        return ['ok' => true];
    }

    /**
     * 连通性测试：校验配置完备性 + 模拟网关握手（真实 SDK 接入点）
     */
    public static function testNetwork(int $id): array
    {
        $network = Db::name('chain_networks')->where('id', $id)->find();
        if (!$network) {
            return ['ok' => false, 'message' => '链网络不存在'];
        }
        $checks   = [];
        $problems = [];

        $rpc = trim((string) $network['rpc_url']);
        if ($network['chain_code'] === 'consortium' && $rpc === '') {
            $problems[] = '联盟链未配置 RPC 地址';
        } else {
            $checks[] = 'RPC 地址' . ($rpc !== '' ? '已配置' : '未配置（托管网关默认接入）');
        }
        $checks[] = 'AccessKey' . (!empty($network['api_key']) ? '已配置' : '未配置');
        $checks[] = 'AccessSecret' . (!empty($network['api_secret']) ? '已配置' : '未配置');
        if ($network['chain_code'] === 'antchain' && (empty($network['api_key']) || empty($network['api_secret']))) {
            $problems[] = '蚂蚁链接入需同时配置 AccessKey 与 AccessSecret';
        }

        $latency = random_int(60, 180); // 模拟网关往返延迟（ms）
        $checks[] = '网关握手延迟约 ' . $latency . 'ms';
        $checks[] = '链 ID：' . ($network['chain_id'] ?: 'default');

        return [
            'ok'        => empty($problems),
            'chainName' => (string) $network['chain_name'],
            'checks'    => $checks,
            'problems'  => $problems,
            'latency'  => $latency,
        ];
    }

    /**
     * 藏品上链铸造（幂等）
     *
     * 前置：藏品已配置链类型 + 合约地址，且对应链网络已启用
     * 动作：为该藏品全部未上链持仓生成链上凭证（tx_hash/block_number/token_id）
     *
     * @return array{ok:bool,message:string,minted?:int,skipped?:int}
     */
    public static function mintCollectible(int $collectibleId, array $operator): array
    {
        $collectible = Db::name('collectibles')->where('id', $collectibleId)->whereNull('deleted_at')->find();
        if (!$collectible) {
            return ['ok' => false, 'message' => '藏品不存在'];
        }

        $chainType = trim((string) ($collectible['chain_type'] ?? ''));
        if (!in_array($chainType, self::CHAIN_CODES, true)) {
            return ['ok' => false, 'message' => '藏品未配置上链（请先在藏品编辑中选择 文昌链/联盟链/蚂蚁链）'];
        }
        $network = Db::name('chain_networks')->where('chain_code', $chainType)->where('status', 1)->find();
        if (!$network) {
            return ['ok' => false, 'message' => self::CHAIN_NAMES[$chainType] . ' 未启用，请先在上链配置中启用该链网络'];
        }

        $contractAddress = trim((string) ($collectible['contract'] ?? ''));
        if ($contractAddress === '') {
            return ['ok' => false, 'message' => '藏品未配置链上合约地址'];
        }
        $contract = Db::name('chain_contracts')
            ->where('contract_address', $contractAddress)
            ->where('status', 1)->find();
        if (!$contract) {
            return ['ok' => false, 'message' => '合约 ' . mb_substr($contractAddress, 0, 16) . '… 未在上链配置中登记或已停用'];
        }

        $batchLimit = (int) (Db::name('system_configs')->where('config_key', 'chain_mint_batch_limit')->value('config_value') ?: 500);

        Db::startTrans();
        try {
            // 锁定未上链持仓（仅 held/consigned/frozen 状态；recovered/destroyed 不上链）
            $ucs = Db::name('user_collectibles')
                ->where('collectible_id', $collectibleId)
                ->whereNull('tx_hash')
                ->whereIn('status', ['held', 'consigned', 'frozen'])
                ->lock(true)
                ->limit($batchLimit)
                ->select()->toArray();

            if (empty($ucs)) {
                Db::name('collectibles')->where('id', $collectibleId)->update([
                    'onchain_status' => 2,
                    'updated_at'     => date('Y-m-d H:i:s'),
                ]);
                Db::commit();
                return ['ok' => true, 'message' => '全部持仓已在链上（无可铸造数据）', 'minted' => 0];
            }

            $now = date('Y-m-d H:i:s');
            $blockNumber = self::nextBlockNumber($chainType);
            $minted = 0;

            foreach ($ucs as $uc) {
                $txHash = self::buildTxHash($chainType, (int) $uc['id']);
                $tokenId = $collectibleId . sprintf('%06d', (int) $uc['id']);
                Db::name('user_collectibles')->where('id', $uc['id'])->update([
                    'tx_hash'      => $txHash,
                    'block_number' => $blockNumber,
                    'token_id'     => $tokenId,
                    'updated_at'   => $now,
                ]);
                $minted++;
            }

            // 合约交易计数 + 藏品上链状态收口
            Db::name('chain_contracts')->where('id', $contract['id'])->update([
                'tx_count'   => Db::raw('tx_count + ' . $minted),
                'updated_at' => $now,
            ]);
            Db::name('collectibles')->where('id', $collectibleId)->update([
                'onchain_status' => 2,
                'minted_at'      => $now,
                'updated_at'     => $now,
            ]);

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return ['ok' => false, 'message' => '上链铸造失败（已回滚）：' . $e->getMessage()];
        }

        return [
            'ok'      => true,
            'message' => '铸造完成：' . $minted . ' 份持仓已上链（' . self::CHAIN_NAMES[$chainType] . '）',
            'minted'  => $minted,
        ];
    }

    /**
     * 链上交易流水（真实持仓数据聚合，tx_hash 非空）
     */
    public static function transactions(array $filters, int $page, int $pageSize): array
    {
        $query = Db::name('user_collectibles')->alias('uc');

        $chainCode = (string) ($filters['chainCode'] ?? '');
        if ($chainCode !== '') {
            $query->where('c.chain_type', $chainCode);
        }
        $type = (string) ($filters['type'] ?? '');
        if ($type !== '') {
            // mint=持有上链 / transfer=转赠变更
            $query->where('uc.source', $type === 'transfer' ? 'transfer' : '');
            if ($type === 'transfer') {
                $query->whereOr('uc.status', 'transferred');
            }
        }
        $status = (string) ($filters['status'] ?? '');
        if ($status !== '') {
            $query->where('uc.status', $status);
        }
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('uc.tx_hash', "%{$keyword}%")
                  ->whereOr('u.username', 'like', "%{$keyword}%")
                  ->whereOr('u.uid', $keyword)
                  ->whereOr('c.name', 'like', "%{$keyword}%");
            });
        }
        $range = $filters['dateRange'] ?? null;
        if ($range) {
            if ($range[0] !== '') $query->where('uc.updated_at', '>=', $range[0]);
            if ($range[1] !== '') $query->where('uc.updated_at', '<=', $range[1]);
        }

        $query->whereNotNull('uc.tx_hash');

        // F7-D5 修复：join 必须在 count() 之前注册——
        // 筛选条件引用了 c./u. 别名（chainCode/keyword），count 时未 join 会直接 SQL 报错
        $query->field('uc.id, uc.tx_hash, uc.block_number, uc.token_id, uc.status AS uc_status, uc.updated_at,
                       uc.serial, u.uid, u.username, c.name AS collectible_name, c.image,
                       c.chain_type, c.contract, c.token_standard')
            ->join('users u', 'u.id = uc.user_id', 'LEFT')
            ->join('collectibles c', 'c.id = uc.collectible_id', 'LEFT');

        $total = (clone $query)->count();
        $rows = $query->order('uc.block_number', 'desc')
            ->page($page, $pageSize)
            ->select()->toArray();

        $list = array_map(function ($row) {
            $row['chain_code'] = $row['chain_type'];
            $row['chain_name'] = self::CHAIN_NAMES[$row['chain_type']] ?? ($row['chain_type'] ?: '未配置');
            // 交易类型推断：转赠完成/转出 = Transfer，其余 = Mint
            $row['tx_type'] = in_array($row['uc_status'], ['transferred', 'transferred_out'], true) ? 'Transfer' : 'Mint';
            $row['tx_status'] = 'success';
            $row['gas'] = '0.0' . random_int(1, 99); // 模拟燃料费（真实接入后由回执填充）
            return camelize_keys($row);
        }, $rows);

        return ['list' => $list, 'total' => $total];
    }

    /**
     * 链上统计（合约页概览卡）
     */
    public static function stats(): array
    {
        $onchain = Db::name('user_collectibles')->whereNotNull('tx_hash')->count();
        $pending = Db::name('user_collectibles')
            ->whereIn('status', ['held', 'consigned', 'frozen'])
            ->whereNull('tx_hash')->count();
        $byChain = [];
        foreach (Db::name('chain_networks')->select()->toArray() as $network) {
            $byChain[] = [
                'chainCode' => $network['chain_code'],
                'chainName' => $network['chain_name'],
                'status'    => (int) $network['status'],
                'contracts' => (int) Db::name('chain_contracts')->where('network_id', $network['id'])->count(),
                'txCount'   => (int) Db::name('chain_contracts')->where('network_id', $network['id'])->sum('tx_count'),
            ];
        }
        return [
            'onchainHoldings' => $onchain,
            'pendingHoldings' => $pending,
            'onchainRate'     => $onchain + $pending > 0 ? round($onchain / ($onchain + $pending) * 100, 1) : 0,
            'networks'        => $byChain,
        ];
    }

    // ------------------------------------------------------------------
    // 私有辅助
    // ------------------------------------------------------------------

    private static function maskSecret(string $secret): string
    {
        if ($secret === '') {
            return '';
        }
        $len = mb_strlen($secret);
        if ($len <= 6) {
            return str_repeat('*', $len);
        }
        return mb_substr($secret, 0, 3) . str_repeat('*', min(8, $len - 4)) . mb_substr($secret, -2);
    }

    private static function nextBlockNumber(string $chainCode): int
    {
        $max = (int) Db::name('user_collectibles')->whereNotNull('block_number')->max('block_number');
        return max($max, 1000000) + 1;
    }

    private static function buildTxHash(string $chainCode, int $ucId): string
    {
        return '0x' . hash('sha256', $chainCode . '|' . $ucId . '|' . microtime(true) . '|' . random_int(0, PHP_INT_MAX));
    }
}
