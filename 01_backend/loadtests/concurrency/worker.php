<?php
/** AMIAL-LOADTEST-003 — عامل تزامن: ينفّذ N تحويلات بين أعضاء البِركة. */
$root = dirname(__DIR__, 2);
require "$root/vendor/autoload.php";
$app = require "$root/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$wid=(int)($argv[1]??0); $ops=(int)($argv[2]??100);
$pool=json_decode(file_get_contents(__DIR__.'/pool.json'),true); $ids=$pool['ids']; $n=count($ids);
$trait = new class { use \App\Traits\TransactionTrait; };
$ok=$insufficient=$deadlock=$conn=$other=0; mt_srand($wid*7919+13);
for($i=0;$i<$ops;$i++){
  $a=$ids[mt_rand(0,$n-1)]; $b=$ids[mt_rand(0,$n-1)]; if($a===$b)$b=$ids[($i+1)%$n];
  try { $trait->customer_send_money_transaction($a,$b,'10.0000','0.5000'); $ok++; }
  catch(\App\Exceptions\InsufficientBalanceException $e){ $insufficient++; }
  catch(\Throwable $e){ $m=$e->getMessage();
    if(str_contains($m,'Deadlock')||str_contains($m,'Lock wait')) $deadlock++;
    elseif(str_contains($m,'Too many connections')||str_contains($m,'1040')) $conn++;
    else $other++; }
}
file_put_contents(__DIR__."/res_$wid.json", json_encode(compact('ok','insufficient','deadlock','conn','other')));
