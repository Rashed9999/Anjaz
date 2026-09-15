<?php

namespace App\Services;

use App\Models\MerchantProfile;
use App\Models\MerchantVerificationRequest;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * AMIAL-MERCHANT-VERIFY-001 — خدمة توثيق التاجر.
 *
 * Flow:
 *   1) المستخدم يصبح تاجراً غير موثّق (يخلق MerchantProfile بـ verification_status='unverified').
 *   2) submit() — يرفع وثائق + بيانات → ينشئ MerchantVerificationRequest بـ status='pending_review'.
 *   3) Admin يراجع → approve/reject/requestResubmission.
 *   4) عند approve: MerchantProfile.verification_status → 'verified'.
 */
class MerchantVerificationService
{
    /** الوثائق المطلوبة (الإلزامية) للموافقة. */
    public const REQUIRED_DOCS = [
        'id_card_front',
        'id_card_back',
        'commercial_register',
        'store_photo',
    ];

    public const OPTIONAL_DOCS = [
        'address_proof',
        'profession_license',
        'optional_document',
    ];

    public function __construct(
        private readonly NotificationService $notif,
    ) {}

    /** تأكّد أن المستخدم لديه MerchantProfile (يخلقه إن لم يوجد). */
    public function ensureMerchantProfile(User $user): MerchantProfile
    {
        return MerchantProfile::firstOrCreate(
            ['user_id' => $user->id],
            ['verification_status' => 'unverified', 'tier' => 'standard']
        );
    }

    /**
     * المستخدم يقدّم/يحدّث طلب توثيق.
     * إن وُجد طلب سابق pending: يحدّثه. وإلا: ينشئ جديداً.
     */
    public function submit(
        User $merchant,
        array $data,
        array $files = [],
    ): MerchantVerificationRequest {
        $profile = $this->ensureMerchantProfile($merchant);

        if ($profile->verification_status === 'verified') {
            throw new RuntimeException('الحساب موثَّق بالفعل');
        }

        if ($profile->verification_status === 'verification_suspended') {
            throw new RuntimeException('التوثيق موقوف. تواصل مع الإدارة.');
        }

        // التحقّق من البيانات الأساسية
        if (empty($data['business_name'])) {
            throw new InvalidArgumentException('اسم النشاط التجاري مطلوب');
        }

        return DB::transaction(function () use ($merchant, $profile, $data, $files) {
            // ابحث عن طلب نشط (pending_review أو resubmission_required)
            $request = MerchantVerificationRequest::where('merchant_user_id', $merchant->id)
                ->whereIn('status', ['pending_review', 'resubmission_required'])
                ->first();

            // ارفع الملفات وحفظ مساراتها
            $paths = $this->uploadFiles($merchant->id, $files);

            if ($request) {
                // تحديث (في حالة resubmission_required)
                $request->update(array_merge(
                    $this->sanitizeData($data),
                    $paths,
                    ['status' => 'pending_review', 'admin_note' => null],
                ));
            } else {
                $request = MerchantVerificationRequest::create(array_merge([
                    'request_ulid' => (string) Str::ulid(),
                    'merchant_user_id' => $merchant->id,
                    'zone_code' => $merchant->zone_code ?? 'SOUTH',
                    'status' => 'pending_review',
                ], $this->sanitizeData($data), $paths));
            }

            // حدّث الـ MerchantProfile
            $profile->update(['verification_status' => 'pending_review']);

            // إشعار
            $this->notifySubmitted($merchant, $request);

            return $request->fresh();
        });
    }

    /** Admin: موافقة على الطلب. */
    public function approve(MerchantVerificationRequest $request, int $adminId, ?string $tier = null): MerchantVerificationRequest
    {
        if ($request->status !== 'pending_review') {
            throw new RuntimeException('الطلب ليس بانتظار مراجعة');
        }

        return DB::transaction(function () use ($request, $adminId, $tier) {
            $request->update([
                'status' => 'verified',
                'reviewed_by_admin_id' => $adminId,
                'reviewed_at' => now(),
            ]);

            $profile = MerchantProfile::where('user_id', $request->merchant_user_id)->first();
            if ($profile) {
                $updates = ['verification_status' => 'verified'];
                if ($tier && in_array($tier, ['standard', 'premium', 'gold'], true)) {
                    $updates['tier'] = $tier;
                }
                $profile->update($updates);
            }

            // إشعار التاجر
            $merchant = User::find($request->merchant_user_id);
            if ($merchant) {
                // ══════════════════════════════════════════════════════
                // AMIAL-KYC-EVIDENCE-001 — **وثيقةُ النشاط لا توثّق الشخص.**
                //
                // كان هنا `$merchant->is_kyc_verified = 1` مباشرةً، وسببُه
                // مشكلةٌ حقيقيّةٌ مكتوبةٌ في تعليقٍ سابق: تاجرٌ اعتمدته
                // الإدارةُ كان **يبقى محبوساً** لأنّ الحقلَ الذي يقرؤه
                // التطبيقُ لم يتحرّك. **والمشكلةُ صحيحةٌ والعلاجُ كان
                // خطأً**: سجلُّ النشاط التجاريّ (اسمُ المتجر، السجلُّ
                // التجاريّ) **لا يثبت هويّةَ صاحبه**، فمنحُه التوثيقَ
                // الشخصيَّ يفتح سقفَ مالٍ على وثيقةٍ لا تخصّ الشخص.
                //
                // **فصار البابُ واحداً**: يُسأل مصدرُ القرار، فإن اكتمل
                // ملفُّ الهويّة رُفع القفلُ **من خلاله** بتدقيقه ومبدئه
                // الرباعيّ؛ وإن لم يكتمل بقي الحسابُ كما هو **ويُقال
                // للتاجر ما ينقصه** — فلا يبقى محبوساً بلا سببٍ يعرفه،
                // وهو عينُ ما شكا منه التعليقُ السابق.
                // ══════════════════════════════════════════════════════
                $reviewer = User::find($adminId);
                $identity = null;

                if ($reviewer && (int) ($merchant->is_kyc_verified ?? 0) !== 1) {
                    try {
                        app(\App\Services\KycDocumentService::class)
                            ->decideAccountVerification(
                                user: $merchant,
                                reviewer: $reviewer,
                                approve: true,
                            );
                        $merchant->refresh();
                    } catch (\DomainException $e) {
                        // **ولا يُسقِط توثيقَ النشاط**: هما قراران، ونقصُ
                        // الهويّة لا يُلغي اعتمادَ المتجر — يُقال ويُبلَّغ.
                        $identity = $e->getMessage();
                    }
                }

                $this->notif->dispatch(
                    $merchant,
                    'merchant_verified',
                    '✓ تمّ توثيق متجرك',
                    'تهانينا! تمّ توثيق متجر "' . $request->business_name . '" بنجاح.'
                        // **وما ينقص يُقال في الرسالة نفسِها** — تاجرٌ يُبلَّغ
                        // بالتوثيق ثمّ يجد نفسَه محبوساً يفتح تذكرةَ دعم.
                        . ($identity ? "\n\nويبقى توثيقُ هويّتك الشخصيّة: " . $identity : ''),
                    data: [
                        'request_ulid' => $request->request_ulid,
                        'identity_pending' => $identity,
                    ],
                );
            }

            return $request->fresh();
        });
    }

    /** Admin: رفض نهائي. */
    public function reject(MerchantVerificationRequest $request, int $adminId, string $reason): MerchantVerificationRequest
    {
        if ($request->status !== 'pending_review') {
            throw new RuntimeException('الطلب ليس بانتظار مراجعة');
        }
        $request->update([
            'status' => 'rejected',
            'admin_note' => $reason,
            'reviewed_by_admin_id' => $adminId,
            'reviewed_at' => now(),
        ]);
        MerchantProfile::where('user_id', $request->merchant_user_id)
            ->update(['verification_status' => 'rejected']);

        $merchant = User::find($request->merchant_user_id);
        if ($merchant) {
            $this->notif->dispatch(
                $merchant,
                'merchant_verification_rejected',
                'تمّ رفض طلب التوثيق',
                "السبب: {$reason}",
                data: ['request_ulid' => $request->request_ulid],
            );
        }
        return $request->fresh();
    }

    /** Admin: طلب إعادة رفع وثائق. */
    public function requestResubmission(
        MerchantVerificationRequest $request,
        int $adminId,
        string $reason,
    ): MerchantVerificationRequest {
        if ($request->status !== 'pending_review') {
            throw new RuntimeException('الطلب ليس بانتظار مراجعة');
        }
        $request->update([
            'status' => 'resubmission_required',
            'admin_note' => $reason,
            'reviewed_by_admin_id' => $adminId,
            'reviewed_at' => now(),
        ]);
        MerchantProfile::where('user_id', $request->merchant_user_id)
            ->update(['verification_status' => 'resubmission_required']);

        $merchant = User::find($request->merchant_user_id);
        if ($merchant) {
            $this->notif->dispatch(
                $merchant,
                'merchant_resubmission_required',
                'يلزم إعادة رفع وثائق',
                "السبب: {$reason}",
                data: ['request_ulid' => $request->request_ulid],
            );
        }
        return $request->fresh();
    }

    /** الحالة الحالية للتاجر (إن وجدت). */
    public function currentRequest(User $merchant): ?MerchantVerificationRequest
    {
        return MerchantVerificationRequest::where('merchant_user_id', $merchant->id)
            ->orderByDesc('id')
            ->first();
    }

    // ============ Private ============

    private function sanitizeData(array $data): array
    {
        $allowed = [
            'business_name', 'commercial_register_number', 'business_category',
            'city', 'address', 'bank_name', 'bank_account_number',
            'bank_account_holder', 'contact_phone',
        ];
        return array_intersect_key($data, array_flip($allowed));
    }

    /**
     * يرفع الملفات لـ storage/app/private/merchant_verifications/{merchantId}/{ulid}_{type}.
     * يرجّع map من نوع الوثيقة لـ path.
     */
    private function uploadFiles(int $merchantId, array $files): array
    {
        $paths = [];
        $map = [
            'id_card_front' => 'id_card_front_path',
            'id_card_back' => 'id_card_back_path',
            'commercial_register' => 'commercial_register_path',
            'store_photo' => 'store_photo_path',
            'address_proof' => 'address_proof_path',
            'profession_license' => 'profession_license_path',
            'optional_document' => 'optional_document_path',
        ];

        foreach ($map as $key => $column) {
            if (!isset($files[$key]) || !($files[$key] instanceof UploadedFile)) continue;

            $file = $files[$key];
            // تحقّق من MIME (صور و PDF فقط)
            $mime = $file->getMimeType();
            $allowedMimes = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf'];
            if (!in_array($mime, $allowedMimes, true)) {
                throw new InvalidArgumentException("نوع الملف غير مدعوم: {$key}");
            }
            // الحجم 5MB كحد أقصى (وثيقة §5)
            if ($file->getSize() > 5 * 1024 * 1024) {
                throw new InvalidArgumentException("حجم الملف يتجاوز 5MB: {$key}");
            }

            // private storage
            $ext = $file->getClientOriginalExtension() ?: 'bin';
            $filename = (string) Str::ulid() . "_{$key}.{$ext}";
            $path = $file->storeAs("merchant_verifications/{$merchantId}", $filename, 'local');
            $paths[$column] = $path;
        }
        return $paths;
    }

    private function notifySubmitted(User $merchant, MerchantVerificationRequest $request): void
    {
        try {
            $this->notif->dispatch(
                $merchant,
                'merchant_verification_submitted',
                'تم استلام طلب التوثيق',
                'سنراجع طلبك خلال 24-48 ساعة عمل. ستصلك نتيجة المراجعة بإشعار.',
                data: ['request_ulid' => $request->request_ulid],
            );
        } catch (\Throwable $e) {
            logger()->warning('Verification submitted notification failed: ' . $e->getMessage());
        }
    }
}
