<?php
/** AMIAL-LOADTEST-004 — عامل كاشير: ينفّذ N فواتير بيع نقدي (مع خصم مخزون). */
$root = dirname(__DIR__, 2);
require "$root/vendor/autoload.php";
$app = require "$root/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\User; use App\Services\CashierService;
$wid=(int)($argv[1]??0); $ops=(int)($argv[2]??100); $mode=$argv[3]??'spread'; // spread | hot (كل البيع على تاجر واحد)
$pool=json_decode(file_get_contents(__DIR__.'/cashier_pool.json'),true); $n=count($pool);
$cash = app(CashierService::class); $ok=$deadlock=$conn=$other=0; mt_srand($wid*7919+13);
for($i=0;$i<$ops;$i++){
  $e = $mode==='hot' ? $pool[0] : $pool[mt_rand(0,$n-1)];
  try {
    $m = User::find($e['merchant']);
    $cash->recordSale($m, '10.0000', 'cash', [['product_id'=>$e['product'],'qty'=>1,'price'=>10]]);
    $ok++;
  } catch(\Throwable $ex){ $msg=$ex->getMessage();
    if(str_contains($msg,'Deadlock')||str_contains($msg,'Lock wait')) $deadlock++;
    elseif(str_contains($msg,'Too many connections')||str_contains($msg,'1040')) $conn++;
    else $other++; }
}
file_put_contents(__DIR__."/cres_$wid.json", json_encode(compact('ok','deadlock','conn','other')));
