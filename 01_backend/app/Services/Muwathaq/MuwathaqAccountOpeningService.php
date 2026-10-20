<?php

namespace App\Services\Muwathaq;

use App\Models\AgentProfile;
use App\Models\EMoney;
use App\Models\MerchantProfile;
use App\Models\MuwathaqAccountOpeningApplication;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * طبقة التوافق المؤقتة بين أميال باي و«موثّق».
 *
 * مصدر قرار فتح الحساب هو الطلب هنا. حقول KYC القديمة لا تزال تُحدَّث عند
 * الاعتماد فقط حتى تعمل حواجز المال القائمة أثناء الانتقال، ولا تُستخدم
 * لاختيار مسار العميل في الواجهة الجديدة.
 */
class MuwathaqAccountOpeningService
{
    public const PATH_STANDARD_CUSTOMER = 'standard_customer';
    public const PATH_WOMEN_PROGRESSIVE = 'women_progressive';
    public const PATH_MERCHANT_SECTOR = 'merchant_sector';
    public const PATH_AGENT_OPERATION = 'agent_operation';

    public function submitFor(User $user, ?string $businessType = null): MuwathaqAccountOpeningApplication
    {
        return DB::transaction(function () use ($user, $businessType): MuwathaqAccountOpeningApplication {
            $existing = MuwathaqAccountOpeningApplication::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            [$kind, $path, $tier, $vertical] = $this->resolvePath($user, $businessType);

            $application = MuwathaqAccountOpeningApplication::query()->create([
                'application_ulid' => (string) Str::ulid(),
                'user_id' => $user->id,
                'subject_kind' => $kind,
                'verification_path' => $path,
                'business_type' => $vertical,
                'requested_tier' => $tier,
                'status' => MuwathaqAccountOpeningApplication::STATUS_SUBMITTED,
                'submitted_at' => now(),
            ]);

            // التسجيل يُنشئ القضية محلياً أولاً ثم يضع رسالةً للإرسال بعد
            // commit. لذلك لا يضيع الطلب إن تعطّل muwathaq.amialpay.com.
            app(MuwathaqBridgeService::class)->recordApplication($application);

            return $application;
        });
    }

    public function receiveDecision(
        string $applicationUlid,
        string $decision,
        string $reference,
        ?string $reason = null,
        array $context = [],
    ): MuwathaqAccountOpeningApplication {
        $allowed = [
            MuwathaqAccountOpeningApplication::STATUS_APPROVED,
            MuwathaqAccountOpeningApplication::STATUS_REJECTED,
            MuwathaqAccountOpeningApplication::STATUS_NEEDS_MORE_INFO,
            MuwathaqAccountOpeningApplication::STATUS_UNDER_REVIEW,
        ];
        if (!in_array($decision, $allowed, true)) {
            throw new DomainException('قرار موثّق غير معروف.');
        }

        return DB::transaction(function () use ($applicationUlid, $decision, $reference, $reason, $context) {
            $application = MuwathaqAccountOpeningApplication::query()
                ->where('application_ulid', $applicationUlid)
                ->lockForUpdate()
                ->first();
            if ($application === null) {
                throw new DomainException('طلب فتح الحساب غير موجود.');
            }

            if ($application->isFinal()) {
                if ($application->decision_reference === $reference && $application->status === $decision) {
                    return $application;
                }
                throw new DomainException('هذا الطلب حُسم سابقاً ولا يقبل قراراً جديداً.');
            }

            $application->status = $decision;
            $application->decision_reference = $reference;
            $application->decision_reason = $reason;
            $application->decision_context = $this->safeContext($context);
            $application->decided_at = in_array($decision, [
                MuwathaqAccountOpeningApplication::STATUS_APPROVED,
                MuwathaqAccountOpeningApplication::STATUS_REJECTED,
            ], true) ? now() : null;
            $application->save();

            if ($decision === MuwathaqAccountOpeningApplication::STATUS_APPROVED) {
                $this->activateApprovedApplication($application);
                $application->refresh();
            }

            return $application;
        });
    }

    /** @return array{0:string,1:string,2:int|null,3:string|null} */
    private function resolvePath(User $user, ?string $businessType): array
    {
        if ((int) $user->type === MERCHANT_TYPE) {
            $vertical = trim((string) ($businessType ?: MerchantProfile::query()
                ->where('user_id', $user->id)->value('business_type')));
            if ($vertical === '') {
                throw new DomainException('قطاع التاجر مطلوب قبل إرسال الطلب إلى موثّق.');
            }
            return ['merchant', self::PATH_MERCHANT_SECTOR, null, $vertical];
        }

        if ((int) $user->type === AGENT_TYPE) {
            return ['agent', self::PATH_AGENT_OPERATION, null, null];
        }

        $gender = strtolower(trim((string) $user->gender));
        if (!in_array($gender, ['male', 'female'], true)) {
            throw new DomainException('يجب تحديد الجنس عند فتح حساب العميل لاختيار مسار التحقق الصحيح.');
        }

        return $gender === 'female'
            ? ['customer', self::PATH_WOMEN_PROGRESSIVE, 1, null]
            : ['customer', self::PATH_STANDARD_CUSTOMER, null, null];
    }

    private function activateApprovedApplication(MuwathaqAccountOpeningApplication $application): void
    {
        $user = User::query()->lockForUpdate()->findOrFail($application->user_id);

        match ($application->subject_kind) {
            'customer' => $this->activateCustomer($user, $application),
            'merchant' => $this->activateMerchant($user, $application),
            'agent' => $this->activateAgent($user, $application),
            default => throw new DomainException('نوع طلب فتح الحساب غير مدعوم.'),
        };

        EMoney::query()->firstOrCreate([
            'user_id' => $user->id,
            'currency' => \App\Support\Money\Currencies::BASE,
        ]);
    }

    private function activateCustomer(User $user, MuwathaqAccountOpeningApplication $application): void
    {
        $context = (array) ($application->decision_context ?? []);
        $governorate = \App\Support\YemenGovernorates::codeFromName(
            isset($context['verified_residence_governorate'])
                ? (string) $context['verified_residence_governorate']
                : null,
        );
        if (($context['verified_phone'] ?? false) !== true || $governorate === null) {
            throw new DomainException(
                'لا يفعّل موثّق حساب العميل قبل تأكيد الهاتف ومحافظة الإقامة الموثقة.'
            );
        }

        // قرار موثّق هو مصدر حقيقة الإثبات. تُكتب هذه الحقول فقط كجسر مؤقت
        // لحواجز المال القائمة، من دون نسخ أي مستند أو رقم هوية إلى أميال باي.
        $user->is_phone_verified = 1;
        $user->residence_governorate = $governorate;
        $user->verified_residence_governorate = $governorate;
        $user->residence_verified_at = now();

        if ($application->verification_path === self::PATH_WOMEN_PROGRESSIVE) {
            // إسقاط توافق فقط للحواجز المالية القديمة؛ المسار الحقيقي محفوظ في الطلب.
            $user->kyc_tier = max(1, (int) ($user->kyc_tier ?? 0));
        } else {
            if (($context['verified_identity'] ?? false) !== true) {
                throw new DomainException('لا يفعّل الحساب القياسي قبل إثبات الهوية من موثّق.');
            }
            // الحساب القياسي للذكر ليس «Tier» في موثّق. القيمة 3 توافق انتقالية
            // للخدمات القديمة التي لا تزال تقرأ الحقل حتى تُنقل إلى هذا المصدر.
            $user->is_kyc_verified = 1;
            $user->kyc_tier = max(3, (int) ($user->kyc_tier ?? 0));
        }
        $user->save();
        app(\App\Services\ZoneAssignmentService::class)->assignFromKyc($user, $governorate);
    }

    private function activateMerchant(User $user, ?MuwathaqAccountOpeningApplication $application = null): void
    {
        $context = (array) ($application?->decision_context ?? []);
        if (($context['verified_owner_identity'] ?? false) !== true
            || ($context['verified_business_requirements'] ?? false) !== true) {
            throw new DomainException('لا يفعّل التاجر قبل توثيق مالك المنشأة ومتطلبات قطاعه من موثّق.');
        }
        $profile = MerchantProfile::query()->where('user_id', $user->id)->lockForUpdate()->first();
        if ($profile === null) {
            throw new DomainException('ملف التاجر غير موجود؛ لا يمكن تفعيل قطاع بلا ملف منشأة.');
        }
        $profile->verification_status = 'verified';
        $profile->verified_at = now();
        $profile->save();
        $user->is_kyc_verified = 1;
        // التسجيل الجديد يخلق مالك المنشأة غير نشط. لا تُفتح لوحة القطاع
        // إلا عند قرار موثّق، ثم يقوم VerticalBootstrap بتقديم قدرات القطاع.
        if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'is_active')) {
            $user->is_active = 1;
        }
        $user->save();
    }

    private function activateAgent(User $user, ?MuwathaqAccountOpeningApplication $application = null): void
    {
        $context = (array) ($application?->decision_context ?? []);
        if (($context['admin_provisioned'] ?? false) !== true
            || ($context['verified_owner_identity'] ?? false) !== true) {
            throw new DomainException('لا يفعّل الوكيل قبل إثبات إنشائه الإداري وتوثيق هويته من موثّق.');
        }
        $profile = AgentProfile::query()->where('user_id', $user->id)->lockForUpdate()->first();
        if ($profile === null) {
            throw new DomainException('ملف الوكيل التشغيلي غير موجود؛ ينشأ الوكيل من الإدارة فقط.');
        }
        $profile->status = 'active';
        $profile->save();
        $user->is_kyc_verified = 1;
        $user->save();
    }

    private function safeContext(array $context): array
    {
        // لا نسمح بتمرير وثائق أو أرقام هوية إلى سجل الربط. يبقى المرجع لدى موثّق.
        return array_filter($context, static fn ($value, $key) => in_array($key, [
            'policy_version', 'reviewer_reference', 'risk_band', 'notes_code',
            'verified_phone', 'verified_residence_governorate',
            'verified_identity', 'verified_owner_identity', 'verified_business_requirements', 'admin_provisioned',
        ], true) && is_scalar($value), ARRAY_FILTER_USE_BOTH);
    }
}
