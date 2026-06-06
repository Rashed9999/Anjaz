<?php
/** AMIAL-LOADTEST-004 — تهيئة تجّار + منتجات لاختبار ضغط الكاشير. php seed_cashier.php [merchants] [stock] */
$root = dirname(__DIR__, 2);
require "$root/vendor/autoload.php";
$app = require "$root/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\User; use App\Models\MerchantProfile; use App\Services\CashierService;
use App\Support\Access\AccessConstants as A;
$M = (int)($argv[1] ?? 20); $STOCK = (string)($argv[2] ?? '1000000');
$cash = app(CashierService::class);
$merchants = [];
for ($i=0;$i<$M;$i++){
  $m = User::forceCreate(['f_name'=>'Mer','l_name'=>(string)$i,'phone'=>'+96771C'.str_pad((string)$i,6,'0',STR_PAD_LEFT),'password'=>bcrypt('x'),'type'=>3,'zone_code'=>'SOUTH']);
  MerchantProfile::create(['user_id'=>$m->id,'verification_status'=>'verified','tier'=>'small','risk_category'=>'standard','subscription_plan'=>A::PLAN_ENTERPRISE,'subscription_expires_at'=>now()->addYear(),'business_type'=>A::BIZ_RETAIL]);
  $p = $cash->addProduct($m, ['name'=>"Item$i",'price'=>'10','quantity'=>$STOCK]);
  $merchants[] = ['merchant'=>$m->id,'product'=>$p->id];
}
file_put_contents(__DIR__.'/cashier_pool.json', json_encode($merchants));
$totalStock = \App\Models\MerchantProduct::sum('quantity');
echo "seeded merchants=$M total_stock=$totalStock\n";
