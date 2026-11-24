<?php

declare(strict_types=1);

namespace CustomerSuite;

use PDOException;

final class Accounts
{
    private readonly Audit $audit;

    public function __construct(private readonly Database $db)
    {
        $this->audit = new Audit($db);
    }

    public function create(Access $actor, int $companyId, array $input): array
    {
        $actor->allow($companyId, 'manager', 'sales');
        $name = Input::text($input['name'] ?? null, '客户名称', 160);
        $registration = Input::optionalText($input['registration_ref'] ?? null, '主体登记号', 100);
        $registration = $registration === null ? null : strtoupper(Input::key($registration, '主体登记号', 100));
        $owner = isset($input['owner_id']) ? Input::id($input['owner_id']) : $actor->userId;
        $this->assertAssignee($actor, $companyId, $owner);
        $parent = isset($input['parent_id']) ? Input::id($input['parent_id']) : null;
        $parentCompany = isset($input['parent_company_id']) ? Input::id($input['parent_company_id']) : $companyId;
        if ($parent === null && isset($input['parent_company_id'])) {
            throw new BusinessError('关联集团时请同时填写集团客户编号。');
        }
        if ($parentCompany !== $companyId) {
            $company = $actor->company($companyId);
            if ((int) $company['parent_id'] !== $parentCompany || !$actor->has($companyId, 'manager')) {
                throw new BusinessError('跨公司集团关系须由经理在直属组织建立。', 403);
            }
            $actor->allow($parentCompany, 'manager');
        }
        return $this->db->transaction(function () use ($actor, $companyId, $name, $registration, $owner, $parent, $parentCompany): array {
            if ($parent !== null) {
                $parentAccount = $this->account($parentCompany, $parent, true);
                if ($parentAccount['state'] === 'merged') {
                    throw new BusinessError('集团客户已归并，请使用当前主档。', 409);
                }
                if ($parentCompany === $companyId) {
                    $this->readable($actor, $companyId, $parentAccount);
                }
            }
            $id = $this->db->insert(
                'INSERT INTO accounts (company_id,parent_company_id,parent_id,owner_id,name,registration_ref) VALUES (?,?,?,?,?,?)',
                [$companyId, $parent === null ? null : $parentCompany, $parent, $owner, $name, $registration],
            );
            $this->audit->record($actor, $companyId, 'account', $id, 1, 'account.create', ['owner_id' => $owner]);
            return ['id' => $id, 'version' => 1];
        });
    }

    public function find(Access $actor, int $companyId, int $id): array
    {
        $actor->company($companyId);
        $row = $this->account($companyId, $id);
        $this->readable($actor, $companyId, $row);
        return $row;
    }

    public function list(Access $actor, int $companyId, string $state = 'active', ?string $cursor = null): array
    {
        $actor->allow($companyId, 'manager', 'sales', 'success');
        $state = Input::choice($state, '客户状态', ['active', 'frozen', 'merged']);
        $own = !$actor->has($companyId, 'manager', 'success');
        $page = WorklistPage::read($cursor);
        return WorklistPage::result($this->db->rows(
            'SELECT id,name,state,owner_id,parent_id,parent_company_id,version,updated_at FROM accounts
             WHERE company_id = ? AND state = ?' . ($own ? ' AND owner_id = ?' : '') . $page->clause() .
            ' ORDER BY updated_at DESC,id DESC LIMIT ' . (WorklistPage::SIZE + 1),
            [$companyId, $state, ...($own ? [$actor->userId] : []), ...$page->parameters()],
        ));
    }

    public function writable(Access $actor, int $companyId, int $id, bool $historical = false): array
    {
        $actor->allow($companyId, 'manager', 'sales');
        $row = $this->account($companyId, $id, true);
        if (!$actor->has($companyId, 'manager') && (int) $row['owner_id'] !== $actor->userId) {
            throw new BusinessError('该客户已分配给其他负责人。', 403);
        }
        if ($row['state'] === 'merged' && !$historical) {
            throw new BusinessError('客户已归并，请打开当前主档。', 409, ['merged_into_id' => (int) $row['merged_into_id']]);
        }
        return $row;
    }

    public function currentId(int $company, int $id): int
    {
        $seen = [];
        while (true) {
            if (isset($seen[$id])) {
                throw new \LogicException('Customer merge links contain a cycle.');
            }
            $seen[$id] = true;
            $row = $this->account($company, $id);
            if ($row['merged_into_id'] === null) {
                return $id;
            }
            $id = (int) $row['merged_into_id'];
        }
    }

    public function revise(Access $actor, int $companyId, int $id, int $version, ?int $owner, ?string $state): array
    {
        $actor->allow($companyId, 'manager');
        if ($owner === null && $state === null) {
            throw new BusinessError('至少选择负责人或客户状态。');
        }
        if ($owner !== null && $owner !== $actor->userId) {
            $actor->eligible($companyId, $owner, 'sales');
        }
        if ($state !== null) {
            $state = Input::choice($state, '客户状态', ['active', 'frozen']);
        }
        return $this->db->transaction(function () use ($actor, $companyId, $id, $version, $owner, $state): array {
            $row = $this->writable($actor, $companyId, $id);
            self::checkVersion($row, $version);
            $owner ??= (int) $row['owner_id'];
            $state ??= $row['state'];
            $this->db->run(
                'UPDATE accounts SET owner_id = ?,state = ?,version = version + 1 WHERE company_id = ? AND id = ?',
                [$owner, $state, $companyId, $id],
            );
            $this->audit->record($actor, $companyId, 'account', $id, $version + 1, 'account.revise', [
                'previous_owner_id' => (int) $row['owner_id'], 'owner_id' => $owner,
                'previous_state' => $row['state'], 'state' => $state,
            ]);
            return ['id' => $id, 'version' => $version + 1];
        });
    }

    public function addAlias(Access $actor, int $companyId, int $id, int $version, string $source, string $externalId): array
    {
        $source = strtolower(Input::key($source, '来源', 40));
        $externalId = Input::key($externalId, '外部编号', 120);
        return $this->db->transaction(function () use ($actor, $companyId, $id, $version, $source, $externalId): array {
            $account = $this->writable($actor, $companyId, $id);
            self::checkVersion($account, $version);
            try {
                $this->db->run(
                    'INSERT INTO account_aliases (company_id,source,external_id,account_id,original_account_id,original_name,original_owner_id)
                     VALUES (?,?,?,?,?,?,?)',
                    [$companyId, $source, $externalId, $id, $id, $account['name'], $account['owner_id']],
                );
            } catch (PDOException $error) {
                if (($error->errorInfo[1] ?? null) !== 1062) {
                    throw $error;
                }
                throw new BusinessError('外部来源编号已被使用。', 409);
            }
            $this->db->run('UPDATE accounts SET version = version + 1 WHERE company_id = ? AND id = ?', [$companyId, $id]);
            $this->audit->record($actor, $companyId, 'account', $id, $version + 1, 'account.alias.add', [
                'source' => $source, 'external_id' => $externalId,
            ]);
            return ['id' => $id, 'version' => $version + 1];
        });
    }

    public function merge(Access $actor, int $companyId, int $targetId, int $targetVersion, int $sourceId, int $sourceVersion): array
    {
        $actor->allow($companyId, 'manager');
        if ($targetId === $sourceId) {
            throw new BusinessError('请选择两份不同的客户档案。');
        }
        return $this->db->transaction(function () use ($actor, $companyId, $targetId, $targetVersion, $sourceId, $sourceVersion): array {
            $firstId = min($targetId, $sourceId);
            $secondId = max($targetId, $sourceId);
            $locked = [
                $firstId => $this->account($companyId, $firstId, true),
                $secondId => $this->account($companyId, $secondId, true),
            ];
            $target = $locked[$targetId];
            $source = $locked[$sourceId];
            self::checkVersion($target, $targetVersion);
            self::checkVersion($source, $sourceVersion);
            if ($target['state'] === 'merged' || $source['state'] === 'merged') {
                throw new BusinessError('档案已发生归并，请重新选择当前主档。', 409);
            }
            if ($target['registration_ref'] === null || $target['registration_ref'] !== $source['registration_ref']) {
                throw new BusinessError('识别证据不足：登记号缺失或不同，同名不足以判断为同一主体。', 409);
            }
            if ((int) $target['parent_id'] === $sourceId || (int) $source['parent_id'] === $targetId) {
                throw new BusinessError('存在直接集团关系，请先核对主体层级。', 409);
            }
            $this->db->run('UPDATE account_aliases SET account_id = ? WHERE company_id = ? AND account_id = ?', [$targetId, $companyId, $sourceId]);
            $this->db->run('UPDATE contacts SET account_id = ?,version = version + 1 WHERE company_id = ? AND account_id = ?', [$targetId, $companyId, $sourceId]);
            $this->db->run(
                "UPDATE accounts SET state = 'merged',merged_into_id = ?,version = version + 1 WHERE company_id = ? AND id = ?",
                [$targetId, $companyId, $sourceId],
            );
            $this->db->run('UPDATE accounts SET version = version + 1 WHERE company_id = ? AND id = ?', [$companyId, $targetId]);
            $history = ['source_id' => $sourceId, 'source_owner_id' => (int) $source['owner_id'], 'target_id' => $targetId];
            $this->audit->record($actor, $companyId, 'account', $sourceId, $sourceVersion + 1, 'account.merged', $history);
            $this->audit->record($actor, $companyId, 'account', $targetId, $targetVersion + 1, 'account.merge.accept', $history);
            return ['id' => $targetId, 'version' => $targetVersion + 1, 'source_id' => $sourceId];
        });
    }

    public function detail(Access $actor, int $companyId, int $id, int $after = 0): array
    {
        $result = $this->find($actor, $companyId, $id);
        $result['aliases'] = $this->db->rows(
            'SELECT source,external_id,original_account_id,original_name,original_owner_id FROM account_aliases
             WHERE company_id = ? AND account_id = ? ORDER BY source,external_id LIMIT 100',
            [$companyId, $id],
        );
        $result['timeline'] = $this->audit->timeline($companyId, 'account', $id, $after);
        return $result;
    }

    public static function checkVersion(array $row, int $expected): void
    {
        if ((int) $row['version'] !== $expected) {
            throw new BusinessError('记录版本已变化，请重新查看。', 409, ['current_version' => (int) $row['version']]);
        }
    }

    private function account(int $companyId, int $id, bool $forUpdate = false): array
    {
        return $this->db->row(
            'SELECT * FROM accounts WHERE company_id = ? AND id = ?' . ($forUpdate ? ' FOR UPDATE' : ''),
            [$companyId, $id],
        ) ?? throw new BusinessError('未找到客户。', 404);
    }

    private function readable(Access $actor, int $companyId, array $row): void
    {
        if (!$actor->has($companyId, 'manager', 'success')
            && (!$actor->has($companyId, 'sales') || (int) $row['owner_id'] !== $actor->userId)
        ) {
            throw new BusinessError('未找到客户。', 404);
        }
    }

    private function assertAssignee(Access $actor, int $companyId, int $owner): void
    {
        if ($owner === $actor->userId) {
            return;
        }
        $actor->allow($companyId, 'manager');
        $actor->eligible($companyId, $owner, 'sales');
    }
}
