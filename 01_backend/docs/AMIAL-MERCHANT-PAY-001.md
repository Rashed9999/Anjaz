# AMIAL-MERCHANT-PAY-001 — دفع التاجر (QR / POS)

## الفكرة
مسار دفع مستقل: عميل → تاجر. العميل يدفع المبلغ كاملاً، **التاجر يتحمّل الرسم**
(bearer=merchant)، المنصّة تكسب الرسم. الرسم من محرّك الرسوم (`MERCHANT_QR` / `MERCHANT_POS`).
يُنسب لموظف POS عبر `pos_user_id` مع بقاء المال لحساب التاجر الرئيسي (الوثيقة §11).

## Backend
| النوع | المسار | الوصف |
|---|---|---|
| MERGE | `app/Traits/TransactionTrait.php` | `merchant_payment_transaction()` |
| MERGE | `app/Models/Transaction.php` | `pos_user_id` في fillable |
| ADD | `database/migrations/2026_05_23_150001_amial_add_pos_user_to_transactions.php` | عمود pos_user_id |
| ADD | `app/Http/Controllers/Api/V1/Amial/MerchantPaymentController.php` | quote + pay |
| MERGE | `routes/api/amial.php` | `merchant/quote` + `merchant/pay` |
| ADD | `tests/Feature/MerchantPaymentTest.php` | 3 اختبارات |

### حركة المال
- العميل يُخصم `amount`.
- التاجر يُضاف `amount − fee`.
- الأدمن `charge_earned += fee`.
- قيد ledger (`ledgerTransferWithFee`) + إيصال (`qr_payment`/`pos_payment`) + snapshot النسخة.

### API
```
POST /api/v1/amial/merchant/quote  { amount, channel }
  → meta: { amount, fee, merchant_receives, channel }

POST /api/v1/amial/merchant/pay    { merchant_phone|merchant_user_id, amount, channel, pos_user_id?, note? }
  middleware: amial.zone:merchant_payment, amial.idempotency, amial.rate-limit
  → meta: { transaction_id, amount, fee, merchant_receives, merchant{name,verified} }
```

## تطبيق المستخدم (Flutter v2.10)
| النوع | المسار |
|---|---|
| ADD | `lib/features/merchant/domain/repositories/merchant_pay_repo.dart` |
| ADD | `lib/features/merchant/controllers/merchant_pay_controller.dart` |
| ADD | `lib/features/merchant/screens/merchant_pay_screen.dart` |
| MERGE | `lib/helper/get_di.dart` (تسجيل repo + controller) |

- `MerchantPayScreen`: إدخال رقم التاجر + المبلغ، معاينة حيّة (التاجر يستلم كم)، زر دفع، حوار نجاح بالمرجع.
- idempotency: مفتاح لكل عملية عبر `prepareNewPayment()`.

## ما يتبقّى عليك في التطبيق
- **زر فتح الشاشة**: أضف زر/أيقونة "دفع تاجر" في الشاشة الرئيسية أو شاشة QR
  ينتقل لـ `MerchantPayScreen` (مع تمرير `prefillMerchantPhone`/`merchantUserId` من مسح QR إن وُجد).
- ربط ماسح QR الفعلي (إن أردت) ليملأ بيانات التاجر.

## التحقق (صدق)
- ✅ فحص بنيوي ناجح (PHP + Dart).
- ⚠️ **لم تُشغَّل اختبارات/تحليل** — لا PHP ولا Flutter في البيئة. شغّل عندك:
  `php artisan test --filter=MerchantPayment` و `flutter analyze`.
