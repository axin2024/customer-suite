<?php
declare(strict_types=1);

namespace CustomerSuite;

final class SubscriptionPilot
{
    public function __construct(private readonly Database $db)
    {
    }

    /** These reads also run against an untouched 1.1 schema. */
    public function preflight(int $company): array
    {
        $known = $this->db->row('SELECT id,code FROM companies WHERE id=?',[$company]) ?? throw new BusinessError('未找到公司。',404);
        $tables = [
            'agreements'=>'SELECT a.id,a.reference_no,a.account_id,a.active_revision,a.version,v.currency,v.value_minor,v.starts_on,v.ends_on,v.decision,v.evidence_ref
                FROM agreements a LEFT JOIN agreement_versions v ON v.company_id=a.company_id AND v.agreement_id=a.id AND v.revision=a.active_revision WHERE a.company_id=? ORDER BY a.id',
            'contract_versions'=>'SELECT * FROM agreement_versions WHERE company_id=? ORDER BY agreement_id,revision',
            'tickets'=>'SELECT id,agreement_id,agreement_revision,state,version FROM service_tickets WHERE company_id=? ORDER BY id',
            'attachments'=>'SELECT handover_id,agreement_id,agreement_revision,byte_length,sha256,SHA2(body,256) actual_sha256 FROM delivery_acceptances WHERE company_id=? ORDER BY handover_id',
        ];
        $digests = [];
        foreach ($tables as $label=>$sql) {
            $hash = hash_init('sha256');
            $count = 0;
            foreach ($this->db->stream($sql,[$company]) as $row) {
                hash_update($hash,Input::json($row)."\n");
                ++$count;
            }
            $digests[$label] = ['count'=>$count,'sha256'=>hash_final($hash)];
        }
        $issues = $this->db->rows('SELECT id,issue FROM ('.$this->inspection().') checked WHERE issue IS NOT NULL ORDER BY id',[$company]);
        return ['company'=>$known,'baseline'=>$digests,'issues'=>$issues];
    }

    private function inspection(): string
    {
        return "SELECT a.id,a.version,CASE WHEN v.decision IS NULL OR v.decision<>'signed' THEN 'unsigned_revision'
            WHEN c.id IS NULL THEN 'unknown_payer' WHEN NOT EXISTS (SELECT 1 FROM quote_lines l WHERE l.company_id=a.source_company_id
            AND l.quote_id=a.quote_id AND l.revision=a.quote_revision) THEN 'missing_quote_lines' ELSE NULL END issue FROM agreements a
            LEFT JOIN agreement_versions v ON v.company_id=a.company_id AND v.agreement_id=a.id AND v.revision=a.active_revision
            LEFT JOIN accounts c ON c.company_id=a.company_id AND c.id=a.account_id WHERE a.company_id=?";
    }

    public function backfill(Access $actor,int $company,int $limit): array
    {
        $actor->allow($company,'manager');
        $limit = Input::integer($limit,'批量大小',1,500);
        return $this->db->transaction(function () use ($company,$limit): array {
            $this->ensure($company);
            $state = $this->status($company,true);
            $rows = $this->db->rows('SELECT q.* FROM ('.$this->inspection().') q LEFT JOIN subscription_contract_checks c ON c.company_id=? AND c.agreement_id=q.id
                WHERE c.checked_version IS NULL OR c.checked_version<>q.version OR c.issue IS NOT NULL
                ORDER BY (c.issue IS NOT NULL),q.id LIMIT '.$limit,[$company,$company]);
            $cursor = $state['last_agreement_id'];
            foreach ($rows as $r) {
                $this->db->run('INSERT INTO subscription_contract_checks (company_id,agreement_id,checked_version,issue) VALUES (?,?,?,?)
                    ON DUPLICATE KEY UPDATE checked_version=?,issue=?',[$company,$r['id'],$r['version'],$r['issue'],$r['version'],$r['issue']]);
                $cursor = max($cursor,$r['id']);
            }
            $this->db->run('UPDATE subscription_pilots SET last_agreement_id=? WHERE company_id=?',[$cursor,$company]);
            return ['last_agreement_id'=>$cursor,'checked_ids'=>array_column($rows,'id'),'pending'=>$this->pending($company)];
        });
    }

    private function pending(int $company): int
    {
        return $this->db->row('SELECT COUNT(*) n FROM agreements a LEFT JOIN subscription_contract_checks c ON c.company_id=a.company_id AND c.agreement_id=a.id
            WHERE a.company_id=? AND (c.checked_version IS NULL OR c.checked_version<>a.version OR c.issue IS NOT NULL)',[$company])['n'];
    }

    private function ensure(int $company): void
    {
        $this->db->run('INSERT INTO subscription_pilots (company_id) VALUES (?) ON DUPLICATE KEY UPDATE company_id=company_id',[$company]);
    }

    public function status(int $company,bool $lock=false): array
    {
        return $this->db->row('SELECT state,version,last_agreement_id FROM subscription_pilots WHERE company_id=?'.($lock?' FOR UPDATE':''),[$company])
            ?? ['state'=>'setup','version'=>0,'last_agreement_id'=>0];
    }

    public function change(Access $actor,int $company,int $revision,string $mode): array
    {
        $actor->allow($company,'manager');
        Input::choice($mode,'试点状态',['active','draining']);
        return $this->db->transaction(function () use ($actor,$company,$revision,$mode): array {
            $this->ensure($company);
            $prior = $this->status($company,true);
            if ($prior['version'] !== $revision || $prior['state'] === $mode || ($prior['state']==='setup' && $mode==='draining')) {
                throw new BusinessError('试点状态或版本不允许此次切换。',409);
            }
            if ($mode==='active' && $this->pending($company)) {
                throw new BusinessError('旧合同尚未核对，请继续分批检查或处理归属问题。',409);
            }
            $this->db->run('UPDATE subscription_pilots SET state=?,version=version+1 WHERE company_id=?',[$mode,$company]);
            (new Audit($this->db))->record($actor,$company,'company',$company,$revision+1,'pilot.change',['from'=>$prior['state'],'to'=>$mode]);
            return $this->status($company);
        });
    }

    public function accepting(int $company): void
    {
        $row = $this->db->row('SELECT state FROM subscription_pilots WHERE company_id=? FOR SHARE',[$company]);
        if (($row['state'] ?? 'setup') !== 'active') {
            throw new BusinessError('此公司尚未启用订阅试点，或正在停止受理。',409);
        }
    }

    public function outstanding(Access $actor,int $company): iterable
    {
        $actor->allow($company,'manager','finance');
        return $this->db->stream("SELECT 'usage_conflict' kind,t.subscription_id,t.id object_id,t.request_key reference FROM usage_attempts t
            WHERE t.company_id=? AND t.disposition='conflict' AND NOT EXISTS (SELECT 1 FROM usage_conflict_reviews r WHERE r.company_id=t.company_id AND r.attempt_id=t.id)
            UNION ALL SELECT 'dispute',s.subscription_id,d.id,d.state FROM subscription_disputes d JOIN subscription_statements s ON s.id=d.statement_id AND s.company_id=d.company_id
            WHERE d.company_id=? AND d.state IN ('open','held') UNION ALL SELECT 'draft',s.subscription_id,s.id,s.billing_period FROM subscription_statements s
            WHERE s.company_id=? AND s.state='draft' ORDER BY kind,object_id",[$company,$company,$company]);
    }
}
