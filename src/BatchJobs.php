<?php

declare(strict_types=1);

namespace CustomerSuite;

final class BatchJobs
{
    public const KINDS = ['lead.reassign' => '线索分配与回收', 'outreach.process' => '续约触达'];
    public const STATES = [
        'pending' => '待处理', 'completed' => '已完成', 'partial' => '部分完成',
        'rejected' => '已拒绝', 'retryable' => '可重试', 'waiting_external' => '等待外部确认',
    ];
    private const RETRY_STATES = ['retryable', 'waiting_external'];

    public function __construct(private readonly Database $db)
    {
    }

    public static function selection(array $items): array
    {
        if (!array_is_list($items) || count($items) < 1 || count($items) > 50) {
            throw new BusinessError('请选中 1 至 50 条记录。');
        }
        $selection = [];
        foreach ($items as $item) {
            if (is_string($item) && preg_match('/^[1-9][0-9]*:[1-9][0-9]*$/D', $item)) {
                [$id, $version] = explode(':', $item);
            } elseif (is_array($item) && count($item) === 2 && isset($item['id'], $item['version'])) {
                ['id' => $id, 'version' => $version] = $item;
            } else {
                throw new BusinessError('选集中的每条记录须包含编号与版本。');
            }
            $id = Input::id($id);
            if (isset($selection[$id])) {
                throw new BusinessError('同一批任务中不能重复选择记录。');
            }
            $selection[$id] = ['id' => $id, 'version' => Input::integer($version, '版本', 1, 4294967295)];
        }
        return array_values($selection);
    }

    public function create(Access $actor, int $company, array $data): array
    {
        $kind = Input::choice($data['kind'], '任务类型', array_keys(self::KINDS));
        $this->authorize($actor, $company, $kind);
        $parameters = [];
        if ($kind === 'lead.reassign') {
            $parameters['reason'] = Input::text($data['reason'] ?? null, '分配或回收原因', 240);
            if (isset($data['owner_id'])) {
                $actor->eligible($company, $data['owner_id'], 'sales');
                $parameters['owner_id'] = $data['owner_id'];
            }
        } elseif (isset($data['owner_id']) || isset($data['reason'])) {
            throw new BusinessError('续约触达使用已排队的内容与授权。');
        }

        $id = $this->db->insert(
            'INSERT INTO batch_jobs (company_id,requested_by,kind,parameters) VALUES (?,?,?,?)',
            [$company, $actor->userId, $kind, Input::json($parameters)],
        );
        $rows = [];
        foreach ($data['items'] as $position => $item) {
            $source = $this->source($actor, $company, $kind, $item['id']);
            $rows[] = [$company, $id, $position + 1, $item['id'], $item['version'], $item['version'],
                $source['renewal_id'] ?? null, $source === null ? 'rejected' : 'pending',
                $source === null ? '未找到授权范围内的原记录。' : null, bin2hex(random_bytes(16))];
        }
        $this->db->insertRows(
            'INSERT INTO batch_items (company_id,batch_id,position,subject_id,selected_version,subject_version,renewal_id,state,reason,receipt_seed)',
            $rows,
        );
        (new Audit($this->db))->record($actor, $company, 'batch', $id, 1, 'batch.create', [
            'kind' => $kind, 'selected' => count($rows),
        ]);
        return ['id' => $id, 'count' => count($rows)];
    }

    public function retry(Access $actor, int $company, array $data): array
    {
        $batch = $this->readable($actor, $company, $data['id']);
        $selection = array_column($data['items'], 'version', 'id');
        $placeholders = implode(',', array_fill(0, count($selection), '?'));
        $items = $this->db->rows(
            "SELECT id,subject_id,version,state FROM batch_items WHERE company_id = ? AND batch_id = ?
             AND id IN ($placeholders) ORDER BY id FOR UPDATE",
            [$company, $batch['id'], ...array_keys($selection)],
        );
        if (count($items) !== count($selection)) {
            throw new BusinessError('未找到此任务中的选中条目。', 404);
        }
        foreach ($items as $item) {
            Accounts::checkVersion($item, $selection[$item['id']]);
            if (!in_array($item['state'], self::RETRY_STATES, true)) {
                throw new BusinessError('选集中含有当前不可重试的条目。', 409);
            }
            if ($this->source($actor, $company, $batch['kind'], $item['subject_id']) === null) {
                throw new BusinessError('选集中含有不在当前授权范围内的条目。', 404);
            }
        }
        $this->db->run(
            "UPDATE batch_items SET state = 'pending',version = version + 1
             WHERE company_id = ? AND batch_id = ? AND id IN ($placeholders)",
            [$company, $batch['id'], ...array_keys($selection)],
        );
        (new Audit($this->db))->record($actor, $company, 'batch', $batch['id'], 1, 'batch.retry', ['items' => array_keys($selection)]);
        return ['id' => $batch['id'], 'count' => count($items)];
    }

    public function run(Access $worker, int $company, int $limit, ?int $id = null): array
    {
        $worker->allow($company, 'manager');
        if ($id !== null) {
            $this->readable($worker, $company, $id);
        }
        $items = [];
        for ($index = 0; $index < $limit; $index++) {
            $item = $this->db->transaction(fn () => $this->processOne($worker, $company, $id));
            if ($item === null) {
                break;
            }
            $items[] = $item;
        }
        return ['count' => count($items), 'items' => $items];
    }

    public function list(Access $actor, int $company, int $before): array
    {
        $actor->allow($company, 'manager', 'marketing', 'success');
        $manager = $actor->has($company, 'manager');
        $kinds = array_keys($this->availableKinds($actor, $company));
        $placeholders = implode(',', array_fill(0, count($kinds), '?'));
        $batches = $this->db->rows(
            "SELECT b.id,b.kind,b.requested_by,b.created_at,u.display_name FROM batch_jobs b JOIN users u ON u.id = b.requested_by
             WHERE b.company_id = ? AND b.id < ? AND b.kind IN ($placeholders)" .
                ($manager ? '' : ' AND b.requested_by = ?') . ' ORDER BY b.id DESC LIMIT 31',
            [$company, $before, ...$kinds, ...($manager ? [] : [$actor->userId])],
        );
        $more = count($batches) > 30;
        $batches = array_slice($batches, 0, 30);
        if ($batches !== []) {
            $ids = array_column($batches, 'id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $counts = [];
            foreach ($this->db->rows(
                "SELECT batch_id,state,COUNT(*) AS total FROM batch_items WHERE company_id = ? AND batch_id IN ($placeholders) GROUP BY batch_id,state",
                [$company, ...$ids],
            ) as $row) {
                $counts[$row['batch_id']][$row['state']] = $row['total'];
            }
            foreach ($batches as &$batch) {
                $batch += self::summary($counts[$batch['id']]);
            }
            unset($batch);
        }
        return ['items' => $batches, 'before' => $more ? $batches[array_key_last($batches)]['id'] : null];
    }

    public function detail(Access $actor, int $company, int $id, int $after = 0): array
    {
        return $this->db->transaction(function () use ($actor, $company, $id, $after): array {
            $batch = $this->readable($actor, $company, $id);
            $items = $this->db->rows(
                'SELECT id,position,subject_id,selected_version,subject_version,renewal_id,state,reason,result,attempts,version,updated_at
                 FROM batch_items WHERE company_id = ? AND batch_id = ? ORDER BY position', [$company, $id],
            );
            $counts = array_count_values(array_column($items, 'state'));
            $hidden = [];
            foreach ($items as &$item) {
                $source = $this->source($actor, $company, $batch['kind'], $item['subject_id']);
                $item['can_open'] = $source !== null;
                if ($item['can_open']) {
                    $item['result'] = $item['result'] === null ? null : json_decode($item['result'], true, flags: JSON_THROW_ON_ERROR);
                } else {
                    $hidden[$item['id']] = true;
                    $item['renewal_id'] = null;
                    $item['result'] = null;
                    $item['reason'] = '当前无法查看原记录。';
                }
            }
            unset($item);
            $timeline = (new Audit($this->db))->timeline($company, 'batch', $id, $after);
            foreach ($timeline as &$event) {
                if (isset($hidden[$event['details']['item_id'] ?? 0])) {
                    unset($event['details']['reason'], $event['details']['receipt_key']);
                }
            }
            unset($event);
            $batch['parameters'] = json_decode($batch['parameters'], true, flags: JSON_THROW_ON_ERROR);
            return $batch + self::summary($counts) + ['items' => $items, 'timeline' => $timeline];
        });
    }

    public function readable(Access $actor, int $company, int $id): array
    {
        $actor->allow($company, 'manager', 'marketing', 'success');
        $batch = $this->db->row(
            'SELECT b.id,b.kind,b.parameters,b.created_at,b.requested_by,u.display_name
             FROM batch_jobs b JOIN users u ON u.id = b.requested_by WHERE b.company_id = ? AND b.id = ?', [$company, $id],
        );
        if ($batch === null || ($batch['requested_by'] !== $actor->userId && !$actor->has($company, 'manager'))) {
            throw new BusinessError('未找到此批量任务。', 404);
        }
        $this->authorize($actor, $company, $batch['kind']);
        return $batch;
    }

    public function availableKinds(Access $actor, int $company): array
    {
        return array_filter(self::KINDS, fn (string $kind) => $actor->has($company, ...self::roles($kind)), ARRAY_FILTER_USE_KEY);
    }

    public function candidates(Access $actor, int $company, string $kind, int $before): array
    {
        $kind = Input::choice($kind, '任务类型', array_keys(self::KINDS));
        $this->authorize($actor, $company, $kind);
        if ($kind === 'lead.reassign') {
            $items = $this->db->rows(
                "SELECT id,version,name AS label,state FROM leads WHERE company_id = ? AND id < ?
                 AND state IN ('open','assigned','qualified') ORDER BY id DESC LIMIT 51", [$company, $before],
            );
        } else {
            $manager = $actor->has($company, 'manager');
            $items = $this->db->rows(
                "SELECT o.id,o.version,o.renewal_id,o.channel,o.state,r.cycle_ends_on FROM outreach_jobs o
                 JOIN renewals r ON r.company_id = o.company_id AND r.id = o.renewal_id
                 LEFT JOIN success_assignments s ON s.company_id = r.company_id AND s.agreement_id = r.agreement_id
                 WHERE o.company_id = ? AND o.id < ? AND o.state IN ('queued','retryable','blocked')" .
                    ($manager ? '' : ' AND s.owner_id = ?') . ' ORDER BY o.id DESC LIMIT 51',
                [$company, $before, ...($manager ? [] : [$actor->userId])],
            );
        }
        $more = count($items) > 50;
        $items = array_slice($items, 0, 50);
        return ['items' => $items, 'before' => $more ? $items[array_key_last($items)]['id'] : null];
    }

    private function processOne(Access $worker, int $company, ?int $batchId): ?array
    {
        $item = $this->db->row(
            "SELECT i.*, b.kind, b.requested_by, b.parameters FROM batch_items i
             JOIN batch_jobs b ON b.company_id = i.company_id AND b.id = i.batch_id
             WHERE i.company_id = ? AND i.state = 'pending'" . ($batchId === null ? '' : ' AND i.batch_id = ?') .
                ' ORDER BY i.id LIMIT 1 FOR UPDATE OF i SKIP LOCKED',
            $batchId === null ? [$company] : [$company, $batchId],
        );
        if ($item === null) {
            return null;
        }

        $requester = new Access($this->db, $item['requested_by']);
        $attempt = $item['attempts'] + 1;
        $key = 'batch:' . $item['receipt_seed'] . ':' . $attempt;
        $data = ['id' => $item['subject_id'], 'version' => $item['subject_version']];
        $data += json_decode($item['parameters'], true, flags: JSON_THROW_ON_ERROR);
        try {
            $this->authorize($requester, $company, $item['kind']);
            $result = (new Operations($this->db))->execute($requester, $company, $item['kind'], $key, $data)['result'];
            [$state, $reason] = self::outcome($item['kind'], $result);
        } catch (BusinessError $error) {
            $result = $item['kind'] === 'outreach.process' && $error->status === 409
                ? $this->source($requester, $company, $item['kind'], $item['subject_id']) : null;
            if ($result !== null && in_array($result['state'], ['delivered', 'replayed'], true)) {
                $state = 'completed';
                $reason = '原触达已完成。';
            } else {
                $result = null;
                $state = 'rejected';
                $reason = $error->getMessage();
            }
            $key = null;
        }
        $this->db->run(
            'UPDATE batch_items SET state = ?, reason = ?, result = ?, subject_version = ?, attempts = ?, version = version + 1
             WHERE company_id = ? AND id = ?',
            [$state, $reason, $result === null ? null : Input::json($result), $result['version'] ?? $item['subject_version'],
                $attempt, $company, $item['id']],
        );
        $progress = ['batch_id' => $item['batch_id'], 'item_id' => $item['id'], 'state' => $state, 'attempt' => $attempt];
        (new Audit($this->db))->record($worker, $company, 'batch', $item['batch_id'], $item['version'] + 1, 'batch.item',
            $progress + ['reason' => $reason, 'receipt_key' => $key, 'requested_by' => $requester->userId]);
        return $progress;
    }

    private function source(Access $actor, int $company, string $kind, int $id): ?array
    {
        if ($kind === 'lead.reassign') {
            return $this->db->row('SELECT id FROM leads WHERE company_id = ? AND id = ?', [$company, $id]);
        }
        try {
            return (new Outreach($this->db))->readable($actor, $company, $id);
        } catch (BusinessError $error) {
            if ($error->status !== 404 && $error->status !== 403) {
                throw $error;
            }
            return null;
        }
    }

    private function authorize(Access $actor, int $company, string $kind): void
    {
        $actor->allow($company, ...self::roles($kind));
    }

    private static function roles(string $kind): array
    {
        return ['manager', $kind === 'lead.reassign' ? 'marketing' : 'success'];
    }

    private static function outcome(string $kind, array $result): array
    {
        if ($kind === 'lead.reassign' || in_array($result['state'], ['delivered', 'replayed'], true)) {
            return ['completed', null];
        }
        if ($result['state'] === 'blocked') {
            return ['rejected', Outreach::BLOCKS[$result['block_reason']]];
        }
        return [$result['last_error'] === 'gateway_unconfirmed' ? 'waiting_external' : 'retryable',
            Outreach::ERRORS[$result['last_error']]];
    }

    private static function summary(array $counts): array
    {
        $counts += array_fill_keys(['pending', 'completed', 'rejected', 'retryable', 'waiting_external'], 0);
        $total = array_sum($counts);
        $state = match (true) {
            $counts['completed'] === $total => 'completed',
            $counts['completed'] > 0 => 'partial',
            $counts['pending'] > 0 => 'pending',
            $counts['waiting_external'] > 0 => 'waiting_external',
            $counts['retryable'] > 0 => 'retryable',
            default => 'rejected',
        };
        return ['state' => $state, 'counts' => $counts, 'total' => $total];
    }
}
