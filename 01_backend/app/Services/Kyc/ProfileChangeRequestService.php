<?php

namespace App\Services\Kyc;

use App\Models\User;
use App\Services\AuditService;
use App\Support\Kyc\KycProfileFields;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-PROFILE-CHANGE-002 — **الدعمُ يطلب ويتابع، ولا يكتب.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولمَ ليس زرَّ تعديل:** من يستطيع تغييرَ `identification_number`
 * يستطيع تحويلَ حسابٍ موثَّقٍ إلى شخصٍ آخر ثمّ سحبَ رصيده. **والصلاحيّةُ
 * التي تفعل ذلك لا يجوز أن تكون في يدٍ واحدة** — وهذا ليس تشدّداً: هو
 * المبدأُ الرباعيُّ القائمُ في هذا المشروع على المستندات، يُطبَّق على
 * الحقول أيضاً.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وأربعُ حمايات، لكلٍّ ثمنٌ لو غابت:**
 *
 * ① **لا يعتمد المرءُ طلبَه** — ومن فتح ومن اعتمد لا يكونان واحداً،
 *    وإلّا صار «الطلبُ» زرَّ تعديلٍ بخطوتين.
 *
 * ② **ولا يعتمد المرءُ طلبَ نفسِه** — موظّفو المنصّة عملاءُ فيها أيضاً،
 *    ولهم محافظُ وحدود.
 *
 * ③ **وحقولُ الهويّة تطلب وثيقة** — رقمُ هويّةٍ يتغيّر بلا وثيقةٍ ليس
 *    تحديثاً بل استبدالَ شخص.
 *
 * ④ **والاعتمادُ يُبطل التوثيق حين يمسّ الهويّة** — فمن غيّر رقمَ
 *    هويّته وثيقتُه المعتمَدةُ تخصّ الرقمَ القديم. **وإبقاءُ التوثيق
 *    قائماً على وثيقةٍ لا تخصّ البيانَ الجديد هو التزويرُ بعينه**، ولا
 *    خطأَ في أيّ سجلّ.
 */
class ProfileChangeRequestService
{
    public const STATUS_PENDING_CUSTOMER = 'PENDING_CUSTOMER';
    public const STATUS_PENDING_REVIEW = 'PENDING_REVIEW';
    public const STATUS_APPROVED = 'APPROVED';
    public const STATUS_REJECTED = 'REJECTED';
    public const STATUS_CANCELLED = 'CANCELLED';

    /**
     * الحقولُ التي يجوز طلبُ تغييرها.
     *
     * **قائمةٌ بيضاءُ لا سوداء**: حقلٌ لم يُتوقَّع يُرفض بدل أن يمرّ.
     * فبلا هذا يصير المسارُ باباً لكتابة `is_kyc_verified` أو
     * `zone_code` أو أيِّ عمودٍ في الجدول.
     */
    public const CHANGEABLE = [
        'f_name', 'l_name', 'father_name', 'grandfather_name', 'name_en',
        'email', 'occupation', 'marital_status',
        'address', 'residence_district', 'residence_area',
        'residence_landmark', 'housing_type', 'residence_governorate',
        'employer_name', 'job_title', 'work_address',
        'income_source', 'account_purpose', 'monthly_income',
        'kin_name', 'kin_phone', 'kin_relation',
        'kin2_name', 'kin2_phone', 'kin2_relation',
        'identification_number', 'identification_type',
        'identification_issue_date', 'identification_expiry_date',
        'id_place_of_issue',
    ];

    /**
     * حقولٌ لا تُقبل بلا وثيقةٍ داعمة — وهي التي تُعرّف الشخص.
     */
    public const NEEDS_DOCUMENT = [
        'f_name', 'l_name', 'father_name', 'grandfather_name', 'name_en',
        'identification_number', 'identification_type',
        'identification_issue_date', 'identification_expiry_date',
        'id_place_of_issue',
    ];

    /**
     * حقولٌ يُبطل تغييرُها التوثيقَ القائم.
     *
     * **فالوثيقةُ المعتمَدةُ تخصّ البيانَ القديم**، وإبقاءُ «موثَّق» فوق
     * بيانٍ جديدٍ لم تُراجَع وثيقتُه هو التزويرُ بعينه.
     */
    public const RESETS_VERIFICATION = [
        'f_name', 'l_name', 'father_name', 'grandfather_name', 'name_en',
        'identification_number', 'identification_type',
    ];

    public function __construct(private readonly AuditService $audit)
    {
    }

    /**
     * يفتح طلباً — من الدعم نيابةً، أو من العميل لنفسه.
     */
    public function open(
        User $subject,
        string $field,
        ?int $openedBy,
        string $openedByType = 'customer',
        ?string $reason = null,
    ): int {
        if (! in_array($field, self::CHANGEABLE, true)) {
            // **قائمةٌ بيضاء** — انظر شرحَ الثابت.
            throw new DomainException('حقلٌ لا يُطلب تغييرُه من هذا المسار: ' . $field);
        }

        // **ولا يُفتح طلبان لحقلٍ واحد** — فيتعارضان، ويعتمد مراجعان
        // قيمتين مختلفتين في دقيقة.
        $open = DB::table('profile_change_requests')
            ->where('user_id', $subject->id)
            ->where('field', $field)
            ->whereIn('status', [self::STATUS_PENDING_CUSTOMER, self::STATUS_PENDING_REVIEW])
            ->exists();

        if ($open) {
            throw new DomainException('يوجد طلبٌ مفتوحٌ لهذا الحقل — يُغلق قبل فتح غيره');
        }

        $id = DB::table('profile_change_requests')->insertGetId([
            'user_id' => $subject->id,
            'opened_by' => $openedBy,
            'opened_by_type' => $openedByType,
            'field' => $field,
            // **«قبل» تُلتقط الآن لا عند القرار** — فالسجلُّ يجيب عن
            // وقتِه لا عن وقتِ قراءته.
            'old_value' => $this->readable($subject, $field),
            'new_value' => null,
            'reason' => $reason === null ? null : mb_substr(trim($reason), 0, 500),
            'status' => self::STATUS_PENDING_CUSTOMER,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->audit->record([
            'actor_type' => $openedByType === 'admin' ? 'admin' : 'customer',
            'actor_user_id' => $openedBy,
            'subject_type' => 'user',
            'subject_id' => (string) $subject->id,
            'action' => 'PROFILE_CHANGE_REQUESTED',
            'decision_code' => 'OPENED',
            'reason' => $reason,
            'severity' => 'info',
            'context' => ['field' => $field, 'request_id' => $id],
        ]);

        return $id;
    }

    /**
     * العميلُ يملأ القيمةَ الجديدة — **ولا يملؤها الموظّف**.
     */
    public function submit(int $requestId, User $actor, string $newValue,
        ?int $supportingDocumentId = null): void
    {
        $row = $this->find($requestId);

        if ((int) $row->user_id !== (int) $actor->id) {
            // **والقيمةُ من صاحبها لا من غيره** — وإلّا فالطلبُ ثوبٌ
            // لزرّ التعديل نفسِه.
            throw new DomainException('القيمةُ الجديدةُ يملؤها صاحبُ الحساب وحدَه');
        }

        if ($row->status !== self::STATUS_PENDING_CUSTOMER) {
            throw new DomainException('الطلبُ ليس في طور انتظار العميل');
        }

        if (trim($newValue) === '') {
            throw new DomainException('القيمةُ الجديدةُ فارغة');
        }

        if (in_array($row->field, self::NEEDS_DOCUMENT, true) && $supportingDocumentId === null) {
            throw new DomainException(
                'هذا الحقلُ يُعرّف الشخصَ — يلزم رفعُ وثيقةٍ داعمةٍ قبل الإرسال');
        }

        DB::table('profile_change_requests')->where('id', $requestId)->update([
            'new_value' => mb_substr(trim($newValue), 0, 500),
            'supporting_document_id' => $supportingDocumentId,
            'status' => self::STATUS_PENDING_REVIEW,
            'updated_at' => now(),
        ]);
    }

    /**
     * مراجعٌ يعتمد أو يرفض — **وليس من فتح، ولا صاحبُ الحساب**.
     */
    public function decide(int $requestId, User $reviewer, bool $approve,
        ?string $reason = null): void
    {
        $row = $this->find($requestId);

        if ($row->status !== self::STATUS_PENDING_REVIEW) {
            throw new DomainException('الطلبُ ليس بانتظار المراجعة');
        }

        if ((int) $row->user_id === (int) $reviewer->id) {
            throw new DomainException('FOUR_EYES_VIOLATION');
        }

        // **ومن فتح لا يعتمد** — وإلّا صار «الطلبُ» زرَّ تعديلٍ بخطوتين.
        if ($row->opened_by !== null && (int) $row->opened_by === (int) $reviewer->id) {
            throw new DomainException('FOUR_EYES_VIOLATION');
        }

        if (! $approve && mb_strlen(trim((string) $reason)) < 5) {
            // رفضٌ بلا سببٍ يجعل العميلَ يعيد الطلبَ نفسَه مرّةً بعد مرّة.
            throw new DomainException('سبب الرفض مطلوب وواضح للعميل');
        }

        DB::transaction(function () use ($row, $reviewer, $approve, $reason, $requestId) {
            $account = User::query()->lockForUpdate()->findOrFail($row->user_id);

            if ($approve) {
                if (Schema::hasColumn('users', $row->field)) {
                    $account->{$row->field} = $row->new_value;
                }

                // **والاعتمادُ يُبطل التوثيقَ حين يمسّ الهويّة.**
                //
                // الوثيقةُ المعتمَدةُ تخصّ البيانَ القديم، وإبقاءُ
                // «موثَّق» فوق بيانٍ جديدٍ لم تُراجَع وثيقتُه هو التزويرُ
                // بعينه — ولا خطأَ في أيّ سجلّ.
                if (in_array($row->field, self::RESETS_VERIFICATION, true)
                    && Schema::hasColumn('users', 'kyc_update_required')) {
                    $account->kyc_update_required = 1;
                    $account->kyc_update_requested_at = now();
                }

                $account->save();
            }

            DB::table('profile_change_requests')->where('id', $requestId)->update([
                'status' => $approve ? self::STATUS_APPROVED : self::STATUS_REJECTED,
                'decided_by' => $reviewer->id,
                'decided_at' => now(),
                'decision_reason' => $reason === null ? null : mb_substr(trim($reason), 0, 500),
                'updated_at' => now(),
            ]);

            $this->audit->record([
                'actor_type' => 'admin',
                'actor_user_id' => $reviewer->id,
                'subject_type' => 'user',
                'subject_id' => (string) $account->id,
                'action' => 'PROFILE_CHANGE_DECIDED',
                'decision_code' => $approve ? 'APPROVED' : 'REJECTED',
                'reason' => $reason,
                // تغييرُ بيانٍ في ملفٍّ موثَّقٍ حدثٌ يُراجَع لا معلومة.
                'severity' => 'critical',
                'context' => [
                    'request_id' => $requestId,
                    'field' => $row->field,
                    'old_value' => $row->old_value,
                    'new_value' => $row->new_value,
                    'reset_verification' => $approve
                        && in_array($row->field, self::RESETS_VERIFICATION, true),
                ],
            ]);
        });
    }

    /** صاحبُ الحساب يسحب طلبَه. */
    public function cancel(int $requestId, User $actor): void
    {
        $row = $this->find($requestId);

        if ((int) $row->user_id !== (int) $actor->id
            && (int) ($row->opened_by ?? 0) !== (int) $actor->id) {
            throw new DomainException('لا يُلغى طلبُ غيرِك');
        }

        if (! in_array($row->status, [self::STATUS_PENDING_CUSTOMER, self::STATUS_PENDING_REVIEW], true)) {
            throw new DomainException('الطلبُ محسومٌ ولا يُلغى');
        }

        DB::table('profile_change_requests')->where('id', $requestId)
            ->update(['status' => self::STATUS_CANCELLED, 'updated_at' => now()]);
    }

    /** الطابورُ — الأقدمُ أوّلاً، فالانتظارُ هو ما يُشتكى منه. */
    public function pendingQueue(int $limit = 100): array
    {
        return DB::table('profile_change_requests as r')
            ->leftJoin('users as u', 'u.id', '=', 'r.user_id')
            ->where('r.status', self::STATUS_PENDING_REVIEW)
            ->orderBy('r.created_at')
            ->limit($limit)
            ->get([
                'r.id', 'r.field', 'r.old_value', 'r.new_value', 'r.reason',
                'r.created_at', 'r.opened_by', 'r.supporting_document_id',
                'u.f_name', 'u.l_name', 'u.phone',
            ])->map(fn ($r) => (array) $r)->all();
    }

    private function find(int $id): object
    {
        $row = DB::table('profile_change_requests')->find($id);

        if ($row === null) {
            throw new DomainException('طلبٌ غيرُ موجود');
        }

        return $row;
    }

    /**
     * القيمةُ الحاليّةُ نصّاً — **ولا تُقرأ من عمودٍ لا يوجد**.
     */
    private function readable(User $user, string $field): ?string
    {
        if (! Schema::hasColumn('users', $field)) {
            return null;
        }

        $v = $user->{$field};

        return $v === null ? null : mb_substr((string) $v, 0, 500);
    }

    /** الحقولُ التي يعرفها هذا المسارُ ويعرفها جردُ KYC معاً. */
    public static function kycFieldsCovered(): array
    {
        return array_values(array_intersect(
            self::CHANGEABLE, KycProfileFields::TEXT_FIELDS,
        ));
    }
}
