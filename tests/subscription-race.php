<?php
declare(strict_types=1);

use CustomerSuite\Access;
use CustomerSuite\Input;
use CustomerSuite\Operations;
use CustomerSuite\UsageEvents;

function concurrentSubscriptionRequests(array $first,array $second): array
{
    $workers = [];
    try {
        foreach ([$first,$second] as $request) {
            $child = proc_open([PHP_BINARY,__FILE__],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);
            if (!is_resource($child)) { throw new RuntimeException('Cannot spawn billing client'); }
            $workers[] = [$child,$pipes];
            fwrite($pipes[0],Input::json($request)."\n");
            fflush($pipes[0]);
            if (trim((string)fgets($pipes[1]))!=='ready') {
                throw new RuntimeException('Client failed before transaction: '.stream_get_contents($pipes[2]));
            }
        }
        foreach ($workers as [,$pipes]) { fwrite($pipes[0],"go\n"); fflush($pipes[0]); }
        $result = [];
        foreach ($workers as [,$pipes]) {
            $reply = fgets($pipes[1]);
            if (!$reply) { throw new RuntimeException('Client failed during transaction: '.stream_get_contents($pipes[2])); }
            $result[] = json_decode($reply,true,flags:JSON_THROW_ON_ERROR);
        }
        return $result;
    } finally {
        foreach ($workers as [$child,$pipes]) {
            foreach ($pipes as $pipe) { fclose($pipe); }
            proc_close($child);
        }
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'])===__FILE__) {
    require __DIR__.'/bootstrap.php';
    $request = json_decode(fgets(STDIN),true,flags:JSON_THROW_ON_ERROR);
    $actor = new Access($db,$request['user']);
    fwrite(STDOUT,"ready\n");
    fflush(STDOUT);
    if (trim((string)fgets(STDIN))==='go') {
        $result = $request['action']==='open'
            ? (new Operations($db))->execute($actor,$request['company'],'subscription.open',$request['key'],$request['data'])['result']
            : (new UsageEvents($db))->ingest($actor,$request['company'],$request['sub'],$request['key'],$request['data']);
        echo Input::json($result).PHP_EOL;
    }
}
