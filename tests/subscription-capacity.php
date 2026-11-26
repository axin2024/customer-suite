<?php
declare(strict_types=1);

use CustomerSuite\Access;
use CustomerSuite\Identity;
use CustomerSuite\Operations;

require __DIR__.'/bootstrap.php';
require __DIR__.'/SubscriptionFixture.php';
$db->run("SET timestamp = UNIX_TIMESTAMP('2027-03-01 00:00:00')");
$tag = bin2hex(random_bytes(5));
$identity = new Identity($db);
$root = $db->row('SELECT id FROM users WHERE is_admin=1 ORDER BY id LIMIT 1');
$actor = new Access($db,$root['id'] ?? $identity->bootstrap('容量演练管理员',$tag.'@example.test','Test-only-'.bin2hex(random_bytes(15))));
$company = $identity->addCompany($actor,'PERF-'.$tag,'月账单容量演练');
$fixture = signedSubscriptionFixture($db,$actor,$company,$tag,1000);
$ops = new Operations($db);
$first = $ops->execute($actor,$company,'subscription.open','first',array_replace($fixture['open'],['seats'=>1])+$fixture['branches'][0])['result'];
$db->transaction(function () use ($db,$fixture,$company,$first,$actor): void {
    $customers = [];
    for ($i=1;$i<1000;++$i) {
        $customers[] = [$company,$company,$fixture['payer'],$actor->userId,sprintf('CAP-%04d',$i)];
    }
    $db->insertRows('INSERT INTO accounts (company_id,parent_company_id,parent_id,owner_id,name)',$customers);
    $db->run("INSERT INTO subscriptions (company_id,agreement_id,agreement_revision,source_company_id,quote_id,quote_revision,line_no,payer_id,
        service_company_id,service_account_id,seats,plan_id,entitlement_rev,term_start,term_end,paid_from,billing_timezone,opening_digest,opened_by)
        SELECT s.company_id,s.agreement_id,s.agreement_revision,s.source_company_id,s.quote_id,s.quote_revision,s.line_no,s.payer_id,c.company_id,c.id,1,
        s.plan_id,s.entitlement_rev,s.term_start,s.term_end,s.paid_from,s.billing_timezone,SHA2(CONCAT('seed-',c.id),256),s.opened_by
        FROM subscriptions s JOIN accounts c ON c.company_id=s.company_id AND c.parent_id=s.payer_id AND c.name LIKE 'CAP-%' WHERE s.id=?",[$first['subscription_id']]);
    $db->run("INSERT INTO subscription_usage (company_id,subscription_id,source,event_id,metric,units,occurred_at,local_date,billing_period,digest)
        WITH RECURSIVE seq(n) AS (SELECT 1 UNION ALL SELECT n+1 FROM seq WHERE n<100)
        SELECT s.company_id,s.id,'fixture',CONCAT(s.id,'-',seq.n),'api_calls',2,TIMESTAMPADD(SECOND,seq.n,'2027-03-15 00:00:00'),
            '2027-03-15','2027-03',SHA2(CONCAT('usage-',s.id,'-',seq.n),256)
        FROM subscriptions s CROSS JOIN seq WHERE s.company_id=?",[$company]);
});
$inputs = $db->row('SELECT COUNT(DISTINCT subscription_id) subscriptions,COUNT(*) events,SUM(units) units FROM subscription_usage WHERE company_id=?',[$company]);
same([1000,100000,200000],array_map('intval',array_values($inputs)),'Actual measured fixture');
$samples = [];
for ($run=0;$run<4;++$run) {
    if ($run) {
        // This company's synthetic drafts belong exclusively to this benchmark. Recreate each to measure inserts, including every fee line.
        $db->transaction(function () use ($db,$company): void {
            $db->run("DELETE l FROM subscription_statement_lines l JOIN subscription_statements s ON s.id=l.statement_id AND s.company_id=l.company_id
                WHERE s.company_id=? AND s.state='draft'",[$company]);
            $db->run("DELETE FROM subscription_statements WHERE company_id=? AND state='draft'",[$company]);
        });
    }
    $start = hrtime(true);
    $digests = [];
    $after = null;
    do {
        $fields = ['period'=>'2027-03'];
        if ($after !== null) { $fields['after'] = $after; }
        $reply = $ops->execute($actor,$company,'billing.batch','load-'.$run.'-'.($after ?? 0),$fields)['result'];
        foreach ($reply['items'] as $item) { $digests[$item['subscription_id']] = $item['digest']; }
        $after = $reply['next_after'];
    } while ($after !== null);
    $elapsed = round((hrtime(true)-$start)/1000000,3);
    $facts = $db->row('SELECT COUNT(*) statements,SUM(total_minor) total_minor FROM subscription_statements WHERE company_id=?',[$company]);
    $lines = $db->row('SELECT COUNT(*) n FROM subscription_statement_lines WHERE company_id=?',[$company])['n'];
    same([1000,30000000,51000],[(int)$facts['statements'],(int)$facts['total_minor'],$lines],'1000 fixed fees and 50 metered charges per subscription');
    same(1000,count($digests),'All subscriptions returned by real billing operation');
    same(true,$elapsed<=180000,'Monthly billing fits three-minute target');
    $digest = hash('sha256',json_encode($digests,JSON_THROW_ON_ERROR));
    if ($samples) { same($samples[0]['billing_digest'],$digest,'All source charges recalculate identically'); }
    $samples[] = ['phase'=>$run===0?'warmup':'measured','elapsed_ms'=>$elapsed,'successful_statements'=>count($digests),
        'rejections'=>0,'timeouts'=>0,'persisted_lines'=>$lines,'total_minor'=>(int)$facts['total_minor'],'billing_digest'=>$digest];
}
echo json_encode(['measured_at_utc'=>gmdate(DATE_ATOM),'platform'=>PHP_OS_FAMILY,'arch'=>php_uname('m'),
    'cpu'=>getenv('PROCESSOR_IDENTIFIER') ?: php_uname('m'),'php'=>PHP_VERSION,'php_peak_bytes'=>memory_get_peak_usage(true),
    'mysql'=>$db->row('SELECT VERSION() version,@@innodb_buffer_pool_size buffer_pool_bytes'),'fixture'=>$inputs,'samples'=>$samples],
    JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
