<?php

declare(strict_types=1);

use CustomerSuite\Workbench;
use CustomerSuite\ServiceSettings;

$manager = $actor->has($chosen, 'manager');
$sales = $actor->has($chosen, 'sales');
$marketing = $actor->has($chosen, 'marketing');
$detail = isset($query['id']);
$idVersion = $detail ? ['id' => $result['id'], 'version' => $result['version'] ?? 1] : [];
$owned = $detail && (int) ($result['owner_id'] ?? 0) === $actor->userId;

if ($view === 'accounts' && !$detail && ($manager || $sales)) {
    formPanel('新增客户', $csrf, 'account.create', '保存客户', static function () use ($manager): void {
        field('name', '客户名称');
        field('registration_ref', '主体登记号', 'text', false);
        field('parent_id', '集团主档编号', 'number', false);
        if ($manager) {
            field('parent_company_id', '上级公司编号', 'number', false);
            field('owner_id', '负责销售编号', 'number', false);
        }
    });
}

if ($view === 'accounts' && $detail && ($manager || ($sales && $owned)) && $result['state'] !== 'merged') {
    $accountId = ['account_id' => $result['id']];
    formPanel('新增联系人', $csrf, 'contact.create', '保存联系人', static function (): void {
        field('name', '姓名');
        field('job_title', '职位');
        field('email', '邮箱', 'email', false);
        field('phone', '电话', 'tel', false);
    }, $accountId);
    formPanel('添加档案来源', $csrf, 'account.alias', '保存档案来源', static function (): void {
        field('source', '外部系统');
        field('external_id', '外部主体编号');
    }, $idVersion);
    formPanel('登记联系人授权', $csrf, 'contact.permission', '保存授权决定', static function () use ($result): void {
        selectField('id', '联系人', array_column($result['contacts'], 'name', 'id'));
        field('version', '联系人当前版本', 'number');
        selectField('purpose', '用途', ['export' => '名单导出', 'marketing' => '营销', 'success' => '客户成功', 'service' => '服务跟进']);
        selectField('channel', '渠道', ['email' => '邮箱', 'phone' => '电话']);
        selectField('allowed', '选择', ['1' => '同意', '0' => '撤回']);
        field('evidence', '依据编号');
    }, $accountId);
    if ($manager) {
        formPanel('更新负责人或冻结状态', $csrf, 'account.revise', '保存状态', static function (): void {
            field('owner_id', '负责销售编号', 'number', false);
            selectField('state', '客户状态', ['active' => '正常', 'frozen' => '冻结'], false);
        }, $idVersion);
        formPanel('归并重复客户', $csrf, 'account.merge', '执行归并', static function (): void {
            field('source_id', '被归并档案编号', 'number');
            field('source_version', '被归并档案版本', 'number');
        }, $idVersion);
    }
}

if ($view === 'campaigns' && ($manager || $marketing)) {
    formPanel('新增活动', $csrf, 'campaign.create', '保存活动', static function (): void {
        field('code', '活动代码');
        field('name', '活动名称');
        selectField('channel', '渠道', ['web' => '网站', 'email' => '电子邮件', 'event' => '活动', 'partner' => '合作伙伴', 'direct' => '直接联系', 'social' => '社交渠道']);
    });
    formPanel('收录活动线索', $csrf, 'lead.receive', '收录线索', static function () use ($result): void {
        selectField('campaign_id', '所属活动', array_column($result['items'], 'name', 'id'));
        field('name', '线索名称');
        field('email', '邮箱', 'email', false);
        field('phone', '电话', 'tel', false);
        field('source', '来源系统');
        field('external_id', '来源记录编号');
        field('occurred_at', '发生时间（含时区）', 'text', true, gmdate('Y-m-d\TH:i:s\Z'));
    });
}

if ($view === 'leads' && $detail) {
    if ($sales && $result['state'] === 'open') {
        formPanel('认领线索', $csrf, 'lead.claim', '分配给我', static function (): void {}, $idVersion);
    }
    if (($manager || $marketing) && !in_array($result['state'], ['closed', 'converted'], true)) {
        formPanel('分配或回收线索', $csrf, 'lead.reassign', '更新负责人', static function (): void {
            field('owner_id', '销售编号（留空回收）', 'number', false);
            field('reason', '原因');
        }, $idVersion);
    }
    if (($manager || ($sales && $owned)) && in_array($result['state'], ['assigned', 'qualified'], true)) {
        formPanel('确认资格', $csrf, 'lead.qualify', '保存资格记录', static function (): void {
            field('account_id', '关联客户编号', 'number');
            field('next_follow_up', '下次跟进日期', 'date');
        }, $idVersion);
    }
}

if ($view === 'opportunities') {
    if (!$detail) {
        formPanel('新增商机或转化线索', $csrf, 'opportunity.create', '保存商机', static function (): void {
            field('title', '商机名称');
            field('account_id', '客户编号', 'number');
            field('lead_id', '已确认的线索编号', 'number', false);
            field('lead_version', '线索当前版本', 'number', false);
            field('currency', '币种', 'text', true, 'CNY');
            field('amount', '预估金额');
            field('expected_close', '预计成交日期', 'date');
        });
    } elseif (!in_array($result['stage'], ['won', 'lost'], true)) {
        formPanel('变更商机阶段', $csrf, 'opportunity.move', '保存阶段', static function (): void {
            $stages = Workbench::FILTERS['opportunities'];
            selectField('stage', '目标阶段', array_intersect_key(Workbench::STATES, array_flip($stages)));
            field('decision_contact_id', '决策人编号', 'number', false);
            field('next_follow_up', '跟进日期', 'date', false);
            field('reason', '变更原因', 'text', false);
        }, $idVersion);
        formPanel('更新产品明细', $csrf, 'opportunity.products', '保存明细', static function () use ($result): void {
            $items = array_map(static fn (array $line): array => [
                'product_code' => $line['product_code'], 'product_version' => $line['product_version'],
                'description' => $line['description'], 'quantity' => $line['quantity'],
                'unit_price' => \CustomerSuite\Money::format($line['unit_minor'], $result['currency']),
            ], $result['products']);
            require __DIR__ . '/product-rows.php';
        }, $idVersion);
    }
    if ($detail && $result['quote'] === null && in_array($result['stage'], ['proposal', 'negotiation', 'won'], true)) {
        formPanel('草拟报价', $csrf, 'quote.prepare', '保存报价草稿', static function (): void {
            field('discount_bps', '折扣（基点，100 为 1%）', 'text', true, '0');
            field('expires_on', '报价有效至（UTC）', 'date');
            field('terms', '报价条款');
        }, ['opportunity_id' => $result['id'], 'opportunity_version' => $result['version']]);
    }
}

if ($view === 'forecasts' && !$detail) {
    formPanel('冻结新的预测', $csrf, 'forecast.capture', '保存预测快照', static function (): void {
        field('label', '快照标题');
        field('period_start', '预计成交起始日', 'date');
        field('period_end', '预计成交结束日', 'date');
    });
}

if ($view === 'quotes' && !$detail && $manager) {
    formPanel('设置本公司折扣授权', $csrf, 'quote.policy', '保存折扣规则', static function (): void {
        field('self_approval_bps', '自行审批折扣上限（基点，100 为 1%）');
    });
}

if ($view === 'tickets' && !$detail) {
    formPanel('登记工单', $csrf, 'ticket.receive', '保存受理记录', static function (): void {
        field('agreement_id', '服务合同编号', 'number');
        selectField('category', '工单分类', ServiceSettings::CATEGORIES);
        selectField('priority', '优先级', array_combine(ServiceSettings::PRIORITIES, ServiceSettings::PRIORITIES));
        field('title', '工单标题');
        field('description', '故障或请求说明');
        field('source', '来源代码', 'text', true, 'workbench');
        field('external_id', '来源编号', 'text', true, 'WB-' . bin2hex(random_bytes(8)));
        field('occurred_at', '事件时间（带时区）', 'text', true, gmdate('Y-m-d\TH:i:s\Z'));
    });
    if ($manager) {
        formPanel('扫描到期工单', $csrf, 'ticket.sweep', '执行超时扫描', static function (): void {});
    }
}
