<?php

namespace App\Services;

use App\Models\InstallmentContract;
use App\Models\InstallmentPlan;
use App\Models\InstallmentSchedule;
use App\Models\User;
use App\Traits\TransactionTrait;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * AMIAL-INSTALLMENTS-001 — البيع بالتقسيط (تمويل مرابحة من المحفظة).
 *
 * الشروط والضمانات العالمية المطبَّقة:
 *   • دفعة أولى (down payment) تُحصَّل فوراً — ضمان الجدّية.
 *   • توثيق هوية العميل (KYC) — ضمان الهوية (اختياري بإعداد التاجر).
 *   • كفيل مسجّل (guarantor) — ضمان إضافي (اختياري).
 *   • سقف تمويل (max_amount) — ضمان حدّ المخاطرة.
 *   • هامش مرابحة (markup) بدل الفائدة — متوافق شرعاً، وقد يكون صفراً.
 *   • رسم تأخير (late fee) على القسط المتأخر بعد أيام السماح.
 *   • المحفظة مصدر السداد — خصم مباشر بقيود مزدوجة.
 */
class InstallmentService
{
    use TransactionTrait;

    public function plan(User $merchant): InstallmentPlan
    {
        return InstallmentPlan::firstOrCreate(
            ['merchant_user_id' => $merchant->id],
            [
                'is_active' => false,
                'min_amount' => '0',
                'max_amount' => '0',
                'down_payment_percent' => '25',
                'durations' => [3, 6, 12],
                'markup_percent' => '0',
                'late_fee_percent' => '0',
                'grace_days' => 3,
                'require_kyc' => true,
                'require_guarantor' => false,
                'zone_code' => $merchant->zone_code ?? 'SOUTH',
            ],
        );
    }

    public function savePlan(User $merchant, array $data): InstallmentPlan
    {
        $p = $this->plan($merchant);
        $durations = $data['durations'] ?? $p->durations ?? [3, 6, 12];
        $durations = array_values(array_unique(array_filter(array_map('intval', (array) $durations), fn ($m) => $m >= 1 && $m <= 60)));
        sort($durations);
        $p->fill([
            'is_active' => (bool) ($data['is_active'] ?? $p->is_active),
            'min_amount' => $data['min_amount'] ?? $p->min_amount,
            'max_amount' => $data['max_amount'] ?? $p->max_amount,
            'down_payment_percent' => $data['down_payment_percent'] ?? $p->down_payment_percent,
            'durations' => $durations ?: [3, 6, 12],
            'markup_percent' => $data['markup_percent'] ?? $p->markup_percent,
            'late_fee_percent' => $data['late_fee_percent'] ?? $p->late_fee_percent,
            'grace_days' => (int) ($data['grace_days'] ?? $p->grace_days),
            'require_kyc' => (bool) ($data['require_kyc'] ?? $p->require_kyc),
            'require_guarantor' => (bool) ($data['require_guarantor'] ?? $p->require_guarantor),
        ]);
        $p->save();
        return $p;
    }

    /** حساب تفاصيل خطة تقسيط (معاينة) لمبلغ ومدّة — بلا أي حركة مالية. */
    public function quote(InstallmentPlan $plan, float $principal, int $months): array
    {
        if (!$plan->is_active) throw new InvalidArgumentException('التقسيط غير مُفعّل لدى هذا التاجر');
        if ($principal <= 0) throw new InvalidArgumentException('السعر يجب أن يكون موجباً');
        if ((float) $plan->min_amount > 0 && $principal < (float) $plan->min_amount) {
            throw new InvalidArgumentException('السعر أقل من الحدّ الأدنى للتقسيط');
        }
        if ((float) $plan->max_amount > 0 && $principal > (float) $plan->max_amount) {
            throw new InvalidArgumentException('السعر يتجاوز سقف التمويل');
        }
        $durations = $plan->durations ?: [3, 6, 12];
        if (!in_array($months, $durations, true)) {
            throw new InvalidArgumentException('مدّة غير متاحة — المتاح: ' . implode('، ', $durations) . ' شهراً');
        }

        $down = round($principal * (float) $plan->down_payment_percent / 100, 2);
        $financed = round($principal - $down, 2);
        $markup = round($financed * (float) $plan->markup_percent / 100, 2);
        $totalPayable = round($financed + $markup, 2);
        $monthly = round($totalPayable / $months, 2);
        // آخر قسط يُصحّح فرق التقريب
        $lastAdjust = round($totalPayable - ($monthly * ($months - 1)), 2);

        return [
            'principal' => round($principal, 2),
            'down_payment' => $down,
            'financed_amount' => $financed,
            'markup_amount' => $markup,
            'total_payable' => $totalPayable,
            'months' => $months,
            'monthly_amount' => $monthly,
            'last_installment' => $lastAdjust,
            'grand_total' => round($down + $totalPayable, 2), // الدفعة الأولى + المموّل مع الهامش
        ];
    }

    /**
     * إنشاء عقد تقسيم: يفحص الأهلية والضمانات، يحصّل الدفعة الأولى فوراً من
     * محفظة العميل إلى التاجر، ثم يولّد جدول الأقساط.
     */
    public function createContract(
        User $merchant,
        User $customer,
        float $principal,
        int $months,
        ?User $guarantor = null,
        ?string $itemName = null,
    ): InstallmentContract {
        $plan = $this->plan($merchant);
        $q = $this->quote($plan, $principal, $months);

        // ---- الضمانات وشروط الأهلية ----
        if ($customer->id === $merchant->id) {
            throw new InvalidArgumentException('لا يمكن التقسيط على التاجر نفسه');
        }
        if ($plan->require_kyc && !($customer->is_kyc_verified ?? false)) {
            throw new RuntimeException('يشترط توثيق هوية العميل (KYC) قبل التقسيط');
        }
        if ($plan->require_guarantor && !$guarantor) {
            throw new RuntimeException('يشترط كفيل مسجّل لهذا التقسيط');
        }

        return DB::transaction(function () use ($merchant, $customer, $guarantor, $plan, $q, $months, $itemName) {
            // 1) تحصيل الدفعة الأولى فوراً (خصم من العميل، إضافة للتاجر) — ضمان الجدّية
            $down = MoneyService::normalize((string) $q['down_payment']);
            if (MoneyService::isPositive($down)) {
                $this->guard()->lockWalletsOrdered([$customer->id, $merchant->id]);
                $this->guard()->debit($customer->id, $down, 'installment_down_payment');
                $this->guard()->credit($merchant->id, $down, 'installment_down_payment');
            }

            $contract = InstallmentContract::create([
                'merchant_user_id' => $merchant->id,
                'customer_user_id' => $customer->id,
                'guarantor_user_id' => $guarantor?->id,
                'item_name' => $itemName,
                'principal' => $q['principal'],
                'down_payment' => $q['down_payment'],
                'financed_amount' => $q['financed_amount'],
                'markup_amount' => $q['markup_amount'],
                'total_payable' => $q['total_payable'],
                'months' => $months,
                'monthly_amount' => $q['monthly_amount'],
                'paid_amount' => '0',
                'status' => 'active',
                'started_at' => now(),
                'zone_code' => $merchant->zone_code ?? 'SOUTH',
            ]);

            // 2) جدول الأقساط الشهرية (آخر قسط يُصحّح التقريب)
            for ($i = 1; $i <= $months; $i++) {
                $amount = $i === $months ? $q['last_installment'] : $q['monthly_amount'];
                InstallmentSchedule::create([
                    'contract_id' => $contract->id,
                    'seq' => $i,
                    'due_date' => now()->addMonths($i)->toDateString(),
                    'amount' => $amount,
                    'status' => 'due',
                ]);
            }

            return $contract->fresh('schedules');
        });
    }

    /**
     * سداد قسط (أو أكثر) من محفظة العميل. يوزّع المبلغ على أقدم الأقساط غير
     * المسدّدة، ويُكمل العقد عند سداد الكل.
     *
     * @return array{paid:string, remaining:string, status:string}
     */
    public function payInstallment(User $customer, InstallmentContract $contract, string $amount): array
    {
        if ($contract->customer_user_id !== $customer->id) {
            throw new InvalidArgumentException('هذا العقد لا يخصّك');
        }
        if ($contract->status !== 'active') {
            throw new RuntimeException('العقد غير نشط');
        }
        $amount = MoneyService::normalize($amount);
        if (!MoneyService::isPositive($amount)) {
            throw new InvalidArgumentException('مبلغ السداد يجب أن يكون موجباً');
        }

        return DB::transaction(function () use ($customer, $contract, $amount) {
            $contract = InstallmentContract::where('id', $contract->id)->lockForUpdate()->first();
            $remaining = MoneyService::sub((string) $contract->total_payable, (string) $contract->paid_amount);
            if (MoneyService::gt($amount, $remaining)) {
                throw new InvalidArgumentException('المبلغ أكبر من المتبقّي (' . $remaining . ')');
            }

            // حرّك المال: خصم من العميل، إضافة للتاجر
            $this->guard()->lockWalletsOrdered([$customer->id, $contract->merchant_user_id]);
            $this->guard()->debit($customer->id, $amount, "installment_pay:{$contract->id}");
            $this->guard()->credit($contract->merchant_user_id, $amount, "installment_pay:{$contract->id}");

            // وزّع على أقدم الأقساط غير المسدّدة
            $left = $amount;
            $schedules = InstallmentSchedule::where('contract_id', $contract->id)
                ->where('status', '!=', 'paid')->orderBy('seq')->lockForUpdate()->get();
            foreach ($schedules as $s) {
                if (!MoneyService::isPositive($left)) break;
                $dueOnRow = MoneyService::sub((string) $s->amount, (string) $s->paid_amount);
                $applied = MoneyService::gt($left, $dueOnRow) ? $dueOnRow : $left;
                $s->paid_amount = MoneyService::normalize(MoneyService::add((string) $s->paid_amount, $applied));
                if (!MoneyService::gt(MoneyService::sub((string) $s->amount, (string) $s->paid_amount), '0')) {
                    $s->status = 'paid';
                    $s->paid_at = now();
                }
                $s->save();
                $left = MoneyService::sub($left, $applied);
            }

            $contract->paid_amount = MoneyService::normalize(MoneyService::add((string) $contract->paid_amount, $amount));
            $newRemaining = MoneyService::sub((string) $contract->total_payable, (string) $contract->paid_amount);
            if (!MoneyService::gt($newRemaining, '0')) {
                $contract->status = 'completed';
                $contract->completed_at = now();
            }
            $contract->save();

            return [
                'paid' => $amount,
                'remaining' => MoneyService::normalize($newRemaining),
                'status' => $contract->status,
            ];
        });
    }
}
