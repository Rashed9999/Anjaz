<?php
/** AMIAL-LOADTEST-003 — تهيئة بِركة محافظ لاختبار التزامن. الاستخدام: php seed_pool.php [count] */
$root = dirname(__DIR__, 2);
require "$root/vendor/autoload.php";
$app = require "$root/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\User; use App\Models\EMoney; use Illuminate\Support\Facades\Hash;
$POOL = (int)($argv[1] ?? 100);
$admin = User::forceCreate(['f_name'=>'Adm','l_name'=>'In','phone'=>'+96770ADMIN','password'=>Hash::make('x'),'type'=>0,'zone_code'=>'SOUTH']);
EMoney::create(['user_id'=>$admin->id,'current_balance'=>'0.0000','charge_earned'=>'0.0000','pending_balance'=>'0.0000','held_balance'=>'0.0000','zone_code'=>'SOUTH','version'=>0]);
$ids=[];
for($i=0;$i<$POOL;$i++){
  $u=User::forceCreate(['f_name'=>'LT','l_name'=>(string)$i,'phone'=>'+96770P'.str_pad((string)$i,6,'0',STR_PAD_LEFT),'password'=>Hash::make('x'),'type'=>2,'zone_code'=>'SOUTH']);
  EMoney::create(['user_id'=>$u->id,'current_balance'=>'1000000.0000','charge_earned'=>'0.0000','pending_balance'=>'0.0000','held_balance'=>'0.0000','zone_code'=>'SOUTH','version'=>0]);
  $ids[]=$u->id;
}
file_put_contents(__DIR__.'/pool.json', json_encode(['admin'=>$admin->id,'ids'=>$ids]));
echo "seeded pool=$POOL wallets_total=".EMoney::sum('current_balance')."\n";
