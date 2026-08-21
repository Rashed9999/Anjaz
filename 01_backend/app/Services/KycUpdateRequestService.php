<?php

namespace App\Services;

use App\Models\KycDocument;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-KYC-UPDATE-REQUEST-001 — مصدر التنفيذ الوحيد لطلب تحديث الهوية.
 *
 * لا يترك مسار AML أو الدعم Boolean معزولاً: طلب التحديث يقيّد الوصول
 * المالي، يخفض فئة KYC، ويحفظ طلباً قابلاً للعرض والتدقيق ويُبلغ العميل.
 */
class KycUpdateRequestService
{
    public function __construct(private readonly NotificationService $notifications)
    {
    }

    /** @return array{already_required:bool,previous_tier:int,financial_access_restricted:bool,invalidated_documents:int} */
    public function request(User $customer, ?User $actor, string $source): array
    {
        return DB::transaction(function () use ($customer, $actor, $source) {
            $account = User::query()->lockForUpdate()->findOrFail($customer->id);
            $alreadyRequired = Schema::hasColumn('users', 'kyc_update_required')
                && (int) $account->kyc_update_required === 1;
            // قد تكون العلامة القديمة موجودةً بلا تاريخ قبل هذه الهجرة؛
            // تُعامل كطلبٍ غير مُرحّل مرة واحدة، فلا تبقى وثائقها القديمة
            // صالحةً خطأً. أمّا الطلب الجديد ذو التاريخ فلا نمس وثائق رفعها
            // العميل بعده عند ضغط الموظف للزر ثانية.
            $needsInitialEvidenceReset = ! $alreadyRequired
                || $account->kyc_update_requested_at === null;
            $previousTier = (int) ($account->kyc_tier ?? 0);

            // الحراس القديمة تقرأ is_kyc_verified، وحارس حدود KYC يقرأ
            // kyc_tier. خفض أحدهما وحده يترك باباً مالياً مفتوحاً.
            $changes = [
                'is_kyc_verified' => 0,
                'kyc_tier' => 0,
                'kyc_tier_updated_at' => now(),
            ];
            if (Schema::hasColumn('users', 'kyc_update_required')) {
                $changes += [
                    'kyc_update_required' => 1,
                    'kyc_update_requested_at' => now(),
                    'kyc_update_requested_by' => $actor?->id,
                    'kyc_update_previous_tier' => $previousTier,
                ];
            }
            $account->forceFill($changes)->save();

            // وثائق الاعتماد السابقة لا تثبت تحديثاً جديداً. لو بقيت
            // APPROVED لأمكن ضغط «اعتماد الحساب» فوراً بلا وثيقة جديدة.
            // لا نحذفها: تبقى سجلاً تاريخياً، لكن لا تدخل في الاكتمال.
            // AMIAL-KYC-UPDATE-002 — كان هذا شرطاً ثلاثيّاً **بلا فرعٍ ثانٍ**
            // (`A ? B;`)، فلا يُحلَّل الملفُّ أصلاً: كلُّ مسارٍ يلمس هذه
            // الخدمةَ يسقط بـ`ParseError` عند بناء الحاوية — لا عند الطلب.
            // فصار شرطاً صريحاً يقول ما يفعل ومتى.
            $invalidatedDocuments = 0;

            if ($needsInitialEvidenceReset) {
                $invalidatedDocuments = KycDocument::query()
                    ->where('user_id', $account->id)
                    ->where('status', KycDocument::STATUS_APPROVED)
                    ->update([
                        'status' => KycDocument::STATUS_SUPERSEDED,
                        'rejection_reason' => 'استُبدلت بطلب تحديث هوية جديد',
                    ]);
            }

            if (! $alreadyRequired) {
                $this->notifications->dispatch(
                    $account,
                    'kyc_update_required',
                    'تحديث الهوية مطلوب',
                    'أوقفنا العمليات الحساسة مؤقتاً حتى ترفع وثائق الهوية وتُعتمد.',
                    data: ['source' => $source, 'requested_at' => now()->toIso8601String()],
                );
            }

            return [
                'already_required' => $alreadyRequired,
                'previous_tier' => $previousTier,
                'financial_access_restricted' => true,
                'invalidated_documents' => $invalidatedDocuments,
            ];
        });
    }
}
