<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-CUSTOMER-SYSTEMS-CENTER-001
 *
 * لوحة مراقبة لا تنشئ حقيقة ثانية. كل رقم هنا يُقرأ من المصدر التشغيلي
 * نفسه: جداول KYC/الحدود/الطلبات/السداد/الإيصالات/الديون/الإشعارات/audit.
 */
class CustomerSystemsCenterService
{
    /** @return array<string,mixed> */
    public function snapshot(): array
    {
        return [
            'generated_at' => now()->toIso8601String(),
            'summary' => $this->summary(),
            'systems' => $this->systems(),
            'policy_blocks' => $this->policyBlocks(),
            'credits' => $this->credits(),
            'receipts' => $this->receipts(),
            'bill_pay' => $this->billPay(),
            'payment_requests' => $this->paymentRequests(),
            'notifications' => $this->notifications(),
        ];
    }

    /** @return array<string,mixed> */
    private function summary(): array
    {
        return [
            'customers' => $this->count('users', fn ($q) => $q->where('type', 2)),
            'kyc_pending' => $this->count('kyc_documents', fn ($q) => $q->where('status', 'pending')),
            'limit_overrides' => $this->count('users', fn ($q) => $q->where('type', 2)->whereNotNull('limit_override')),
            'policy_blocks_24h' => $this->count('audit_decisions', fn ($q) => $q
                ->where('action', 'CUSTOMER_POLICY_BLOCKED')
                ->where('created_at', '>=', now()->subDay())),
            'outstanding_credit_accounts' => $this->count('customer_credit_accounts', fn ($q) => $q->where('current_balance', '>', 0)),
            'pending_payment_requests' => $this->count('payment_requests', fn ($q) => $q->where('status', 'pending')),
            'pending_bill_orders' => $this->count('bill_payment_orders', fn ($q) => $q
                ->whereIn('status', ['pending', 'processing', 'pending_provider_confirmation'])),
            'receipt_pdf_failures' => $this->count('receipts', fn ($q) => $q->where('status', 'pdf_failed')),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function systems(): array
    {
        $hasDeliveryProof = Schema::hasTable('notification_deliveries')
            || Schema::hasTable('notification_delivery_logs');

        return [
            $this->system(
                'kyc',
                'التوثيق وKYC',
                Schema::hasTable('kyc_documents') && $this->hasRoute('admin.amial.kyc.page'),
                'لجنة التحقق، السكن، ملفات فتح الحساب، والتدرج 0→1→2→3.',
                [
                    ['label' => 'طلبات معلقة', 'value' => $this->count('kyc_documents', fn ($q) => $q->where('status', 'pending'))],
                    ['label' => 'ملفات فتح الحساب', 'value' => $this->count('registration_dossiers')],
                ],
                [
                    $this->action('لجنة التحقق', 'admin.amial.kyc.page'),
                    $this->action('ملفات فتح الحساب', 'admin.amial.registration-dossiers.page'),
                    $this->action('سجل التدقيق', 'admin.amial.audit.index'),
                ],
            ),
            $this->system(
                'limits',
                'الحدود المالية',
                Schema::hasTable('kyc_tier_limits') && $this->hasRoute('admin.amial.hub.limits.index'),
                'سقوف المستويات والاستثناءات الفردية المنخفضة وسجل تغييرات السياسة.',
                [
                    ['label' => 'سياسات نشطة', 'value' => $this->count('kyc_tier_limits', fn ($q) => $q->where('is_active', true))],
                    ['label' => 'استثناءات عملاء', 'value' => $this->count('users', fn ($q) => $q->where('type', 2)->whereNotNull('limit_override'))],
                ],
                [
                    $this->action('مركز الحدود', 'admin.amial.hub.limits.index'),
                    $this->action('ملف العميل', 'admin.amial.customer.page'),
                ],
            ),
            $this->system(
                'guards',
                'حراس KYC وCross-Tier',
                Schema::hasTable('audit_decisions'),
                'الحارس لا يُعطّل من الإدارة؛ الإدارة ترى حالات المنع وأسبابها والعميل المتأثر.',
                [
                    ['label' => 'منع آخر 24 ساعة', 'value' => $this->count('audit_decisions', fn ($q) => $q
                        ->where('action', 'CUSTOMER_POLICY_BLOCKED')
                        ->where('created_at', '>=', now()->subDay()))],
                    ['label' => 'منع آخر 7 أيام', 'value' => $this->count('audit_decisions', fn ($q) => $q
                        ->where('action', 'CUSTOMER_POLICY_BLOCKED')
                        ->where('created_at', '>=', now()->subDays(7)))],
                ],
                [
                    $this->action('أحداث الحارس', 'admin.amial.audit.index', ['action' => 'CUSTOMER_POLICY_BLOCKED']),
                    $this->action('لجنة التحقق', 'admin.amial.kyc.page'),
                ],
            ),
            $this->system(
                'wallet_transfers',
                'المحفظة والتحويلات',
                Schema::hasTable('transactions') && $this->hasRoute('admin.transaction.index'),
                'الحركة الأساسية للعميل: إرسال واستقبال وحركات المحفظة، مع التتبع من كشف المعاملات إلى ملف العميل والدفتر.',
                [
                    ['label' => 'حركات عملاء اليوم', 'value' => $this->customerTransactionCountToday()],
                    ['label' => 'تحويلات اليوم', 'value' => $this->customerTransactionTypeCountToday(['send_money', 'received_money'])],
                ],
                [
                    $this->action('كشف المعاملات', 'admin.transaction.index'),
                    $this->action('ملف العميل', 'admin.amial.customer.page'),
                    $this->action('مركز الدفتر', 'admin.amial.ledger.page'),
                ],
            ),
            $this->system(
                'merchant_payments',
                'دفع العملاء للتجار',
                Schema::hasTable('transactions') && $this->hasRoute('admin.transaction.index'),
                'دفعات QR/POS/التاجر ظاهرة من كشف المعاملات، ويمكن تتبع مرجعها إلى التاجر والرسوم والدفتر.',
                [
                    ['label' => 'دفعات اليوم', 'value' => $this->customerTransactionTypeCountToday([
                        'merchant_payment', 'pay_merchant', 'pos_payment', 'qr_payment',
                    ])],
                    ['label' => 'دفعات آخر 7 أيام', 'value' => $this->customerTransactionTypeCountSince([
                        'merchant_payment', 'pay_merchant', 'pos_payment', 'qr_payment',
                    ], now()->subDays(7))],
                ],
                [
                    $this->action('كشف المعاملات', 'admin.transaction.index', ['trx_type' => 'merchant_payment']),
                    $this->action('فواتير التجار', 'admin.amial.invoices.page'),
                    $this->action('مركز التجار', 'admin.amial.hub.merchants'),
                ],
            ),
            $this->system(
                'safe_payment',
                'الدفع الآمن',
                Schema::hasTable('safe_payments') && $this->hasRoute('admin.amial.safe-payments.index'),
                'الأموال المحجوزة والنزاعات والحسم الإداري تمر من شاشة الدفع الآمن نفسها، ولا تُحل من هذا المركز مباشرةً.',
                [
                    ['label' => 'نزاعات مفتوحة', 'value' => $this->count('safe_payments', fn ($q) => $q->where('status', 'disputed'))],
                    ['label' => 'مبلغ محجوز', 'value' => $this->sum('safe_payments', 'held_amount', fn ($q) => $q->where('held_amount', '>', 0)), 'money' => true],
                ],
                [
                    $this->action('الدفع الآمن والنزاعات', 'admin.amial.safe-payments.index'),
                    $this->action('سجل التدقيق', 'admin.amial.audit.index', ['action' => 'SAFE_PAYMENT']),
                ],
            ),
            $this->system(
                'donations',
                'التبرعات والجمعيات',
                Schema::hasTable('donations') && $this->hasRoute('admin.amial.charity.page'),
                'التبرعات والحملات والتسويات لها لوحة إدارة مستقلة، مع مرجع المحفظة والإيصال والتسوية.',
                [
                    ['label' => 'تبرعات اليوم', 'value' => $this->count('donations', fn ($q) => $q->whereDate('donated_at', today()))],
                    ['label' => 'قيمة اليوم', 'value' => $this->sum('donations', 'amount', fn ($q) => $q->whereDate('donated_at', today())), 'money' => true],
                ],
                [
                    $this->action('لوحة التبرعات', 'admin.amial.charity.page'),
                    $this->action('سجل التدقيق', 'admin.amial.audit.index', ['action' => 'CHARITY']),
                ],
            ),
            $this->system(
                'family_funds',
                'الصندوق العائلي',
                Schema::hasTable('family_funds')
                    && Schema::hasTable('family_fund_transactions')
                    && $this->hasRoute('admin.amial.surface.funds'),
                'الصناديق وأرصدة الحوض وحركات المساهمة والصرف والطلبات المعلقة ظاهرة للإدارة من مصدرها التشغيلي.',
                [
                    ['label' => 'صناديق نشطة', 'value' => $this->count('family_funds', fn ($q) => $q->where('status', 'active'))],
                    ['label' => 'صرف ينتظر اعتماداً', 'value' => $this->count('family_fund_transactions', fn ($q) => $q->where('status', 'pending_approval'))],
                ],
                [
                    $this->action('صناديق العائلة', 'admin.amial.surface.funds'),
                    $this->action('سجل التدقيق', 'admin.amial.audit.index', ['action' => 'FAMILY_FUND']),
                ],
            ),
            $this->system(
                'withdrawals',
                'السحب المبدوء من العميل',
                Schema::hasTable('withdrawal_requests') && $this->hasRoute('admin.withdraw.index'),
                'طلبات السحب الصادرة من تطبيق العميل تبقى قابلة للمراقبة حتى التنفيذ أو الإلغاء أو الانتهاء.',
                [
                    ['label' => 'طلبات معلقة', 'value' => $this->count('withdrawal_requests', fn ($q) => $q->where('status', 'pending'))],
                    ['label' => 'مبلغ محجوز للطلبات المعلقة', 'value' => $this->sum('withdrawal_requests', 'total_debit', fn ($q) => $q->where('status', 'pending')), 'money' => true],
                ],
                [
                    $this->action('طلبات السحب', 'admin.withdraw.index'),
                    $this->action('كشف المعاملات', 'admin.transaction.index'),
                ],
            ),
            $this->system(
                'idempotency',
                'سلامة التكرار وإعادة الإرسال',
                Schema::hasTable('audit_decisions'),
                'منع تنفيذ الطلب المالي نفسه مرتين حارس خلفي إلزامي؛ الإدارة ترى الاصطدامات ولا تملك زر تعطيله.',
                [
                    ['label' => 'محتوى مختلف / 24س', 'value' => $this->count('audit_decisions', fn ($q) => $q
                        ->where('action', 'IDEMPOTENCY_BODY_MISMATCH')
                        ->where('created_at', '>=', now()->subDay()))],
                    ['label' => 'محتوى مختلف / 7 أيام', 'value' => $this->count('audit_decisions', fn ($q) => $q
                        ->where('action', 'IDEMPOTENCY_BODY_MISMATCH')
                        ->where('created_at', '>=', now()->subDays(7)))],
                ],
                [
                    $this->action('أحداث منع التكرار', 'admin.amial.audit.index', ['action' => 'IDEMPOTENCY_BODY_MISMATCH']),
                    $this->action('كشف المعاملات', 'admin.transaction.index'),
                ],
                'complete',
                null,
            ),
            $this->system(
                'credits',
                'الأجل وديون العملاء',
                Schema::hasTable('customer_credit_accounts') && Schema::hasTable('customer_credit_movements'),
                'مراقبة الدين الموحد المرتبط بالتاجر والعميل وحركات السداد/المرتجع.',
                [
                    ['label' => 'حسابات عليها رصيد', 'value' => $this->count('customer_credit_accounts', fn ($q) => $q->where('current_balance', '>', 0))],
                    ['label' => 'إجمالي مستحق', 'value' => $this->sum('customer_credit_accounts', 'current_balance', fn ($q) => $q->where('current_balance', '>', 0)), 'money' => true],
                ],
                [
                    $this->action('ملف العميل الموحد', 'admin.amial.customer.page'),
                    $this->action('كشف المعاملات', 'admin.transaction.index'),
                ],
            ),
            $this->system(
                'payment_requests',
                'طلبات الأموال',
                Schema::hasTable('payment_requests') && $this->hasRoute('admin.amial.surface.payment-requests'),
                'متابعة الطلب من الإنشاء إلى الدفع/الإلغاء/الانتهاء مع مرجع العملية.',
                [
                    ['label' => 'معلقة', 'value' => $this->count('payment_requests', fn ($q) => $q->where('status', 'pending'))],
                    ['label' => 'مدفوعة', 'value' => $this->count('payment_requests', fn ($q) => $q->where('status', 'paid'))],
                ],
                [
                    $this->action('طلبات الأموال', 'admin.amial.surface.payment-requests'),
                    $this->action('ملف العميل', 'admin.amial.customer.page'),
                ],
            ),
            $this->system(
                'bill_pay',
                'السداد والمزوّدون',
                Schema::hasTable('bill_payment_orders') && $this->hasRoute('admin.amial.surface.bill-providers'),
                'حالة المزود، العمليات المعلقة، المصالحة، المراجع، والإيصالات.',
                [
                    ['label' => 'قيد التأكيد', 'value' => $this->count('bill_payment_orders', fn ($q) => $q->where('status', 'pending_provider_confirmation'))],
                    ['label' => 'ناجحة اليوم', 'value' => $this->count('bill_payment_orders', fn ($q) => $q->where('status', 'success')->whereDate('created_at', today()))],
                ],
                [
                    $this->action('مركز السداد', 'admin.amial.surface.bill-providers'),
                    $this->action('سجل التدقيق', 'admin.amial.audit.index', ['action' => 'BILL_PAY_SUCCESS']),
                ],
            ),
            $this->system(
                'receipts',
                'الإيصالات والمستندات',
                Schema::hasTable('receipts'),
                'سجل موحد للإيصالات، حالة PDF، التحقق، والمرجع المالي.',
                [
                    ['label' => 'إيصالات اليوم', 'value' => $this->count('receipts', fn ($q) => $q->whereDate('issued_at', today()))],
                    ['label' => 'PDF فاشل', 'value' => $this->count('receipts', fn ($q) => $q->where('status', 'pdf_failed'))],
                ],
                [
                    $this->action('كشف المعاملات', 'admin.transaction.index'),
                    $this->action('ملف العميل', 'admin.amial.customer.page'),
                ],
            ),
            $this->system(
                'notifications',
                'إشعارات العميل',
                Schema::hasTable('amial_notifications'),
                $hasDeliveryProof
                    ? 'الإشعار الداخلي وإثبات التسليم الخارجي قابلان للتتبع.'
                    : 'الإشعار الداخلي قابل للتتبع؛ لا يوجد بعد سجل Delivery خارجي يثبت وصول Push للجهاز.',
                [
                    ['label' => 'أُنشئت اليوم', 'value' => $this->count('amial_notifications', fn ($q) => $q->whereDate('created_at', today()))],
                    ['label' => 'غير مقروءة', 'value' => $this->count('amial_notifications', fn ($q) => $q->whereNull('read_at'))],
                ],
                [
                    $this->action('إعداد Firebase', 'admin.business-settings.fcm-index'),
                    $this->action('ملف العميل', 'admin.amial.customer.page'),
                ],
                $hasDeliveryProof ? 'complete' : 'partial',
                $hasDeliveryProof ? null : 'ينقص إثبات Delivery خارجي مستقل عن إنشاء الإشعار داخل قاعدة البيانات.',
            ),
            $this->system(
                'reports',
                'تقارير العميل والدفتر',
                $this->hasRoute('admin.amial.reporting-center.index'),
                'التقارير تقرأ من مصادر الدفتر والحركات ولا تعيد بناء حقيقة مالية موازية.',
                [],
                [
                    $this->action('مركز التقارير', 'admin.amial.reporting-center.index'),
                    $this->action('مركز الدفتر', 'admin.amial.ledger.page'),
                ],
            ),
            [
                'key' => 'quick_pay',
                'title' => 'الدفع السريع',
                'state' => 'deferred',
                'state_label' => 'مؤجل',
                'description' => 'مؤجل بقرار المشروع الحالي، لذلك لا يُحسب كنظام مخفي أو ناقص عن طريق الخطأ.',
                'metrics' => [],
                'actions' => [],
                'gap' => 'مؤجل عمداً.',
                'visibility' => 'ظاهر كمؤجل بقرار المشروع',
                'controls' => 'لا أوامر حتى استئناف العمل عليه',
                'audit' => 'لا أحداث تشغيلية لأنه غير مفعّل',
            ],
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function policyBlocks(): array
    {
        if (!Schema::hasTable('audit_decisions')) {
            return [];
        }

        return DB::table('audit_decisions')
            ->where('action', 'CUSTOMER_POLICY_BLOCKED')
            ->orderByDesc('id')
            ->limit(40)
            ->get(['decision_id', 'subject_id', 'decision_code', 'reason', 'context', 'transaction_id', 'created_at'])
            ->map(function ($row): array {
                $ctx = json_decode((string) ($row->context ?? ''), true) ?: [];
                return [
                    'decision_id' => (string) $row->decision_id,
                    'customer_id' => $row->subject_id,
                    'code' => (string) ($row->decision_code ?? ''),
                    'reason' => (string) ($row->reason ?? ''),
                    'feature' => (string) ($ctx['feature'] ?? '—'),
                    'tier' => $ctx['tier'] ?? null,
                    'amount' => $ctx['amount'] ?? null,
                    'transaction_id' => $row->transaction_id,
                    'at' => (string) $row->created_at,
                ];
            })->all();
    }

    /** @return array<int,array<string,mixed>> */
    private function credits(): array
    {
        if (!Schema::hasTable('customer_credit_accounts')) {
            return [];
        }

        return DB::table('customer_credit_accounts')
            ->where('current_balance', '>', 0)
            ->orderByDesc('current_balance')
            ->limit(25)
            ->get(['id', 'merchant_user_id', 'customer_user_id', 'customer_name', 'current_balance', 'credit_limit', 'classification', 'last_payment_at'])
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'merchant_user_id' => (int) $r->merchant_user_id,
                'customer_user_id' => $r->customer_user_id ? (int) $r->customer_user_id : null,
                'customer_name' => (string) $r->customer_name,
                'balance' => (string) $r->current_balance,
                'limit' => (string) $r->credit_limit,
                'classification' => (string) $r->classification,
                'last_payment_at' => $r->last_payment_at,
            ])->all();
    }

    /** @return array<int,array<string,mixed>> */
    private function receipts(): array
    {
        if (!Schema::hasTable('receipts')) {
            return [];
        }

        return DB::table('receipts')
            ->orderByDesc('id')
            ->limit(30)
            ->get(['receipt_number', 'user_id', 'receipt_type', 'amount', 'fee', 'status', 'reference_transaction_id', 'issued_at'])
            ->map(fn ($r) => (array) $r)->all();
    }

    /** @return array<int,array<string,mixed>> */
    private function billPay(): array
    {
        if (!Schema::hasTable('bill_payment_orders')) {
            return [];
        }

        return DB::table('bill_payment_orders')
            ->whereIn('status', ['pending', 'processing', 'pending_provider_confirmation', 'failed'])
            ->orderByDesc('id')
            ->limit(30)
            ->get(['order_ulid', 'user_id', 'amount', 'fee', 'status', 'provider_reference', 'provider_message', 'updated_at'])
            ->map(fn ($r) => (array) $r)->all();
    }

    /** @return array<int,array<string,mixed>> */
    private function paymentRequests(): array
    {
        if (!Schema::hasTable('payment_requests')) {
            return [];
        }

        return DB::table('payment_requests')
            ->orderByDesc('id')
            ->limit(30)
            ->get(['request_ulid', 'requester_user_id', 'recipient_user_id', 'amount', 'status', 'paid_transaction_id', 'created_at'])
            ->map(fn ($r) => (array) $r)->all();
    }

    /** @return array<int,array<string,mixed>> */
    private function notifications(): array
    {
        if (!Schema::hasTable('amial_notifications')) {
            return [];
        }

        return DB::table('amial_notifications')
            ->orderByDesc('id')
            ->limit(30)
            ->get(['user_id', 'type', 'title', 'read_at', 'created_at'])
            ->map(fn ($r) => (array) $r)->all();
    }

    /** @param array<int,array<string,mixed>> $metrics @param array<int,array<string,mixed>|null> $actions */
    private function system(
        string $key,
        string $title,
        bool $ready,
        string $description,
        array $metrics,
        array $actions,
        ?string $forcedState = null,
        ?string $gap = null,
    ): array {
        $state = $forcedState ?? ($ready ? 'complete' : 'incomplete');

        return [
            'key' => $key,
            'title' => $title,
            'state' => $state,
            'state_label' => match ($state) {
                'complete' => 'مكتمل إدارياً',
                'partial' => 'جزئي',
                'deferred' => 'مؤجل',
                default => 'غير مكتمل',
            },
            'description' => $description,
            'metrics' => $metrics,
            'actions' => array_values(array_filter($actions)),
            'gap' => $gap,
            'visibility' => $ready ? 'مرئي من لوحة الإدارة' : 'غير مكتمل إدارياً',
            'controls' => $this->controlDescription($key, $actions),
            'audit' => $this->auditDescription($key),
        ];
    }

    /** @param array<int,array<string,mixed>|null> $actions */
    private function controlDescription(string $key, array $actions): string
    {
        return match ($key) {
            'guards', 'idempotency' =>
                'مراقبة فقط — لا يوجد زر لتعطيل الحارس أو تجاوز القرار',
            'reports', 'receipts' =>
                'قراءة وتتبع؛ أي تصحيح يتم من مصدر العملية لا من التقرير/السند',
            'wallet_transfers', 'merchant_payments' =>
                'التتبع من الشاشات المالية؛ لا تعديل رصيد مباشر من المركز',
            'safe_payment' =>
                'الحسم من شاشة النزاع المحمية وبصلاحياتها، لا من هذا المركز',
            'kyc' =>
                'الاعتماد والرفض وإعادة الطلب من لجنة التحقق فقط',
            'limits' =>
                'تعديل السياسة والاستثناء المنخفض من مركز الحدود مع سبب وتدقيق',
            default =>
                $actions !== [] ? 'الأوامر من الشاشات الأصلية المحمية' : 'لا إجراء إداري مطلوب',
        };
    }

    private function auditDescription(string $key): string
    {
        return match ($key) {
            'kyc' => 'وثائق + مراجع/قرار + ملف تسجيل + audit_decisions',
            'limits' => 'قبل/بعد + الموظف + السبب داخل audit_decisions',
            'guards' => 'CUSTOMER_POLICY_BLOCKED مع العميل والسبب والميزة والمبلغ',
            'idempotency' => 'IDEMPOTENCY_BODY_MISMATCH + المفتاح/الارتباط عند توفره',
            'wallet_transfers' => 'transaction_id + قيود الدفتر + سجل التدقيق',
            'merchant_payments' => 'مرجع المعاملة + إيصال + رسوم + قيود الدفتر',
            'safe_payment' => 'دورة الحالة + الأموال المحجوزة + قرار النزاع + audit',
            'donations' => 'wallet_transaction_id + receipt_id + settlement + audit',
            'family_funds' => 'سجل حركات append-only + مرجع المحفظة + قرارات الصرف',
            'withdrawals' => 'طلب السحب + op_code + transaction_id + الحالة',
            'credits' => 'حركات ائتمان append-only + balance_after + مراجع البيع/السداد',
            'payment_requests' => 'request_ulid + paid_transaction_id + حالة الطلب + audit',
            'bill_pay' => 'order_ulid + provider_reference + طلبات المزود + إيصال + audit',
            'receipts' => 'receipt_number + verification_code + مرجع المعاملة + حالة PDF',
            'notifications' => 'إنشاء/قراءة داخليان؛ Delivery الخارجي معلن كفجوة إن لم يوجد سجله',
            'reports' => 'قراءة من الدفتر/المعاملات؛ التقرير لا يكتب حقيقة مالية جديدة',
            default => Schema::hasTable('audit_decisions')
                ? 'مرجع تشغيلي وسجل تدقيق عند وجود قرار'
                : 'سجل التدقيق غير متاح',
        };
    }

    /** @return array<string,mixed>|null */
    private function action(string $label, string $routeName, array $params = []): ?array
    {
        if (!$this->hasRoute($routeName)) {
            return null;
        }

        return ['label' => $label, 'url' => route($routeName, $params)];
    }

    private function hasRoute(string $name): bool
    {
        return Route::has($name);
    }

    private function customerTransactionCountToday(): int
    {
        if (!Schema::hasTable('transactions') || !Schema::hasTable('users')) {
            return 0;
        }

        return (int) DB::table('transactions as t')
            ->join('users as u', 'u.id', '=', 't.user_id')
            ->where('u.type', 2)
            ->whereDate('t.created_at', today())
            ->count();
    }

    /** @param array<int,string> $types */
    private function customerTransactionTypeCountToday(array $types): int
    {
        return $this->customerTransactionTypeCountSince($types, now()->startOfDay());
    }

    /** @param array<int,string> $types */
    private function customerTransactionTypeCountSince(array $types, \DateTimeInterface $since): int
    {
        if (!Schema::hasTable('transactions') || !Schema::hasTable('users')) {
            return 0;
        }

        return (int) DB::table('transactions as t')
            ->join('users as u', 'u.id', '=', 't.user_id')
            ->where('u.type', 2)
            ->whereIn('t.transaction_type', $types)
            ->where('t.created_at', '>=', $since)
            ->count();
    }

    private function count(string $table, ?callable $scope = null): int
    {
        if (!Schema::hasTable($table)) {
            return 0;
        }

        $query = DB::table($table);
        if ($scope) {
            $scope($query);
        }

        return (int) $query->count();
    }

    private function sum(string $table, string $column, ?callable $scope = null): string
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
            return '0';
        }

        $query = DB::table($table);
        if ($scope) {
            $scope($query);
        }

        return (string) ($query->sum($column) ?: '0');
    }
}
