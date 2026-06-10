# دليل الاختبارات — أميال باي

## هرم الاختبارات — التغطية الفعلية

كل طبقات هرم الاختبارات مُغطّاة فعلياً (من القاعدة إلى القمّة):

| طبقة الهرم | الموقع | العدد | الأداة |
|---|---|---|---|
| **Unit** (وحدة) | `01_backend/tests/Unit/` | 48 اختبار (MoneyService، **FeeService**، **ZonePolicy**…) | PHPUnit |
| **Component/Integration** | `01_backend/tests/Feature/` | ~590 اختبار (خدمات + قاعدة بيانات + Ledger) | PHPUnit |
| **API** | `01_backend/tests/Feature/` (HTTP) | ~15 ملف يضرب نقاط HTTP فعلية | PHPUnit |
| **UI (Widget)** | `02_flutter_app/test/` | 10 اختبارات (لغات + توجيه + **كل شاشة Home + اللوحات**) | flutter_test |
| **E2E تدفّق** | `02_flutter_app/test/customer_flow_test.dart` | **دخول → تحويل → إيصال** (backend مزيّف) | flutter_test |
| **E2E جهاز** | `02_flutter_app/integration_test/` | إقلاع كامل على جهاز Linux desktop (أخضر) | integration_test |
| **حمل/تزامن** | `01_backend/loadtests/` | k6 + harness تزامن (تحويلات/كاشير) | k6 + PHP |

> **الإجمالي:** ~615 اختبار خلفي + 10 اختبارات Flutter (UI + تدفّق E2E) + إقلاع E2E على جهاز.

### قناة OTP/إشعارات عبر WhatsApp (AMIAL-WHATSAPP-OTP-001)
- `WhatsappModule` (5 مزوّدين، OTP + نصّ حرّ) + `OtpDispatcher` (واتساب أولاً → SMS)
  + جعل `SmsModule::send` تفويضاً شفّافاً (كل المتصلين يكتسبون واتساب بلا تغيير).
- **إدارة من لوحة الأدمن:** `WhatsappAdminController` (API محمي: config/provider/channel/test)
  + شاشة Flutter `whatsapp_settings_screen.dart` + تقنيع الأسرار.
- **إشعارات واتساب:** `NotificationService` يرسل نسخة واتساب اختيارية (لا تكسر الإشعار).
- اختبارات: `WhatsappOtpTest.php` (8 — Http مُزيّف: OTP/fallback/تفضيل/قالب/نصّ حرّ)
  + `WhatsappAdminTest.php` (9 — Passport: صلاحية/حفظ/تقنيع/قناة/تجريبي/إشعارات).
  التوافق الخلفي مؤكّد (24 اختبار auth) + شاشة Flutter ضمن `screens_widget_test`.

### آخر إضافات (إغلاق فجوات الهرم)
- **توسيع طبقة الوحدة:** `FeeServiceTest` (15) + `ZonePolicyUnitTest` (15) +
  `MoneyServiceOperatorsTest` (6) — منطق مالي نقي بلا قاعدة بيانات.
- **اختبار UI لكل شاشة:** `screens_widget_test.dart` — شاشات Home (بيع سريع/تجزئة/وكيل)
  تُبنى وأزرارها فعّالة (onTap != null)، واللوحات تُبنى دون انهيار.
- **تدفّق E2E كامل:** `customer_flow_test.dart` — دخول العميل ثم تحويل ثم جلب الإيصال،
  مع التحقّق من تسلسل نداءات الـ API الفعلي.

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
