<?php

namespace App\Services\Merchant;

use App\Models\User;
use App\Services\AuditService;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-MERCHANT-APPROVAL-002 — **إذنُ مديرٍ لمرّةٍ واحدة.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * يُحيي عمودَ `approval` الذي كان يُكتب ولا يقرؤه أحد، فيصير الفصلُ بين
 * المُعِدّ والمعتمِد **قابلاً للتنفيذ** بدل أن يكون شللاً.
 *
 * **والموظّفُ هو من ينفّذ** — الإذنُ يُمنح ولا يُنفَّذ عنه. فيبقى «من فعل»
 * صادقاً في سجلّ التدقيق، ويُقيَّد معه «من أذن».
 */
class MerchantOverrideService
{
    /** ساعتان — والإذنُ لورديّةٍ لا ليوم. */
    public const EXPIRY_HOURS = 2;

    public function __construct(
        private readonly MerchantPermissionService $perm,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * يطلب موظّفٌ إذناً لفعلٍ يحتاج اعتماداً.
     *
     * @throws DomainException إن كان لا يملك الفعلَ أصلاً
     */
    public function request(User $staff, string $permission, string $reason,
        ?string $amount = null): int
    {
        if (trim($reason) === '') {
            throw new DomainException('سببُ الطلب مطلوب');
        }

        // **ولا يُطلَب إذنٌ لِما لا يُملَك أصلاً.** الإذنُ يرفع قيدَ
        // الاعتماد عن صلاحيّةٍ ممنوحة — **ولا يمنح صلاحيّةً غيرَ ممنوحة**.
        // وبلا هذا يصير بابَ تصعيدٍ: يطلب الكاشيرُ إذناً بفعلٍ لا يملكه
        // فيمنحه مديرٌ لا ينتبه.
        if (! $this->perm->can($staff, $permission)) {
            throw new DomainException('هذا الإجراء خارج صلاحيّاتك — والإذنُ '
                . 'يرفع قيدَ الاعتماد ولا يمنح صلاحيّةً جديدة');
        }

        $merchantId = $this->perm->merchantIdFor($staff);
        $now = now();

        $id = (int) DB::table('merchant_permission_overrides')->insertGetId([
            'merchant_user_id' => $merchantId,
            'requested_by_user_id' => $staff->id,
            'permission_code' => $permission,
            'max_amount' => $amount,
            'reason' => mb_substr($reason, 0, 1000),
            'status' => 'pending',
            'expires_at' => $now->copy()->addHours(self::EXPIRY_HOURS),
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $this->audit->record([
            'actor_type' => 'merchant_staff',
            'actor_user_id' => $staff->id,
            'subject_type' => 'merchant',
            'subject_id' => (string) $merchantId,
            'action' => 'MERCHANT_OVERRIDE_REQUESTED',
            'decision_code' => 'PENDING',
            'reason' => $reason,
            'severity' => 'notice',
            'context' => ['permission' => $permission, 'amount' => $amount,
                'override_id' => $id],
        ]);

        return $id;
    }

    /**
     * يمنح مديرٌ الإذن.
     *
     * @throws DomainException SELF_APPROVAL · NOT_PENDING · EXPIRED · NOT_AUTHORISED
     */
    public function grant(User $manager, int $id, ?string $note = null): void
    {
        DB::transaction(function () use ($manager, $id, $note) {
            $row = DB::table('merchant_permission_overrides')
                ->where('id', $id)->lockForUpdate()->first();

            if ($row === null) {
                throw new DomainException('الطلب غير موجود');
            }

            // **لا يعتمد أحدٌ لنفسه** — وهو الفصلُ بعينه، لا تفصيلاً فيه.
            if ((int) $row->requested_by_user_id === (int) $manager->id) {
                throw new DomainException('لا يعتمد أحدٌ طلبَ نفسِه');
            }

            // **ومن يأذن يملك ما يأذن به.** كاشيرٌ لا يعتمد لكاشير.
            // ويُقاس بالصلاحيّة نفسِها بلا قيد الاعتماد: من يملكها مطلقةً
            // هو المدير.
            if (! $this->grantsWithoutApproval($manager, $row->permission_code)) {
                throw new DomainException('لا تملك سلطةَ الإذن بهذا الإجراء');
            }

            // **وداخل المنشأة نفسِها** — القاعدة الثامنة: الهويّة تحدّد
            // النطاق، ومعرِّفُ طلبٍ يأتي من الطلب لا يُوثَق به.
            if ((int) $this->perm->merchantIdFor($manager) !== (int) $row->merchant_user_id) {
                throw new DomainException('الطلب يخصّ منشأةً أخرى');
            }

            if ($row->status !== 'pending') {
                throw new DomainException('الطلب لم يعد معلَّقاً');
            }

            if (strtotime((string) $row->expires_at) < time()) {
                DB::table('merchant_permission_overrides')->where('id', $id)
                    ->update(['status' => 'expired', 'decided_at' => now(),
                        'updated_at' => now()]);

                throw new DomainException('انتهت صلاحيّةُ الطلب');
            }

            DB::table('merchant_permission_overrides')->where('id', $id)->update([
                'status' => 'granted',
                'granted_by_user_id' => $manager->id,
                'decision_note' => $note === null ? null : mb_substr($note, 0, 500),
                'decided_at' => now(), 'updated_at' => now(),
            ]);
        });

        $this->audit->record([
            'actor_type' => 'merchant_staff',
            'actor_user_id' => $manager->id,
            'subject_type' => 'merchant_override',
            'subject_id' => (string) $id,
            'action' => 'MERCHANT_OVERRIDE_GRANTED',
            'decision_code' => 'GRANTED',
            'reason' => $note ?? 'أُذن',
            'severity' => 'warning',
        ]);
    }

    public function reject(User $manager, int $id, string $note): void
    {
        if (trim($note) === '') {
            throw new DomainException('سببُ الرفض مطلوب');
        }

        $affected = DB::table('merchant_permission_overrides')
            ->where('id', $id)->where('status', 'pending')
            ->where('requested_by_user_id', '!=', $manager->id)
            ->update(['status' => 'rejected', 'granted_by_user_id' => $manager->id,
                'decision_note' => mb_substr($note, 0, 500),
                'decided_at' => now(), 'updated_at' => now()]);

        if ($affected === 0) {
            throw new DomainException('الطلب لم يعد معلَّقاً');
        }

        $this->audit->record([
            'actor_type' => 'merchant_staff',
            'actor_user_id' => $manager->id,
            'subject_type' => 'merchant_override',
            'subject_id' => (string) $id,
            'action' => 'MERCHANT_OVERRIDE_REJECTED',
            'decision_code' => 'REJECTED',
            'reason' => $note,
            'severity' => 'notice',
        ]);
    }

    /**
     * **يستهلك** إذناً صالحاً إن وُجد — ويُرجع `true` إن استُهلك.
     *
     * ولا يُنادى إلّا لحظةَ التنفيذ: **إذنٌ يُقرأ ولا يُستهلَك إذنٌ دائم**،
     * فيُنفَّذ به مرّتان.
     */
    public function consume(User $staff, string $permission, ?string $amount = null): bool
    {
        return (bool) DB::transaction(function () use ($staff, $permission, $amount) {
            $row = DB::table('merchant_permission_overrides')
                ->where('requested_by_user_id', $staff->id)
                ->where('permission_code', $permission)
                ->where('status', 'granted')
                ->where('expires_at', '>', now())
                ->orderBy('id')          // الأقدمُ أوّلاً — لا يُترَك ليموت
                ->lockForUpdate()->first();

            if ($row === null) {
                return false;
            }

            // **والسقفُ يُقارَن بما يُنفَّذ لا بما طُلب.**
            if ($row->max_amount !== null && $amount !== null
                && bccomp($amount, (string) $row->max_amount, 4) > 0) {
                return false;
            }

            // **ولا يُستهلَك إذنٌ بلا سقفٍ لمبلغٍ كبير.** إذنٌ طُلب لفعلٍ
            // بلا مبلغ لا يُجيز فعلاً بمبلغ: الآذنُ لم يرَ رقماً.
            if ($row->max_amount === null && $amount !== null) {
                return false;
            }

            DB::table('merchant_permission_overrides')->where('id', $row->id)->update([
                'status' => 'consumed',
                'consumed_at' => now(),
                'consumed_amount' => $amount,
                'updated_at' => now(),
            ]);

            return true;
        });
    }

    /** أيملك هذا المستخدمُ الصلاحيّةَ **بلا قيد اعتماد**؟ */
    private function grantsWithoutApproval(User $user, string $permission): bool
    {
        if ($this->perm->isOwner($user)) {
            return true;
        }

        $r = $this->perm->evaluate($user, $permission);

        return $r['allowed'] && $r['approval'] === 'none';
    }
}
