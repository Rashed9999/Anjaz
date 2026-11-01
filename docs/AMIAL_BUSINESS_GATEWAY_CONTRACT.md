# عقد قناة «أميال أعمال» ↔ أميال باي — الإصدار 1 (آخر إضافة: v1.6)

> AMIAL-BUSINESS-GATEWAY-001. هذا العقد هو المرجع الوحيد للطرفين. أي تغيير
> فيه يُضاف بإصدار جديد ولا يكسر ما قبله.

## 1. من يملك ماذا

| النظام | مصدر الحقيقة لـ | ممنوع عليه |
|---|---|---|
| **موثّق** | الهوية ووثائق التحقق وقرار فتح الحساب | تحريك أي مال |
| **أميال باي** | المحافظ والأرصدة والقيود والرسوم والحدود وAML والسحب | تشغيل المنشأة (المخزون والفواتير) بعد الترحيل |
| **أميال أعمال** | المنتجات والمخزون والفواتير والمشتريات ودفاتر التاجر | **تعديل أي رصيد.** يطلب، وأميال باي ينفّذ |

**الثابت الأول:** لا نقطة في هذه القناة تحرّك مالاً. نيّة الدفع تنشئ طلب QR
في `payment_requests`، والعميل يدفعه من تطبيق أميال باي عبر المسار المالي
القائم (`PaymentRequestService::settle`) بكل حراساته: KYC والحدود وAML
والقفل والقيد المزدوج والتدقيق.

## 2. الربط (Business Link)

- لكل تاجر رابط واحد (`link_id` بصيغة ULID). أميال أعمال تحفظه مع المنشأة.
- رقم المستخدم الداخلي في أميال باي **لا يغادره**.
- الإنشاء حالياً يدوي للتجار الحاليين:
  ```bash
  php artisan amial:business link {merchant_user_id}
  ```
  ويصدر حدث `business_link.created` فتنشئ أميال أعمال المنشأة.
- حالات الرابط: `active` · `suspended` · `revoked`. غير النشط يُرفض بـ `BUSINESS_LINK_INACTIVE`.

## 3. أمان النقل

### 3.1 الطلبات الواردة (أميال أعمال → أميال باي)

كل طلب إلى `https://<amial-pay>/api/v1/business/*` يحمل:

| الترويسة | القيمة |
|---|---|
| `X-Amial-Business-Timestamp` | ثواني Unix. النافذة ±300 ثانية |
| `X-Amial-Business-Nonce` | 16–64 محرفاً `[A-Za-z0-9_-]`، لا يتكرر أبداً |
| `X-Amial-Business-Link` | `link_id` |
| `X-Amial-Business-Signature` | hex لـ HMAC-SHA256 |

النص الموقّع (أسطر مفصولة بـ `\n`):

```
{timestamp}
{nonce}
{METHOD}
{path}            مثال: /api/v1/business/payment-intents
{query}           فارغ إن لم يوجد
{sha256_hex(body)}  جسم فارغ = sha256 لنص فارغ
```

السر: `AMIAL_BUSINESS_INBOUND_SECRET`. إن غاب تُغلق القناة (503).

الرفض: `BUSINESS_SIGNATURE_EXPIRED` · `BUSINESS_NONCE_INVALID` ·
`BUSINESS_SIGNATURE_INVALID` · `BUSINESS_REQUEST_REPLAYED` (409) ·
`BUSINESS_LINK_NOT_FOUND` (404) · `BUSINESS_GATEWAY_NOT_CONFIGURED` (503).

### 3.2 الأحداث الصادرة (أميال باي → أميال أعمال)

`POST {AMIAL_BUSINESS_WEBHOOK_URL}` بجسم JSON وترويسات:

| الترويسة | القيمة |
|---|---|
| `X-Amial-Pay-Event-Id` | معرّف الحدث (ULID) |
| `X-Amial-Pay-Event-Type` | نوع الحدث |
| `X-Amial-Pay-Timestamp` | ثواني Unix |
| `X-Amial-Pay-Signature` | `HMAC-SHA256(secret, "{timestamp}.{body}")` |

السر: `AMIAL_BUSINESS_WEBHOOK_SECRET` (مختلف عن سر الوارد إلزاماً).

## 4. غلاف الردّ

الغلاف القائم في أميال باي، بلا تغيير:

```json
{ "success": true, "code": "INTENT_READY", "message": "…", "errors": {}, "meta": { … } }
```

البيانات في `meta`. الرفض: `success=false` ورمز ثابت في `code`.

## 5. النقاط (v1)

### `GET /api/v1/business/link`
ملخص التاجر: `link_id` · `link_status` · `merchant_number` · `store_name` ·
`business_type` · `verification_status` · `account_active` ·
`can_receive_payments` · `wallet_currency`. **لا أرصدة.**

### `POST /api/v1/business/payment-intents`

| الحقل | النوع | ملاحظة |
|---|---|---|
| `business_reference` | نص `[A-Za-z0-9_.:-]{1,64}` | **فريد لكل رابط.** رقم الفاتورة في أميال أعمال |
| `amount` | **نص** `^\d{1,15}(\.\d{1,2})?$` | لا يُرسل رقماً عشرياً (float) |
| `currency` | `YER` | عملة المحفظة فقط. الفاتورة بالسعودي تُحوَّل في أميال أعمال قبل الطلب |
| `description` | نص ≤255 | اختياري |
| `branch_ref` · `device_ref` | نص ≤64 | اختياري، للتقارير |
| `expires_in_seconds` | 60–900 | الافتراضي 300 |

**تكرار آمن:** المرجع نفسه بالمبلغ نفسه يعيد النيّة نفسها (لا طلب دفع ثانٍ).
المرجع نفسه بمبلغ آخر: `409 BUSINESS_REFERENCE_CONFLICT`.

الردّ `201 INTENT_READY`:

```json
{
  "intent_id": "01J…", "business_reference": "INV-1001", "status": "pending",
  "amount": "1500.5000", "currency": "YER",
  "short_code": "K7M2QX", "qr_payload": "https://amialpay.com/req/K7M2QX",
  "expires_at": "2026-10-31T10:05:00+03:00", "paid_transaction_id": null, "succeeded_at": null,
  "branch_ref": null, "device_ref": null
}
```

يعرض الكاشير `qr_payload` رمز QR، والعميل يمسحه بتطبيق أميال باي.

الرفض: `VALIDATION` · `CURRENCY_NOT_SUPPORTED` · `AMOUNT_INVALID` ·
`TTL_OUT_OF_RANGE` · `KYC_REQUIRED` (403) · `ACCOUNT_RESTRICTED` (403) ·
`BUSINESS_LINK_INACTIVE` (403).

### `GET /api/v1/business/payment-intents/{intent_id}`
الحالة: `pending` · `succeeded` · `expired` · `cancelled`. نيّة لرابط آخر = `404 INTENT_NOT_FOUND`.
يُستعمل احتياطاً إن تأخر الحدث، لا بديلاً عنه.

### `POST /api/v1/business/payment-intents/{intent_id}/cancel`
يلغي نيّة غير مدفوعة (ويلغي طلب الدفع معها فلا يُدفع بعدها). متكرر الأمان.
نيّة مدفوعة: `409 INTENT_ALREADY_SUCCEEDED` — ما بعد الدفع استرداد (v2).

### `GET /api/v1/business/migration/summary` و `GET /api/v1/business/migration/{products|customers|suppliers}?cursor=N`

تصدير **للقراءة فقط** لترحيل التاجر (AMIAL-BUSINESS-MIGRATION-001). الصفحة 200 عنصر،
و`next_cursor` يقود للتالية (`null` = انتهت). التوقيع يشمل الاستعلام (`cursor=…`).

- **المخزون من مصدره:** تجزئة = مجموع `product_stocks` (أو العمود القديم لصنفٍ بلا صفوف)؛
  جملة = الدفعات النشطة؛ صيدلية = الدفعات النشطة غير المنتهية.
- **محطة الوقود** (`source=fuel`): المخزون = مجموع `book_liters` لخزّانات الصنف النشطة،
  والتكلفة من آخر توريدٍ مرحَّل. الصنف يحمل `fuel.tanks[]` (الرقم · السعة · حدّ التنبيه · الدفتري)
  و`fuel.nozzles[]` (المضخة · المسدس · الخزّان · العدّاد). شركات الأساطيل عملاء `source=fuel`
  برصيدها و`fuel_cards[]`؛ حدّ الصفر في أميال باي = بلا حدّ (`null`). وتدخل في تجميد الآجل.
- **المطعم**: الملخّص يحمل `restaurant_tables[]` (الاسم · المقاعد) و`open_restaurant_orders`
  (عدد الطلبات غير المغلقة)؛ أميال أعمال ترفض التنفيذ ما دام أكبر من صفر.
- **التكلفة الصفرية = مجهولة** (`unit_cost: null`)، لأن عمود `cost_price` افتراضه صفر.
- **الأرصدة** المخزّنة تُطابَق بآخر سطر في دفترها، والفرق يُبلَّغ في `warnings`.
- `summary.totals` هي ما تطابقه أميال أعمال بعد الاستيراد؛ أي فرق ⇒ يُرَدّ الاستيراد كله.

## 6. الأحداث (v1)

```json
{ "event_id": "01J…", "event_type": "payment_intent.succeeded", "event_version": 1,
  "occurred_at": "…", "link_id": "01J…", "data": { … } }
```

| الحدث | متى |
|---|---|
| `business_link.created` | عند ربط التاجر. `data` = ملخص الرابط |
| `payment_intent.succeeded` | دفع العميل. `data` يحمل `paid_transaction_id` |
| `payment_intent.expired` | انتهت الصلاحية بلا دفع |
| `payment_intent.cancelled` | أُلغيت النيّة |

**الضمانات:**
- الحدث يُكتب **داخل معاملة سببه**: لا حدث لدفعٍ رُدّ، ولا دفع بلا حدث.
- التسليم «مرةً على الأقل». أميال أعمال تُسقط المكرر بـ `event_id`.
- الترتيب غير مضمون؛ الحالة النهائية (`succeeded`) لا تُستبدل بأقدم منها.
- إعادة المحاولة بعد: 1د · 5د · 15د · 1س · 6س · 12س، ثم `dead` (يبقى ولا يُحذف) مع سجل خطأ.

**قاعدة أميال أعمال:** الفاتورة لا تُقفل «مدفوعة بالمحفظة» إلا بحدث
`payment_intent.succeeded` موقّع أو بقراءة الحالة `succeeded` من النقطة أعلاه.
أي ادعاء من شاشة الكاشير وحده لا يُقبل.

## 6.1 الآجل (v1.1 — AMIAL-BUSINESS-CREDIT-001)

إضافات لا تكسر ما قبلها.

### نيّة تحصيل دين
`POST /payment-intents` يقبل حقولاً اختيارية:

| الحقل | ملاحظة |
|---|---|
| `purpose` | `sale` (الافتراض) أو `debt_collection` |
| `contact_ref` | إلزامي مع `debt_collection`: مرجع العميل في أميال أعمال |
| `payer_phone` | اختياري: يصل الطلب لتطبيق صاحب الرقم **ولا يدفعه غيره** |

الردّ والأحداث تحمل أيضاً `purpose` · `origin` (`business`/`customer_app`) · `contact_ref` ·
`payer_masked_name`. المراجع التي تبدأ بـ `APP-` محجوزة لأميال باي. رفض جديد: `PAYER_NOT_FOUND` (404).

**سداد من تطبيق العميل:** يصل `payment_intent.succeeded` لنيّة لم تُنشئها أميال أعمال، وفيه
`origin=customer_app` و`contact_ref`. أميال أعمال تُنشئ التحصيل منه؛ مرجعٌ لا تعرفه ⇒ شذوذ لا قيد.

### `POST /credit/handover`
`{reference, credit_fingerprint}` — البصمة من `migration/summary.credit_fingerprint`.
`200 CREDIT_HANDED_OVER {handed_over_at, reference, replayed}`. المرجع نفسه يُعاد بأمان.
الرفض (409): `CREDIT_CHANGED_SINCE_EXPORT` · `CREDIT_COLLECTIONS_OUTSTANDING` · `CREDIT_ALREADY_HANDED_OVER`.
بعده كل حركة آجل للتاجر في أميال باي ⇒ `409 CREDIT_MANAGED_IN_AMIAL_BUSINESS`.

### `PUT /customer-debts/{contact_ref}`
`{customer_name, customer_phone, balance, overdue_amount, next_due_date, reminders_enabled, version, as_of}`.
`version` عدد صحيح يزيد مع كل تغيّر؛ الأقل أو المساوي ⇒ `200 DEBT_VERSION_STALE` بلا أثر.

### `POST /customers/resolve`
`{phone}` ⇒ `{found, masked_name}` فقط. 30 طلباً/دقيقة لكل رابط.

## 6.2 الاسترداد (v1.2 — AMIAL-BUSINESS-REFUND-001)

### `POST /refunds`
`{business_reference, intent_id, amount, reason?}` — نيّة ناجحة لهذا الرابط، والمبلغ نصّ بمنزلتين.
`201 REFUND_COMPLETED` (ضمن تفويض التاجر) أو `201 REFUND_PENDING_APPROVAL`. المرجع نفسه بالمبلغ نفسه
يعيد الطلب نفسه؛ بمبلغ آخر `409 BUSINESS_REFERENCE_CONFLICT`.
الرفض: `INTENT_NOT_FOUND` (404) · `INTENT_NOT_PAID` · `REFUND_EXCEEDS_PAYMENT` · `PAYER_UNKNOWN` (409).

### `GET /refunds/{refund_id}`
`status`: `pending_approval` · `completed` · `rejected` · `expired`، و`approval`: `mandate` · `merchant_pin`.

### الأحداث
`business_refund.completed` · `business_refund.rejected` · `business_refund.expired` — `data` بشكل `GET /refunds/{id}`.

**القاعدة:** توقيع القناة لا يُخرج مالاً. التاجر يوافق برمز PIN من تطبيقه
(`merchant/business-refunds/{id}/approve`) أو يضع تفويضاً (`merchant/business-refunds/mandate`).
المال يعود لدافع النيّة فقط، والمعلّق ينتهي بعد 72 ساعة.

## 6.3 المحفظة والسحب (v1.3 — AMIAL-MERCHANT-CASHOUT-001)

### `GET /wallet`
`{currency, available, held, pending_cash_outs, pending_cash_out_total, as_of}` — قراءة للمطابقة فقط.
لا يخرج رقم عملية سحب عبر القناة: هو سرّ الصرف عند الوكيل.

### الحدث `merchant_cash_out.completed`
`{withdrawal_id, transaction_id, amount, fee, net_amount, total_debit, currency, completed_at}` بعد أن يصرف الوكيل للتاجر.
أميال أعمال تقيّده: مدين الصندوق (amount) ومصروف (fee) / دائن المحفظة (amount + fee).

السحب نفسه يُنشأ في تطبيق المالك فقط (`merchant/cash-out` برمز PIN)، والوكيل يتحقق إلزامياً من هاتف التاجر
أو رقم حسابه قبل الصرف.

## 6.4 سجل التدقيق (v1.4 — AMIAL-BUSINESS-AUDIT-001)

### `POST /audit-events`
`{event_ref, action, actor, subject_type, subject_id, context, occurred_at, hash}` — أميال أعمال تُرسل الأحداث
الحسّاسة فقط (تغيير سعر · إلغاء · خصم يدوي · صلاحيات · دخول فاشل · استرداد). يُحفظ مرة واحدة لكل
`(link, event_ref)`، ويُكتب في سجلّ تدقيق أميال باي بادئةَ `AB_`. الردّ `{recorded, replayed}`.

## 6.5 الرسوم (v1.5 — AMIAL-BUSINESS-FEES-001)

**أميال باي محرّك الرسوم الوحيد.** أميال أعمال لا تخزّن جدول رسوم ولا تحسب رسماً؛ تقرأ ما يُرسل وتقيّده.

- كل حدث ماليّ يحمل `fee` و`net_amount`:
  - `payment_intent.succeeded`: `fee` ما تحمّله التاجر من الدفعة (صفر حين يتحمّله العميل)، و`net_amount = amount − fee`.
    أميال أعمال تقيّد: مدين المحفظة `net_amount` + مدين مصروف `fee` / دائن المبيعات أو الذمم `amount`.
  - `business_refund.*`: `fee` (صفر اليوم) و`net_amount`.
  - `merchant_cash_out.completed`: `net_amount` (ما استلمه نقداً) و`total_debit` (ما خرج من المحفظة).
- حدثٌ قديم بلا `fee` يُقرأ صفراً — التوافق محفوظ.

### `GET /fees/quote?operation={merchant_cash_out|wallet_payment}&amount=`
`{operation, amount, fee, net_amount, total_debit}` — عرضٌ قبل الفعل من المحرّك نفسه الذي سيحسب عند التنفيذ.
الاستعلام موقّع بعد ترتيب مفاتيحه أبجدياً (RFC 3986)، كما يطبّعه الخادم.

## 6.6 الاشتراك والميزات (v1.6 — AMIAL-BUSINESS-PLANS-001)

**اشتراكٌ واحد في أميال باي.** أميال أعمال لا تبيع باقة؛ تقرأ ما يحقّ للتاجر وتطبّقه.

### `GET /entitlements`
```
{ plan, plan_label, paid_plan, status: free|active|expired, expires_at,
  features: [{code: "ab.*", name_ar, name_en, group, min_plan, available, source: plan|addon|locked}],
  limits: {users, products},            // null = بلا حد
  after_expiry: {features: [code], limits},
  plans: [{code, label, monthly_price, currency, currency_label, durations: [{months, discount_percent, price}], features, limits}],
  upgrade_channel: "amial_pay_app", version }
```
- `version` بصمةُ المحتوى: تتغيّر بتغيّر أيّ شيء فيه.
- الرموز من `BusinessFeatureRegistry`؛ رمزٌ يُضاف ولا يُعاد تسميته. رمزٌ مجهول في `extra_features` يُتجاهل.
- `plan` رمز الباقة في **كتالوج الباقات** (قد يكون باقةً مخصّصة مثل `business_plus`)، وميزاتها وحدودها كما ضُبطت
  في لوحة الإدارة. وتعديل الباقة نفسها يكتب `entitlements.changed` لكل مشتركيها.

### الحدث `entitlements.changed`
`{version}` — يُكتب عند أيّ تغيّر في `subscription_plan` أو `subscription_expires_at` أو `extra_features`.
أميال أعمال تعيد قراءة `/entitlements` بعده، وتقرأ كل ساعة احتياطاً، وتنزل عند `expires_at` دون انتظار حدث.

## 7. ثوابت محروسة باختبارات

`tests/Feature/BusinessGatewayTest.php` (27 حالة، 6 منها جُرّبت بالعكس):

- طلب بلا توقيع · بسر خاطئ · بجسم معدّل · بوقت قديم · مُعاد ← مرفوض.
- مرجع مكرر ← النيّة نفسها؛ بمبلغ آخر ← تعارض، ولا طلب دفع ثانٍ.
- الدفع يحرّك المبلغ نفسه مرة واحدة؛ الدفع الثاني مرفوض.
- نيّة ملغاة أو منتهية لا تقبل مالاً.
- نيّة أُغلقت وطلبها بقي قابلاً للدفع ← **تُرَدّ عملية الدفع كلها**.
- دفع استُهلك في أميال أعمال لا يُربط ببيع في كاشير أميال باي.
- رابط لا يقرأ نوايا رابط آخر.
- الأحداث موقّعة، وتُعاد عند الفشل، ولا تضيع إن غابت القناة.
- الحدث يحمل الرسم والصافي، وعرض الرسم من محرّك الرسوم نفسه.

## 8. خارج v1 (مخطط له في v2)

استرداد للعميل · رصيد المحفظة وكشفها للمطابقة · طلب سحب · خصم اشتراك أميال
أعمال من المحفظة · حدث `merchant.status_changed` · إنشاء الرابط تلقائياً عند
اعتماد موثّق. **كل ما يُخرج مالاً من محفظة التاجر سيتطلب تأكيداً من التاجر
نفسه (PIN)، لا توقيع القناة وحده.**

## 9. الإعداد

```text
# أميال باي
AMIAL_BUSINESS_INBOUND_SECRET=<سر عشوائي 64 محرفاً>
AMIAL_BUSINESS_WEBHOOK_SECRET=<سر مختلف>
AMIAL_BUSINESS_WEBHOOK_URL=https://<نطاق أميال أعمال>/api/amial-pay/webhooks
```

والمجدوِل يشغّل `amial:business tick` كل دقيقة (مضبوط في `routes/console.php`).
