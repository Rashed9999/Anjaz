<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Models\MuwathaqAccountOpeningApplication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * واجهة موثّق الداخلية فقط.
 *
 * مصدر حقيقة الحساب والحالة التشغيلية يبقى أميال باي. موثّق يقرأ دليلاً
 * محدوداً للمراجعة ويرسل أحداث التدقيق عبر نفس قناة HMAC الموقعة.
 */
final class MuwathaqOperationsController extends Controller
{
    public function directory(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'list' => 'nullable|in:all,new,review,customer_female,customer_male,merchant,agent,active,suspended',
            'q' => 'nullable|string|max:80',
            'limit' => 'nullable|integer|min:1|max:200',
        ]);
        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $list = (string) $request->query('list', 'all');

        // تختار لكل حساب آخر طلب فتح فقط. مصدر حقيقة الحساب هو users؛
        // طلب موثّق يضيف حالة المراجعة ولا يستبعد الحسابات التشغيلية القديمة.
        $latestOpening = DB::table('muwathaq_account_opening_applications')
            ->selectRaw('user_id, MAX(id) AS id')
            ->groupBy('user_id');

        $query = DB::table('users as user')
            ->leftJoinSub($latestOpening, 'latest_opening', static function ($join): void {
                $join->on('latest_opening.user_id', '=', 'user.id');
            })
            ->leftJoin('muwathaq_account_opening_applications as opening', 'opening.id', '=', 'latest_opening.id')
            ->whereIn('user.type', [1, 2, 3])
            ->select([
                'opening.application_ulid',
                'opening.subject_kind',
                'opening.verification_path',
                'opening.business_type',
                'opening.requested_tier',
                'opening.status as opening_status',
                'opening.submitted_at',
                'opening.muwathaq_decided_at',
                'opening.admin_decided_at',
                'user.id as user_id',
                'user.type as user_type',
                'user.f_name',
                'user.father_name',
                'user.family_name',
                'user.l_name',
                'user.declared_legal_name',
                'user.phone',
                'user.email',
                'user.gender',
                'user.is_active',
            ]);

        match ($list) {
            'new' => $query->where('opening.status', MuwathaqAccountOpeningApplication::STATUS_SUBMITTED),
            'review' => $query->whereIn('opening.status', [
                MuwathaqAccountOpeningApplication::STATUS_SUBMITTED,
                MuwathaqAccountOpeningApplication::STATUS_UNDER_REVIEW,
                MuwathaqAccountOpeningApplication::STATUS_NEEDS_MORE_INFO,
                MuwathaqAccountOpeningApplication::STATUS_AWAITING_ADMIN_APPROVAL,
            ]),
            'customer_female' => $query->where('user.type', 2)->whereRaw('LOWER(user.gender) = ?', ['female']),
            'customer_male' => $query->where('user.type', 2)->whereRaw('LOWER(user.gender) = ?', ['male']),
            'merchant' => $query->where('user.type', 3),
            'agent' => $query->where('user.type', 1),
            'active' => $query->where(function ($builder): void {
                $builder->where(function ($approved): void {
                    $approved->where('opening.status', MuwathaqAccountOpeningApplication::STATUS_APPROVED)
                        ->where(function ($active): void {
                            $active->whereNull('user.is_active')->orWhere('user.is_active', 1);
                        });
                })->orWhere(function ($legacy): void {
                    $legacy->whereNull('opening.id')
                        ->where(function ($active): void {
                            $active->whereNull('user.is_active')->orWhere('user.is_active', 1);
                        });
                });
            }),
            'suspended' => $query->where('user.is_active', 0),
            default => null,
        };

        $needle = trim((string) $request->query('q', ''));
        if ($needle !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $needle).'%';
            $query->where(function ($builder) use ($like): void {
                $builder->where('opening.application_ulid', 'like', $like)
                    ->orWhere('user.phone', 'like', $like)
                    ->orWhere('user.email', 'like', $like)
                    ->orWhere('user.declared_legal_name', 'like', $like)
                    ->orWhere('user.f_name', 'like', $like);
            });
        }

        $rows = $query->orderByDesc('opening.submitted_at')
            ->orderByDesc('user.id')
            ->limit((int) $request->query('limit', 100))
            ->get()
            ->map(static function ($row): array {
                $name = trim((string) ($row->declared_legal_name ?: implode(' ', array_filter([
                    $row->f_name, $row->father_name, $row->family_name ?: $row->l_name,
                ]))));
                $state = $row->opening_status ? (string) $row->opening_status : 'legacy';
                $operational = (int) $row->is_active === 0
                    ? 'suspended'
                    : ($state === 'legacy' || $state === MuwathaqAccountOpeningApplication::STATUS_APPROVED ? 'active' : 'pending');
                $accountType = (string) ($row->subject_kind ?: match ((int) $row->user_type) {
                    1 => 'agent',
                    3 => 'merchant',
                    default => 'customer',
                });

                return [
                    'application_ulid' => $row->application_ulid,
                    'account_type' => $accountType,
                    'customer_gender' => $row->gender,
                    'verification_path' => $row->verification_path,
                    'business_type' => $row->business_type,
                    'requested_tier' => $row->requested_tier,
                    'opening_status' => $state,
                    'operational_status' => $operational,
                    'applicant' => [
                        'name' => $name !== '' ? $name : '—',
                        'phone' => $row->phone,
                        'email' => $row->email,
                    ],
                    'submitted_at' => optional($row->submitted_at)->toIso8601String(),
                    'muwathaq_decided_at' => optional($row->muwathaq_decided_at)->toIso8601String(),
                    'admin_decided_at' => optional($row->admin_decided_at)->toIso8601String(),
                ];
            })->values();

        $stats = MuwathaqAccountOpeningApplication::query()
            ->selectRaw('subject_kind, status, COUNT(*) AS total')
            ->groupBy('subject_kind', 'status')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $rows,
            'meta' => [
                'list' => $list,
                'count' => $rows->count(),
                'status_breakdown' => $stats,
            ],
        ]);
    }

    public function storeAuditEvent(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'event_ulid' => ['required', 'string', 'size:26', 'regex:/^[0-9A-HJKMNP-TV-Z]{26}$/'],
            'reviewer_identity' => 'required|string|max:190',
            'action' => 'required|string|in:session_started,directory_opened,case_opened,evidence_opened,decision_recorded',
            'application_ulid' => ['nullable', 'string', 'size:26', 'regex:/^[0-9A-HJKMNP-TV-Z]{26}$/'],
            'case_ulid' => ['nullable', 'string', 'size:26', 'regex:/^[0-9A-HJKMNP-TV-Z]{26}$/'],
            'subject_kind' => 'nullable|string|in:customer,merchant,agent',
            'context' => 'nullable|array',
            'occurred_at' => 'required|date',
        ]);
        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $data = $validator->validated();
        $context = collect((array) ($data['context'] ?? []))
            ->only(['list', 'decision', 'delivery_status'])
            ->all();

        DB::table('muwathaq_reviewer_audit_events')->updateOrInsert(
            ['event_ulid' => $data['event_ulid']],
            [
                'reviewer_identity' => $data['reviewer_identity'],
                'action' => $data['action'],
                'application_ulid' => $data['application_ulid'] ?? null,
                'case_ulid' => $data['case_ulid'] ?? null,
                'subject_kind' => $data['subject_kind'] ?? null,
                'context' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'occurred_at' => $data['occurred_at'],
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        return response()->json(['success' => true, 'code' => 'MUWATHAQ_AUDIT_EVENT_STORED']);
    }

    private function validationError($validator): JsonResponse
    {
        return response()->json([
            'success' => false,
            'code' => 'VALIDATION_FAILED',
            'message' => 'بيانات موثّق الداخلية غير صحيحة.',
            'errors' => $validator->errors(),
            'meta' => (object) [],
        ], 422);
    }
}
