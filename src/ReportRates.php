<?php

declare(strict_types=1);

namespace CustomerSuite;

final class ReportRates
{
    public function __construct(private readonly Database $db)
    {
    }

    public function create(Access $actor, int $company, array $data): array
    {
        $actor->allow($company, 'manager');
        $date = Input::date($data['rate_date']);
        if ($date > gmdate('Y-m-d')) {
            throw new BusinessError('汇率日期不得晚于当前 UTC 日期。');
        }
        $source = Input::text($data['source'], '汇率来源', 160);
        $rates = ['CNY' => '1.00000000'];
        foreach ($data['rates'] as $code => $value) {
            $currency = Money::currency($code);
            if (!is_string($value) || !preg_match('/^(0|[1-9][0-9]{0,3})(?:\.([0-9]{1,8}))?$/D', $value, $parts)) {
                throw new BusinessError('请用十进制字符串提供汇率，最多 4 位整数和 8 位小数。');
            }
            $rate = $parts[1] . '.' . str_pad($parts[2] ?? '', 8, '0');
            if ($rate === '0.00000000' || ($currency === 'CNY' && $rate !== $rates['CNY'])) {
                throw new BusinessError('汇率必须大于零，CNY 固定为 1。');
            }
            if ($currency !== 'CNY' && isset($rates[$currency])) {
                throw new BusinessError('同一币种只允许登记一次。');
            }
            $rates[$currency] = $rate;
        }
        return $this->db->transaction(function () use ($actor, $company, $date, $source, $rates): array {
            $id = $this->db->insert('INSERT INTO report_fx_sets (company_id,rate_date,source,created_by) VALUES (?,?,?,?)',
                [$company, $date, $source, $actor->userId]);
            $rows = [];
            foreach ($rates as $currency => $rate) {
                $rows[] = [$company, $id, $currency, $rate];
            }
            $this->db->insertRows('INSERT INTO report_fx_rates (company_id,set_id,currency,rate)', $rows);
            (new Audit($this->db))->record($actor, $company, 'report_fx', $id, 1, 'report.fx',
                ['rate_date' => $date, 'source' => $source, 'rates' => $rates]);
            return ['id' => $id, 'version' => 1, 'rate_date' => $date, 'rates' => $rates];
        });
    }

    public function list(Access $actor, int $company): array
    {
        $actor->allow($company, 'manager');
        return $this->db->rows('SELECT id,rate_date,source,created_at FROM report_fx_sets WHERE company_id = ? ORDER BY id DESC LIMIT 50', [$company]);
    }

    public function record(int $company, int $id): array
    {
        return $this->db->row(
            'SELECT id,rate_date,source,created_at FROM report_fx_sets WHERE company_id = ? AND id = ?', [$company, $id],
        ) ?? throw new BusinessError('未找到当前公司的汇率版本。', 404);
    }
}
