# دمج AML Engine في الـ Financial Services

**AMIAL-AML-001 (v1.4)**

## القاعدة الذهبية

```
كل عملية مالية (debit) يجب أن تمر عبر AmlScreeningService->screen() قبل التنفيذ.
```

## الـ Pattern العام

```php
use App\Aml\TransactionContext;
use App\Exceptions\AmlBlockedException;
use App\Exceptions\AmlHeldException;
use App\Services\AmlScreeningService;

class YourFinancialService
{
    public function __construct(
        private readonly AmlScreeningService $aml,
        // ... باقي dependencies
    ) {}

    public function executeTransaction(...): SomeModel
    {
        // 1) أنشئ context قبل أي خصم
        $ulid = (string) Str::ulid();
        $context = new TransactionContext(
            actorUserId: $sender->id,
            counterpartyUserId: $recipient?->id,
            transactionType: 'send_money', // أو safe_payment_fund, donation, bill_pay
            amount: $amountNormalized,
            timestamp: now(),
            transactionUlid: $ulid,
            ipAddress: request()?->ip(),
        );

        // 2) فحص AML
        $decision = $this->aml->screen($context);

        // 3) لو blocked → throw (بدون تنفيذ شيء)
        if ($decision->isBlocked()) {
            throw new AmlBlockedException($decision);
        }

        // 4) لو held → throw مع flag_id (المعاملة لم تُنفَّذ، تنتظر admin)
        if ($decision->isHeld()) {
            // الـ Service يستطيع جلب الـ flag_id من DB:
            $flag = AmlFlaggedTransaction::where('transaction_ulid', $ulid)->first();
            throw new AmlHeldException($decision, $flag?->id);
        }

        // 5) لو allowed أو flagged → نفّذ المعاملة عادي
        // (flagged مسجلة للمراجعة لكنها لا توقف التنفيذ)
        return DB::transaction(function () use (...) {
            // ... خصم/إضافة + إنشاء records
        });
    }
}
```

## مثال: send_money

في `TransactionTrait::sendMoney()`:

```php
public function sendMoney($request)
{
    // ... validation أساسي ...

    $context = new TransactionContext(
        actorUserId: $request->user()->id,
        counterpartyUserId: $recipient->id,
        transactionType: 'send_money',
        amount: $request->input('amount'),
        timestamp: now(),
        transactionUlid: $transactionId,
        ipAddress: $request->ip(),
    );

    $aml = app(\App\Services\AmlScreeningService::class);
    $decision = $aml->screen($context);

    if ($decision->isBlocked()) {
        throw new \App\Exceptions\AmlBlockedException($decision);
    }
    if ($decision->isHeld()) {
        $flag = \App\Models\Aml\AmlFlaggedTransaction::where('transaction_ulid', $transactionId)->first();
        throw new \App\Exceptions\AmlHeldException($decision, $flag?->id);
    }

    // ... باقي الكود (الخصم، الإضافة، الإيصال) كما هو ...
}
```

## مثال: safe_payment

في `SafePaymentService::createAndFund()`, بعد validation وقبل `DB::transaction()`:

```php
$context = new TransactionContext(
    actorUserId: $buyer->id,
    counterpartyUserId: $seller->id,
    transactionType: 'safe_payment_fund',
    amount: $amountNormalized,
    timestamp: now(),
    transactionUlid: (string) Str::ulid(),
);

$decision = $this->aml->screen($context);

if ($decision->isBlocked()) throw new AmlBlockedException($decision);
if ($decision->isHeld()) {
    $flag = AmlFlaggedTransaction::where('transaction_ulid', $context->transactionUlid)->first();
    throw new AmlHeldException($decision, $flag?->id);
}

// ... DB::transaction()
```

## في الـ Controllers — تحويل Exceptions إلى JSON

```php
try {
    $this->service->doFinancialAction(...);
} catch (\App\Exceptions\AmlBlockedException $e) {
    return new JsonResponse($e->toApiArray(), 403);
} catch (\App\Exceptions\AmlHeldException $e) {
    return new JsonResponse($e->toApiArray(), 202); // 202 Accepted (للمراجعة)
} catch (\App\Exceptions\InsufficientBalanceException $e) {
    return new JsonResponse($e->toApiArray(), 402);
}
```

## ماذا يحدث للمستخدم؟

**Allow (لا match):**
- المعاملة تنفذ عادي ✓

**Flag (low risk):**
- المعاملة تنفذ عادي ✓
- مسجَّلة في `aml_flagged_transactions` بحالة `auto_resolved`
- لا تنبيه للـ user
- الـ admin يستطيع رؤيتها في dashboard للمراجعة لاحقاً

**Hold (high risk):**
- المعاملة **لم تنفذ** ✗
- مسجَّلة بحالة `pending_review`
- alert للـ admin (medium/high severity)
- المستخدم يرى: "تم تعليق العملية للمراجعة. سيتم إشعارك خلال 24 ساعة."
- الـ admin يراجع → approve أو reject

**Block (critical):**
- المعاملة لم تنفذ ✗
- مسجَّلة، alert critical للـ admin
- المستخدم يرى رسالة عامة: "تم رفض العملية لأسباب أمنية"
- لا توضيح للأسباب (security through obscurity)

## التوصيات قبل production

### قبل أول pilot
- [ ] `php artisan db:seed --class=AmlDefaultRulesSeeder`
- [ ] راجع كل قاعدة في admin panel
- [ ] **خفض الـ thresholds** للاختبار (مثلاً MAX_SINGLE_TX_HARD = 5,000 بدلاً من 50,000)
- [ ] راقب logs بـ `aml_rule_evaluations` و `aml_flagged_transactions`

### بعد أسبوع من البيانات
- ارجع للقواعد، عدّل الـ thresholds لتجنب false positives
- إذا alerts كثيرة جداً → ارفع thresholds
- إذا alerts قليلة → اخفض

### للـ scale (10k+ users)
- نقل `aml_rule_evaluations` إلى partitioned table (بـ month)
- archive evaluations قديمة (> 90 يوم) إلى cold storage
- إضافة ML-based detection (في v1.5)
- SAR generation (Suspicious Activity Report) للجهات التنظيمية

## Whitelist / Blacklist يدوي

```bash
# whitelist user موثوق (يتجاوز كل القواعد)
curl -X POST /api/admin/amial/aml/users/123/override \
  -d '{"override": "whitelist", "reason": "Approved business account"}'

# blacklist user مشبوه (كل معاملاته block)
curl -X POST /api/admin/amial/aml/users/456/override \
  -d '{"override": "blacklist", "reason": "Fraud confirmed in ticket #789"}'
```

## مراقبة Performance

كل `screen()` يضيف ~50-100ms (يعتمد على عدد القواعد + DB queries).

للأداء:
- استخدم Redis للـ velocity counts (في v1.5)
- caching للقواعد النشطة (Get::cache مع TTL 5 min)

في v1.4 الحالية، أداء معقول لـ 1000-5000 req/min.
