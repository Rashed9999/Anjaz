<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Concerns\DeniesByPlan;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Merchant\PosDevice;
use App\Models\Merchant\PosDeviceSession;
use App\Models\PosUser;
use App\Models\User;
use App\Services\Merchant\PosDeviceRegistrar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-POS-DEVICES-004 — **إدارةُ مقاعد أجهزة نقاط البيع.**
 *
 *   GET    merchant/pos-devices              قائمةُ المقاعد ومستهلَكُها
 *   POST   merchant/pos-devices              تسجيلُ جهازٍ (يستهلك مقعداً)
 *   POST   merchant/pos-devices/pair         اقترانٌ — **يمرّ بالحدّ نفسِه**
 *   PATCH  merchant/pos-devices/{id}         الاسمُ والفرعُ وحدَهما
 *   DELETE merchant/pos-devices/{id}         إلغاءٌ — يُخلي المقعدَ ويقتل الجلسات
 *
 * ══════════════════════════════════════════════════════════════════════
 * **أربعةُ قراراتٍ تُقرأ ولا تُخمَّن:**
 *
 * ① **التسجيلُ والاقترانُ بابان لفعلٍ واحد، فيمرّان بالمُسجِّل نفسِه.**
 *    وبابٌ ثانٍ بمنطقٍ ثانٍ هو **بابُ الالتفاف**: يُقفل الأوّلُ ويُترك
 *    الثاني مفتوحاً، ولا يُلاحَظ لأنّ الاختبارَ يطرق الأوّل. (القيدُ
 *    الرابع: «الاقترانُ ليس التفافاً على التسجيل».)
 *
 * ② **`branch_id` يُتحقَّق أنّه للتاجر نفسِه** — فهو معرِّفٌ يأتي من
 *    الطلب، وما يأتي من الطلب لا يُصدَّق (القاعدة الثامنة). وبدونه
 *    يُعلّق تاجرٌ جهازَه على فرع تاجرٍ آخر.
 *
 * ③ **الإلغاءُ لا يحذف.** يُخلي المقعدَ ويختم الجلسات، ويبقى الأثرُ:
 *    «أيُّ جهازٍ نفّذ عمليّةَ أمس» سؤالٌ يُسأل بعد الإلغاء لا قبله.
 *
 * ④ **ولا تُعرض البصمةُ كاملةً في أيّ ردّ** — تُعرض أربعةُ محارفَ
 *    يميّز بها صاحبُها جهازَه. فالمقصودُ مقعدٌ موثوق، لا تتبّعُ حامله.
 */
class PosDeviceController extends Controller
{
    use DeniesByPlan;

    public function __construct(private readonly PosDeviceRegistrar $registrar) {}

    /** GET — المقاعدُ ومستهلَكُها. */
    public function index(Request $request): JsonResponse
    {
        $owner = $this->owner($request);

        if ($owner instanceof JsonResponse) {
            return $owner;
        }

        $devices = PosDevice::where('merchant_user_id', $owner->id)
            ->orderByDesc('is_active')->orderByDesc('last_seen_at')
            ->get();

        $max = $this->registrar->maxSeats($owner);

        return $this->ok([
            'devices' => $devices->map(fn (PosDevice $d) => $this->present($d))->all(),
            'used' => PosDevice::activeSeats($owner->id),
            'max' => $max,
            'unlimited' => $max < 0,
        ]);
    }

    /** POST — تسجيلُ جهاز. */
    public function store(Request $request): JsonResponse
    {
        return $this->registerThrough($request, 'device_uuid');
    }

    /**
     * POST /pair — **الاقترانُ يمرّ بالمُسجِّل نفسِه.**
     *
     * ولا منطقَ ثانٍ هنا: لو كُتب لكان بابَ التفافٍ على الحدّ.
     */
    public function pair(Request $request): JsonResponse
    {
        return $this->registerThrough($request, 'device_uuid');
    }

    /** PATCH — الاسمُ والفرعُ وحدَهما؛ ولا تُغيَّر الهويّة ولا الحالة. */
    public function update(Request $request, int $id): JsonResponse
    {
        $owner = $this->owner($request);

        if ($owner instanceof JsonResponse) {
            return $owner;
        }

        $device = PosDevice::where('merchant_user_id', $owner->id)->find($id);

        if ($device === null) {
            return $this->error('POS_DEVICE_NOT_FOUND', 'الجهاز غير موجود لهذا الحساب', 404);
        }

        $v = Validator::make($request->all(), [
            'display_name' => 'sometimes|nullable|string|max:120',
            'branch_id' => 'sometimes|nullable|integer',
        ]);

        if ($v->fails()) {
            return $this->error('VALIDATION_ERROR', $v->errors()->first(), 422);
        }

        $patch = [];

        if ($request->has('display_name')) {
            $patch['display_name'] = $request->input('display_name');
        }

        if ($request->has('branch_id')) {
            $branch = $this->resolveBranch($owner, $request->input('branch_id'));

            if ($branch instanceof JsonResponse) {
                return $branch;
            }

            $patch['branch_id'] = $branch;
        }

        if ($patch !== []) {
            $device->forceFill($patch)->save();
        }

        return $this->ok(['device' => $this->present($device->refresh())]);
    }

    /** DELETE — إلغاءٌ يُخلي المقعدَ ويقتل الجلسات. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $owner = $this->owner($request);

        if ($owner instanceof JsonResponse) {
            return $owner;
        }

        $device = PosDevice::where('merchant_user_id', $owner->id)->find($id);

        if ($device === null) {
            return $this->error('POS_DEVICE_NOT_FOUND', 'الجهاز غير موجود لهذا الحساب', 404);
        }

        if ($device->revoked_at !== null) {
            // **إلغاءٌ مكرَّرٌ ليس خطأً** — الحالةُ المطلوبةُ قائمةٌ سلفاً،
            // والردُّ يقولها. (طلبٌ مكرَّرٌ لا يُنتج أثراً ثانياً.)
            return $this->ok([
                'device' => $this->present($device),
                'already_revoked' => true,
                'used' => PosDevice::activeSeats($owner->id),
            ]);
        }

        $this->registrar->revoke($device, $request->user()->id);

        return $this->ok([
            'device' => $this->present($device->refresh()),
            'used' => PosDevice::activeSeats($owner->id),
            'max' => $this->registrar->maxSeats($owner),
        ]);
    }

    // ══════════════════════════════════════════════════════════════════

    /** البابُ الواحدُ للتسجيل والاقتران. */
    private function registerThrough(Request $request, string $field): JsonResponse
    {
        $owner = $this->owner($request);

        if ($owner instanceof JsonResponse) {
            return $owner;
        }

        $v = Validator::make($request->all(), [
            $field => 'required|string|min:8|max:200',
            'display_name' => 'sometimes|nullable|string|max:120',
            'platform' => 'sometimes|nullable|string|max:32',
            'app_version' => 'sometimes|nullable|string|max:32',
            'branch_id' => 'sometimes|nullable|integer',
        ]);

        if ($v->fails()) {
            return $this->error('VALIDATION_ERROR', $v->errors()->first(), 422);
        }

        $branch = $this->resolveBranch($owner, $request->input('branch_id'));

        if ($branch instanceof JsonResponse) {
            return $branch;
        }

        $result = $this->registrar->register($owner, (string) $request->input($field), [
            'branch_id' => $branch,
            'display_name' => $request->input('display_name'),
            'platform' => $request->input('platform'),
            'app_version' => $request->input('app_version'),
        ]);

        if ($result['result'] === PosDeviceRegistrar::RESULT_LIMIT) {
            return new JsonResponse([
                'success' => false,
                'code' => 'PLAN_LIMIT_REACHED',
                'message' => sprintf(
                    'بلغتَ حدَّ أجهزة نقاط البيع في باقتك (%d من %d). '
                    . 'ألغِ جهازاً لم يعد يُستعمل، أو ارفع الباقة.',
                    $result['used'], $result['max']),
                'errors' => (object) [],
                'meta' => [
                    'usage' => ['used' => $result['used'], 'max' => $result['max']],
                ],
            ], 402);
        }

        return $this->ok([
            'device' => $this->present($result['device']),
            'created' => $result['result'] === PosDeviceRegistrar::RESULT_REGISTERED,
            'used' => $result['used'],
            'max' => $result['max'],
        ]);
    }

    /**
     * **صاحبُ المقاعد** — التاجرُ نفسُه، أو تاجرُ الموظّف.
     *
     * والموظّفُ **لا يُسجّل ولا يُلغي**: المقعدُ مورِدٌ في باقة صاحب
     * الحساب، وإتاحتُه للموظّف تجعل كاشيراً يستنفد حدَّ متجرٍ كامل.
     * فيقرأ الموظّفُ ولا يكتب — وهذا يُفرَض على المسار أيضاً برُتبة.
     */
    private function owner(Request $request): User|JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            return $this->error('UNAUTHENTICATED', 'يلزم تسجيل الدخول', 401);
        }

        $pos = PosUser::where('user_id', $user->id)->where('is_active', true)->first();

        if ($pos !== null) {
            if (! $request->isMethod('GET')) {
                return $this->error('POS_DEVICE_OWNER_ONLY',
                    'إدارةُ أجهزة نقاط البيع لصاحب الحساب وحدَه.', 403);
            }

            return User::findOrFail($pos->merchant_user_id);
        }

        return $user;
    }

    /**
     * **الفرعُ يجب أن يكون للتاجر نفسِه.**
     *
     * ومعرِّفُ الفرع يأتي من الطلب، فلا يُصدَّق. وبدون هذا يُعلّق تاجرٌ
     * جهازَه على فرع تاجرٍ آخر — فيقرأ ذاك في شاشته جهازاً ليس له.
     */
    private function resolveBranch(User $owner, mixed $branchId): int|null|JsonResponse
    {
        if ($branchId === null || $branchId === '') {
            return null;
        }

        $branch = Branch::where('merchant_user_id', $owner->id)->find((int) $branchId);

        if ($branch === null) {
            return $this->error('BRANCH_NOT_FOUND',
                'الفرع غير موجود لهذا الحساب', 404);
        }

        return (int) $branch->id;
    }

    /** **ولا بصمةَ كاملةً في أيّ ردّ** — أربعةُ محارفَ للتمييز وحدَها. */
    private function present(PosDevice $d): array
    {
        return [
            'id' => $d->id,
            'display_name' => $d->display_name,
            'hint' => $d->device_hint,
            'branch_id' => $d->branch_id,
            'platform' => $d->platform,
            'app_version' => $d->app_version,
            'registered_at' => $d->registered_at?->toIso8601String(),
            'last_seen_at' => $d->last_seen_at?->toIso8601String(),
            'revoked_at' => $d->revoked_at?->toIso8601String(),
            'is_active' => (bool) $d->is_active && $d->revoked_at === null,
            'live_sessions' => PosDeviceSession::where('pos_device_id', $d->id)
                ->whereNull('ended_at')->count(),
        ];
    }

    private function ok(array $data): JsonResponse
    {
        return new JsonResponse([
            'success' => true, 'code' => 'OK', 'message' => 'تم',
            'errors' => (object) [], 'meta' => (object) [], 'data' => $data,
        ]);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse([
            'success' => false, 'code' => $code, 'message' => $message,
            'errors' => (object) [], 'meta' => (object) [],
        ], $status);
    }
}
