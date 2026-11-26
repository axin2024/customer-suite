<?php
declare(strict_types=1);
use CustomerSuite\Money;

$money = static fn(int $minor): string => Money::format($minor,$result['currency']).' '.$result['currency'];
$finance = $actor->has($chosen,'manager','finance');
if ($actor->has($chosen,'manager','sales')) {
    $fields = static function (): void {
        selectField('kind','变更类型',['change'=>'更换套餐','cancel'=>'期末取消']);
        field('effective_on','变更日期（取消时选申请日）','date');field('plan_id','目标套餐编号','number',false);field('entitlement_rev','目标价格版本','number',false);
        field('reason','变更依据');
    };
    $hidden = ['id'=>$result['subscription_id'],'version'=>$result['version']];
    formPanel('试算合同变更',$csrf,'subscription.preview','试算当期费用',$fields,$hidden);
    if (isset($receipt['result']['proration_minor'])) {
        $preview = $receipt['result'];
        echo '<p role="status">',e($preview['effective_on']),' 生效 · 变更前 ',e($money($preview['before_minor'])),' · 变更后 ',e($money($preview['after_minor'])),' · 差额 ',e($money($preview['proration_minor'])),'</p>';
    }
    formPanel('登记已确认变更',$csrf,'subscription.amend','确认变更',$fields,$hidden);
}
if ($finance) {
    formPanel('生成或重算月度草账',$csrf,'billing.draft','计算账单',static fn()=>field('period','账期','month',true,substr($result['date'],0,7)),['id'=>$result['subscription_id']]);
}
?>
<section class="panel table-panel"><h2>月度账单</h2><p>下月预计固定费 <?= e($money($result['next_fixed_minor'])) ?></p>
<table><thead><tr><th>账期</th><th>状态</th><th>版本</th><th>金额</th></tr></thead><tbody>
<?php foreach ($result['statements'] as $item): ?><tr><td><a href="<?= e(url($base+['date'=>$result['date'],'statement'=>$item['id']])) ?>"><?= e($item['billing_period']) ?></a></td>
<td><?= $item['state']==='locked'?'已锁定':'待复核' ?></td><td><?= e($item['revision']) ?></td><td><?= e($money($item['total_minor'])) ?></td></tr><?php endforeach; ?>
</tbody></table></section>
<?php if (!isset($result['statement'])) { return; }
$bill = $result['statement'];
$ref = ['id'=>$bill['statement_id'],'version'=>$bill['statement_rev']];
?>
<section class="panel table-panel"><h2><?= e($bill['billing_period']) ?> 账单 #<?= e($bill['statement_id']) ?></h2>
<p>合计 <?= e($money($bill['total_minor'])) ?> · 争议冻结 <?= e($money($bill['disputed_minor'])) ?> · 已收 <?= e($money($bill['paid_minor'])) ?> · 可收 <?= e($money($bill['collectible_minor'])) ?></p>
<table><thead><tr><th>账单行</th><th>来源账期</th><th>项目</th><th>原用量号</th><th>计费数量</th><th>金额</th><th>价格版本</th></tr></thead><tbody>
<?php foreach ($bill['lines'] as $line): ?><tr><td><?= e($line['id']) ?></td><td><?= e($line['origin_period']) ?></td>
<td><?= e(['fixed'=>'固定费','overage'=>'超量费','adjustment'=>'跨期调整'][$line['kind']]) ?></td><td><?= e($line['usage_id']) ?></td>
<td><?= e($line['quantity']) ?></td><td><?= e($money($line['amount_minor'])) ?></td><td>#<?= e($line['plan_id']) ?> / <?= e($line['rate_rev']) ?></td></tr><?php endforeach; ?></tbody></table>
<?php foreach ($bill['credits'] as $credit): ?><p>调整 #<?= e($credit['id']) ?> · 用量 #<?= e($credit['usage_id']) ?> · 减少 <?= e($credit['units']) ?> · <?= e($credit['reason']) ?></p><?php endforeach; ?>
<?php foreach ($bill['disputes'] as $case): ?><p>争议 #<?= e($case['id']) ?> · <?= e(['open'=>'待复核','held'=>'继续冻结','credited'=>'已抵扣','denied'=>'驳回'][$case['state']]) ?> · <?= e($case['reason']) ?> · <?= e($case['decision_reason']) ?></p>
<?php if ($finance && in_array($case['state'],['open','held'],true)) {
    formPanel('处理争议 #'.$case['id'],$csrf,'dispute.decide','保存决定',static function (): void {
        selectField('decision','处理结果',['held'=>'冻结争议金额','credited'=>'批准冲减用量','denied'=>'驳回争议']);field('reason','复核依据');
    },$ref+['dispute_id'=>$case['id'],'dispute_rev'=>$case['revision']]);
} endforeach; ?>
<?php foreach ($bill['payments'] as $payment): ?><p>到账 <?= e($payment['received_on']) ?> · <?= e($payment['receipt_no']) ?> · <?= e($money($payment['amount_minor'])) ?></p><?php endforeach; ?></section>
<?php
if ($actor->has($chosen,'manager','service')) {
    $choices = [];
    foreach ($bill['lines'] as $line) {
        if ($line['kind']==='overage') { $choices[$line['id']] = '用量 #'.$line['usage_id'].' · '.$money($line['amount_minor']); }
    }
    if ($choices) {
        formPanel('用量争议',$csrf,'dispute.raise','提交财务复核',static function () use ($choices): void {
            selectField('line_id','账单行',$choices); field('units','争议数量','number');field('reason','原因');
        },$ref);
    }
}
if ($finance && $bill['state']==='draft') {
    formPanel('复核草账',$csrf,'billing.lock','锁定当前版本',static function () use ($bill): void {
        echo '<p>',e($bill['billing_period']),' · v',e($bill['statement_rev']),'</p>';
    },$ref+['digest'=>$bill['digest']]);
}
if ($finance && $bill['state']==='locked') {
    echo '<a href="',e('/export.php?'.http_build_query(['company'=>$chosen,'statement'=>$bill['statement_id']])),'">导出开票明细 CSV</a>';
    formPanel('登记付款回执',$csrf,'billing.payment','确认到账',static function (): void {
        field('receipt_no','付款机构回执号');field('received_on','到账日期','date');field('amount','到账金额');
    },$ref);
}
