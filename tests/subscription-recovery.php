<?php
declare(strict_types=1);

use CustomerSuite\Access;
use CustomerSuite\Identity;
use CustomerSuite\Input;
use CustomerSuite\Operations;
use CustomerSuite\SubscriptionBilling;
use CustomerSuite\UsageEvents;

require __DIR__.'/bootstrap.php';
require __DIR__.'/SubscriptionFixture.php';
[$script,$phase,$path] = $argv;
$db->run("SET timestamp=UNIX_TIMESTAMP('2027-03-01 00:00:00')");
$admin = new Access($db,$db->row('SELECT id FROM users WHERE is_admin=1 ORDER BY id LIMIT 1')['id']);
$ops = new Operations($db);
if ($phase==='setup') {
    $tag = bin2hex(random_bytes(4));
    $company = (new Identity($db))->addCompany($admin,'CRASH-'.$tag,'中断恢复账套');
    $fixture = signedSubscriptionFixture($db,$admin,$company,$tag);
    $sub = $ops->execute($admin,$company,'subscription.open','open',$fixture['open']+$fixture['branches'][0])['result'];
    $state = ['company'=>$company,'subscription'=>$sub['subscription_id'],'agreement'=>$sub['agreement_id'],
        'payload'=>['source'=>'recovery','event_id'=>'HTTP-DROPPED','metric'=>'api_calls','units'=>120,'occurred_at'=>'2027-03-05T13:20:00+08:00']];
    file_put_contents($path,Input::json($state));
    exit;
}
$state = json_decode(file_get_contents($path),true,flags:JSON_THROW_ON_ERROR);
$company = $state['company'];
$id = $state['subscription'];
$usage = (new UsageEvents($db))->ingest($admin,$company,$id,'lost-ack',$state['payload']);
if ($phase==='interrupt') {
    // The database has committed. The parent terminates this process before it can deliver any response to the caller.
    file_put_contents($path.'.committed',Input::json($usage),LOCK_EX);
    sleep(90);
    throw new RuntimeException('Crash injection did not terminate the producer.');
}
if ($phase!=='verify') { throw new RuntimeException('Unknown recovery phase'); }
$first = json_decode(file_get_contents($path.'.committed'),true,flags:JSON_THROW_ON_ERROR);
same(Input::digest($first),Input::digest($usage),'Original request returns the same accepted operation');
$watermark = $db->row('SELECT COUNT(*) events,MAX(id) last_usage_id,SUM(units) units,MAX(occurred_at) last_occurred_at FROM subscription_usage
    WHERE company_id=? AND subscription_id=?',[$company,$id]);
same([1,120],[(int)$watermark['events'],(int)$watermark['units']],'No duplicate metering after restart');
$bill = (new SubscriptionBilling($db))->draft($admin,$company,$id,'2027-03');
same(14000,$bill['total_minor'],'Recalculated bill after restart');
$contract = $db->row('SELECT a.id,a.active_revision,a.version,v.value_minor,v.evidence_ref FROM agreements a JOIN agreement_versions v
    ON v.company_id=a.company_id AND v.agreement_id=a.id AND v.revision=a.active_revision WHERE a.company_id=? AND a.id=?',[$company,$state['agreement']]);
echo Input::json(['receipt'=>$usage,'watermark'=>$watermark,'billing'=>$bill,'contract'=>$contract]),PHP_EOL;
