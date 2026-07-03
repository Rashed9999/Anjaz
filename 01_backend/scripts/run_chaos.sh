#!/usr/bin/env bash
# AMIAL-CHAOS-001 — يقتل mysqld قتلاً قاسياً أثناء عاصفة تحويلات، ثم يتعافى ويتحقّق
# أنّ المال محفوظ والدفتر متوازن ولا يوجد نصف قيد.
set -uo pipefail
cd "$(dirname "$0")/.."
export DB_DATABASE=amial_conc   # نعيد استخدام القاعدة المعزولة

php artisan config:clear >/dev/null 2>&1
echo "→ تهيئة محفظتين مجموعهما 2,000,000 …"
php scripts/chaos.php seed

echo "→ إطلاق عاصفة 200000 تحويل في الخلفية …"
php scripts/chaos.php run 200000 &
RUN_PID=$!

# دع العاصفة تشتغل قليلاً ثم اقتل MySQL قتلاً قاسياً (kill -9 = انهيار حقيقي)
sleep 4
echo "💥 قتل mysqld قسراً (kill -9) وسط الضغط …"
pkill -9 -x mariadbd 2>/dev/null || pkill -9 -x mysqld 2>/dev/null || kill -9 $(pgrep -n mariadbd mysqld) 2>/dev/null
sleep 2

# ننتظر توقّف عملية العاصفة (ستموت عند فقد الاتصال)
wait "$RUN_PID" 2>/dev/null
echo "→ العاصفة توقّفت بفقد الاتصال (كما هو متوقّع)."

echo "→ إعادة تشغيل MySQL (تعافي InnoDB) …"
service mariadb start >/dev/null 2>&1 || mysqld_safe >/dev/null 2>&1 &
until mysqladmin ping 2>/dev/null | grep -q alive; do sleep 1; done
echo "→ MySQL عاد. التحقّق من السلامة بعد التعافي …"
echo
php scripts/chaos.php check
