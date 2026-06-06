#!/usr/bin/env bash
# AMIAL-LOADTEST-003 — اختبار تزامن القلب المالي + فحص سلامة الأموال.
# الاستخدام: ./run.sh [workers] [ops_per_worker] [pool]
set -euo pipefail
cd "$(dirname "$0")"; ROOT="$(cd ../.. && pwd)"
W=${1:-50}; OPS=${2:-100}; POOL=${3:-100}
php "$ROOT/artisan" migrate:fresh --force >/dev/null
php seed_pool.php "$POOL" | tail -1
rm -f res_*.json
BASE=$(php "$ROOT/artisan" tinker --execute='echo \App\Models\EMoney::sum("current_balance");' 2>/dev/null | tail -1)
echo "baseline=$BASE | launching $W workers x $OPS ops = $((W*OPS)) concurrent transfers"
S=$(date +%s.%N); for w in $(seq 1 "$W"); do php worker.php "$w" "$OPS" & done; wait; E=$(date +%s.%N)
php "$ROOT/artisan" tinker --execute='
$w=\App\Models\EMoney::sum("current_balance"); $f=\App\Models\PlatformFeeEntry::sum("amount");
$ok=collect(glob(__DIR__."/loadtests/concurrency/res_*.json"))->map(fn($p)=>json_decode(file_get_contents($p),true));
echo "ok=".$ok->sum("ok")." deadlock=".$ok->sum("deadlock")." conn_limit=".$ok->sum("conn")." err=".$ok->sum("other")."\n";
echo "money: wallets+fees=".($w+$f)." (محفوظ=".(abs(($w+$f)-(float)getenv("BASE"))<0.01?"نعم":"لا").")\n";
' 2>/dev/null | tail -2
echo "elapsed=$(echo "$E-$S"|bc)s"
