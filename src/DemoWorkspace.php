<?php

declare(strict_types=1);

namespace CustomerSuite;

use DateTimeImmutable;
use DateTimeZone;

final class DemoWorkspace
{
    private const GROUP_CODE = 'DEMO-GROUP';
    private const RECEIPT = 'installed-demo-v1';

    private readonly Identity $identity;
    private readonly Operations $operations;
    private readonly DateTimeImmutable $today;

    public function __construct(private readonly Database $db)
    {
        $this->identity = new Identity($db);
        $this->operations = new Operations($db);
        $this->today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
    }

    public function install(string $email, string $password): array
    {
        $schema = $this->db->row('SELECT DATABASE() AS db')['db'];
        if (!preg_match('/^crm_demo(?:_[a-z0-9_]+)?$/D', $schema) || OutreachGateway::mode() !== 'replay') {
            throw new BusinessError('请在 crm_demo 或 crm_demo_ 开头的独立数据库使用回放模式导入样例。');
        }
        $email = Input::email($email) ?? throw new BusinessError('请指定管理员邮箱。');
        $row = $this->db->row('SELECT id FROM users WHERE email = ? AND is_admin = 1 AND active = 1', [$email]);
        if ($row === null) {
            throw new BusinessError('请先用 init-admin 建立演示库管理员。', 404);
        }
        $admin = new Access($this->db, $row['id']);
        $lock = 'customer-suite-demo-' . substr(hash('sha256', $schema), 0, 24);
        if ((int) $this->db->row('SELECT GET_LOCK(?,15) AS acquired', [$lock])['acquired'] !== 1) {
            throw new BusinessError('演示导入正在运行。', 409);
        }
        try {
            return $this->db->transaction(function () use ($admin, $password): array {
                $existing = $this->db->row('SELECT id FROM companies WHERE code = ?', [self::GROUP_CODE]);
                $receipts = new Receipts($this->db);
                if ($existing !== null) {
                    return ['replayed' => true, 'result' => $receipts->read($admin, $existing['id'], self::RECEIPT)['result']];
                }
                if ($this->db->row('SELECT id FROM companies LIMIT 1') !== null) {
                    throw new BusinessError('演示导入要求一个尚无公司的已初始化数据库。', 409);
                }
                // The sample signs contracts earlier in the week than Friday's imported service event.
                $signed = $this->today->modify('last friday')->modify('-4 days')->setTime(1, 30);
                $this->db->run('SET SESSION timestamp = ' . $signed->getTimestamp());
                try {
                    $group = $this->identity->addCompany($admin, self::GROUP_CODE, '演示总部 · 星桥企业服务');
                    return $receipts->run($admin, $group, self::RECEIPT, 'demo.install', [],
                        fn (): array => $this->populate($admin, $group, $password));
                } finally {
                    $this->db->run('SET SESSION timestamp = 0');
                }
            });
        } finally {
            $this->db->run('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    public function subscriptions(string $email): array
    {
        if (!$this->db->row('SELECT id FROM companies WHERE code=?',[self::GROUP_CODE])) {
            throw new BusinessError('请先运行 demo-install 安装三公司演示。');
        }
        $demo = $this->install($email,'')['result'];
        $company = $demo['companies']['c'];
        $manager = new Access($this->db,$demo['users']['manager']['id']);
        $support = new Access($this->db,$demo['users']['c-support']['id']);
        return (new Receipts($this->db))->run($manager,$company,'installed-subscriptions-v2','demo.subscriptions',[],
            function () use ($demo,$company,$manager,$support): array {
                $agreements = new Agreements($this->db);
                $contract = $agreements->subject($company,$demo['contracts']['c']);
                $revision = $agreements->revision($company,$contract['id'],$contract['active_revision']);
                $first = (new DateTimeImmutable($revision['starts_on']))->modify('first day of next month');
                $next = $first->modify('+1 month');
                $write = fn(string $action,array $data): array => $this->write($manager,$company,$action,$data);
                $pilot = new SubscriptionPilot($this->db);
                if ($pilot->status($company)['state']==='setup') {
                    $write('pilot.backfill',['limit'=>500]);
                    $write('pilot.change',['state'=>'active','version'=>0]);
                }
                $plan = $write('plan.publish',['code'=>'INDUSTRIAL-SERVICE','name'=>'设备远程巡检','previous_revision'=>0,
                    'effective_on'=>$revision['starts_on'],'currency'=>'CNY','monthly_fee'=>'100','service_level'=>'standard',
                    'metrics'=>[['metric'=>'api_calls','included_units'=>100,'overage_fee'=>'2']]]);
                $sub = $write('subscription.open',['agreement_id'=>$contract['id'],'agreement_revision'=>$revision['revision'],'line_no'=>1,
                    'plan_id'=>$plan['plan_id'],'entitlement_rev'=>1,'service_company_id'=>$company,'service_account_id'=>$contract['account_id'],
                    'mode'=>'paid','seats'=>1,'term_start'=>$first->format('Y-m-d'),'term_end'=>$first->modify('+3 months')->format('Y-m-d'),
                    'billing_timezone'=>'Asia/Shanghai'])['subscription_id'];
                $usage = (new UsageEvents($this->db))->ingest($manager,$company,$sub,'demo-meter-120',['source'=>'equipment','event_id'=>'inspection-120',
                    'metric'=>'api_calls','units'=>120,'occurred_at'=>$first->format('Y-m-d').'T12:00:00+08:00']);
                $issue = function (DateTimeImmutable $month) use ($sub,$write): array {
                    $draft = $write('billing.draft',['id'=>$sub,'period'=>$month->format('Y-m')]);
                    return $write('billing.lock',['id'=>$draft['statement_id'],'version'=>$draft['statement_rev'],'digest'=>$draft['digest']]);
                };
                $original = $issue($first);
                $ref = ['id'=>$original['statement_id'],'version'=>$original['statement_rev']];
                $statement = (new SubscriptionBilling($this->db))->detail($support,$company,$ref['id']);
                $line = array_values(array_filter($statement['lines'],static fn(array $line): bool => $line['kind']==='overage'))[0];
                $dispute = $this->write($support,$company,'dispute.raise',$ref+['line_id'=>$line['id'],'units'=>5,'reason'=>'巡检系统重复登记五次']);
                $credit = $write('dispute.decide',$ref+['dispute_id'=>$dispute['dispute_id'],'dispute_rev'=>1,'decision'=>'credited','reason'=>'已与巡检流水核对']);
                $adjusted = $issue($next);
                return ['subscription_id'=>$sub,'usage_id'=>$usage['usage_id'],'credit_id'=>$credit['credit_id'],'original'=>$original,'adjusted'=>$adjusted,
                    'workbench_path'=>'/?'.http_build_query(['view'=>'subscriptions','company'=>$company,'id'=>$sub,'date'=>$next->format('Y-m-d'),'statement'=>$adjusted['statement_id']])];
            });
    }

    private function populate(Access $admin, int $group, string $password): array
    {
        $names = ['a' => '甲分公司 · 华东销售', 'b' => '乙分公司 · 华中续约', 'c' => '丙分公司 · 华南支持'];
        $companies = [];
        foreach ($names as $division => $name) {
            $companies[$division] = $this->identity->addCompany($admin, 'DEMO-' . $division, $name, $group);
        }
        ['a' => $a, 'b' => $b, 'c' => $c] = $companies;
        $roles = [
            'manager' => ['集团经理', [$group => ['manager'], $a => ['manager'], $b => ['manager'], $c => ['manager']]],
            'a-sales' => ['甲分公司市场与销售', [$a => ['marketing', 'sales']]],
            'a-delivery' => ['甲分公司交付', [$a => ['delivery']]],
            'b-success' => ['乙分公司客户成功', [$b => ['success', 'sales', 'delivery']]],
            'c-support' => ['丙分公司服务', [$c => ['service', 'sales']]],
        ];
        $users = [];
        foreach ($roles as $key => [$display, $memberships]) {
            $id = $this->identity->addUser($admin, $display, $key . '@demo.example.test', $password);
            $users[$key] = new Access($this->db, $id);
            foreach ($memberships as $company => $companyRoles) {
                foreach ($companyRoles as $role) {
                    $this->identity->membership($admin, $company, $id, $role, true);
                }
            }
        }
        ['manager' => $manager, 'a-sales' => $seller, 'a-delivery' => $delivery, 'b-success' => $success, 'c-support' => $support] = $users;
        $master = $this->write($manager, $group, 'account.create', ['name' => '星霁工业集团 · 客户总部']);
        $buyer = $this->write($manager, $group, 'contact.create', ['account_id' => $master['id'], 'name' => '集团采购代表', 'job_title' => '采购经理']);
        $owners = ['a' => $seller, 'b' => $success, 'c' => $support];
        $customerNames = ['a' => '星霁精密制造', 'b' => '星霁工业运维', 'c' => '星霁设备服务'];
        $contacts = [];
        $allocations = [];
        $start = $this->date('-90 days');
        foreach ($companies as $division => $company) {
            $customer = $this->write($manager, $company, 'account.create', ['name' => $customerNames[$division],
                'parent_id' => $master['id'], 'parent_company_id' => $group, 'owner_id' => $owners[$division]->userId]);
            $contacts[$division] = $this->write($owners[$division], $company, 'contact.create', ['account_id' => $customer['id'],
                'name' => '签约与服务代表', 'job_title' => '运营主管', 'email' => "$division.contact@example.test"]);
            $allocations[$division] = ['company_id' => $company, 'account_id' => $customer['id'],
                'reference_no' => 'DEMO-AGREEMENT-' . strtoupper($division), 'amount' => '20000', 'starts_on' => $start,
                'ends_on' => $this->date($division === 'b' ? '+14 days' : '+365 days'), 'service_level' => 'standard',
                'evidence_ref' => 'DEMO-SIGNING-' . strtoupper($division)];
        }
        $negotiation = $this->proposal($manager, $group, $master['id'], $buyer['id'], '集团工业服务采购', '60000');
        $quote = $this->sign($manager, $group, $negotiation, 'group', array_values($allocations));
        $contractIds = array_column($this->db->rows(
            'SELECT company_id,id FROM agreements WHERE source_company_id = ? AND quote_id = ?', [$group, $quote]), 'id', 'company_id');
        $this->db->run('SET SESSION timestamp = 0');
        $contracts = ['a' => $contractIds[$a], 'b' => $contractIds[$b], 'c' => $contractIds[$c]];
        $sales = $this->sales($seller, $manager, $a, $allocations['a']['account_id'], $contacts['a']['id']);
        $handover = $this->delivery($seller, $delivery, $a, $contracts['a'], $contacts['a']['id']);
        $otherDelivery = $this->write($manager, $b, 'handover.open', ['agreement_id' => $contracts['b'], 'agreement_version' => 1,
            'agreement_revision' => 1, 'owner_id' => $success->userId, 'contact_id' => $contacts['b']['id'], 'scope' => '复核维护清单与到期工作']);
        $this->write($manager, $b, 'handover.confirm', ['id' => $otherDelivery['id'], 'version' => $otherDelivery['version'], 'side' => 'sales']);
        $renewal = $this->renewal($manager, $success, $b, $contracts['b'], $allocations['b']['account_id'], $contacts['b']);
        $tickets = $this->service($manager, $support, $c, $contracts['c'], $allocations['c'], $contacts['c']['id']);
        $rates = $this->write($manager, $group, 'report.fx', ['rate_date' => $this->date('today'), 'source' => '演示记账汇率', 'rates' => []]);
        $report = $this->write($manager, $group, 'report.capture', ['label' => '集团三公司经营快照',
            'company_ids' => array_values($companies), 'fx_set_id' => $rates['id']]);
        return ['group' => $group, 'companies' => $companies, 'users' => array_map(static fn (Access $user): array =>
            array_intersect_key($user->user(), ['id' => true, 'email' => true]), $users), 'quote' => $quote, 'contracts' => $contracts,
            'sales' => $sales, 'handover' => $handover, 'b_handover_id' => $otherDelivery['id'], 'renewal' => $renewal,
            'tickets' => $tickets, 'report_id' => $report['id']];
    }

    private function sales(Access $seller, Access $manager, int $company, int $customer, int $contact): array
    {
        $source = ['name' => '自动化设备扩容线索', 'email' => 'lead@example.test', 'source' => 'demo', 'occurred_at' => $this->instant()];
        $lead = [];
        foreach ([['EXPO', '区域工业展', 'event'], ['WEB', '公开产品咨询', 'web']] as [$code, $name, $channel]) {
            $campaign = $this->write($seller, $company, 'campaign.create', compact('code', 'name', 'channel'));
            $lead = $this->write($seller, $company, 'lead.receive', $source + ['campaign_id' => $campaign['id'], 'external_id' => $code]);
        }
        $this->write($seller, $company, 'lead.receive', ['name' => '设备巡检咨询', 'email' => 'inspection@example.test', 'source' => 'demo',
            'occurred_at' => $source['occurred_at'], 'campaign_id' => $campaign['id'], 'external_id' => 'INSPECTION']);
        $claim = $this->write($seller, $company, 'lead.claim', ['id' => $lead['id'], 'version' => $lead['version']]);
        $qualification = $this->write($seller, $company, 'lead.qualify', ['id' => $lead['id'], 'version' => $claim['version'],
            'account_id' => $customer, 'next_follow_up' => $this->date('+7 days')]);
        $opportunity = $this->proposal($seller, $company, $customer, $contact, '甲分公司 · 自动化设备扩容', '1000000',
            ['lead_id' => $lead['id'], 'lead_version' => $qualification['version']]);
        $this->write($seller, $company, 'opportunity.move', $opportunity + ['stage' => 'negotiation']);
        $forecast = $this->write($manager, $company, 'forecast.capture', ['label' => '甲分公司销售预测',
            'period_start' => $this->date('today'), 'period_end' => $this->date('+90 days')]);
        return ['customer_id' => $customer, 'lead_id' => $lead['id'], 'opportunity_id' => $opportunity['id'], 'forecast_id' => $forecast['id']];
    }

    private function proposal(Access $actor, int $company, int $account, int $contact, string $title, string $amount, array $source = []): array
    {
        $id = $this->write($actor, $company, 'opportunity.create', $source + ['account_id' => $account, 'title' => $title,
            'currency' => 'CNY', 'amount' => $amount, 'expected_close' => $this->date('+35 days')])['id'];
        $this->write($actor, $company, 'opportunity.move', ['id' => $id, 'version' => 1, 'stage' => 'qualified', 'decision_contact_id' => $contact]);
        $this->write($actor, $company, 'opportunity.products', ['id' => $id, 'version' => 2, 'products' => [[
            'product_code' => 'INDUSTRIAL-SERVICE', 'product_version' => '1', 'description' => $title, 'quantity' => 1, 'unit_price' => $amount,
        ]]]);
        $move = $this->write($actor, $company, 'opportunity.move', ['id' => $id, 'version' => 3, 'stage' => 'proposal']);
        return ['id' => $id, 'version' => $move['version']];
    }

    private function sign(Access $actor, int $company, array $proposal, string $scope, array $allocations): int
    {
        $quote = $this->write($actor, $company, 'quote.prepare', ['opportunity_id' => $proposal['id'], 'opportunity_version' => $proposal['version'],
            'discount_bps' => 0, 'expires_on' => $this->date('+28 days'), 'terms' => '工业设备服务与实施，合成案例用协议。']);
        $approved = $this->write($actor, $company, 'quote.submit', ['id' => $quote['id'], 'version' => $quote['version']]);
        $this->write($actor, $company, 'agreement.sign', ['id' => $quote['id'], 'version' => $approved['version'], 'scope' => $scope, 'allocations' => $allocations]);
        return $quote['id'];
    }

    private function delivery(Access $seller, Access $delivery, int $company, int $contract, int $contact): array
    {
        $opened = $this->write($seller, $company, 'handover.open', ['agreement_id' => $contract, 'agreement_version' => 1,
            'agreement_revision' => 1, 'owner_id' => $delivery->userId, 'contact_id' => $contact, 'scope' => '设备接入、培训及年度维护']);
        $record = ['id' => $opened['id']];
        $salesConfirmed = $this->write($seller, $company, 'handover.confirm', $record + ['version' => $opened['version'], 'side' => 'sales']);
        $confirmed = $this->write($delivery, $company, 'handover.confirm', $record + ['version' => $salesConfirmed['version'], 'side' => 'delivery']);
        $milestone = $this->write($delivery, $company, 'handover.milestone.add', $record + ['version' => $confirmed['version'],
            'title' => '设备接入与操作培训', 'due_on' => $this->date('today')]);
        $completed = $this->write($delivery, $company, 'handover.milestone.complete', $record + ['version' => $milestone['version'],
            'milestone_id' => $milestone['milestone_id']]);
        $accepted = $this->write($delivery, $company, 'handover.accept', $record + ['version' => $completed['version'],
            'agreement_revision' => 1, 'contact_id' => $contact, 'confirmed_at' => $this->instant(),
            'evidence_reference' => 'DEMO-ACCEPT-A', 'file_name' => '设备培训签收.txt',
            'file_base64' => base64_encode("演示签收记录\n设备接入与操作培训均已完成。\n")]);
        return $record + ['sha256' => $accepted['sha256']];
    }

    private function renewal(Access $manager, Access $owner, int $company, int $contract, int $customer, array $contact): array
    {
        $this->write($manager, $company, 'success.assign', ['agreement_id' => $contract, 'agreement_version' => 1,
            'owner_id' => $owner->userId, 'reason' => '年度运维续约跟踪']);
        $health = $this->write($owner, $company, 'success.health', ['agreement_id' => $contract]);
        $renewal = $this->write($manager, $company, 'renewal.scan', [])['items'][0];
        $this->write($owner, $company, 'contact.permission', ['account_id' => $customer, 'id' => $contact['id'], 'version' => $contact['version'],
            'purpose' => 'success', 'channel' => 'email', 'allowed' => true, 'evidence' => 'DEMO-CONSENT-B']);
        $job = $this->write($owner, $company, 'outreach.queue', ['id' => $renewal['id'], 'version' => 1,
            'contact_id' => $contact['id'], 'channel' => 'email', 'message' => '请核对下一年度的设备维护期限。']);
        $sent = $this->write($owner, $company, 'outreach.process', ['id' => $job['id'], 'version' => $job['version']]);
        return ['id' => $renewal['id'], 'opportunity_id' => $renewal['opportunity_id'], 'cycle_ends_on' => $renewal['cycle_ends_on'],
            'health_id' => $health['id'], 'outreach_id' => $job['id'], 'outreach_state' => $sent['state']];
    }

    private function service(Access $manager, Access $support, int $company, int $contract, array $allocation, int $contact): array
    {
        $calendar = $this->write($manager, $company, 'service.calendar', ['code' => 'DEMO-CN', 'name' => '丙公司工作时间',
            'timezone' => 'Asia/Shanghai', 'week' => array_fill_keys([1, 2, 3, 4, 5], [['09:00', '12:00'], ['13:00', '18:00']]), 'exceptions' => []]);
        $this->write($manager, $company, 'service.policy', ['service_level' => 'standard', 'effective_from' => $this->date('-90 days') . 'T00:00:00Z',
            'calendar_id' => $calendar['id'], 'pause_for_customer' => true, 'limits' => ['P1' => ['response' => 15, 'resolution' => 60],
                'P2' => ['response' => 30, 'resolution' => 120], 'P3' => ['response' => 90, 'resolution' => 360], 'P4' => ['response' => 120, 'resolution' => 480]]]);
        $queue = $this->write($manager, $company, 'service.queue', ['code' => 'DEMO-SUPPORT', 'name' => '设备维护支持']);
        $this->write($manager, $company, 'service.route', ['category' => 'incident', 'queue_id' => $queue['id']]);
        $this->write($manager, $company, 'service.grant', ['agreement_id' => $contract, 'user_id' => $support->userId, 'allowed' => true]);
        $friday = $this->today->modify('last friday');
        $event = ['agreement_id' => $contract, 'category' => 'incident', 'priority' => 'P2', 'title' => '周五临近下班的设备请求',
            'description' => '切换备用设备后请核对事件记录。', 'occurred_at' => $friday->format('Y-m-d') . 'T17:50:00+08:00',
            'source' => 'demo', 'external_id' => 'C-FRIDAY'];
        $ticket = $this->write($support, $company, 'ticket.receive', $event);
        $legacy = $this->proposal($support, $company, $allocation['account_id'], $contact, '旧设备维护协议', '20000');
        $quote = $this->sign($support, $company, $legacy, 'single', [array_replace($allocation, [
            'reference_no' => 'DEMO-EXPIRED-C', 'starts_on' => $this->date('-180 days'), 'ends_on' => $this->date('-10 days'), 'evidence_ref' => 'DEMO-OLD-C',
        ])]);
        $oldContract = $this->db->row('SELECT id FROM agreements WHERE company_id = ? AND quote_id = ?', [$company, $quote])['id'];
        $this->write($manager, $company, 'service.grant', ['agreement_id' => $oldContract, 'user_id' => $support->userId, 'allowed' => true]);
        $expired = $this->write($support, $company, 'ticket.receive', array_replace($event, [
            'title' => '旧合同服务请求', 'agreement_id' => $oldContract, 'occurred_at' => $this->instant(), 'external_id' => 'C-EXPIRED',
        ]));
        $deadline = $ticket['clocks']['response']['deadline_at'] ?? null;
        $expected = $friday->modify('next monday')->format('Y-m-d') . ' 01:20:00.000000';
        if ($deadline !== $expected || $expired['state'] !== 'review') {
            throw new BusinessError('演示工单验收失败，未保存导入结果。', 409);
        }
        return ['id' => $ticket['id'], 'calendar_id' => $calendar['id'], 'response_deadline_utc' => $deadline,
            'occurred_at' => $event['occurred_at'], 'expired_id' => $expired['id'], 'expired_state' => $expired['state']];
    }

    private function write(Access $actor, int $company, string $operation, array $data): array
    {
        return $this->operations->execute($actor, $company, $operation, bin2hex(random_bytes(16)), $data)['result'];
    }

    private function date(string $relative): string
    {
        return $this->today->modify($relative)->format('Y-m-d');
    }

    private function instant(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
    }
}
