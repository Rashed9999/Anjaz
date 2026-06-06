# AMIAL-SPLIT-BILL-001 — تقسيم الفاتورة (الوثيقة §15)

## الفكرة
التاجر/POS ينشئ فاتورة بمبلغ + مشاركين (عملاء مسجّلين في v1). النظام يقسّم
المبلغ تلقائياً ويمتص فرق التقريب. كل مشارك يدفع حصته عبر **مسار دفع التاجر**
(الرسم من المحرّك، كل دفعة لحساب التاجر الرئيسي). كل دفعة تُسجَّل بـ
`split_bill_id` و`split_participant_id` و`pos_user_id`.

## القيود (الوثيقة)
- لا تكرار رقم داخل نفس الفاتورة (unique index + فحص).
- مشاركان على الأقل.
- v1: عملاء مسجّلون فقط.
- لا تعديل بعد الإنشاء؛ الحالة تتدرّج: open → partially_paid → completed.

## الملفات
| النوع | المسار |
|---|---|
| ADD | `database/migrations/2026_05_23_160001_amial_create_split_bills.php` |
| ADD | `app/Models/SplitBill.php`, `app/Models/SplitBillParticipant.php` |
| ADD | `app/Services/SplitBillService.php` |
| ADD | `app/Http/Controllers/Api/V1/Amial/SplitBillController.php` |
| MERGE | `app/Traits/TransactionTrait.php` (split metadata في merchant_payment) |
| MERGE | `app/Models/Transaction.php` (split_bill_id, split_participant_id) |
| MERGE | `routes/api/amial.php` |
| ADD | `tests/Feature/SplitBillTest.php` (5 اختبارات) |

## API
```
POST /api/v1/amial/merchant/split-bills          { total_amount, participants[], channel?, note? }
GET  /api/v1/amial/merchant/split-bills/{ulid}
GET  /api/v1/amial/split-bills/mine              (حصص العميل المعلّقة)
POST /api/v1/amial/split-bills/participants/{id}/pay   (zone:split_bill)
```

## التقسيم والتقريب
`MoneyService::distribute(total, count)` يضمن أن مجموع الحصص = المبلغ بالضبط
(آخر حصة تمتص الفرق). الاختبار يؤكّد: 100/3 → مجموع = 100.0000.

## ملاحظة عن الاسترجاع والصندوق العائلي (لم تُربط بقصد)
- **الاسترجاع**: بلا رسم — فرض رسم على ردّ المال ممارسة سيئة، والوثيقة §12 لا تذكره.
- **مساهمة الصندوق العائلي**: بلا رسم — وضع المال في صندوقك لا يجب أن يُرسّم.
المحرّك يحتفظ بهما كـ 0 placeholders لو احتيج لاحقاً.

## ما يتبقّى للتطبيق
شاشتان: إنشاء التقسيم (التاجر/POS) + دفع الحصة (العميل، من `split-bills/mine`).

## التحقق (صدق)
- ✅ فحص بنيوي ناجح لكل الملفات.
- ⚠️ **لم تُشغَّل الاختبارات** (لا PHP). شغّل: `php artisan test --filter=SplitBill`.
- إجمالي اختبارات سلسلة الرسوم/الدفع/التقسيم: **38**.

---

# شاشات التطبيق (Flutter v2.11)

| النوع | المسار |
|---|---|
| ADD | `lib/features/merchant/domain/repositories/split_bill_repo.dart` |
| ADD | `lib/features/merchant/controllers/split_bill_controller.dart` |
| ADD | `lib/features/merchant/screens/split_bill_create_screen.dart` (التاجر/POS ينشئ) |
| ADD | `lib/features/merchant/screens/split_bill_my_shares_screen.dart` (العميل يدفع حصته) |
| MERGE | `lib/helper/get_di.dart` |

- **إنشاء**: إدخال الإجمالي + قائمة مشاركين ديناميكية (إضافة/حذف، مشاركان على الأقل،
  منع التكرار)، ثم حوار يعرض حصّة كل مشارك.
- **حصصي**: قائمة الحصص المعلّقة (`split-bills/mine`) مع سحب-للتحديث، تأكيد قبل الدفع،
  وإزالة الحصة بعد الدفع. idempotency لكل حصة.

## يتبقّى عليك
أزرار فتح الشاشتين: "تقسيم فاتورة" في واجهة التاجر/POS → `SplitBillCreateScreen`،
و"حصصي في الفواتير" في واجهة العميل → `SplitBillMySharesScreen`.

## التحقق (صدق)
- ✅ فحص بنيوي ناجح لملفات Dart. ⚠️ لم يُشغَّل `flutter analyze` (لا Flutter في البيئة).
