<?php

declare(strict_types=1);

namespace CustomerSuite;

final class Opportunities
{
    public const WEIGHTS = ['discovery' => 1000, 'qualified' => 2500, 'proposal' => 4000, 'negotiation' => 6000, 'won' => 10000, 'lost' => 0];
    private const PROGRESSION = [
        'discovery' => ['qualified', 'lost'],
        'qualified' => ['discovery', 'proposal', 'lost'],
        'proposal' => ['qualified', 'negotiation', 'lost'],
        'negotiation' => ['proposal', 'won', 'lost'],
        'won' => [],
        'lost' => [],
    ];

    private readonly Accounts $accounts;
    private readonly Audit $audit;

    public function __construct(private readonly Database $db)
    {
        $this->accounts = new Accounts($db);
        $this->audit = new Audit($db);
    }

    public function create(Access $actor, int $companyId, array $input): array
    {
        $accountId = Input::id($input['account_id'] ?? null, '客户编号');
        $leadId = isset($input['lead_id']) ? Input::id($input['lead_id']) : null;
        $leadVersion = isset($input['lead_version']) ? Input::id($input['lead_version']) : null;
        if (($leadId === null) !== ($leadVersion === null)) {
            throw new BusinessError('转化线索时须同时指定线索和版本。');
        }
        $title = Input::text($input['title'] ?? null, '商机名称', 180);
        $currency = Money::currency($input['currency'] ?? null);
        $amount = Money::minor($input['amount'] ?? null, '预估金额', $currency);
        $close = Input::date($input['expected_close'] ?? null);
        return $this->db->transaction(function () use ($actor, $companyId, $accountId, $leadId, $leadVersion, $title, $currency, $amount, $close): array {
            $account = $this->accounts->writable($actor, $companyId, $accountId);
            if ($account['state'] !== 'active') {
                throw new BusinessError('冻结客户暂不能建立新商机。', 409);
            }
            $owner = (int) $account['owner_id'];
            if ($leadId !== null) {
                $lead = (new Leads($this->db))->forConversion($actor, $companyId, $leadId, $leadVersion, $accountId);
                $owner = (int) $lead['owner_id'];
            }
            $id = $this->insert($actor, $companyId, $accountId, $owner, $title, $currency, $amount, $close, $leadId);
            if ($leadId !== null) {
                $this->db->run("UPDATE leads SET state = 'converted',version = version + 1 WHERE company_id = ? AND id = ?", [$companyId, $leadId]);
                $this->audit->record($actor, $companyId, 'lead', $leadId, $leadVersion + 1, 'lead.convert', ['opportunity_id' => $id]);
            }
            return ['id' => $id, 'version' => 1, 'stage' => 'discovery'];
        });
    }

    public function insert(Access $actor, int $company, int $account, int $owner, string $title,
        string $currency, int $amount, string $close, ?int $lead = null): int
    {
        $id = $this->db->insert(
            'INSERT INTO opportunities (company_id,account_id,owner_id,lead_id,title,currency,value_minor,expected_close)
             VALUES (?,?,?,?,?,?,?,?)', [$company, $account, $owner, $lead, $title, $currency, $amount, $close],
        );
        $this->history($actor, $company, $id, 1, null, 'discovery', self::WEIGHTS['discovery'], null);
        $this->audit->record($actor, $company, 'opportunity', $id, 1, 'opportunity.create', ['account_id' => $account, 'lead_id' => $lead]);
        return $id;
    }

    public function updateProducts(Access $actor, int $company, int $id, int $version, mixed $products): array
    {
        return $this->db->transaction(function () use ($actor, $company, $id, $version, $products): array {
            $row = $this->writable($actor, $company, $id);
            Accounts::checkVersion($row, $version);
            $this->assertOpen($row);
            $lines = OpportunityLines::parse($products, $row['currency']);
            if (!$lines['items'] && in_array($row['stage'], ['proposal', 'negotiation'], true)) {
                throw new BusinessError('报价和谈判中的商机须保留至少一项产品。', 409);
            }
            $this->db->run('DELETE FROM opportunity_lines WHERE company_id = ? AND opportunity_id = ?', [$company, $id]);
            $this->db->insertRows(
                'INSERT INTO opportunity_lines (company_id,opportunity_id,line_no,product_code,product_version,description,quantity,unit_minor)',
                array_map(static fn (array $line): array => [$company, $id, ...$line], $lines['items']),
            );
            $this->db->run(
                'UPDATE opportunities SET value_minor = ?,version = version + 1 WHERE company_id = ? AND id = ?',
                [$lines['total'], $company, $id],
            );
            $this->audit->record($actor, $company, 'opportunity', $id, $version + 1, 'opportunity.products',
                ['line_count' => count($lines['items']), 'value_minor' => $lines['total']]);
            return ['id' => $id, 'version' => $version + 1, 'value_minor' => $lines['total']];
        });
    }

    public function move(Access $actor, int $company, int $id, int $version, array $input): array
    {
        $stage = Input::choice($input['stage'] ?? null, '商机阶段', array_keys(self::WEIGHTS));
        $reason = Input::optionalText($input['reason'] ?? null, '阶段变更原因', 240);
        $contactId = isset($input['decision_contact_id']) ? Input::id($input['decision_contact_id']) : null;
        $followUp = isset($input['next_follow_up']) ? Input::date($input['next_follow_up']) : null;
        return $this->db->transaction(function () use ($actor, $company, $id, $version, $stage, $reason, $contactId, $followUp): array {
            $row = $this->writable($actor, $company, $id);
            Accounts::checkVersion($row, $version);
            if (!in_array($stage, self::PROGRESSION[$row['stage']], true)) {
                throw new BusinessError('当前阶段不允许该变更。', 409);
            }
            $decider = $contactId ?? ($row['decision_contact_id'] === null ? null : (int) $row['decision_contact_id']);
            if ($decider !== null && !$this->db->row(
                'SELECT id FROM contacts WHERE company_id = ? AND account_id = ? AND id = ?',
                [$company, $row['account_id'], $decider],
            )) {
                throw new BusinessError('决策人须属于当前客户。');
            }
            if (in_array($stage, ['qualified', 'proposal', 'negotiation', 'won'], true) && $decider === null) {
                throw new BusinessError('进入当前阶段前须登记决策人。');
            }
            if (in_array($stage, ['proposal', 'negotiation', 'won'], true) && (!$row['value_minor'] || !$this->lines($company, $id))) {
                throw new BusinessError('进入报价阶段前须有非零金额的产品明细。');
            }
            if (($stage === 'lost' || self::WEIGHTS[$stage] < self::WEIGHTS[$row['stage']]) && $reason === null) {
                throw new BusinessError('关闭或回退商机须说明原因。');
            }
            $probability = self::WEIGHTS[$stage];
            $this->db->run(
                'UPDATE opportunities SET stage = ?,probability_bps = ?,decision_contact_id = ?,
                 next_follow_up = COALESCE(?,next_follow_up),stage_reason = ?,version = version + 1 WHERE company_id = ? AND id = ?',
                [$stage, $probability, $decider, $followUp, $reason, $company, $id],
            );
            $this->history($actor, $company, $id, $version + 1, $row['stage'], $stage, $probability, $reason);
            $this->audit->record($actor, $company, 'opportunity', $id, $version + 1, 'opportunity.move',
                ['previous_stage' => $row['stage'], 'stage' => $stage, 'probability_bps' => $probability]);
            return ['id' => $id, 'version' => $version + 1, 'stage' => $stage, 'probability_bps' => $probability];
        });
    }

    public function detail(Access $actor, int $company, int $id, int $after = 0): array
    {
        $actor->allow($company, 'manager', 'sales');
        $row = $this->row($company, $id);
        if (!$actor->has($company, 'manager') && !$this->owned($actor, $company, $row)) {
            throw new BusinessError('未找到商机。', 404);
        }
        $row['products'] = $this->lines($company, $id);
        $row['stages'] = $this->db->rows(
            'SELECT * FROM stage_history WHERE company_id = ? AND opportunity_id = ? AND id > ? ORDER BY id LIMIT 100',
            [$company, $id, $after],
        );
        $row['timeline'] = $this->audit->timeline($company, 'opportunity', $id, $after);
        return $row;
    }

    public function list(Access $actor, int $company, string $stage = 'discovery', ?string $cursor = null): array
    {
        $actor->allow($company, 'manager', 'sales');
        $stage = Input::choice($stage, '商机阶段', array_keys(self::WEIGHTS));
        $page = WorklistPage::read($cursor);
        $own = !$actor->has($company, 'manager');
        return WorklistPage::result($this->db->rows(
            'SELECT id,title,account_id,owner_id,stage,currency,value_minor,probability_bps,expected_close,version,updated_at
             FROM opportunities WHERE company_id = ? AND stage = ?' .
            ($own ? ' AND owner_id = ? AND account_id IN (SELECT a.id FROM accounts a WHERE a.company_id = ? AND a.owner_id = ?)' : '') .
            $page->clause() . ' ORDER BY updated_at DESC,id DESC LIMIT ' . (WorklistPage::SIZE + 1),
            [$company, $stage, ...($own ? [$actor->userId, $company, $actor->userId] : []), ...$page->parameters()],
        ));
    }

    public function writable(Access $actor, int $company, int $id): array
    {
        $actor->allow($company, 'manager', 'sales');
        $existing = $this->row($company, $id);
        $this->accounts->writable($actor, $company, (int) $existing['account_id']);
        $row = $this->row($company, $id, true);
        if (!$actor->has($company, 'manager') && (int) $row['owner_id'] !== $actor->userId) {
            throw new BusinessError('商机分配给其他负责人。', 403);
        }
        return $row;
    }

    public function lines(int $company, int $id): array
    {
        return $this->db->rows(
            'SELECT line_no,product_code,product_version,description,quantity,unit_minor FROM opportunity_lines
             WHERE company_id = ? AND opportunity_id = ? ORDER BY line_no', [$company, $id],
        );
    }

    private function history(Access $actor, int $company, int $id, int $version, ?string $from, string $to, int $probability, ?string $reason): void
    {
        $this->db->run(
            'INSERT INTO stage_history (company_id,opportunity_id,actor_id,from_stage,to_stage,opportunity_version,probability_bps,reason)
             VALUES (?,?,?,?,?,?,?,?)', [$company, $id, $actor->userId, $from, $to, $version, $probability, $reason],
        );
    }

    private function row(int $company, int $id, bool $lock = false): array
    {
        return $this->db->row('SELECT * FROM opportunities WHERE company_id = ? AND id = ?' . ($lock ? ' FOR UPDATE' : ''), [$company, $id])
            ?? throw new BusinessError('未找到商机。', 404);
    }

    private function owned(Access $actor, int $company, array $row): bool
    {
        return (int) $row['owner_id'] === $actor->userId
            && $this->db->row('SELECT id FROM accounts WHERE company_id = ? AND id = ? AND owner_id = ?',
                [$company, $row['account_id'], $actor->userId]) !== null;
    }

    private function assertOpen(array $row): void
    {
        if (in_array($row['stage'], ['won', 'lost'], true)) {
            throw new BusinessError('最终阶段的商机不能改写。', 409);
        }
    }
}
