# تعليمات النشر التدريجي — Amial Pay v0.6

> ⚠️ **لا تشغل هذه الـ migrations على production مباشرة.** اتبع التسلسل أدناه.

---

## 0. ما قبل أي شيء — Backup مزدوج

```bash
# 1) Backup DB كامل
mysqldump --single-transaction --routines --triggers \
  -u root -p cash6_db > /backups/cash6_pre_v0.6_$(date +%Y%m%d_%H%M%S).sql

# 2) Backup .env و storage
tar -czf /backups/cash6_files_pre_v0.6_$(date +%Y%m%d_%H%M%S).tar.gz \
  /var/www/cash6/.env \
  /var/www/cash6/storage \
  /var/www/cash6/public

# 3) تأكد الـ backup قابل للاسترجاع
mysql -u root -p < /backups/cash6_pre_v0.6_*.sql --execute="SHOW TABLES" > /dev/null
echo "Backup verified: $?"
```

---

## 1. تنظيف data inconsistencies قبل migrations

migration رقم 3 يضع `UNIQUE INDEX` على `transactions.transaction_id`.
لو وُجدت أي تكرارات في الـ legacy data (بسبب bug `Str::random(5).timestamp`)، الـ migration ستفشل.

### 1.1 فحص التكرارات

```sql
-- استعلام لمعرفة إن كانت توجد تكرارات
SELECT transaction_id, COUNT(*) AS cnt
FROM transactions
GROUP BY transaction_id
HAVING cnt > 1
LIMIT 20;
```

### 1.2 لو وجدت تكرارات

نُلحق suffix للسجلات الأقدم لئلا تكسر unique constraint:

```sql
-- نسخة احتياطية للسجلات المكررة
CREATE TABLE transactions_duplicates_backup AS
SELECT * FROM transactions
WHERE transaction_id IN (
  SELECT transaction_id FROM (
    SELECT transaction_id, COUNT(*) AS cnt
    FROM transactions
    GROUP BY transaction_id HAVING cnt > 1
  ) AS dup
);

-- إضافة suffix للأقدم في كل مجموعة (نحتفظ بالأحدث كما هو)
UPDATE transactions t
JOIN (
  SELECT id, transaction_id,
    ROW_NUMBER() OVER (PARTITION BY transaction_id ORDER BY id ASC) AS rn
  FROM transactions
) ranked ON t.id = ranked.id
SET t.transaction_id = CONCAT(t.transaction_id, '-LEGACY-', t.id)
WHERE ranked.rn > 1;

-- أعد الفحص — يجب أن يعيد 0 صفوف
SELECT transaction_id, COUNT(*) FROM transactions GROUP BY transaction_id HAVING COUNT(*) > 1;
```

---

## 2. اختبار التشغيل في staging (إلزامي)

```bash
cd /var/www/amial_pay_staging  # نسخة طبق الأصل من production

# 2.1 ضع نسخة العمل
git checkout amial-v0.6-batch-1

# 2.2 composer (لا packages جديدة، لكن للسلامة)
composer install --no-dev --optimize-autoloader

# 2.3 pretend migrate أولاً — يطبع SQL بدون تنفيذ
php artisan migrate --pretend > /tmp/migrate_pretend_v0.6.sql
cat /tmp/migrate_pretend_v0.6.sql | less
# تحقق أن SQL منطقي ولا توجد أوامر DROP غير متوقعة
```

---

## 3. التشغيل في staging

```bash
# 3.1 شغّل migrations فعلياً في staging
php artisan migrate

# 3.2 شغّل الاختبارات
php artisan test --filter=MoneyServiceTest
php artisan test --filter=ConcurrentSendMoneyTest
php artisan test --filter=IdempotencyTest
php artisan test --filter=PinSeparationTest
php artisan test --filter=EarnedChargeBugTest

# كل الاختبارات يجب أن تنجح. لو فشل واحد، تتوقف.
```

---

## 4. Load test في staging (إلزامي قبل production)

```bash
# باستخدام k6 (أو JMeter)
# script: tests/load/send_money_concurrent.js

k6 run --vus 100 --duration 5m tests/load/send_money_concurrent.js

# المعايير المقبولة:
# - 0% فشل بسبب race condition
# - 0% رصيد سالب في DB (تحقق بعد الـ test):
#   SELECT COUNT(*) FROM e_money WHERE current_balance < 0;  → 0
# - 0% transaction_id مكرر:
#   SELECT transaction_id, COUNT(*) FROM transactions GROUP BY transaction_id HAVING COUNT(*) > 1;  → empty
# - p99 latency < 500ms
```

---

## 5. النشر إلى production (نافذة maintenance)

> ⚠️ يفضل نافذة maintenance قصيرة (~10 دقائق).
> إذا كان النظام لا يحتمل توقفاً، نستخدم rolling deploy — لكن migration `ALTER TABLE` على DECIMAL قد يأخذ وقتاً على جدول `transactions` الكبير.

```bash
# 5.1 ضع التطبيق في maintenance
php artisan down --message="جاري التحديث" --retry=60

# 5.2 احصل على Lock على Queue (لمنع jobs قديمة تستخدم schema قديم)
php artisan queue:pause

# 5.3 backup ثانية الآن
mysqldump --single-transaction cash6_db > /backups/pre_deploy_$(date +%s).sql

# 5.4 deploy code
git checkout amial-v0.6-batch-1
composer install --no-dev --optimize-autoloader

# 5.5 migrate
php artisan migrate --force

# لو الـ migration على transactions أخذ وقتاً طويلاً، نشغل online schema change tool:
#   pt-online-schema-change --execute \
#     --alter "MODIFY debit DECIMAL(20,4) NOT NULL DEFAULT 0, MODIFY credit DECIMAL(20,4) NOT NULL DEFAULT 0" \
#     D=cash6_db,t=transactions

# 5.6 cache clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# 5.7 افحص: هل التطبيق يستجيب؟
curl -f http://localhost/api/v1/health || echo "FAIL"

# 5.8 أعد الـ Queue worker (لكن — لا تشغل jobs القديمة، امسحها)
php artisan queue:clear  # امسح payloads قديمة قد تكون لها schema قديم
php artisan queue:restart

# 5.9 سكاي up
php artisan up

# 5.10 راقب logs لمدة 15 دقيقة
tail -f storage/logs/laravel.log | grep -iE "error|exception|amial"
```

---

## 6. خطة Rollback (لو ساءت الأمور)

```bash
# 6.1 ضع التطبيق في maintenance
php artisan down

# 6.2 أعد كود v0.5 (cash6 الأصلي)
git checkout cash6-main

# 6.3 rollback migrations
php artisan migrate:rollback --step=6

# لو فشل rollback (احتمال على ALTER TABLE)، استرجع DB من backup:
mysql -u root -p cash6_db < /backups/pre_deploy_*.sql

# 6.4 clear cache
php artisan cache:clear

# 6.5 أعد التشغيل
php artisan up
```

⚠️ **مهم:** بعد deploy، أي transaction أُنشئ تحت schema الجديد لن يُقرأ بشكل صحيح بعد rollback. لذا rollback آمن **فقط في أول ساعة من النشر** قبل تراكم بيانات جديدة.

---

## 7. مراقبة ما بعد النشر

### المؤشرات (24 ساعة الأولى)

| مقياس | المتوقع | إنذار إذا |
|---|---|---|
| `audit_decisions` بـ `decision_code = TX_INSUFFICIENT_BALANCE` | ~1-2% من الـ requests | > 10% (المستخدمون لم يفهموا الـ flow الجديد) |
| `audit_decisions` بـ `severity = critical` | 0 | > 0 |
| محافظ بـ `current_balance < 0` | 0 | > 0 (kill switch فوراً) |
| Queue worker latency | < 30s | > 5 دقائق |
| Jobs في `failed_jobs` | < 0.1% | > 1% |
| Response time p99 | < 500ms | > 2s |

### استعلامات يومية

```sql
-- لا رصيد سالب
SELECT COUNT(*) FROM e_money WHERE current_balance < 0;
SELECT COUNT(*) FROM e_money WHERE pending_balance < 0;
SELECT COUNT(*) FROM e_money WHERE held_balance < 0;

-- لا transaction_id مكرر
SELECT transaction_id, COUNT(*) FROM transactions GROUP BY transaction_id HAVING COUNT(*) > 1;

-- معدلات الفشل
SELECT decision_code, COUNT(*)
FROM audit_decisions
WHERE created_at > NOW() - INTERVAL 1 DAY
GROUP BY decision_code
ORDER BY 2 DESC;

-- محاولات PIN فاشلة (هل توجد brute-force؟)
SELECT user_id, COUNT(*) AS attempts
FROM account_security_events
WHERE event_type = 'PIN_FAILED'
  AND created_at > NOW() - INTERVAL 1 HOUR
GROUP BY user_id
HAVING attempts > 10;
```

---

## 8. خطوات v0.7 (لا تبدأ قبل استقرار v0.6 لأسبوع)

1. تحديد منطقة كل user (zone_code في users table).
2. ZonePolicyService.
3. Legal Terms Acceptance flow.
4. Account Recovery flow.
5. Routes guard: `/install` و `/update` تُمنع في production عبر middleware.
6. PDF/Export عبر Queue (جزء من الإيصالات).
7. Flutter: شاشات السياسة + read-only banner خارج SOUTH.
