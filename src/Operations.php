<?php

declare(strict_types=1);

namespace CustomerSuite;

final class Operations
{
    private const FIELDS = [
        'account.create' => ['name', 'registration_ref?', 'owner_id:i?', 'parent_id:i?', 'parent_company_id:i?'],
        'account.revise' => ['id:i', 'version:i', 'owner_id:i?', 'state?'],
        'account.alias' => ['id:i', 'version:i', 'source', 'external_id'],
        'account.merge' => ['id:i', 'version:i', 'source_id:i', 'source_version:i'],
        'contact.create' => ['account_id:i', 'name', 'job_title', 'email?', 'phone?'],
        'contact.revise' => ['account_id:i', 'id:i', 'version:i', 'name', 'job_title', 'email?', 'phone?'],
        'contact.permission' => ['account_id:i', 'id:i', 'version:i', 'purpose', 'channel', 'allowed:b', 'evidence'],
        'campaign.create' => ['code', 'name', 'channel'],
        'lead.receive' => ['campaign_id:i', 'name', 'email?', 'phone?', 'source', 'external_id', 'occurred_at'],
        'lead.claim' => ['id:i', 'version:i'],
        'lead.reassign' => ['id:i', 'version:i', 'owner_id:i?', 'reason'],
        'lead.qualify' => ['id:i', 'version:i', 'account_id:i', 'next_follow_up'],
        'opportunity.create' => ['account_id:i', 'lead_id:i?', 'lead_version:i?', 'title', 'currency', 'amount', 'expected_close'],
        'opportunity.move' => ['id:i', 'version:i', 'stage', 'decision_contact_id:i?', 'next_follow_up?', 'reason?'],
        'opportunity.products' => ['id:i', 'version:i', 'products:j'],
        'forecast.capture' => ['label', 'period_start', 'period_end'],
        'quote.policy' => ['self_approval_bps:n'],
        'quote.prepare' => ['opportunity_id:i', 'opportunity_version:i', 'version:i?', 'discount_bps:n', 'expires_on', 'terms'],
        'quote.submit' => ['id:i', 'version:i'],
        'quote.decide' => ['id:i', 'version:i', 'decision', 'reason'],
        'agreement.sign' => ['id:i', 'version:i', 'scope', 'allocations:j'],
        'agreement.propose' => ['id:i', 'version:i', 'amount', 'starts_on', 'ends_on', 'service_level', 'terms', 'reason'],
        'agreement.decide' => ['id:i', 'version:i', 'decision', 'reason', 'evidence_ref?'],
        'agreement.move' => ['id:i', 'version:i', 'state', 'reason'],
    ];

    public function __construct(private readonly Database $db)
    {
    }

    public function execute(Access $actor, int $companyId, string $action, string $requestKey, array $input): array
    {
        $data = $this->normalize($action, $input);
        return (new Receipts($this->db))->run(
            $actor, $companyId, $requestKey, $action, $data,
            fn (): array => $this->dispatch($actor, $companyId, $action, $data),
        );
    }

    private function dispatch(Access $actor, int $companyId, string $action, array $data): array
    {
        return match ($action) {
            'account.create' => (new Accounts($this->db))->create($actor, $companyId, $data),
            'account.revise' => (new Accounts($this->db))->revise($actor, $companyId, $data['id'], $data['version'],
                $data['owner_id'] ?? null, $data['state'] ?? null),
            'account.alias' => (new Accounts($this->db))->addAlias($actor, $companyId, $data['id'], $data['version'],
                $data['source'], $data['external_id']),
            'account.merge' => (new Accounts($this->db))->merge($actor, $companyId, $data['id'], $data['version'],
                $data['source_id'], $data['source_version']),
            'contact.create' => (new Contacts($this->db))->save($actor, $companyId, $data['account_id'], $data),
            'contact.revise' => (new Contacts($this->db))->save($actor, $companyId, $data['account_id'], $data, $data['id']),
            'contact.permission' => (new Contacts($this->db))->permission($actor, $companyId, $data['account_id'],
                $data['id'], $data['version'], $data),
            'campaign.create' => (new Campaigns($this->db))->create($actor, $companyId, $data),
            'lead.receive' => (new LeadIntake($this->db))->receive($actor, $companyId, $data),
            'lead.claim' => (new Leads($this->db))->claim($actor, $companyId, $data['id'], $data['version']),
            'lead.reassign' => (new Leads($this->db))->reassign($actor, $companyId, $data['id'], $data['version'],
                $data['owner_id'] ?? null, $data['reason']),
            'lead.qualify' => (new Leads($this->db))->qualify($actor, $companyId, $data['id'], $data['version'],
                $data['account_id'], $data['next_follow_up']),
            'opportunity.create' => (new Opportunities($this->db))->create($actor, $companyId, $data),
            'opportunity.move' => (new Opportunities($this->db))->move($actor, $companyId, $data['id'], $data['version'], $data),
            'opportunity.products' => (new Opportunities($this->db))->updateProducts($actor, $companyId,
                $data['id'], $data['version'], $data['products']),
            'forecast.capture' => (new Forecasts($this->db))->capture($actor, $companyId, $data),
            'quote.policy' => (new Quotes($this->db))->setPolicy($actor, $companyId, $data['self_approval_bps']),
            'quote.prepare' => (new Quotes($this->db))->prepare($actor, $companyId, $data),
            'quote.submit' => (new Quotes($this->db))->submit($actor, $companyId, $data['id'], $data['version']),
            'quote.decide' => (new Quotes($this->db))->decide($actor, $companyId, $data['id'], $data['version'], $data['decision'], $data['reason']),
            'agreement.sign' => (new Agreements($this->db))->sign($actor, $companyId, $data['id'], $data['version'], $data),
            'agreement.propose' => (new Agreements($this->db))->propose($actor, $companyId, $data['id'], $data['version'], $data),
            'agreement.decide' => (new Agreements($this->db))->decide($actor, $companyId, $data['id'], $data['version'], $data),
            'agreement.move' => (new Agreements($this->db))->move($actor, $companyId, $data['id'], $data['version'], $data['state'], $data['reason']),
        };
    }

    private function normalize(string $action, array $input): array
    {
        $fields = self::FIELDS[$action] ?? throw new BusinessError('未找到操作。', 404);
        $data = [];
        foreach ($fields as $definition) {
            $optional = str_ends_with($definition, '?');
            [$name, $type] = array_pad(explode(':', rtrim($definition, '?'), 2), 2, 's');
            $value = $input[$name] ?? null;
            unset($input[$name]);
            if ($optional && ($value === null || (is_string($value) && trim($value) === ''))) {
                continue;
            }
            $data[$name] = match ($type) {
                'i' => Input::id($value, $name),
                'n' => Input::integer($value, $name, 0, 10000),
                'b' => match ($value) {
                    true, '1' => true,
                    false, '0' => false,
                    default => throw new BusinessError('请选择同意或撤回。'),
                },
                'j' => self::structured($value, $name),
                default => Input::text($value, $name, 4096),
            };
        }
        if ($input !== []) {
            throw new BusinessError('操作包含未定义的字段。');
        }
        return $data;
    }

    private static function structured(mixed $input, string $name): array
    {
        if (is_string($input)) {
            try {
                $input = json_decode($input, true, 16, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new BusinessError($name . ' 须为有效 JSON。');
            }
        }
        return is_array($input) ? $input : throw new BusinessError($name . ' 须为数组。');
    }
}
