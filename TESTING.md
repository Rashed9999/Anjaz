# دليل الاختبارات — أميال باي

## نظرة عامة

| الطبقة | الموقع | العدد | الأداة |
|---|---|---|---|
| Backend Unit/Feature | `01_backend/tests/` | 66 ملف موجود + جديد | PHPUnit |
| Backend E2E | `01_backend/tests/Feature/E2E/` | 2 (جديد) | PHPUnit |
| Flutter Widget | `02_flutter_app/test/` | 1 (أُصلح) | flutter_test |
| Flutter E2E (Integration) | `02_flutter_app/integration_test/` | 1 (جديد) | integration_test |

---

## ما أُضيف/أُصلح في هذه النسخة

> سكافولد الاختبار كان ناقصاً ممّا منع تشغيل **كامل** الطاقم. أُصلح كالتالي:

- `tests/TestCase.php` + `tests/CreatesApplication.php` — **كانا مفقودين**؛ كل
  الاختبارات ترث `Tests\TestCase` فكانت كلها تفشل بدونهما.
- `database/factories/UserFactory.php` + `EMoneyFactory.php` — **كانا مفقودين**
  رغم اعتماد عشرات الاختبارات على `User::factory()`.
- `tests/Feature/E2E/CustomerMoneyLifecycleE2ETest.php` — E2E للقلب المالي
  (تحويل أموال كامل: أرصدة + رسوم الأدمن + رفض عدم الكفاية + سياسة Zone).
- `tests/Feature/E2E/PlatformHttpStackE2ETest.php` — E2E لطبقة HTTP كاملة
  (Health + Unified Auth + حارس المصادقة Passport).
- `02_flutter_app/test/widget_test.dart` — **أُصلح** (كان قالب عدّاد افتراضي
  لا يطابق التطبيق وسيفشل) → أصبح smoke لإقلاع التطبيق.
- `02_flutter_app/integration_test/app_test.dart` — E2E لإقلاع التطبيق إلى أول
  شاشة على جهاز/محاكي.
- `pubspec.yaml` — أُضيفت `integration_test` إلى dev_dependencies.

---

## تشغيل اختبارات الـ Backend

> ⚠️ تتطلّب أولاً دمج الحزمة فوق قاعدة Cash6 و`composer install` (انظر `FIXES.md`)،
> لأن `vendor/` وملفات القاعدة غير مُرفقة.

```bash
cd 01_backend
composer install

# جهّز قاعدة بيانات اختبار (MySQL) ثم في .env.testing أو phpunit.xml:
#   DB_DATABASE=amial_pay_test
php artisan migrate --env=testing

# كل الاختبارات
php artisan test

# فقط اختبارات E2E
php artisan test --filter=E2E
php artisan test tests/Feature/E2E

# اختبار واحد
php artisan test --filter=CustomerMoneyLifecycleE2ETest
```

بديل بـ SQLite في الذاكرة (أسرع، لكن قد لا تدعم بعض الهجرات ميزات MySQL):
فعّل في `phpunit.xml` السطرين المُعلّقين:
```xml
<server name="DB_CONNECTION" value="sqlite"/>
<server name="DB_DATABASE" value=":memory:"/>
```

## تشغيل اختبارات Flutter

```bash
cd 02_flutter_app
flutter pub get

# اختبار الـ widget (smoke)
flutter test

# اختبار E2E (يتطلّب جهازاً/محاكياً متصلاً)
flutter test integration_test/app_test.dart \
  --dart-define=BASE_URL=https://api.your-domain.com
```

---

## ملاحظات

- اختبارات الـ E2E المالية تعمل على مستوى `TransactionTrait` (لا HTTP) لأن
  متحكمات العميل المالية تأتي من قاعدة Cash6؛ أمّا متحكمات أميال
  (`App\Http\Controllers\Api\V1\Amial\*`) فموجودة وتُختبر عبر HTTP.
- `UserFactory` أُعيد بناؤه من النموذج والهجرات؛ إن أضافت القاعدة الأصلية أعمدة
  NOT NULL إضافية بلا قيمة افتراضية، أضِفها في الـ factory.
