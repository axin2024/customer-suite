<?php

declare(strict_types=1);

namespace CustomerSuite;

final class Workbench
{
    public const NAVIGATION = [
        'accounts' => ['客户档案', '客户主档、联系人与集团关系', ['manager', 'sales', 'success']],
        'campaigns' => ['营销活动', '维护活动渠道并收录带来源标识的线索', ['manager', 'marketing', 'sales']],
        'leads' => ['线索分配', '查看触点归因、认领和资格确认', ['manager', 'marketing', 'sales']],
        'opportunities' => ['商机管道', '按阶段管理跟进、产品和近期成交', ['manager', 'sales']],
        'forecasts' => ['预测快照', '保存按币种汇总的时点预测', ['manager']],
        'quotes' => ['报价与审批', '比较报价修订，提交折扣审批并登记签约', ['manager', 'sales', 'approver']],
        'agreements' => ['合同台账', '按签约公司查看合同正本及补充协议', ['manager', 'sales', 'approver']],
        'handovers' => ['交付交接', '确认范围与客户代表，办理里程碑和验收', ['manager', 'sales', 'delivery']],
        'tickets' => ['服务工单', '受理合同服务请求，跟踪响应与解决时限', ['manager', 'service']],
        'service-settings' => ['服务设置', '配置队列、合同授权与工作日历', ['manager']],
        'success-contracts' => ['客户成功', '跟踪负责合同的健康度与续约周期', ['manager', 'success']],
        'renewals' => ['续约工作台', '扫描到期合同、登记跟进与触达结果', ['manager', 'success']],
        'batches' => ['批量任务', '分配线索、办理续约触达并查看逐条结果', ['manager', 'marketing', 'success']],
        'reports' => ['经营分析', '按组织和时间追踪成交漏斗、渠道贡献与签约金额', ['manager', 'sales']],
    ];
    public const STATES = [
        'active' => '正常', 'frozen' => '冻结', 'merged' => '已归并',
        'open' => '待认领', 'assigned' => '已分配', 'qualified' => '已确认资格', 'converted' => '已转化', 'closed' => '已关闭',
        'discovery' => '初步接洽', 'proposal' => '报价准备', 'negotiation' => '谈判', 'won' => '赢单', 'lost' => '流失',
        'draft' => '草拟', 'pending' => '待审批', 'approved' => '已批准', 'rejected' => '已拒绝', 'contracted' => '已转合同',
        'archived' => '历史修订', 'signed' => '已签署', 'fulfilling' => '履约中', 'renewing' => '续约中', 'ended' => '已终止',
        'automatic' => '授权范围内通过',
        'planning' => '待确认范围', 'executing' => '交付中', 'accepted' => '已验收',
        'scheduled' => '已排期', 'delayed' => '延期', 'completed' => '已完成',
        'review' => '服务资格待复核', 'waiting' => '等待客户', 'resolved' => '已解决', 'running' => '计时中',
        'paused' => '已暂停', 'complete' => '计时结束',
        'working' => '跟进中', 'renewed' => '已续约', 'superseded' => '已转新周期',
        'queued' => '待处理', 'blocked' => '已拦截', 'retryable' => '待重试', 'delivered' => '渠道已接收',
        'replayed' => '回放完成', 'cancelled' => '已取消',
    ];
    public const FILTERS = [
        'accounts' => ['active', 'frozen', 'merged'],
        'leads' => ['open', 'assigned', 'qualified', 'converted', 'closed'],
        'opportunities' => ['discovery', 'qualified', 'proposal', 'negotiation', 'won', 'lost'],
        'quotes' => ['pending', 'draft', 'approved', 'rejected', 'contracted'],
        'agreements' => ['signed', 'fulfilling', 'renewing', 'ended'],
        'handovers' => ['executing', 'planning', 'accepted'],
        'tickets' => ['open', 'review', 'waiting', 'resolved', 'closed'],
    ];

    public function __construct(private readonly Database $db)
    {
    }

    public function navigation(Access $actor, ?int $company, bool $admin): array
    {
        $links = [];
        if ($company !== null) {
            foreach (self::NAVIGATION as $view => [$title, , $roles]) {
                if ($actor->has($company, ...$roles)) {
                    $links[$view] = $title;
                }
            }
        }
        if ($admin) {
            $links['settings'] = '组织与权限';
        }
        return $links;
    }

    public function settings(Access $actor): array
    {
        $actor->admin();
        return $this->db->rows(
            'SELECT u.id,u.display_name,u.email,u.active,u.is_admin,
                GROUP_CONCAT(CONCAT(c.code,\':\', m.role) ORDER BY c.code,m.role SEPARATOR \', \') AS roles
             FROM users u LEFT JOIN memberships m ON m.user_id = u.id LEFT JOIN companies c ON c.id = m.company_id
             GROUP BY u.id ORDER BY u.id DESC LIMIT 100',
        );
    }

    public function administrative(Access $actor, string $action, array $input): array
    {
        $actor->admin();
        $identity = new Identity($this->db);
        if ($action === 'admin.company') {
            $parent = ($input['parent_id'] ?? '') === '' ? null : Input::id($input['parent_id'], '上级公司');
            return ['id' => $identity->addCompany($actor, Input::text($input['code'] ?? null, '公司代码', 32),
                Input::text($input['name'] ?? null, '公司名称', 160), $parent)];
        }
        if ($action === 'admin.user') {
            if (!is_string($input['password'] ?? null)) {
                throw new BusinessError('请输入密码。');
            }
            return ['id' => $identity->addUser($actor, Input::text($input['name'] ?? null, '姓名', 120),
                Input::text($input['email'] ?? null, '邮箱', 254), $input['password'])];
        }
        if ($action === 'admin.membership') {
            $user = Input::id($input['user_id'] ?? null, '用户编号');
            $company = Input::id($input['company_id'] ?? null, '公司编号');
            $operation = Input::choice($input['operation'] ?? null, '岗位操作', ['grant', 'revoke']);
            $identity->membership($actor, $company, $user, Input::choice($input['role'] ?? null, '岗位', Access::ROLES), $operation === 'grant');
            return ['id' => $user];
        }
        throw new BusinessError('未找到管理操作。', 404);
    }
}
