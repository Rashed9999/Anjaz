# AMIAL-FIX-GATEWAYS — حذف بوّابات الدفع العالمية

## القرار
حذف بوّابات الدفع الموروثة من قالب Cash6 (Stripe, PayPal, Razorpay,
Bkash, SslCommerz, Paystack, Flutterwave, Paymob, PhonePe, MercadoPago,
CashFree, Instamojo, SenangPay). نموذج أميال باي يعتمد على **الوكلاء**
(شركات الصرافة اليمنية) كقناة الشحن الوحيدة — هذه البوّابات لم تكن
تعمل أصلاً في اليمن، وكانت سطح هجوم وتعقيداً بلا فائدة فعلية.

## ⚠️ تحقّق إلزامي أُجري قبل الحذف (وتصحيح ادّعاء خاطئ)

وردني ادّعاء بأنّ `app/Lib/Payment.php` و`app/Traits/Payment.php` أصبحا
"طبقة أساسية" يعتمد عليها `SafePaymentService`، `CashierService`،
`FuelStationService`، `TransactionController` وغيرها. **هذا الادّعاء
غير صحيح** — تحقّقتُ منه بـ `grep` مباشر على كل خدمة مذكورة بالاسم،
ونتيجة كل فحص كانت **صفر تطابق**. سبب الالتباس المرجَّح: ظهور كلمة
`"payment"` كنصّ عادي (`payment_method` كحقل بيانات، `safe_payment`
كاسم config) في هذه الملفّات، وهو مختلف تماماً عن استخدام برمجي فعلي
(`use App\Traits\Payment;`). تأكَّد أنّ المستخدم الوحيد الفعلي لهذه
الطبقة بالكامل كان `PaymentController.php` نفسه — وهو محذوف.

**الدرس المنهجي**: لا أصدّق ادّعاءً (حتى لو مفصَّلاً ومقنعاً ظاهرياً)
يناقض فحصاً مباشراً سابقاً، دون إعادة التحقّق بنفس الصرامة. هذا امتداد
لقاعدة "لا ادّعاء نجاح بلا تحقّق فعلي" التي اعتمدناها منذ USE-001.

## ما حُذف (Backend)

| الملف/المجلّد | السبب |
|---|---|
| `app/Http/Controllers/Gateway/` (13 controller) | بوّابات لا تعمل في اليمن |
| `app/Library/SslCommerz/` | مكتبة بوّابة محذوفة |
| `app/Http/Controllers/PaymentController.php` | المستخدم الوحيد لـ `Traits\Payment` |
| `app/Http/Controllers/Payment/PaymentOrderController.php` | تدفّق OTP/PIN خاصّ ببوّابات خارجية |
| `app/Lib/{Payment,Payer,Receiver,PaymentSuccess,PaymentResponse,Transaction,Constant}.php` | معزولة تماماً، يتيمة بعد حذف PaymentController |
| `app/Traits/Payment.php` | يتيم — لا مستخدم فعلي إلا PaymentController المحذوف |
| `app/Models/Fund.php` + 3 دوال يتيمة في `Helpers.php` (`fund_update`, `fund_add`, `add_fund`) | لا مستدعٍ لها في كامل المشروع بعد حذف البوّابات |
| 3 حزم composer (`stripe/stripe-php`, `razorpay/razorpay`, `devrabiul/laravel-paystack`) | SDKs بوّابات محذوفة |
| ~95 سطر routes (`routes/web.php`) | كل routes البوّابات + PaymentController/PaymentOrderController |
| 9 مفاتيح `.env.example` | مفاتيح API لبوّابات محذوفة |

## ما حُذف (Flutter)

| الملف/المجلّد | السبب |
|---|---|
| `lib/features/add_money/` (4 ملفّات) | يعتمد على endpoint `/api/v1/customer/add-money` الذي خدمه `PaymentController` المحذوف |

## ما لم يُحذف عمداً (قرار هندسي دقيق، لا إهمال)

| العنصر | لماذا بقي |
|---|---|
| `WebScreen` (نُقل إلى `lib/common/screens/`) | متصفّح ويب **عام** يُستخدَم أيضاً للبانرات والروابط المرتبطة — لا علاقة جوهرية له بالدفع. حذفه كان سيكسر ميزتين غير متعلّقتين |
| `TransactionType.addMoney` (enum) | تصنيف نوع معاملة قد يأتي تاريخياً أو من أيّ مصدر شحن (وليس بوّابة تحديداً) |
| `'add_money'` كنصّ في `receipt_models.dart`, `notification_helper.dart`, `agent_models.dart`, إلخ | تصنيف عرض/إشعار لمعاملات شحن — **بما فيها شحن عبر الوكيل نفسه**، لا حصراً عبر بوّابة |
| `migration: 2021_12_05_051247_create_funds_table.php` | migration تاريخية — حذفها قد يكسر ترتيب migrations على بيئات نُفِّذت عليها فعلاً |
| `addon_published_status()` helper function | دالّة عامّة مستخدَمة في Auth/OTP، لا علاقة مباشرة بالبوّابات رغم استخدامها سابقاً كشرط حول كتلة الـ Gateway routes |

## آلية إخفاء زرّ "إضافة رصيد" من واجهة Flutter

اكتُشف أنّ كل أزرار "إضافة رصيد" الثلاثة (في الـ themes الثلاثة
المختلفة) محميّة فعلاً بـ flag واحد من السيرفر:
`splashController.configModel!.systemFeature!.addMoneyStatus!`

بدل حذف الكود من Flutter (3 أماكن، عبر themes متعدّدة)، غُيِّرت القيمة
الافتراضية لهذا الـ flag في `ConfigController.php` من `?? 1` إلى `?? 0`.
هذا **يخفي الزرّ تلقائياً من كل الـ themes الثلاثة بتعديل سطر واحد**،
مع الحفاظ على إمكانية تفعيله لاحقاً من لوحة الإدارة (`business_settings`
جدول، عمود `add_money_status`) لو احتاج المشروع مستقبلاً ربط بوّابة
دفع محلّية يمنية.

كما أُزيل كل أثر برمجي لـ `AddMoneyController`/`DigitalPaymentWidget`
من `transaction_balance_input_screen.dart` (الشاشة المشتركة بين عدّة
أنواع معاملات) بدقّة جراحية — 7 تعديلات منفصلة — مع التحقّق الصريح أنّ
منطق `withdrawRequest`/`sendMoney`/`cashOut` المجاور لم يُمسّ إطلاقاً.

## مطلوب منك بعد الدمج

```bash
composer update    # لإزالة حزم stripe/razorpay/paystack من vendor/
flutter pub get    # تحديث بعد حذف add_money/

# اختياري: تأكّد من القيمة الفعلية في business_settings إن كانت موجودة بالفعل
php artisan tinker
>>> DB::table('business_settings')->where('key', 'add_money_status')->first();
# لو موجودة بقيمة 1 من قبل، حدِّثها يدوياً إلى 0 لإخفاء الزرّ فعلياً:
>>> DB::table('business_settings')->updateOrInsert(['key'=>'add_money_status'], ['value'=>'0']);
```
