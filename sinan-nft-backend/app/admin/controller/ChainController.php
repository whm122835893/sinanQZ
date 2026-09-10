<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\admin\service\ChainService;
use think\facade\Db;

/**
 * 管理后台区块链控制器（藏品上链）
 *
 * - 链网络配置：三链（文昌链/联盟链/蚂蚁链）参数、密钥（AES）、启停、默认链、连通测试
 * - 合约登记：业务合约 CRUD + 启停
 * - 链上交易：持仓上链流水（tx_hash 聚合）
 * - 上链铸造：POST /chain/mint/:id 为藏品未上链持仓生成链上凭证
 */
class ChainController extends BaseController
{
    /**
     * GET /admin/chain/networks
     */
    public function networks()
    {
        return $this->success([
            'networks' => ChainService::networks(),
            'stats'    => ChainService::stats(),
        ]);
    }

    /**
     * PUT /admin/chain/networks/:id
     * 密钥非空才更新（永不清空），联盟链启用前必须配置 RPC
     */
    public function saveNetwork(int $id)
    {
        $result = ChainService::saveNetwork($id, $this->request->param());
        if (!$result['ok']) {
            return $this->fail(4220, $result['message']);
        }
        $this->audit('chain', 'network_save', '更新链网络配置（ID：' . $id . '）',
            $this->auditNetworkDetail($id), 'chain_network', $id);
        return $this->success(null, '链网络配置已保存');
    }

    /**
     * POST /admin/chain/networks/:id/test
     * 连通性测试（配置完备性检查 + 模拟网关握手）
     */
    public function testNetwork(int $id)
    {
        $result = ChainService::testNetwork($id);
        $this->audit('chain', 'network_test', '测试链网络连通性（' . ($result['chainName'] ?? 'ID:' . $id) . '）',
            ['ok' => $result['ok']], 'chain_network', $id);
        return $this->success($result);
    }

    /**
     * GET /admin/chain/contracts
     */
    public function contracts()
    {
        [$page, $pageSize] = $this->pageParams();
        $query = Db::name('chain_contracts')->alias('ct');

        $keyword = trim((string) $this->request->param('keyword', ''));
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('ct.contract_name', "%{$keyword}%")
                  ->whereOr('ct.contract_address', 'like', "%{$keyword}%");
            });
        }
        $networkId = $this->positiveInt('networkId');
        if ($networkId !== null) {
            $query->where('ct.network_id', $networkId);
        }
        $status = $this->request->param('status');
        if ($status !== null && $status !== '') {
            $query->where('ct.status', (int) $status);
        }

        $total = (clone $query)->count();
        $rows = $query->field('ct.*, n.chain_code, n.chain_name, n.env')
            ->join('chain_networks n', 'n.id = ct.network_id', 'LEFT')
            ->order('ct.id', 'desc')
            ->page($page, $pageSize)
            ->select()->toArray();

        $result = array_map(function ($row) {
            $row['status_text'] = ((int) $row['status'] === 1) ? '启用' : '停用';
            return camelize_keys($row);
        }, $rows);

        return $this->paginate($result, $total, $page, $pageSize);
    }

    /**
     * POST /admin/chain/contracts
     * { network_id, contract_name, contract_address, contract_type, description? }
     */
    public function contractCreate()
    {
        $missing = $this->missingParams(['network_id', 'contract_name', 'contract_address']);
        if ($missing) {
            return $this->failMissing($missing);
        }

        $networkId = (int) $this->request->param('network_id');
        $network   = Db::name('chain_networks')->where('id', $networkId)->find();
        if (!$network) {
            return $this->fail(4220, '链网络不存在');
        }

        $address = trim((string) $this->request->param('contract_address'));
        if (mb_strlen($address) < 10 || mb_strlen($address) > 100) {
            return $this->fail(4220, '合约地址长度需为 10~100 字符');
        }
        if (Db::name('chain_contracts')->where('contract_address', $address)->count() > 0) {
            return $this->fail(4220, '该合约地址已登记');
        }

        // F7-D6 修复：显式传入的非法类型必须拒绝，不得静默替换为默认值
        $rawType = $this->request->param('contract_type');
        if ($rawType !== null && $rawType !== '' && !in_array((string) $rawType, ['erc721', 'erc1155', 'ddc721', 'ddc1155'], true)) {
            return $this->fail(4220, '合约类型仅支持 erc721/erc1155/ddc721/ddc1155');
        }
        $type = $this->enumParam('contract_type', ['erc721', 'erc1155', 'ddc721', 'ddc1155'], 'erc721');

        $now = date('Y-m-d H:i:s');
        $id = Db::name('chain_contracts')->insertGetId([
            'network_id'      => $networkId,
            'contract_name'   => mb_substr(trim((string) $this->request->param('contract_name')), 0, 100),
            'contract_address' => mb_substr($address, 0, 100),
            'contract_type'   => (string) $type,
            'token_standard'  => mb_substr(trim((string) $this->request->param('token_standard', strtoupper(str_replace('erc', 'ERC-', (string) $type)))), 0, 20),
            'description'     => mb_substr(trim((string) $this->request->param('description', '')), 0, 255) ?: null,
            'status'          => 1,
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);

        $this->audit('chain', 'contract_create', '登记合约 ' . $address . '（' . $network['chain_name'] . '）',
            ['contract_type' => $type], 'chain_contract', $id);
        return $this->success(['id' => $id], '合约登记成功');
    }

    /**
     * PUT /admin/chain/contracts/:id
     */
    public function contractUpdate(int $id)
    {
        $contract = Db::name('chain_contracts')->where('id', $id)->find();
        if (!$contract) {
            return $this->fail(4040, '合约不存在');
        }

        $update = ['updated_at' => date('Y-m-d H:i:s')];
        if ($this->request->has('contract_name')) {
            $name = trim((string) $this->request->param('contract_name'));
            if ($name === '') {
                return $this->fail(4220, '合约名称不能为空');
            }
            $update['contract_name'] = mb_substr($name, 0, 100);
        }
        if ($this->request->has('contract_type')) {
            $type = $this->enumParam('contract_type', ['erc721', 'erc1155', 'ddc721', 'ddc1155']);
            if ($type === null) {
                return $this->fail(4220, '合约类型仅支持 erc721/erc1155/ddc721/ddc1155');
            }
            $update['contract_type'] = $type;
        }
        if ($this->request->has('description')) {
            $update['description'] = mb_substr(trim((string) $this->request->param('description')), 0, 255) ?: null;
        }
        if ($this->request->has('status')) {
            $update['status'] = ((int) $this->request->param('status') === 1) ? 1 : 0;
        }

        Db::name('chain_contracts')->where('id', $id)->update($update);
        $this->audit('chain', 'contract_update', '更新合约 ' . $contract['contract_address'],
            array_intersect_key($update, array_flip(['contract_name', 'contract_type', 'status'])), 'chain_contract', $id);
        return $this->success(null, '合约已更新');
    }

    /**
     * POST /admin/chain/contracts/:id/toggle
     */
    public function contractToggle(int $id)
    {
        $contract = Db::name('chain_contracts')->where('id', $id)->find();
        if (!$contract) {
            return $this->fail(4040, '合约不存在');
        }
        $new = ((int) $contract['status'] === 1) ? 0 : 1;
        Db::name('chain_contracts')->where('id', $id)->update([
            'status'     => $new,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->audit('chain', 'contract_toggle', ($new === 1 ? '启用' : '停用') . '合约 ' . $contract['contract_address'],
            [], 'chain_contract', $id);
        return $this->success(['status' => $new], $new === 1 ? '合约已启用' : '合约已停用');
    }

    /**
     * DELETE /admin/chain/contracts/:id
     * 已产生交易（tx_count>0）的合约不可删除，仅可停用 —— 保证链上凭证可追溯
     */
    public function contractDelete(int $id)
    {
        $contract = Db::name('chain_contracts')->where('id', $id)->find();
        if (!$contract) {
            return $this->fail(4040, '合约不存在');
        }
        if ((int) $contract['tx_count'] > 0) {
            return $this->fail(4220, '该合约已产生 ' . $contract['tx_count'] . ' 笔链上交易，不可删除（只能停用）');
        }
        Db::name('chain_contracts')->where('id', $id)->delete();
        $this->audit('chain', 'contract_delete', '删除合约 ' . $contract['contract_address'], [], 'chain_contract', $id);
        return $this->success(null, '合约已删除');
    }

    /**
     * GET /admin/chain/transactions
     * 链上交易流水（真实持仓聚合）
     */
    public function transactions()
    {
        [$page, $pageSize] = $this->pageParams();
        $filters = [
            'chainCode' => (string) $this->request->param('chainCode', ''),
            'type'      => (string) $this->request->param('type', ''),
            'status'    => (string) $this->request->param('ucStatus', ''),
            'keyword'   => (string) $this->request->param('keyword', ''),
            'dateRange' => $this->dateRange(),
        ];
        $result = ChainService::transactions($filters, $page, $pageSize);
        return $this->paginate($result['list'], $result['total'], $page, $pageSize);
    }

    /**
     * POST /admin/chain/mint/:id
     * 藏品上链铸造（幂等：仅未上链持仓）
     */
    public function mint(int $id)
    {
        $collectible = Db::name('collectibles')->where('id', $id)->whereNull('deleted_at')->find();
        if (!$collectible) {
            return $this->fail(4040, '藏品不存在');
        }
        $result = ChainService::mintCollectible($id, $this->admin());
        if (!$result['ok']) {
            return $this->fail(4220, $result['message']);
        }
        $this->audit('chain', 'mint', '藏品上链铸造：' . $collectible['name'] . '（' . ($result['minted'] ?? 0) . ' 份）',
            ['minted' => $result['minted'] ?? 0], 'collectible', $id);
        return $this->success(['minted' => $result['minted'] ?? 0], $result['message']);
    }

    /**
     * 审计详情（脱敏：不含密钥）
     */
    private function auditNetworkDetail(int $id): array
    {
        $network = Db::name('chain_networks')->where('id', $id)->find();
        if (!$network) {
            return [];
        }
        return [
            'chain_code' => $network['chain_code'],
            'env'        => $network['env'],
            'rpc_url'    => $network['rpc_url'],
            'status'     => (int) $network['status'],
            'is_default' => (int) $network['is_default'],
            'key_changed' => true,
        ];
    }
}
