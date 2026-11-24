<?php

declare(strict_types=1);

namespace CustomerSuite;

final class Quotes
{
    public const STATES = ['draft', 'pending', 'approved', 'rejected', 'contracted'];
    public const DEFAULT_LIMIT_BPS = 1000;

    private readonly Opportunities $opportunities;
    private readonly Audit $audit;

    public function __construct(private readonly Database $db)
    {
        $this->opportunities = new Opportunities($db);
        $this->audit = new Audit($db);
    }

    public function setPolicy(Access $actor, int $company, mixed $limit): array
    {
        $actor->allow($company, 'manager');
        $limit = Input::integer($limit, '自行审批折扣上限（基点）', 0, 10000);
        return $this->db->transaction(function () use ($actor, $company, $limit): array {
            $this->db->row('SELECT id FROM companies WHERE id = ? FOR UPDATE', [$company]);
            $version = (int) ($this->policy($company)['version']) + 1;
            $this->db->run(
                'INSERT INTO quote_policies (company_id,self_approval_bps,version) VALUES (?,?,?)
                 ON DUPLICATE KEY UPDATE self_approval_bps = ?,version = ?', [$company, $limit, $version, $limit, $version],
            );
            $this->audit->record($actor, $company, 'quote_policy', $company, $version, 'quote.policy', ['self_approval_bps' => $limit]);
            return ['id' => $company, 'version' => $version, 'self_approval_bps' => $limit];
        });
    }

    public function prepare(Access $actor, int $company, array $data): array
    {
        $opportunityId = $data['opportunity_id'];
        $discount = Input::integer($data['discount_bps'], '折扣（基点）', 0, 9999);
        $expiry = Input::date($data['expires_on']);
        $terms = Input::text($data['terms'], '报价条款', 2000);
        return $this->db->transaction(function () use ($actor, $company, $data, $opportunityId, $discount, $expiry, $terms): array {
            $opportunity = $this->opportunities->writable($actor, $company, $opportunityId);
            Accounts::checkVersion($opportunity, $data['opportunity_version']);
            $this->checkSubject($company, $opportunity);
            $this->checkExpiry($expiry);
            $quote = $this->db->row('SELECT * FROM quotes WHERE company_id = ? AND opportunity_id = ? FOR UPDATE', [$company, $opportunityId]);
            if ($quote !== null) {
                Accounts::checkVersion($quote, $data['version'] ?? 0);
                $current = $this->revision($company, $quote['id'], $quote['revision']);
                if ($quote['state'] === 'contracted' || ($quote['state'] === 'pending' && $current['expires_on'] >= $this->today())) {
                    throw new BusinessError('待审批或已签约的报价不能修订。', 409);
                }
            } elseif (isset($data['version'])) {
                throw new BusinessError('未找到原报价。', 404);
            }
            $lines = $this->opportunities->lines($company, $opportunityId);
            $gross = (int) $opportunity['value_minor'];
            $net = $gross - Money::weighted($gross, $discount);
            if (!$lines || $net < 1) {
                throw new BusinessError('报价须有产品和正数实收金额。');
            }
            $id = $quote['id'] ?? $this->db->insert('INSERT INTO quotes (company_id,opportunity_id) VALUES (?,?)', [$company, $opportunityId]);
            $revision = ($quote['revision'] ?? 0) + 1;
            $this->db->run(
                'INSERT INTO quote_revisions (company_id,quote_id,revision,currency,gross_minor,discount_bps,net_minor,expires_on,terms,prepared_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?)', [$company, $id, $revision, $opportunity['currency'], $gross, $discount, $net, $expiry, $terms, $actor->userId],
            );
            $this->db->insertRows(
                'INSERT INTO quote_lines (company_id,quote_id,revision,line_no,product_code,product_version,description,quantity,unit_minor)',
                array_map(static fn (array $line): array => [$company, $id, $revision, ...array_values($line)], $lines),
            );
            $version = ($quote['version'] ?? 0) + 1;
            $this->db->run("UPDATE quotes SET revision = ?,version = ?,state = 'draft' WHERE company_id = ? AND id = ?", [$revision, $version, $company, $id]);
            $this->audit->record($actor, $company, 'quote', $id, $version, 'quote.prepare', ['revision' => $revision, 'net_minor' => $net]);
            return ['id' => $id, 'version' => $version, 'revision' => $revision, 'state' => 'draft'];
        });
    }

    public function submit(Access $actor, int $company, int $id, int $version): array
    {
        return $this->db->transaction(function () use ($actor, $company, $id, $version): array {
            $quote = $this->sellerLock($actor, $company, $id);
            Accounts::checkVersion($quote, $version);
            $this->checkState($quote, 'draft');
            $revision = $this->revision($company, $id, $quote['revision']);
            $this->checkExpiry($revision['expires_on']);
            $policy = $this->policy($company);
            $automatic = $revision['discount_bps'] <= $policy['self_approval_bps'];
            $this->db->run(
                'INSERT INTO quote_review (company_id,quote_id,revision,submitted_by,self_approval_bps,policy_version,decision,decided_by,decided_at)
                 VALUES (?,?,?,?,?,?,?,?,IF(? IS NULL,NULL,UTC_TIMESTAMP(6)))',
                [$company, $id, $quote['revision'], $actor->userId, $policy['self_approval_bps'], $policy['version'],
                    $automatic ? 'automatic' : null, $automatic ? $actor->userId : null, $automatic ? $actor->userId : null],
            );
            return $this->recordState($actor, $company, $quote, $automatic ? 'approved' : 'pending', 'quote.submit');
        });
    }

    public function decide(Access $actor, int $company, int $id, int $version, string $decision, string $reason): array
    {
        $actor->allow($company, 'manager', 'approver');
        $decision = Input::choice($decision, '审批结论', ['approved', 'rejected']);
        $reason = Input::text($reason, '审批依据', 400);
        return $this->db->transaction(function () use ($actor, $company, $id, $version, $decision, $reason): array {
            $quote = $this->row($company, $id, true);
            Accounts::checkVersion($quote, $version);
            $this->checkState($quote, 'pending');
            $revision = $this->revision($company, $id, $quote['revision']);
            if (in_array($actor->userId, [(int) $revision['submitted_by'], (int) $revision['prepared_by']], true)) {
                throw new BusinessError('折扣须由独立审批人复核。', 403);
            }
            if ($decision === 'approved') {
                $this->checkExpiry($revision['expires_on']);
            }
            $this->db->run(
                'UPDATE quote_review SET decision = ?,decided_by = ?,reason = ?,decided_at = UTC_TIMESTAMP(6)
                 WHERE company_id = ? AND quote_id = ? AND revision = ?',
                [$decision, $actor->userId, $reason, $company, $id, $quote['revision']],
            );
            return $this->recordState($actor, $company, $quote, $decision, 'quote.decide');
        });
    }

    public function signable(Access $actor, int $company, int $id, int $version): array
    {
        $quote = $this->sellerLock($actor, $company, $id);
        Accounts::checkVersion($quote, $version);
        $this->checkState($quote, 'approved');
        $revision = $this->revision($company, $id, $quote['revision']);
        $this->checkExpiry($revision['expires_on']);
        return $quote + $revision;
    }

    public function contracted(Access $actor, int $company, array $quote, int $count): array
    {
        return $this->recordState($actor, $company, $quote, 'contracted', 'quote.contract', ['agreement_count' => $count]);
    }

    public function list(Access $actor, int $company, string $state, ?string $cursor): array
    {
        $actor->allow($company, 'manager', 'sales', 'approver');
        Input::choice($state, '报价状态', self::STATES);
        $page = WorklistPage::read($cursor);
        $manager = (int) $actor->has($company, 'manager');
        $seller = (int) $actor->has($company, 'sales');
        $approver = (int) $actor->has($company, 'approver');
        return WorklistPage::result($this->db->rows(
            'SELECT q.id,q.state,q.version,q.revision,q.updated_at,o.title,r.currency,r.net_minor,r.discount_bps,r.expires_on
             FROM quotes q JOIN opportunities o ON o.company_id = q.company_id AND o.id = q.opportunity_id
             JOIN accounts a ON a.company_id = o.company_id AND a.id = o.account_id
             JOIN quote_revisions r ON r.company_id = q.company_id AND r.quote_id = q.id AND r.revision = q.revision
             LEFT JOIN quote_review d ON d.company_id = q.company_id AND d.quote_id = q.id AND d.revision = q.revision
             WHERE q.company_id = ? AND q.state = ? AND
                 (? = 1 OR (? = 1 AND o.owner_id = ? AND a.owner_id = ?) OR (? = 1 AND (q.state = \'pending\' OR d.decided_by = ?)))'
             . $page->clause('q') . ' ORDER BY q.updated_at DESC,q.id DESC LIMIT ' . (WorklistPage::SIZE + 1),
            [$company, $state, $manager, $seller, $actor->userId, $actor->userId, $approver, $actor->userId, ...$page->parameters()],
        ));
    }

    public function detail(Access $actor, int $company, int $id, int $after, ?int $revisionNo = null): array
    {
        $actor->allow($company, 'manager', 'sales', 'approver');
        $quote = $this->row($company, $id);
        $revisionNo ??= $quote['revision'];
        $revision = $this->revision($company, $id, $revisionNo);
        $controls = $actor->has($company, 'manager') || ($actor->has($company, 'sales')
            && (int) $quote['owner_id'] === $actor->userId && (int) $quote['account_owner_id'] === $actor->userId);
        $reviewer = $actor->has($company, 'approver') && (
            ($quote['revision'] === $revisionNo && $quote['state'] === 'pending') || (int) $revision['decided_by'] === $actor->userId
        );
        if (!$controls && !$reviewer) {
            throw new BusinessError('未找到报价。', 404);
        }
        $isCurrent = $quote['revision'] === $revisionNo;
        $quote['current_revision'] = $controls ? $quote['revision'] : $revisionNo;
        $quote['revision'] = $revisionNo;
        $quote['state'] = $isCurrent ? $quote['state'] : 'archived';
        $quote['can_prepare'] = $controls;
        $quote['can_review'] = ($reviewer || $actor->has($company, 'manager'))
            && !in_array($actor->userId, [(int) $revision['prepared_by'], (int) $revision['submitted_by']], true);
        $quote['products'] = $this->db->rows(
            'SELECT line_no,product_code,product_version,description,quantity,unit_minor FROM quote_lines
             WHERE company_id = ? AND quote_id = ? AND revision = ? ORDER BY line_no', [$company, $id, $revisionNo],
        );
        $quote['revisions'] = $controls ? $this->db->rows(
            'SELECT revision,expires_on,currency,net_minor,created_at FROM quote_revisions WHERE company_id = ? AND quote_id = ?
             ORDER BY revision DESC LIMIT 100', [$company, $id],
        ) : [];
        $quote['allocations'] = $controls && $quote['state'] === 'contracted' ? $this->db->rows(
            'SELECT a.id,a.company_id,a.reference_no,a.legal_name,c.name AS company_name
             FROM agreements a JOIN companies c ON c.id = a.company_id
             JOIN accounts b ON b.company_id = a.company_id AND b.id = a.account_id
             WHERE a.source_company_id = ? AND a.quote_id = ? AND a.quote_revision = ? AND
                (? = 1 OR EXISTS (SELECT 1 FROM memberships m WHERE m.company_id = a.company_id AND m.user_id = ?
                    AND (m.role = \'manager\' OR (m.role = \'sales\' AND a.owner_id = m.user_id AND b.owner_id = m.user_id))))
             ORDER BY c.name,c.id', [$company, $id, $revisionNo, (int) $actor->user()['is_admin'], $actor->userId],
        ) : [];
        $quote['timeline'] = $controls ? $this->audit->timeline($company, 'quote', $id, $after) : [];
        return $quote + $revision;
    }

    private function sellerLock(Access $actor, int $company, int $id): array
    {
        $actor->allow($company, 'manager', 'sales');
        $quote = $this->row($company, $id);
        $opportunity = $this->opportunities->writable($actor, $company, $quote['opportunity_id']);
        $this->checkSubject($company, $opportunity);
        return $this->row($company, $id, true) + ['account_id' => $opportunity['account_id']];
    }

    private function checkSubject(int $company, array $opportunity): void
    {
        $account = $this->db->row('SELECT state FROM accounts WHERE company_id = ? AND id = ?', [$company, $opportunity['account_id']]);
        if ($account['state'] !== 'active' || !in_array($opportunity['stage'], ['proposal', 'negotiation', 'won'], true)) {
            throw new BusinessError('仅正常客户中处于报价、谈判或赢单的商机可签发报价。', 409);
        }
    }

    private function row(int $company, int $id, bool $lock = false): array
    {
        if ($lock) {
            return $this->db->row('SELECT * FROM quotes WHERE company_id = ? AND id = ? FOR UPDATE', [$company, $id])
                ?? throw new BusinessError('未找到报价。', 404);
        }
        return $this->db->row(
            'SELECT q.*,o.title,o.owner_id,o.account_id,o.version AS opportunity_version,a.owner_id AS account_owner_id
             FROM quotes q JOIN opportunities o ON o.company_id = q.company_id AND o.id = q.opportunity_id
             JOIN accounts a ON a.company_id = o.company_id AND a.id = o.account_id WHERE q.company_id = ? AND q.id = ?', [$company, $id],
        ) ?? throw new BusinessError('未找到报价。', 404);
    }

    private function revision(int $company, int $id, int $revision): array
    {
        return $this->db->row(
            'SELECT r.*,d.submitted_by,d.self_approval_bps,d.policy_version,d.decision,d.decided_by,d.reason,d.decided_at
             FROM quote_revisions r LEFT JOIN quote_review d USING (company_id,quote_id,revision)
             WHERE r.company_id = ? AND r.quote_id = ? AND r.revision = ?', [$company, $id, $revision],
        ) ?? throw new BusinessError('未找到报价版本。', 404);
    }

    private function checkExpiry(string $expiry): void
    {
        if ($expiry < $this->today()) {
            throw new BusinessError('报价已过期，请先创建新修订。', 409);
        }
    }

    private function today(): string
    {
        return $this->db->row('SELECT UTC_DATE() AS today')['today'];
    }

    private function policy(int $company): array
    {
        return $this->db->row('SELECT self_approval_bps,version FROM quote_policies WHERE company_id = ?', [$company])
            ?? ['self_approval_bps' => self::DEFAULT_LIMIT_BPS, 'version' => 0];
    }

    private function checkState(array $quote, string $state): void
    {
        if ($quote['state'] !== $state) {
            throw new BusinessError('当前报价状态不允许该操作。', 409);
        }
    }

    private function recordState(Access $actor, int $company, array $quote, string $state, string $action, array $details = []): array
    {
        $id = (int) $quote['id'];
        $version = $quote['version'] + 1;
        $this->db->run('UPDATE quotes SET state = ?,version = ? WHERE company_id = ? AND id = ?', [$state, $version, $company, $id]);
        $this->audit->record($actor, $company, 'quote', $id, $version, $action, ['revision' => $quote['revision'], 'state' => $state] + $details);
        return ['id' => $id, 'version' => $version, 'revision' => $quote['revision'], 'state' => $state] + $details;
    }
}
