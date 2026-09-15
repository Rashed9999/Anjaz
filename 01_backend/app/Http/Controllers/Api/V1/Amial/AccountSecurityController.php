<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use App\Services\TransactionPinService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-ACCOUNT-SECURITY-001 — **كلمةُ المرور تُغيَّر، والرمزُ يُفصَل عنها.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمنُ الذي دُفع.** قال صاحبُ المشروع: «آخرُ خطوةٍ في التسجيل هي إدخالُ
 * رمز PIN وكلمة المرور، **ويبدو أنّ رمز PIN هو كلمةُ المرور** — إذن كيف
 * يمكن تغييرُ كلمة المرور بعد تسجيل الدخول؟ **لا يوجد طريقة**».
 *
 * **وكلا الملاحظتين صحيحة، وقِيستا ولم تُفترَضا:**
 *
 *   ① `RegisterController` يكتب `transaction_pin = $request->password`،
 *      والحقلُ `'hashed'` في `$casts`. فرمزُ التحويل **هو** كلمةُ المرور
 *      حرفاً بحرف لكلّ حسابٍ سُجّل من التطبيق — عميلاً وتاجراً ووكيلاً.
 *      **فمن رأى كلمةَ مرورك مرّةً يستطيع تحويلَ مالك**، والفصلُ الذي
 *      بُني `TransactionPinService` كلُّه من أجله ملغىً عند المنبع.
 *
 *   ② `grep -rn "change-password" routes/` → **لا شيء**. وفي المشروع
 *      `forgot-password` و`reset-password` (وكلاهما يمرّ برسالةٍ نصّيّةٍ
 *      إلى الهاتف)، **ولا مسارَ واحدٌ يغيّر كلمةَ المرور لمن دخل بالفعل**.
 *      و`change-pin` مبنيٌّ **للعميل والوكيل وحدَهما** — فالتاجر، وهو من
 *      يحرّك أكبرَ المبالغ، لا يملك تغييرَ رمزه أصلاً.
 *
 * **ولمَ لا يُحذَف السطرُ ① وحدَه.** `TransactionPinService::FALLBACK_DEADLINE`
 * هو `2026-06-15` **وقد مضى**. فحسابٌ بلا `transaction_pin` يُرفَض رفضاً
 * دائماً في `checkPin` — أي أنّ حذفَ السطر يمنع كلَّ مسجَّلٍ جديدٍ من
 * التحويل إلى الأبد. **فيُبقى الرمزُ ويُفصَل**: بابٌ يختار به صاحبُه رمزاً
 * مستقلّاً، ولافتةٌ تقول له إنّ رمزَه لم يُختَر بعد.
 *
 * **والعلامةُ موجودةٌ في المخطّط ولا تحتاج عموداً جديداً:**
 * `transaction_pin_set_at` يكتبه `TransactionPinService::setPin` وحدَه،
 * والتسجيلُ لا يكتبه. فـ«رمزٌ مضبوطٌ وتاريخُ ضبطِه فارغ» تعني بالضبط
 * **«الرمزُ هو كلمةُ مرور التسجيل ولم يُختَر قطّ»**.
 *
 * يظهر في : التطبيق ← الإعدادات ← «أمانُ الحساب» (لكلّ الأنواع الثلاثة).
 * ويُوصل إليه من : قائمة الإعدادات، ومن لافتةِ «رمزُك هو كلمةُ مرورك».
 * وفي لوحة الإدارة: لا — قرارُ أمانٍ يخصّ صاحبَ الحساب وحدَه.
 *
 * @see \Tests\Feature\AccountSecurityGuardTest
 */
class AccountSecurityController extends Controller
{
    /** أدنى طولٍ لكلمة المرور الجديدة — والتسجيلُ اليومَ يقبل أربعةَ أرقام. */
    public const MIN_PASSWORD = 4;

    public function __construct(
        private readonly TransactionPinService $pins,
        private readonly AuditService $audit,
    ) {}

    /**
     * **حالةُ الأمان** — ولا يخرج منها سرٌّ ولا تجزئة.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        $hasPin = ! empty($user->transaction_pin);
        $chosen = $user->transaction_pin_set_at !== null;

        return $this->ok([
            'pin_is_set' => $hasPin,
            'pin_was_chosen' => $hasPin && $chosen,

            // **الحقيقةُ الصريحةُ لا الصمت** (القاعدة السابعة): رمزٌ مضبوطٌ
            // بلا تاريخِ اختيارٍ = رمزُ التسجيل، أي كلمةُ المرور نفسُها.
            'pin_equals_password' => $hasPin && ! $chosen,

            'pin_set_at' => $user->transaction_pin_set_at?->format('Y-m-d H:i'),
            'pin_locked_until' => $user->pin_locked_until?->format('Y-m-d H:i'),
            'notice' => ($hasPin && ! $chosen)
                ? 'رمزُ التحويل هو كلمةُ مرورك نفسُها لأنّه لم يُختَر بعد. '
                    .'اختر رمزاً مستقلّاً — فمن عرف كلمةَ مرورك عرف رمزَك.'
                : null,
        ]);
    }

    /**
     * **تغييرُ كلمة المرور** — بالقديمة، لمن دخل بالفعل.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:'.self::MIN_PASSWORD.'|max:64|confirmed',
        ], [], [
            'current_password' => 'كلمة المرور الحالية',
            'new_password' => 'كلمة المرور الجديدة',
        ]);

        if ($v->fails()) {
            return $this->validationError($v);
        }

        $user = $request->user();

        if (! Hash::check((string) $request->input('current_password'), (string) $user->password)) {
            $this->audit->record([
                'actor_type' => 'user',
                'actor_user_id' => $user->id,
                'subject_type' => 'password',
                'subject_id' => (string) $user->id,
                'action' => 'PASSWORD_CHANGE_REFUSED',
                'decision_code' => 'BAD_CURRENT_PASSWORD',
                'reason' => 'current password mismatch',
                'severity' => 'warning',
            ]);

            return $this->error('BAD_PASSWORD', 'كلمة المرور الحالية غير صحيحة', 422);
        }

        $new = (string) $request->input('new_password');

        if (Hash::check($new, (string) $user->password)) {
            return $this->error('SAME_PASSWORD',
                'الكلمةُ الجديدةُ هي القديمةُ نفسُها', 422);
        }

        // ══════════════════════════════════════════════════════════════
        // **والرمزُ لا يتبع كلمةَ المرور.** لو حُدِّث معها لعاد العطلُ
        // نفسُه بثوبٍ جديد: يظنّ صاحبُه أنّه فصلهما وهما واحد.
        //
        // **لكنّ من رمزُه كلمةُ مرورِه القديمة يُقال له**، ولا يُترك
        // يظنّ أنّ تغييرَ الكلمة أغلق البابَ وقد بقي مفتوحاً بالقديمة.
        // ══════════════════════════════════════════════════════════════
        $pinStillOldPassword = ! empty($user->transaction_pin)
            && $user->transaction_pin_set_at === null;

        $user->password = Hash::make($new);
        $user->save();

        $this->audit->record([
            'actor_type' => 'user',
            'actor_user_id' => $user->id,
            'subject_type' => 'password',
            'subject_id' => (string) $user->id,
            'action' => 'PASSWORD_CHANGED',
            'decision_code' => 'OK',
            'reason' => 'self-service change',
            'severity' => 'info',
        ]);

        return $this->ok([
            'pin_equals_password' => $pinStillOldPassword,
        ], 'PASSWORD_CHANGED', $pinStillOldPassword
            ? 'غُيّرت كلمةُ المرور. **ورمزُ التحويل ما زال كلمةَ مرورك '
                .'القديمة** — اختر رمزاً مستقلّاً الآن.'
            : 'غُيّرت كلمةُ المرور');
    }

    /**
     * **اختيارُ رمز التحويل** — لكلّ الأنواع، لا للعميل والوكيل وحدَهما.
     */
    public function changePin(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'current' => 'required|string',
            'new_pin' => 'required|digits_between:4,6|confirmed',
        ], [], [
            'current' => 'الرمز الحالي',
            'new_pin' => 'الرمز الجديد',
        ]);

        if ($v->fails()) {
            return $this->validationError($v);
        }

        $user = $request->user();

        if ($user->pin_locked_until !== null && $user->pin_locked_until->isFuture()) {
            return $this->error('PIN_LOCKED',
                'الرمزُ مقفلٌ مؤقّتاً بعد محاولاتٍ خاطئة — أعِد المحاولة لاحقاً',
                429);
        }

        $new = (string) $request->input('new_pin');

        // **ورمزٌ يساوي كلمةَ المرور ليس فصلاً** — وهو العطلُ نفسُه يُعاد
        // بيدِ صاحبه هذه المرّة. فيُرفَض ويُقال سببُه.
        if (Hash::check($new, (string) $user->password)) {
            return $this->error('PIN_EQUALS_PASSWORD',
                'لا يكون الرمزُ كلمةَ مرورك — اختر رمزاً مختلفاً، '
                .'فالغرضُ من الرمز أن يكون سرّاً ثانياً', 422);
        }

        if (! $this->pins->changePin($user, (string) $request->input('current'), $new)) {
            return $this->error('BAD_PIN',
                'الرمزُ الحاليُّ غير صحيح، أو الجديدُ يساويه', 422);
        }

        return $this->ok([
            'pin_was_chosen' => true,
        ], 'PIN_CHANGED', 'اختيرَ رمزُ التحويل — وصار مستقلّاً عن كلمة المرور');
    }

    // ── الغلاف ──────────────────────────────────────────────────────

    private function ok(array $meta, string $code = 'OK', string $message = 'OK'): JsonResponse
    {
        return new JsonResponse([
            'success' => true, 'code' => $code, 'message' => $message,
            'errors' => (object) [], 'meta' => $meta,
        ]);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse([
            'success' => false, 'code' => $code, 'message' => $message,
            'errors' => (object) [], 'meta' => (object) [],
        ], $status);
    }

    private function validationError(\Illuminate\Contracts\Validation\Validator $v): JsonResponse
    {
        return new JsonResponse([
            'success' => false, 'code' => 'VALIDATION', 'errors' => $v->errors(),
            'message' => (string) $v->errors()->first(), 'meta' => (object) [],
        ], 422);
    }
}
