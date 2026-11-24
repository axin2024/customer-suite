<?php

declare(strict_types=1);

namespace CustomerSuite;

final class Agreements
{
    public const LEVELS = ['standard' => '标准', 'priority' => '优先', 'critical' => '关键'];
    public const PROGRESSION = [
        'signed' => ['fulfilling', 'ended'],
        'fulfilling' => ['renewing', 'ended'],
        'renewing' => ['fulfilling', 'ended'],
        'ended' => [],
    ];

    private readonly Accounts $accounts;
    private readonly Audit $audit;

    public function __construct(private readonly Database $db)
    {
        $this->accounts = new Accounts($db);
        $this->audit = new Audit($db);
    }

    public function sign(Access $actor, int $company, int $id, int $version, array $data): array
    {
        $scope = Input::choice($data['scope'], '签约范围', ['single', 'group']);
        $allocations = $data['allocations'];
        if (!array_is_list($allocations) || count($allocations) < 1 || count($allocations) > 20 || ($scope === 'single' && count($allocations) !== 1)) {
            throw new BusinessError('请填写对应的签约主体，集团最多 20 家，每家公司一份。');
        }
        return $this->db->transaction(function () use ($actor, $company, $id, $version, $scope, $allocations): array {
            $quotes = new Quotes($this->db);
            $quote = $quotes->signable($actor, $company, $id, $version);
            if ($scope === 'group') {
                $actor->allow($company, 'manager');
            }
            $parsed = [];
            $total = 0;
            foreach ($allocations as $input) {
                if (!is_array($input) || array_diff_key($input, array_flip([
                    'company_id', 'account_id', 'reference_no', 'amount', 'starts_on', 'ends_on', 'service_level', 'evidence_ref', 'terms',
                ]))) {
                    throw new BusinessError('签约明细包含未定义的字段。');
                }
                $target = Input::id($input['company_id'] ?? null, '签约公司');
                if (isset($parsed[$target])) {
                    throw new BusinessError('一份报价在每家公司仅可建立一份合同。');
                }
                $accountId = Input::id($input['account_id'] ?? null, '签约客户');
                $reference = Input::key($input['reference_no'] ?? null, '合同编号', 64);
                $evidence = Input::text($input['evidence_ref'] ?? null, '签署凭据编号', 160);
                $terms = $this->terms($input + ['terms' => $quote['terms']], $quote['currency']);
                $total += $terms['value_minor'];
                $parsed[$target] = ['account_id' => $accountId, 'reference_no' => $reference, 'evidence_ref' => $evidence] + $terms;
            }
            if ($total !== (int) $quote['net_minor']) {
                throw new BusinessError('各主体签约金额合计必须等于报价实收金额。');
            }
            ksort($parsed, SORT_NUMERIC);
            foreach ($parsed as $target => $allocation) {
                $account = $this->legalAccount($actor, $company, $quote['account_id'], $scope, $target, $allocation['account_id']);
                $this->createSigned($actor, $target, $company, $account, $quote, $allocation);
            }
            return $quotes->contracted($actor, $company, $quote, count($parsed));
        });
    }

    public function propose(Access $actor, int $company, int $id, int $version, array $data): array
    {
        return $this->db->transaction(function () use ($actor, $company, $id, $version, $data): array {
            $contract = $this->sellerLock($actor, $company, $id);
            Accounts::checkVersion($contract, $version);
            $latest = $this->assertCanChange($contract);
            $active = $this->revision($company, $id, $contract['active_revision']);
            $terms = $this->terms($data, $active['currency']);
            $reason = Input::text($data['reason'], '变更依据', 400);
            $next = $latest['revision'] + 1;
            $this->db->run(
                'INSERT INTO agreement_versions (company_id,agreement_id,revision,based_on,decision,currency,value_minor,starts_on,ends_on,
                    service_level,terms,change_reason,prepared_by) VALUES (?,?,?,?,\'pending\',?,?,?,?,?,?,?,?)',
                [$company, $id, $next, $active['revision'], $active['currency'], ...array_values($terms), $reason, $actor->userId],
            );
            $this->db->run('UPDATE agreements SET latest_revision = ?,version = ? WHERE company_id = ? AND id = ?', [$next, $version + 1, $company, $id]);
            $this->audit->record($actor, $company, 'agreement', $id, $version + 1, 'agreement.propose', ['revision' => $next, 'based_on' => $active['revision']]);
            return ['id' => $id, 'version' => $version + 1, 'revision' => $next, 'decision' => 'pending'];
        });
    }

    public function decide(Access $actor, int $company, int $id, int $version, array $data): array
    {
        $actor->allow($company, 'manager', 'approver');
        $decision = Input::choice($data['decision'], '补充协议决定', ['signed', 'rejected']);
        $reason = Input::text($data['reason'], '复核依据', 400);
        $evidence = Input::optionalText($data['evidence_ref'] ?? null, '签署凭据编号', 160);
        if ($decision === 'signed' && $evidence === null) {
            throw new BusinessError('签署补充协议须登记签署凭据。');
        }
        return $this->db->transaction(function () use ($actor, $company, $id, $version, $decision, $reason, $evidence): array {
            $contract = $this->row($company, $id, true);
            Accounts::checkVersion($contract, $version);
            $revision = $this->revision($company, $id, $contract['latest_revision']);
            if ($contract['state'] === 'ended' || $revision['decision'] !== 'pending') {
                throw new BusinessError('当前没有待复核的补充协议。', 409);
            }
            if ($actor->userId === (int) $revision['prepared_by']) {
                throw new BusinessError('发起人不能复核自己提交的补充协议。', 403);
            }
            $this->db->run(
                'UPDATE agreement_versions SET decision = ?,evidence_ref = ?,decided_by = ?,decision_reason = ?,decided_at = UTC_TIMESTAMP(6)
                 WHERE company_id = ? AND agreement_id = ? AND revision = ?',
                [$decision, $decision === 'signed' ? $evidence : null, $actor->userId, $reason, $company, $id, $revision['revision']],
            );
            $activeNo = $decision === 'signed' ? $revision['revision'] : $contract['active_revision'];
            $this->db->run('UPDATE agreements SET active_revision = ?,version = ? WHERE company_id = ? AND id = ?', [$activeNo, $version + 1, $company, $id]);
            $this->audit->record($actor, $company, 'agreement', $id, $version + 1, 'agreement.decide',
                ['revision' => $revision['revision'], 'decision' => $decision, 'active_revision' => $activeNo]);
            return ['id' => $id, 'version' => $version + 1, 'revision' => $revision['revision'], 'decision' => $decision, 'active_revision' => $activeNo];
        });
    }

    public function move(Access $actor, int $company, int $id, int $version, string $state, string $reason): array
    {
        $state = Input::choice($state, '合同状态', array_keys(self::PROGRESSION));
        $reason = Input::text($reason, '合同状态变更原因', 400);
        return $this->db->transaction(function () use ($actor, $company, $id, $version, $state, $reason): array {
            $contract = $this->sellerLock($actor, $company, $id);
            Accounts::checkVersion($contract, $version);
            $this->assertCanChange($contract);
            if (!in_array($state, self::PROGRESSION[$contract['state']], true)) {
                throw new BusinessError('当前合同状态不支持该变更。', 409);
            }
            $this->db->run('UPDATE agreements SET state = ?,version = ? WHERE company_id = ? AND id = ?', [$state, $version + 1, $company, $id]);
            $this->audit->record($actor, $company, 'agreement', $id, $version + 1, 'agreement.move',
                ['from' => $contract['state'], 'state' => $state, 'reason' => $reason]);
            return ['id' => $id, 'version' => $version + 1, 'revision' => $contract['active_revision'], 'state' => $state];
        });
    }

    public function list(Access $actor, int $company, string $state, ?string $cursor): array
    {
        $actor->allow($company, 'manager', 'sales', 'approver');
        Input::choice($state, '合同状态', array_keys(self::PROGRESSION));
        $page = WorklistPage::read($cursor);
        return WorklistPage::result($this->db->rows(
            'SELECT a.id,a.reference_no,a.legal_name,a.state,a.version,a.active_revision,a.updated_at,v.currency,v.value_minor,v.ends_on,v.service_level
             FROM agreements a JOIN accounts c ON c.company_id = a.company_id AND c.id = a.account_id
             JOIN agreement_versions v ON v.company_id = a.company_id AND v.agreement_id = a.id
                AND v.revision = IF(? = 1 OR (? = 1 AND a.owner_id = ? AND c.owner_id = ?),a.active_revision,a.latest_revision)
             JOIN agreement_versions l ON l.company_id = a.company_id AND l.agreement_id = a.id AND l.revision = a.latest_revision
             WHERE a.company_id = ? AND a.state = ?
             AND (? = 1 OR (? = 1 AND a.owner_id = ? AND c.owner_id = ?) OR (? = 1 AND (l.decision = \'pending\' OR l.decided_by = ?)))'
             . $page->clause('a') . ' ORDER BY a.updated_at DESC,a.id DESC LIMIT ' . (WorklistPage::SIZE + 1),
            [$manager = (int) $actor->has($company, 'manager'), $seller = (int) $actor->has($company, 'sales'), $actor->userId, $actor->userId,
                $company, $state, $manager, $seller, $actor->userId, $actor->userId, (int) $actor->has($company, 'approver'),
                $actor->userId, ...$page->parameters()],
        ));
    }

    public function detail(Access $actor, int $company, int $id, int $after, ?int $revisionNo = null): array
    {
        $actor->allow($company, 'manager', 'sales', 'approver');
        $contract = $this->row($company, $id);
        $manager = $actor->has($company, 'manager');
        $owner = $actor->has($company, 'sales') && $actor->userId === (int) $contract['owner_id']
            && $actor->userId === (int) $contract['account_owner_id'];
        $latest = $this->revision($company, $id, $contract['latest_revision']);
        $full = $manager || $owner;
        $revision = $this->revision($company, $id, $revisionNo ?? ($full ? $contract['active_revision'] : $latest['revision']));
        $approval = $actor->has($company, 'approver') && ($revision['decision'] === 'pending' || (int) $revision['decided_by'] === $actor->userId);
        if (!$full && !$approval) {
            throw new BusinessError('未找到合同。', 404);
        }
        if ((int) $contract['source_company_id'] !== $company && !$actor->has($contract['source_company_id'], 'manager')) {
            unset($contract['source_company_id'], $contract['quote_id'], $contract['quote_revision']);
        }
        $contract['can_change'] = $full;
        $contract['can_review'] = ($manager || $approval) && (int) $latest['prepared_by'] !== $actor->userId && $latest['decision'] === 'pending';
        $contract['latest_decision'] = $full || $latest['revision'] === $revision['revision'] ? $latest['decision'] : null;
        $contract['revisions'] = $full ? $this->db->rows(
            'SELECT revision,based_on,decision,currency,value_minor,ends_on,decided_at FROM agreement_versions
             WHERE company_id = ? AND agreement_id = ? ORDER BY revision DESC LIMIT 100', [$company, $id],
        ) : [];
        $contract['timeline'] = $full ? $this->audit->timeline($company, 'agreement', $id, $after) : [];
        return $contract + $revision;
    }

    public function subject(int $company, int $id, bool $lock = false): array
    {
        return $this->row($company, $id, $lock);
    }

    public function revision(int $company, int $id, int $revision): array
    {
        return $this->db->row('SELECT * FROM agreement_versions WHERE company_id = ? AND agreement_id = ? AND revision = ?', [$company, $id, $revision])
            ?? throw new BusinessError('未找到合同版本。', 404);
    }

    private function legalAccount(Access $actor, int $source, int $sourceAccount, string $scope, int $target, int $id): array
    {
        if ($scope === 'group') {
            $actor->allow($target, 'manager');
            if ((int) $actor->company($target)['parent_id'] !== $source) {
                throw new BusinessError('集团分签须使用总部直属的签约公司。');
            }
        } elseif ($source !== $target || $sourceAccount !== $id) {
            throw new BusinessError('独立签约主体应与商机匹配。');
        }
        $account = $this->accounts->writable($actor, $target, $id);
        if ($account['state'] !== 'active') {
            throw new BusinessError('签约主体须为正常客户档案。', 409);
        }
        if ($scope === 'group' && ((int) $account['parent_company_id'] !== $source || (int) $account['parent_id'] !== $sourceAccount)) {
            throw new BusinessError('分签客户须关联至报价的集团主档。');
        }
        return $account;
    }

    private function createSigned(Access $actor, int $company, int $source, array $account, array $quote, array $terms): void
    {
        if ($this->db->row('SELECT id FROM agreements WHERE company_id = ? AND reference_no = ?', [$company, $terms['reference_no']])) {
            throw new BusinessError('合同编号已在此公司使用。', 409);
        }
        try {
            $id = $this->db->insert(
                'INSERT INTO agreements (company_id,account_id,legal_name,legal_registration_ref,reference_no,source_company_id,quote_id,quote_revision,owner_id)
                 VALUES (?,?,?,?,?,?,?,?,?)', [$company, $account['id'], $account['name'], $account['registration_ref'], $terms['reference_no'],
                    $source, $quote['id'], $quote['revision'], $account['owner_id']],
            );
        } catch (\PDOException $failure) {
            if (($failure->errorInfo[1] ?? null) !== 1062) {
                throw $failure;
            }
            throw new BusinessError('签约主体或合同编号已登记。', 409);
        }
        $this->db->run(
            'INSERT INTO agreement_versions (company_id,agreement_id,revision,decision,currency,value_minor,starts_on,ends_on,service_level,terms,
                prepared_by,evidence_ref,decided_by,decided_at) VALUES (?,?,1,\'signed\',?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))',
            [$company, $id, $quote['currency'], $terms['value_minor'], $terms['starts_on'], $terms['ends_on'], $terms['service_level'], $terms['terms'],
                $actor->userId, $terms['evidence_ref'], $actor->userId],
        );
        $this->audit->record($actor, $company, 'agreement', $id, 1, 'agreement.sign', ['account_id' => $account['id'], 'revision' => 1]);
        $this->audit->record($actor, $company, 'account', $account['id'], $account['version'], 'agreement.link', ['agreement_id' => $id]);
    }

    private function terms(array $input, string $currency): array
    {
        $amount = Money::minor($input['amount'] ?? null, '合同金额', $currency);
        $starts = Input::date($input['starts_on'] ?? null);
        $ends = Input::date($input['ends_on'] ?? null);
        if ($starts > $ends || $amount < 1) {
            throw new BusinessError('合同须有正数金额，起始日不得晚于到期日。');
        }
        return ['value_minor' => $amount, 'starts_on' => $starts, 'ends_on' => $ends,
            'service_level' => Input::choice($input['service_level'] ?? null, '服务级别', array_keys(self::LEVELS)),
            'terms' => Input::text($input['terms'] ?? null, '合同条款', 2000)];
    }

    private function sellerLock(Access $actor, int $company, int $id): array
    {
        $actor->allow($company, 'manager', 'sales');
        $existing = $this->row($company, $id);
        $this->accounts->writable($actor, $company, $existing['account_id']);
        $contract = $this->row($company, $id, true);
        if (!$actor->has($company, 'manager') && $actor->userId !== (int) $contract['owner_id']) {
            throw new BusinessError('合同分配给其他销售。', 403);
        }
        return $contract;
    }

    private function assertCanChange(array $contract): array
    {
        $latest = $this->revision($contract['company_id'], $contract['id'], $contract['latest_revision']);
        if ($contract['state'] === 'ended' || $latest['decision'] === 'pending') {
            throw new BusinessError('已终止或待复核的合同不能继续变更。', 409);
        }
        return $latest;
    }

    private function row(int $company, int $id, bool $lock = false): array
    {
        $sql = $lock ? 'SELECT a.* FROM agreements a' : 'SELECT a.*,c.owner_id AS account_owner_id FROM agreements a
            JOIN accounts c ON c.company_id = a.company_id AND c.id = a.account_id';
        return $this->db->row($sql . ' WHERE a.company_id = ? AND a.id = ?' . ($lock ? ' FOR UPDATE' : ''), [$company, $id])
            ?? throw new BusinessError('未找到合同。', 404);
    }
}
