<?php
declare(strict_types=1);
$base = ['company'=>$chosen,'view'=>'subscriptions'];
if (!isset($query['id'])):
?>
<section class="panel table-panel"><h2>合同订阅</h2>
<table><thead><tr><th>订阅</th><th>付款主体</th><th>服务对象</th><th>席位</th><th>到期日（不含）</th></tr></thead><tbody>
<?php foreach ($result['items'] as $item): ?><tr><td><a href="<?= e(url($base+['id'=>$item['subscription_id']])) ?>">#<?= e($item['subscription_id']) ?></a></td>
<td><?= e($item['payer']) ?></td><td><?= e($item['beneficiary']) ?></td><td><?= e($item['seats']) ?></td><td><?= e($item['term_end']) ?></td></tr><?php endforeach; ?>
</tbody></table><?php if (!$result['items']): ?><p>当前没有可查看的订阅。</p><?php endif; ?>
<?php if ($result['next_before']): ?><a href="<?= e(url($base+['before'=>$result['next_before']])) ?>">下一页</a><?php endif; ?></section>
<?php
if ($actor->has($chosen,'manager','finance')) {
    formPanel('导入付款回执 CSV',$csrf,'billing.import-payments','核对并登记整批',static function (): void {
        echo '<label>付款回执文件 <input type="file" name="payments" accept=".csv,text/csv" required></label>';
        echo '<p>列顺序为 statement_id、revision、receipt_no、received_on、amount。</p>';
    },[],true);
}
if ($actor->has($chosen,'manager')) {
    echo '<p>订阅试点 · ',e(['setup'=>'准备中','active'=>'受理中','draining'=>'正在排空'][$result['pilot']['state']]),' · v',e($result['pilot']['version']),'</p>';
    formPanel('分批核对旧合同',$csrf,'pilot.backfill','核对下一批',static fn()=>field('limit','本批合同数','number',true,100));
    $next = $result['pilot']['state']==='active'?'draining':'active';
    formPanel('切换受理状态',$csrf,'pilot.change',$next==='active'?'启用订阅试点':'停止新增受理',static function ():void {},
        ['state'=>$next,'version'=>$result['pilot']['version']]);
    echo '<a href="',e('/export.php?'.http_build_query(['company'=>$chosen,'kind'=>'billing-open'])),'">导出未决计量、争议和草账</a>';
    formPanel('发布套餐价格',$csrf,'plan.publish','发布版本',static function (): void {
        field('code','签约产品编码'); field('name','套餐名称'); field('previous_revision','上一版本','text',true,'0'); field('effective_on','生效日','date');
        field('currency','币种','text',true,'CNY'); field('monthly_fee','每月固定费'); selectField('service_level','服务等级',['standard'=>'标准','priority'=>'优先','critical'=>'关键']);
        textAreaField('metrics','计量规则（JSON）','[{"metric":"api_calls","included_units":100,"overage_fee":"2.00"}]');
    });
}
if ($actor->has($chosen,'manager','sales')) {
    formPanel('按已签合同开通',$csrf,'subscription.open','开通订阅',static function (): void {
        foreach (['agreement_id'=>'合同编号','agreement_revision'=>'签署版本','line_no'=>'行号','plan_id'=>'套餐编号',
            'entitlement_rev'=>'签约日价格版本','service_company_id'=>'服务对象所属公司编号','service_account_id'=>'服务客户编号','seats'=>'分配席位'] as $key=>$label) {
            field($key,$label,'number');
        }
        field('term_start','服务开始','date');field('term_end','截止日期（不含）','date');field('billing_timezone','计费时区','text',true,'Asia/Shanghai');
        selectField('mode','开通方式',['paid'=>'付费','trial'=>'试用']);
    });
}
endif;
if (isset($query['id'])): $base['id'] = $result['subscription_id'];
?>
<section class="panel table-panel"><h2><?= e($result['name']) ?> · #<?= e($result['subscription_id']) ?></h2>
<p><?= e($result['payer']) ?> 付款 · <?= e($result['beneficiary']) ?> 使用 · <?= e($result['seats']) ?> 席位</p>
<p><?= e($result['term_start']) ?> 至 <?= e($result['term_end']) ?>（截止日不含） · <?= e($result['billing_timezone']) ?> · 权益版本 <?= e($result['entitlement_rev']) ?></p>
<form method="get"><?php foreach ($base as $key=>$value): ?><input type="hidden" name="<?= e($key) ?>" value="<?= e($value) ?>"><?php endforeach; ?>
<label>权益日期 <input type="date" name="date" required value="<?= e($result['date']) ?>"></label><button>查询权益</button></form>
<p><?= e(['scheduled'=>'待开通','expired'=>'到期','active'=>'有效','trial'=>'试用','cancelled'=>'已取消'][$result['state']]) ?> · 月费 <?= e(CustomerSuite\Money::format($result['monthly_minor'],$result['currency'])) ?> <?= e($result['currency']) ?> · 本月固定费预计 <?= e(CustomerSuite\Money::format($result['fixed_forecast_minor'],$result['currency'])) ?></p>
<table><thead><tr><th>计量项</th><th>每期配额</th><th>当期已用</th><th>剩余额度</th><th>超量单价</th></tr></thead><tbody>
<?php foreach ($result['metrics'] as $metric): ?><tr><td><?= e($metric['metric']) ?></td><td><?= e($metric['included_units']) ?></td>
<td><?= e($metric['used_units']) ?></td><td><?= e($metric['balance_units']) ?></td><td><?= e(CustomerSuite\Money::format($metric['overage_minor'],$result['currency'])) ?></td></tr><?php endforeach; ?>
</tbody></table></section>
<?php
require __DIR__.'/subscription-billing.php';
if ($result['paid_from'] === null && $actor->has($chosen,'manager','sales')) {
    formPanel('试用转付费',$csrf,'subscription.convert','确认转换',static fn()=>field('paid_from','计费起始日','date'),['id'=>$result['subscription_id'],'version'=>$result['version']]);
}
if ($actor->has($chosen,'manager')) {
    formPanel('客户查阅授权',$csrf,'subscription.viewer','更新授权',static function (): void {
        field('user_id','客户管理员账号编号','number'); selectField('allowed','授权',['1'=>'授予','0'=>'撤销']);
    },['id'=>$result['subscription_id']]);
}
if (isset($result['attempts'])): ?>
<section class="panel table-panel"><h2>计量接收记录</h2>
<?php foreach (['conflict'=>'待核查差异','accepted'=>'已入账','duplicate'=>'重复投递'] as $key=>$label): ?>
<a href="<?= e(url($base+['disposition'=>$key,'date'=>$result['date']])) ?>"><?= e($label) ?></a><?php endforeach; ?>
<table><thead><tr><th>处理号</th><th>用量号</th><th>请求号</th><th>输入</th></tr></thead><tbody>
<?php foreach ($result['attempts'] as $attempt): ?><tr><td><?= e($attempt['id']) ?></td><td><?= e($attempt['usage_id']) ?></td><td><?= e($attempt['request_key']) ?></td><td><?= e($attempt['payload']) ?>
<?php if ($attempt['reviewer_id']!==null): ?> · 核查结论 <?= e($attempt['reason']) ?>
<?php elseif ($attempt['disposition']==='conflict' && $actor->has($chosen,'manager','meter')):
    formPanel('处理差异',$csrf,'usage.review','确认保留原始用量',static fn()=>field('reason','核查结论'),['id'=>$result['subscription_id'],'attempt_id'=>$attempt['id']]);
endif; ?></td></tr><?php endforeach; ?>
</tbody></table><?php if (count($result['attempts'])===50): ?><a href="<?= e(url($base+['disposition'=>$query['disposition']??'conflict','before'=>end($result['attempts'])['id'],'date'=>$result['date']])) ?>">更早记录</a><?php endif; ?></section>
<?php endif; endif; ?>
