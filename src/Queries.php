<?php

declare(strict_types=1);

namespace CustomerSuite;

final class Queries
{
    public function __construct(private readonly Database $db)
    {
    }

    public function read(Access $actor, int $company, string $resource, array $query): array
    {
        $id = isset($query['id']) ? Input::id($query['id']) : null;
        $after = isset($query['after']) ? Input::id($query['after']) : 0;
        $cursor = Input::optionalText($query['cursor'] ?? null, '分页标记', 120);
        return match ($resource) {
            'retention' => (new SubscriptionRenewals($this->db))->board($actor,$company,$query['month'] ?? gmdate('Y-m'),isset($query['before']) ? Input::id($query['before']) : PHP_INT_MAX),
            'statement' => (new SubscriptionBilling($this->db))->detail($actor,$company,$id ?? throw new BusinessError('须指定账单编号。')),
            'usage-attempts' => (new UsageEvents($this->db))->history($actor,$company,$id ?? throw new BusinessError('须指定订阅编号。'),
                $query['disposition'] ?? 'conflict',isset($query['before']) ? Input::id($query['before']) : PHP_INT_MAX),
            'subscriptions' => $id === null ? (new Subscriptions($this->db))->register($actor,$company,isset($query['before']) ? Input::id($query['before']) : PHP_INT_MAX)
                    + ['pilot'=>(new SubscriptionPilot($this->db))->status($company)]
                : (new Subscriptions($this->db))->detail($actor,$company,$id,Input::date($query['date'] ?? gmdate('Y-m-d'))),
            'subscription-plan' => (new SubscriptionPlans($this->db))->onDate($actor, $company,
                $id ?? throw new BusinessError('须指定套餐编号。'), Input::date($query['date'] ?? null)),
            'accounts' => $id === null
                ? (new Accounts($this->db))->list($actor, $company, Input::text($query['state'] ?? 'active', '客户状态', 20), $cursor)
                : (new Accounts($this->db))->detail($actor, $company, $id, $after),
            'contacts' => ['items' => (new Contacts($this->db))->forAccount($actor, $company,
                $id ?? throw new BusinessError('须指定客户编号。'), $after)],
            'directory' => ['items' => (new Contacts($this->db))->directory($actor, $company,
                $id ?? throw new BusinessError('须指定客户编号。'), Input::text($query['channel'] ?? 'email', '渠道', 20))],
            'campaigns' => ['items' => (new Campaigns($this->db))->list($actor, $company, $after ?: PHP_INT_MAX)],
            'leads' => $id === null
                ? (new Leads($this->db))->list($actor, $company, Input::text($query['state'] ?? 'open', '线索状态', 20), $cursor)
                : (new Leads($this->db))->detail($actor, $company, $id, $after),
            'opportunities' => $id === null
                ? (new Opportunities($this->db))->list($actor, $company, Input::text($query['state'] ?? 'discovery', '商机阶段', 20), $cursor)
                : (new Opportunities($this->db))->detail($actor, $company, $id, $after),
            'forecasts' => $id === null
                ? ['items' => (new Forecasts($this->db))->list($actor, $company, $after ?: PHP_INT_MAX)]
                : (new Forecasts($this->db))->detail($actor, $company, $id, $after),
            'forecast-diff' => (new Forecasts($this->db))->compare($actor, $company,
                $id ?? throw new BusinessError('须指定原快照。'), Input::id($query['to'] ?? null, '新快照'), $after),
            'quotes' => $id === null
                ? (new Quotes($this->db))->list($actor, $company, Input::text($query['state'] ?? 'pending', '报价状态', 20), $cursor)
                : (new Quotes($this->db))->detail($actor, $company, $id, $after, isset($query['revision']) ? Input::id($query['revision']) : null),
            'agreements' => $id === null
                ? (new Agreements($this->db))->list($actor, $company, Input::text($query['state'] ?? 'signed', '合同状态', 20), $cursor)
                : (new Agreements($this->db))->detail($actor, $company, $id, $after, isset($query['revision']) ? Input::id($query['revision']) : null),
            'handovers' => $id === null
                ? (new Handovers($this->db))->list($actor, $company, Input::text($query['state'] ?? 'executing', '交接状态', 20), $cursor)
                : (new Handovers($this->db))->detail($actor, $company, $id, $after),
            'service-settings' => (new ServiceSettings($this->db))->list($actor, $company),
            'tickets' => $id === null
                ? (new ServiceTickets($this->db))->list($actor, $company, Input::text($query['state'] ?? 'open', '工单状态', 20),
                    isset($query['queue']) ? Input::id($query['queue']) : null, $cursor)
                : (new ServiceTickets($this->db))->detail($actor, $company, $id, $after, Input::optionalText($query['at'] ?? null, '查看时点', 35)),
            'receipts' => (new Receipts($this->db))->read($actor, $company, Input::key($query['key'] ?? null, '请求标识', 128)),
            'success-contracts' => $id === null
                ? (new SuccessContracts($this->db))->list($actor, $company, $cursor)
                : (new SuccessContracts($this->db))->detail($actor, $company, $id),
            'health' => $id === null
                ? (new HealthSnapshots($this->db))->list($actor, $company, Input::optionalText($query['state'] ?? null, '健康度等级', 20), $cursor)
                : (new HealthSnapshots($this->db))->detail($actor, $company, $id),
            'renewals' => $id === null
                ? (new Renewals($this->db))->list($actor, $company, Input::optionalText($query['state'] ?? null, '续约状态', 20), $cursor)
                : (new Renewals($this->db))->detail($actor, $company, $id, $after),
            'outreach' => ['items' => (new Outreach($this->db))->related($actor, $company,
                $id ?? throw new BusinessError('须指定续约编号。'), $after)],
            'reports' => $id === null
                ? ['items' => (new Reports($this->db))->list($actor, $company,
                    isset($query['before']) ? Input::id($query['before']) : PHP_INT_MAX)]
                : (new Reports($this->db))->detail($actor, $company, $id, $query),
            'report-rates' => ['items' => (new ReportRates($this->db))->list($actor, $company)],
            'batches' => $id === null
                ? (new BatchJobs($this->db))->list($actor, $company, isset($query['before']) ? Input::id($query['before']) : PHP_INT_MAX)
                : (new BatchJobs($this->db))->detail($actor, $company, $id, $after),
            'batch-candidates' => (new BatchJobs($this->db))->candidates($actor, $company,
                Input::text($query['kind'] ?? null, '任务类型', 32), isset($query['before']) ? Input::id($query['before']) : PHP_INT_MAX),
            default => throw new BusinessError('未找到资源。', 404),
        };
    }
}
