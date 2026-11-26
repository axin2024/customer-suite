<?php
declare(strict_types=1);
use CustomerSuite\Money;
$base = ['company'=>$chosen,'view'=>'retention','month'=>$result['month']];
?>
<section class="panel"><form method="get"><input type="hidden" name="view" value="retention"><input type="hidden" name="company" value="<?= e($chosen) ?>">
<label>最后服务月 <input type="month" name="month" value="<?= e($result['month']) ?>" required></label><button>切换月份</button></form>
<?php if ($actor->has($chosen,'manager')) {
    operation($csrf,'retention.scan','扫描本月到期订阅',static function (): void {},['month'=>$result['month']]);
} foreach ($result['summary'] as $s): ?>
<p><?= e($s['currency']) ?> · 待续金额 <?= e(Money::format((int)$s['forecast_minor'],$s['currency'])) ?> · 已签续期 <?= e(Money::format((int)$s['signed_minor'],$s['currency'])) ?>
 · 成功 <?= e($s['renewed']) ?> / 流失 <?= e($s['lost']) ?> · 已结案成功率 <?= $s['win_rate_bps']===null?'—':e($s['win_rate_bps']/100).'%' ?></p>
<?php endforeach; ?></section>
<?php foreach ($result['items'] as $case): ?><section class="panel"><h2>续约 #<?= e($case['id']) ?> · 合同 #<?= e($case['agreement_id']) ?></h2>
<p>付款客户 #<?= e($case['payer_id']) ?> · 负责人 #<?= e($case['owner_id']) ?> · 到期日（不含）<?= e($case['term_end']) ?> ·
<?= e(['open'=>'待跟进','working'=>'跟进中','renewed'=>'已续签','lost'=>'流失'][$case['state']]) ?></p>
<p>预计 <?= e(Money::format($case['forecast_minor'],$case['currency'])) ?> <?= e($case['currency']) ?> · <?= e($case['retention_action'] ?? $case['close_reason']) ?></p>
<?php if (in_array($case['state'],['open','working'],true)) {
    $hidden = ['id'=>$case['id'],'version'=>$case['version']];
    formPanel('安排保留动作',$csrf,'retention.follow','保存跟进',static function (): void {
        field('follow_up','跟进日期','date');field('forecast','预计续期金额');field('reason','保留动作');
    },$hidden);
    formPanel('关闭续约案件',$csrf,'retention.close','确认结果',static function (): void {
        selectField('outcome','结果',['renewed'=>'已签续期','lost'=>'流失']);field('signed_agreement_id','续期合同号（成功时必填）','number',false);field('reason','结果说明');
    },$hidden);
} ?></section><?php endforeach; ?>
<?php if ($result['next_before']): ?><a href="<?= e(url($base+['before'=>$result['next_before']])) ?>">下一页</a><?php endif; ?>
