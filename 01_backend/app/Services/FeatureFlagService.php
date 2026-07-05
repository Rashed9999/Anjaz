<?php

namespace App\Services;

use App\Exceptions\FeatureHasFundsException;
use App\Models\FeatureFlag;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-MAINT-001 — خدمة «الصيانة الأولية» (Feature Flags + حارس مالي).
 *
 * تشغيل/إيقاف الميزات من لوحة الأدمن. قاعدة الأمان الصلبة:
 *   الإيقاف مسموح فقط إذا كان التراكم المالي للميزة = صفر.
 *
 * كل ميزة ذات أموال تُعرّف money_source يُطابق دالّة حساب هنا. الميزات بلا
 * أموال (money_source = null) تُغلق بحرّية (تراكمها دائماً صفر).
 */
class FeatureFlagService
{
    private const CACHE_TTL = 300; // 5 دقائق
    private const CACHE_PREFIX = 'feature_flag:';

    public function __construct(
        private readonly AuditService $audit,
    ) {}

    /** هل الميزة مفعّلة؟ (مُخزّن مؤقتاً). ميزة غير معرّفة = مفعّلة افتراضياً (fail-open). */
    public function isEnabled(string $key): bool
    {
        return Cache::remember(self::CACHE_PREFIX . $key, self::CACHE_TTL, function () use ($key) {
            $flag = FeatureFlag::where('key', $key)->first();
            return $flag ? (bool) $flag->enabled : true;
        });
    }

    /** تشغيل ميزة — مسموح دائماً. */
    public function enable(User $admin, string $key, ?string $note = null): FeatureFlag
    {
        $flag = FeatureFlag::where('key', $key)->firstOrFail();
        $flag->update([
            'enabled' => true,
            'updated_by_admin_id' => $admin->id,
            'last_note' => $note,
            'disabled_at' => null,
        ]);
        $this->forget($key);

        $this->audit->record([
            'actor_type' => 'admin', 'actor_user_id' => $admin->id,
            'subject_type' => 'user', 'subject_id' => (string) $admin->id,
            'action' => 'FEATURE_ENABLED', 'decision_code' => strtoupper($key),
            'reason' => $note ?: 'تشغيل الميزة', 'severity' => 'notice',
        ]);

        return $flag->fresh();
    }

    /**
     * إيقاف ميزة — مسموح فقط إذا كان تراكمها المالي = صفر.
     * @throws FeatureHasFundsException
     */
    public function disable(User $admin, string $key, ?string $note = null): FeatureFlag
    {
        $flag = FeatureFlag::where('key', $key)->firstOrFail();

        $outstanding = $this->outstandingBalance($key);
        if (bccomp($outstanding['amount'], '0', 4) > 0) {
            throw new FeatureHasFundsException($key, $outstanding['amount'], $outstanding['count']);
        }

        $flag->update([
            'enabled' => false,
            'updated_by_admin_id' => $admin->id,
            'last_note' => $note,
            'disabled_at' => now(),
        ]);
        $this->forget($key);

        $this->audit->record([
            'actor_type' => 'admin', 'actor_user_id' => $admin->id,
            'subject_type' => 'user', 'subject_id' => (string) $admin->id,
            'action' => 'FEATURE_DISABLED', 'decision_code' => strtoupper($key),
            'reason' => $note ?: 'إيقاف الميزة للصيانة', 'severity' => 'warning',
        ]);

        return $flag->fresh();
    }

    /** كل الميزات مع حالتها وتراكمها المالي الحيّ (للوحة). */
    public function overview(): array
    {
        return FeatureFlag::orderBy('category')->orderBy('name_ar')->get()->map(function (FeatureFlag $f) {
            $out = $this->outstandingBalance($f->key);
            return [
                'key' => $f->key,
                'name_ar' => $f->name_ar,
                'description_ar' => $f->description_ar,
                'category' => $f->category,
                'enabled' => (bool) $f->enabled,
                'is_core' => (bool) $f->is_core,
                'has_money_source' => $f->money_source !== null,
                'outstanding_amount' => $out['amount'],
                'outstanding_count' => $out['count'],
                'can_disable' => bccomp($out['amount'], '0', 4) === 0,
                'disabled_at' => $f->disabled_at,
                'last_note' => $f->last_note,
            ];
        })->toArray();
    }

    /**
     * التراكم المالي الحيّ لميزة: مجموع الأموال المحتجزة داخلها وعدد العمليات.
     * الميزات بلا money_source → صفر (تُغلق بحرّية).
     */
    public function outstandingBalance(string $key): array
    {
        $flag = FeatureFlag::where('key', $key)->first();
        $source = $flag?->money_source;
        if (!$source) {
            return ['amount' => '0.0000', 'count' => 0];
        }

        try {
            return match ($source) {
                'safe_payment' => $this->sumWhere(
                    'safe_payments', 'held_amount',
                    fn($q) => $q->whereIn('status', [
                        'funded', 'in_delivery', 'delivered', 'disputed', 'partially_refunded',
                    ])
                ),
                'family_fund' => $this->sumWhere(
                    'family_funds', DB::raw('balance + held_balance'),
                    fn($q) => $q->whereIn('status', ['active', 'frozen']),
                    'balance + held_balance'
                ),
                'donations' => $this->sumWhere(
                    'donations', 'amount',
                    fn($q) => $q->where('status', 'completed') // جُمعت ولم تُسوَّ للجمعية بعد
                ),
                'bill_pay' => $this->sumWhere(
                    'bill_payment_orders', 'amount',
                    fn($q) => $q->whereIn('status', ['pending', 'processing', 'pending_provider_confirmation'])
                ),
                'pending_transfers' => $this->sumWhere(
                    'pending_transfers', 'amount',
                    fn($q) => $q->where('status', 'holding')
                ),
                default => ['amount' => '0.0000', 'count' => 0],
            };
        } catch (\Throwable $e) {
            // فشل القراءة → نُبلّغ "غير صفر" احتياطاً (لا نسمح بإغلاق أعمى)
            return ['amount' => '-1', 'count' => -1];
        }
    }

    private function sumWhere(string $table, $column, callable $filter, ?string $rawExpr = null): array
    {
        $q = DB::table($table);
        $filter($q);
        $count = (clone $q)->count();
        $sum = $rawExpr
            ? (string) ((clone $q)->sum(DB::raw($rawExpr)) ?? '0')
            : (string) ((clone $q)->sum($column) ?? '0');

        return ['amount' => number_format((float) $sum, 4, '.', ''), 'count' => $count];
    }

    private function forget(string $key): void
    {
        Cache::forget(self::CACHE_PREFIX . $key);
    }
}
