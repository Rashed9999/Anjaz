# دليل اختبارات الأداء — K6

**AMIAL-LOADTEST-001 (v1.0-E)**

## ما الذي نختبره؟

| الـ Script | الهدف | الوقت |
|---|---|---|
| `send_money.js` | DB locking، concurrent transactions | 16 دقيقة |
| `login_flood.js` | rate limiting، auth bottleneck | 2 دقيقة |
| `mixed_workload.js` | realistic workload | 30 دقيقة |

## التثبيت

```bash
# macOS
brew install k6

# Linux (Ubuntu/Debian)
sudo gpg -k
sudo gpg --no-default-keyring --keyring /usr/share/keyrings/k6-archive-keyring.gpg \
    --keyserver hkp://keyserver.ubuntu.com:80 --recv-keys C5AD17C747E3415A3642D57D77C6C491D6AC1D69
echo "deb [signed-by=/usr/share/keyrings/k6-archive-keyring.gpg] https://dl.k6.io/deb stable main" \
    | sudo tee /etc/apt/sources.list.d/k6.list
sudo apt-get update && sudo apt-get install k6
```

## تجهيز الاختبار

### 1. أنشئ بيانات اختبار في staging

```bash
php artisan tinker
>>> User::factory()->count(100)->create(['zone_code' => 'SOUTH']);
>>> User::all()->each(fn($u) => EMoney::create(['user_id' => $u->id, 'current_balance' => '10000.0000']));
```

### 2. احصل على tokens

```bash
# للاختبار، أنشئ tokens مباشرة من tinker
>>> $tokens = User::limit(20)->get()->map(fn($u) => $u->createToken('k6')->accessToken)->implode(',');
>>> echo $tokens;
```

### 3. شغل الـ test

```bash
export K6_BASE_URL="https://staging.amialpay.com"
export K6_TOKENS="token1,token2,token3,..."
export K6_RECEIVERS="21,22,23,24,25"   # user_ids

# سيناريو واحد
k6 run loadtests/send_money.js

# سيناريو مع stages مخصصة
k6 run --stage 1m:50,3m:500,1m:1000,2m:500,1m:0 loadtests/send_money.js

# مع تصدير النتائج
k6 run --out json=results.json loadtests/send_money.js
```

## قراءة النتائج

### معايير القبول (لـ pilot 2k DAU)

```
http_req_duration ............: avg=350ms  p(95)=850ms  p(99)=1.2s   ← OK
errors .......................: 0.05%  ← ممتاز
send_money_success ...........: 2,450
send_money_failed ............: 1
insufficient_balance .........: 120  ← متوقع (بعد تحويلات متعددة)
```

### إشارات الخطر 🚨

```
✗ http_req_duration p(95) > 1s
✗ errors > 1%
✗ status 500 errors
✗ deadlocks في logs
✗ queue depth > 10,000
```

## ما يجب مراقبته أثناء الـ test

افتح في tabs مختلفة:

**1. App logs:**
```bash
tail -f storage/logs/laravel.log | grep -i "deadlock\|timeout\|fatal"
```

**2. MySQL:**
```bash
mysql> SHOW PROCESSLIST;     # عدد active connections
mysql> SHOW ENGINE INNODB STATUS\G  # deadlocks
```

**3. Redis:**
```bash
redis-cli INFO stats | grep instantaneous_ops_per_sec
redis-cli INFO memory | grep used_memory_human
```

**4. Queue:**
```bash
php artisan queue:size
php artisan queue:monitor default:1000,receipts:5000
```

**5. System:**
```bash
htop                          # CPU + RAM
iostat -x 1                   # Disk I/O
netstat -an | grep :443 | wc -l   # active connections
```

## القرار

بعد اختبار:

| النتيجة | القرار |
|---|---|
| كل thresholds passed | ✓ جاهز لإطلاق pilot |
| 1-2 thresholds failed بهامش ضيق | ⚠️ optimize ثم أعد |
| فشل واسع | ❌ مشكلة بنيوية — راجع DB indexes، queue config، server resources |

## التوصية: ابدأ صغيراً، تدرج

1. **أسبوع 1:** k6 على staging بـ 50 VU
2. **أسبوع 2:** k6 على staging بـ 500 VU
3. **أسبوع 3:** k6 على staging بـ 1000 VU (مع real PDF generation)
4. **أسبوع 4:** pilot live بـ 500 user حقيقي
5. **شهر 2:** scale up تدريجياً

**لا تختبر على production أبداً** — استخدم staging مطابق.

---

## 🆕 السكربت المتدرّج الشامل (AMIAL-LOADTEST-002)

`staged_all_features.js` — اختبار واحد يتدرّج عبر **20 → 100 → 500 → 1000 →
4000 → 10000** مستخدم متزامن، وكل مستخدم يجرّب عملية من **كل مزايا أميال**
(تحويل، QR، طلب أموال، إيصالات، إشعارات، ملف شخصي، وقود، صيدلية، جملة، كاشير،
Safe-Pay، تقسيم فواتير، تبرّعات، فواتير، صناديق، اشتراكات) بأوزان واقعية.

### 1) جهّز 10000 مستخدم + توكنات

```bash
LOADTEST_COUNT=10000 php artisan db:seed --class=LoadTestSeeder
cp storage/app/tokens.json storage/app/merchants.json loadtests/
```
> الـ Seeder الآن يصدّر **توكن العميل وتوكن التاجر** (الأخير مطلوب لمزايا POS)،
> والعدد قابل للضبط عبر `LOADTEST_COUNT`.

### 2) شغّل

```bash
# كامل حتى 10000 (يتطلّب مولّد حمل قوي / k6 موزّع)
k6 run -e BASE_URL=https://staging.amialpay.com loadtests/staged_all_features.js

# على جهاز واحد: قُصّ السقف (مثلاً 2000)
k6 run -e BASE_URL=https://staging.amialpay.com -e PEAK=2000 loadtests/staged_all_features.js
```

### 3) النتائج
- مقياس نجاح + زمن استجابة **لكل ميزة** (`feat_<name>_ok`, `feat_<name>_ms`).
- `business_errors` للأخطاء غير المتوقّعة (5xx/فشل نقل).
- تقرير موجز في نهاية التشغيل + `loadtests/summary.json`.
- thresholds: تحويل/QR ≥97%، كاشير ≥95%، p(95) < 3s، أخطاء النقل < 2%.

> 💡 مزايا POS الكتابية (وقود/صيدلية/جملة) تُقاس افتراضياً عبر نقاط القراءة
> (dashboards) لتفادي الاعتماد على بيانات staging؛ فعّل الكتابة بملء حمولاتها.
> 10000 VU على جهاز واحد غير واقعي — استخدم `PEAK` أو وزّع الحمل.

### 4) عمليات الكتابة لمزايا POS + تقرير لكل مرحلة

**تفعيل كتابة POS** (وقود/صيدلية/جملة/كاشير) بحمولات مطابقة لقواعد التحقق
الفعلية في المتحكمات:
```bash
k6 run -e BASE_URL=... -e POS_WRITES=1 loadtests/staged_all_features.js
```
> تتطلّب أن يملك تجّار staging كياناتهم (محطة + مضخّات + منتجات + نوبة مفتوحة
> للوقود، منتجات للصيدلية/الكاشير، عميل + منتجات للجملة). 404/422 = كيان غير
> مُهيّأ (متوقّع) ويُحتسب "معالَجاً" لا فشلاً.

**تشغيل المراحل بالتتابع مع تقرير منفصل لكل مستوى:**
```bash
export BASE_URL=https://staging.amialpay.com
./loadtests/run_stages.sh                         # 20→10000، 2m لكل مرحلة
STAGE_DURATION=1m POS_WRITES=1 ./loadtests/run_stages.sh
LEVELS="20 100 500" ./loadtests/run_stages.sh     # مستويات مخصّصة
```
يحفظ `loadtests/reports/report_<level>.json` لكل مستوى + ملخّص `reports/_summary.csv`
(p95، نسبة فشل النقل، نسبة الـ checks) لمقارنة المراحل بسهولة.

**مرحلة مفردة يدوياً:**
```bash
k6 run -e BASE_URL=... -e STAGE=1000 -e STAGE_DURATION=3m loadtests/staged_all_features.js
```

### 5) تهيئة تلقائية لتجّار POS (نجاح كتابة POS بلا إعداد يدوي)

`LoadTestSeeder` يُهيّئ تلقائياً **مجموعة تجّار POS كاملة** (افتراضي 50، عبر
`LOADTEST_POS_POOL`) باستخدام نفس خدمات المتحكمات — لكل تاجر:
- ⛽ محطة + منتج وقود + مضخّة + **نوبة مفتوحة**
- 💊 صيدلية + منتج
- 📦 business جملة + منتج + عميل
- 🧾 منتج كاشير
- باقة **enterprise** (عمليات/منتجات غير محدودة) لتجاوز حدود الاستخدام.

ويُصدّر `storage/app/pos.json` بمعرّفات الكيانات الحقيقية. السكربت يقرأه تلقائياً:
```bash
LOADTEST_COUNT=10000 LOADTEST_POS_POOL=50 php artisan db:seed --class=LoadTestSeeder
cp storage/app/{tokens,merchants,pos}.json loadtests/
k6 run -e BASE_URL=https://staging.amialpay.com -e POS_WRITES=1 loadtests/staged_all_features.js
```
> مع `pos.json` تنجح كل عمليات كتابة POS بمعرّفات حقيقية. بدونه، يتخطّى السكربت
> كتابة POS بأمان ويكتفي بالقراءة.

### 6) منتجات/عملاء تجريبيون متعدّدون + دفعة لحظية (Burst)

**منتجات وعملاء أكثر لكل تاجر POS** (لتنويع حمولات الكتابة):
```bash
LOADTEST_POS_PRODUCTS=10 LOADTEST_POS_CUSTOMERS=10 \
  LOADTEST_COUNT=10000 php artisan db:seed --class=LoadTestSeeder
```
يُنشئ لكل تاجر POS عدّة منتجات (وقود/صيدلية/جملة/كاشير) وعدّة عملاء جملة،
ويصدّرها كمصفوفات في `pos.json`؛ يختار k6 منها عشوائياً في كل عملية.

**دفعة لحظية — 2000 عملية في نفس اللحظة** (اختبار تزامن/أقفال DB):
```bash
# 2000 عملية مختلطة (كل المزايا) تنطلق معاً
k6 run -e BASE_URL=... -e POS_WRITES=1 -e BURST=2000 loadtests/staged_all_features.js

# 2000 تحويل متزامن نقي (ضغط أقصى على أقفال المحفظة)
k6 run -e BASE_URL=... -e BURST=2000 -e BURST_OP=transfer loadtests/staged_all_features.js

# 2000 دفع QR متزامن
k6 run -e BASE_URL=... -e BURST=2000 -e BURST_OP=qr_pay loadtests/staged_all_features.js
```
> `BURST=n` يُشغّل n مستخدماً بتكرار واحد ينطلقون في نفس اللحظة. `BURST_OP`
> (اختياري) يوجّه الدفعة لعملية واحدة؛ بدونه تكون مختلطة عبر كل المزايا.
> راقب `SHOW ENGINE INNODB STATUS` و logs للـ deadlocks أثناء الدفعة.

### 7) معايير قبول وضع الدفعة (Burst thresholds)

في وضع `BURST` تُطبَّق عتبات **أصرم** تلقائياً (يفشل التشغيل إن لم تتحقّق):

| المعيار | العتبة | الدلالة |
|---|---|---|
| `server_errors` | **== 0** | لا أخطاء 5xx = **لا deadlocks/انهيار معاملة** |
| `http_req_failed` | < 1% | استقرار النقل تحت الذروة |
| `http_req_duration` | p(95) < 5s | زمن مقبول عند التزامن الأقصى |
| `business_errors` | < 1% من حجم الدفعة | أخطاء عمل غير متوقّعة محدودة |
| `feat_<op>_ok` | **> 99%** | نجاح العملية المستهدَفة (مع `BURST_OP`) |
| `checks` | > 98% | نجاح عام (دفعة مختلطة) |

> أُضيف عدّاد `server_errors` (يلتقط كل 5xx) كمؤشّر مباشر على الـ deadlocks —
> أيّ deadlock في المحفظة يظهر كـ 500 ويُفشل العتبة فوراً. راجع أيضاً
> `SHOW ENGINE INNODB STATUS` و logs للتأكيد.

مثال — قبول 2000 تحويل لحظي (صفر deadlocks، نجاح ≥99%):
```bash
k6 run -e BASE_URL=... -e BURST=2000 -e BURST_OP=transfer loadtests/staged_all_features.js
# يفشل التشغيل تلقائياً إذا ظهر أي 5xx أو نزل النجاح تحت 99%.
```
