# Amial Pay — v0.6 + v0.7-A

> منهج: WORKFLOW-AMIAL-001 (دفعات من المصدر الأصلي)
> الدفعة الأولى: REFACTOR-CORE-AMIAL-001 (v0.6)
> الدفعة الثانية: ZONE-001 + LEGAL-001 + RECOVERY-001 (v0.7-A)
> التاريخ: 2026-05-15

---

## ابدأ من هنا

اقرأ بهذا الترتيب:

**v0.6:**
1. **`docs/AUDIT_v0.6.md`** — تقرير الفحص الكامل (12 نقطة حرجة).
2. **`docs/CHANGES_v0.6.md`** — جدول كل ملف وتصنيفه.
3. **`docs/MIGRATION_NOTES_v0.6.md`** — تعليمات النشر التدريجي.

**v0.7-A:**
4. **`docs/CHANGES_v0.7-A.md`** — Zone + Legal + Recovery
5. **`docs/MIGRATION_NOTES_v0.7-A.md`** — نشر v0.7-A فوق v0.6

⚠️ **مهم:** v0.7-A يفترض أن v0.6 منشورة. اختبار كل دفعة بـ staging قبل التالي.

---

## بنية المجلدات

```
amial_pay_working_copy/
│
├── docs/                                    📄 وثائق الدفعة (يجب قراءتها أولاً)
│   ├── AUDIT_v0.6.md                       تقرير الفحص الأمني والمالي
│   ├── CHANGES_v0.6.md                     جدول التصنيف لكل الملفات
│   └── MIGRATION_NOTES_v0.6.md             تعليمات النشر + rollback
│
├── app/
│   ├── CentralLogics/
│   │   ├── Helpers_PATCH.php               🔄 MERGE — 8 دوال معدّلة من Helpers.php
│   │   └── SmsModule.php                   🔄 REPLACE — بدون echo + PSR-3 logs
│   │
│   ├── Models/
│   │   ├── AccountSecurityEvent.php        ✨ ADD — أحداث أمان الحساب
│   │   ├── AuditDecision.php               ✨ ADD — سجل القرارات (append-only)
│   │   ├── EMoney.php                      🔄 REPLACE — decimal:4 + guards
│   │   ├── IdempotencyKey.php              ✨ ADD — مفاتيح idempotency
│   │   ├── Transaction.php                 🔄 REPLACE — decimal:4 + zone fields
│   │   └── User.php                        🔄 MERGE — PIN منفصل + tokens revocation
│   │
│   ├── Services/                           ✨ namespace جديد بالكامل
│   │   ├── AuditService.php                كتابة audit_decisions مع PII sanitization
│   │   ├── EnvironmentGuardService.php     حماية .env في production
│   │   ├── FinancialGuardService.php       فحص + خصم مركزي مع lockForUpdate
│   │   ├── FirebaseTokenService.php        cache لـ FCM access_token
│   │   ├── IdempotencyService.php          idempotent processing
│   │   ├── MoneyService.php                BC Math (بديل لـ float)
│   │   └── TransactionPinService.php       PIN منفصل + counter + lock
│   │
│   ├── Exceptions/                         ✨ جديدة
│   │   ├── DuplicateTransactionException.php
│   │   ├── EnvironmentMutationBlockedException.php
│   │   └── InsufficientBalanceException.php
│   │
│   ├── Http/Middleware/
│   │   └── EnforceIdempotency.php          ✨ middleware للـ endpoints المالية
│   │
│   ├── Jobs/
│   │   └── SendTransactionNotificationJob.php  ✨ إشعارات async
│   │
│   └── Traits/
│       └── TransactionTrait.php            🔄 REPLACE — قلب القلب المالي
│
├── database/
│   └── migrations/                         ✨ 6 migrations
│       ├── 2026_05_15_100001_amial_create_idempotency_keys_table.php
│       ├── 2026_05_15_100002_amial_add_transaction_pin_to_users.php
│       ├── 2026_05_15_100003_amial_refactor_transactions_table.php
│       ├── 2026_05_15_100004_amial_refactor_e_money_table.php
│       ├── 2026_05_15_100005_amial_create_audit_decisions_table.php
│       └── 2026_05_15_100006_amial_create_account_security_events_table.php
│
└── tests/                                  ✨ 36 اختباراً
    ├── Unit/
    │   └── MoneyServiceTest.php            12 اختبار — float precision
    └── Feature/
        ├── ConcurrentSendMoneyTest.php     4 اختبار — locks + negative balance
        ├── IdempotencyTest.php             9 اختبار — duplicate prevention
        ├── PinSeparationTest.php           11 اختبار — PIN ≠ password
        └── EarnedChargeBugTest.php         4 اختبار — bug إصلاح
```

---

## كيف تستخدم هذه الدفعة

### 1. ضع الملفات في نسخة العمل (ليس على الأصلي!)

```bash
# نسخة الأصلي محفوظة كما هي
cp -r cash6/ cash6_original_backup/

# نسخة العمل
cp -r cash6/ amial_pay_working_copy/

# انسخ ملفات الدفعة فوق نسخة العمل
cp -r <هذا_الـ_zip>/app/* amial_pay_working_copy/app/
cp -r <هذا_الـ_zip>/database/migrations/* amial_pay_working_copy/database/migrations/
cp -r <هذا_الـ_zip>/tests/* amial_pay_working_copy/tests/
cp -r <هذا_الـ_zip>/docs/* amial_pay_working_copy/docs/
```

### 2. ادمج Helpers يدوياً

`Helpers_PATCH.php` يحتوي 8 دوال معدّلة. **لا تستبدل Helpers.php كاملاً** —
افتح الأصلي واستبدل كل دالة بالنسخة المقابلة في الـ patch (نفس الـ signature).

### 3. أضف middleware في Kernel

في `app/Http/Kernel.php`:
```php
protected $routeMiddleware = [
    // ...
    'amial.idempotency' => \App\Http\Middleware\EnforceIdempotency::class,
];
```

### 4. اربط middleware بـ routes المالية

في `routes/api/customer.php`:
```php
Route::middleware(['auth:api', 'amial.idempotency'])->group(function () {
    Route::post('/send-money', ...);
    Route::post('/cash-out', ...);
    Route::post('/request-money', ...);
});
```

### 5. شغّل الاختبارات

```bash
php artisan test --filter='MoneyServiceTest|ConcurrentSendMoneyTest|IdempotencyTest|PinSeparationTest|EarnedChargeBugTest'
```

### 6. اقرأ MIGRATION_NOTES قبل النشر

تحديداً قسم 1 (تنظيف data inconsistencies) و قسم 4 (load test).

---

## ما حُلّ في v0.6

| # | المشكلة | الحل |
|---|---|---|
| 1 | غياب lockForUpdate → رصيد سالب تحت race | FinancialGuardService::lockWallet |
| 2 | send_money بدون فحص رصيد | FinancialGuardService::debit يرمي exception |
| 3 | Str::random(5)+timestamp → collisions | ULID + UNIQUE INDEX |
| 4 | FLOAT للأموال → أخطاء تقريب | DECIMAL(20,4) + BC Math MoneyService |
| 5 | PIN = password | TransactionPinService + transaction_pin منفصل |
| 6 | لا idempotency | IdempotencyService + middleware |
| 7 | earned_charge للمستخدم العادي | محصور في creditAdminCharge |
| 8 | setEnvironmentValue في production | EnvironmentGuardService يرمي exception |
| 9 | إشعارات داخل DB::transaction | SendTransactionNotificationJob + DB::afterCommit |
| 10 | getAccessToken بدون cache | FirebaseTokenService مع Cache::remember |
| 11 | translate() يكتب على disk | Cache في memory فقط |
| 12 | SmsModule يطبع echo | PSR-3 logging + phone masking |

---

## ما **لم** يُحلّ في v0.6 (مؤجل للدفعات القادمة)

| البند | الدفعة |
|---|---|
| Zone Policy (رفض خارج SOUTH) | v0.7 |
| Legal terms acceptance | v0.7 |
| Account recovery flow | v0.7 |
| Merchant verification | v0.8 (التاجر لم يُشترَ بعد) |
| POS users + refund | v0.8 |
| Safe Payment | v0.8 |
| Family Fund | v0.9 |
| Bill Pay | v0.9 |
| Flutter UI changes + APK build | خارج بيئة المهندس البرمجي |
| تغيير branding (Cash6 → Amial Pay) | يحتاج Flutter + معالجة assets |

---

## نقطة مهمة عن الـ APK

أنا **لا أستطيع بناء APK** من هذه البيئة لأن:

1. لا يوجد Flutter SDK مُثبت (يحتاج ~2.5 GB).
2. لا يوجد Android SDK + Build Tools + Platform Tools (يحتاج ~5 GB).
3. الشبكة معطّلة — لا يمكن تحميل dependencies من pub.dev.
4. لا يوجد توقيع keystore.

**الخطوات الصحيحة لبناء APK:**

```bash
# على جهاز فيه Flutter + Android SDK:
cd <user_app_directory>
flutter clean
flutter pub get
flutter build apk --release \
  --build-name=0.6.0 \
  --build-number=60 \
  --target-platform android-arm,android-arm64,android-x64

# الملف ينتج في: build/app/outputs/flutter-apk/app-release.apk
```

تعديلات v0.6 على الـ backend **لا تتطلب** تعديل user_app الآن — الـ APIs متوافقة للخلف.
الـ Flutter changes (branding, terms screen, زر إنشاء PIN منفصل) تأتي مع v0.7.

---

## التواصل والمتابعة

عند جاهزية staging لاختبار هذه الدفعة، أرسل لي:

1. تأكيد أن الـ backup تم.
2. تأكيد أن staging يطابق production.
3. نتائج `mysqldump --check-only` على transactions table.
4. نتائج `php artisan migrate --pretend`.

بعدها نتفق على نافذة النشر وننتقل لـ v0.7 (Zone + Legal + Recovery).
