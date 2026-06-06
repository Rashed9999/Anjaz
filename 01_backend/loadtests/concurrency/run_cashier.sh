#!/usr/bin/env bash
# AMIAL-LOADTEST-004 — ضغط الكاشير: فواتير بيع متزامنة + فحص سلامة المخزون.
# الاستخدام: ./run_cashier.sh [workers] [ops] [mode: spread|hot] [merchants]
set -euo pipefail
cd "$(dirname "$0")"; ROOT="$(cd ../.. && pwd)"
W=${1:-50}; OPS=${2:-100}; MODE=${3:-spread}; MERCH=${4:-20}
php "$ROOT/artisan" migrate:fresh --force >/dev/null
php seed_cashier.php "$MERCH" 1000000 | tail -1
rm -f cres_*.json
BASE=$(php "$ROOT/artisan" tinker --execute='echo \App\Models\MerchantProduct::sum("quantity");' 2>/dev/null | tail -1)
echo "stock_baseline=$BASE | launching $W workers x $OPS = $((W*OPS)) [$MODE]"
S=$(date +%s.%N); for w in $(seq 1 "$W"); do php cashier_worker.php "$w" "$OPS" "$MODE" & done; wait; E=$(date +%s.%N)
php "$ROOT/artisan" tinker --execute='
$o=collect(glob(__DIR__."/loadtests/concurrency/cres_*.json"))->map(fn($p)=>json_decode(file_get_contents($p),true));
$sold=(float)getenv("BASE")-\App\Models\MerchantProduct::sum("quantity"); $sales=\App\Models\MerchantSale::count();
echo "ok=".$o->sum("ok")." deadlock=".$o->sum("deadlock")." conn_limit=".$o->sum("conn")." err=".$o->sum("other")."\n";
echo "stock_sold=$sold sales=$sales (مطابق=".($sold==$o->sum("ok")&&$sales==$o->sum("ok")?"نعم":"لا").")\n";
' 2>/dev/null | tail -2
echo "elapsed=$(echo "$E-$S"|bc)s"
