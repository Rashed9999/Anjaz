<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Services\Kyc\IdentityExpiryService;
use App\Services\Kyc\ProfileChangeRequestService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-PROFILE-CHANGE-005 — **الطرفُ الناقص: مكانٌ يملأ فيه العميل.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وهذا بعينه العطلُ الذي عالجه `AMIAL-KYC-DOCS-001` في المستندات:**
 * زرٌّ في لوحة الدعم يضع علامةً على المستخدم، **ولا مكانَ يستجيب فيه**.
 * الزرُّ يعمل والعميلُ ينتظر ما لن يأتي.
 *
 * فبلا هذه النقاط يكون «طلبُ تحديث البيانات» نصفَ ميزة: يُفتح الطلبُ من
 * اللوحة ويبقى `PENDING_CUSTOMER` إلى الأبد — **لأنّ العميلَ لا يراه**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والحمايةُ كلُّها في الخدمة لا ها هنا.** هذا المتحكّمُ ينقل ويترجم:
 * صاحبُ الحساب وحدَه يملأ، والحقولُ من قائمةٍ بيضاء، وحقولُ الهويّة تلزمها
 * وثيقة — **والثلاثةُ مفروضةٌ في `ProfileChangeRequestService`**، فمسارٌ
 * ثانٍ يُضاف غداً يرثها ولا يُعيد كتابتها. (وحمايةٌ في المتحكّم وحدَه
 * تُنسى عند أوّل مدخلٍ ثانٍ — القاعدة الرابعة.)
 */
class ProfileChangeController extends Controller
{
    public function __construct(
        private readonly ProfileChangeRequestService $requests,
        private readonly IdentityExpiryService $expiry,
    ) {
    }

    /**
     * طلباتي — **ومعها حالةُ هويّتي**.
     *
     * فالشاشةُ الواحدةُ تجيب عن السؤالين اللذين يأتي بهما العميل: «ماذا
     * يُطلب منّي؟» و«متى تنتهي هويّتي؟». وشاشتان لسؤالين متلازمين تجعل
     * أحدَهما لا يُرى.
     */
    public function mine(Request $request): JsonResponse
    {
        $user = $request->user();

        $rows = DB::table('profile_change_requests')
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get([
                'id', 'field', 'old_value', 'new_value', 'reason', 'status',
                'decision_reason', 'created_at', 'decided_at',
                'supporting_document_id',
            ]);

        return response()->json([
            'success' => true,
            'data' => [
                'requests' => $rows->map(fn ($r) => [
                    'id' => (int) $r->id,
                    'field' => $r->field,
                    'field_label' => self::LABELS[$r->field] ?? $r->field,
                    // **ورمزٌ بلا ترجمةٍ يُعرَض خاماً ويُوسَم** — وترجمةٌ
                    // مخترَعةٌ أسوأ من رمزٍ إنجليزيّ: الإنجليزيُّ يُوقف
                    // القارئَ ليسأل، والمخترَعةُ تُمرّره واثقاً من معنىً
                    // لم يقصده أحد.
                    'has_label' => isset(self::LABELS[$r->field]),
                    'old_value' => $r->old_value,
                    'new_value' => $r->new_value,
                    'reason' => $r->reason,
                    'status' => $r->status,
                    'decision_reason' => $r->decision_reason,
                    'needs_document' => in_array(
                        $r->field, ProfileChangeRequestService::NEEDS_DOCUMENT, true),
                    'resets_verification' => in_array(
                        $r->field, ProfileChangeRequestService::RESETS_VERIFICATION, true),
                    'created_at' => $r->created_at,
                    'decided_at' => $r->decided_at,
                ])->all(),

                // **وحالةُ الهويّة تُقال للعميل لا للمراجع وحدَه.** من
                // لا يعرف أنّ هويّته تنتهي بعد شهرٍ لا يستخرج بديلاً —
                // ووثيقةٌ رسميّةٌ في اليمن تُستخرج في أسابيع.
                'identity' => $this->expiry->stateOf($user),
            ],
            'errors' => (object) [], 'meta' => (object) [],
        ]);
    }

    /**
     * العميلُ يفتح طلباً لنفسه — بلا وسيط.
     *
     * فليس كلُّ تحديثٍ يبدأ بمكالمةٍ للدعم: من انتقل سكنُه يُبلّغ من
     * التطبيق. **وميزةٌ لا تُبلَغ إلّا عبر موظّفٍ تُستعمَل عُشرَ ما تُستعمل.**
     */
    public function open(Request $request): JsonResponse
    {
        $data = $request->validate([
            'field' => ['required', 'string', 'max:60'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        try {
            $id = $this->requests->open(
                $request->user(), $data['field'],
                (int) $request->user()->id, 'customer', $data['reason'],
            );
        } catch (DomainException $e) {
            return $this->refuse($e);
        }

        return response()->json([
            'success' => true,
            'data' => ['request_id' => $id],
            'errors' => (object) [], 'meta' => (object) [],
        ], 201);
    }

    /** القيمةُ الجديدة — **من صاحبها وحدَه**، والخدمةُ تفرض ذلك. */
    public function submit(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'new_value' => ['required', 'string', 'max:500'],
            'supporting_document_id' => ['nullable', 'integer'],
        ]);

        try {
            $this->requests->submit(
                $id, $request->user(), $data['new_value'],
                $data['supporting_document_id'] ?? null,
            );
        } catch (DomainException $e) {
            return $this->refuse($e);
        }

        return response()->json([
            'success' => true,
            'data' => ['status' => ProfileChangeRequestService::STATUS_PENDING_REVIEW],
            'errors' => (object) [], 'meta' => (object) [],
        ]);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        try {
            $this->requests->cancel($id, $request->user());
        } catch (DomainException $e) {
            return $this->refuse($e);
        }

        return response()->json([
            'success' => true, 'data' => (object) [],
            'errors' => (object) [], 'meta' => (object) [],
        ]);
    }

    /** الحقولُ التي يجوز للعميل طلبُ تغييرها — **تُسأل ولا تُكتب في التطبيق**. */
    public function fields(): JsonResponse
    {
        // **وقائمةٌ مكتوبةٌ في التطبيق تشيخ**: يُضاف حقلٌ في الخادم فلا
        // يظهر في الشاشة أبداً، ويُحذف آخرُ فيبقى معروضاً ويُرفض عند
        // الإرسال. فتُسأل من مصدرها.
        return response()->json([
            'success' => true,
            'data' => array_map(fn ($f) => [
                'field' => $f,
                'label' => self::LABELS[$f] ?? $f,
                'has_label' => isset(self::LABELS[$f]),
                'needs_document' => in_array(
                    $f, ProfileChangeRequestService::NEEDS_DOCUMENT, true),
                'resets_verification' => in_array(
                    $f, ProfileChangeRequestService::RESETS_VERIFICATION, true),
            ], ProfileChangeRequestService::CHANGEABLE),
            'errors' => (object) [], 'meta' => (object) [],
        ]);
    }

    /** رفضٌ يقول سببَه — ورسالةٌ لا تدلّ عليه تُنتج تذكرةَ دعمٍ لا إجراء. */
    private function refuse(DomainException $e): JsonResponse
    {
        return response()->json([
            'success' => false,
            'code' => 'PROFILE_CHANGE_REFUSED',
            'message' => $e->getMessage(),
            'errors' => (object) [], 'meta' => (object) [],
        ], 422);
    }

    /**
     * معجمُ الحقول — **ولا يُخترَع معنى**.
     *
     * ما ليس فيه يُعرَض برمزه ويُوسَم `has_label = false`، فيراه القارئُ
     * ويسأل. (وهو قرارُ سجلّ التدقيق نفسُه، مطبَّقٌ ها هنا.)
     */
    private const LABELS = [
        'f_name' => 'الاسم الأوّل',
        'l_name' => 'اسم العائلة',
        'father_name' => 'اسم الأب',
        'grandfather_name' => 'اسم الجدّ',
        'name_en' => 'الاسم بالإنجليزيّة',
        'email' => 'البريد الإلكترونيّ',
        'occupation' => 'المهنة',
        'marital_status' => 'الحالة الاجتماعيّة',
        'address' => 'العنوان',
        'residence_district' => 'المديريّة',
        'residence_area' => 'الحيّ / العزلة',
        'residence_landmark' => 'أقرب معلم',
        'housing_type' => 'نوع السكن',
        'residence_governorate' => 'محافظة السكن',
        'employer_name' => 'جهة العمل',
        'job_title' => 'المسمّى الوظيفيّ',
        'work_address' => 'عنوان العمل',
        'income_source' => 'مصدر الدخل',
        'account_purpose' => 'الغرض من الحساب',
        'monthly_income' => 'الدخل الشهريّ',
        'kin_name' => 'اسم الشخص القريب',
        'kin_phone' => 'هاتف الشخص القريب',
        'kin_relation' => 'صلة القرابة',
        'kin2_name' => 'اسم الشخص الثاني',
        'kin2_phone' => 'هاتف الشخص الثاني',
        'kin2_relation' => 'صلة القرابة (الثاني)',
        'identification_number' => 'رقم الهويّة',
        'identification_type' => 'نوع الهويّة',
        'identification_issue_date' => 'تاريخ إصدار الهويّة',
        'identification_expiry_date' => 'تاريخ انتهاء الهويّة',
        'id_place_of_issue' => 'مكان إصدار الهويّة',
    ];
}
