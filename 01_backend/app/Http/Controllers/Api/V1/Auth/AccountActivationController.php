<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditService;
use App\Services\EmailIdentityService;
use App\Services\Otp\EmailOtpService;
use App\Services\TransactionPinService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * تفعيل حسابٍ اعتمده موثّق — من داخل التطبيق: البريد + رمز التفعيل الواصل عند الاعتماد، ثم كلمة مرور وPIN في خطوةٍ واحدة،
 * ويُعاد للعميل ما يعيده الدخول العاديّ فيفتح حسابه دون صفحة ويب ودون «نسيت كلمة المرور».
 *
 * الأمان: الحساب مؤهَّل فقط إن كان معتمداً من موثّق وبريده غير موثَّق بعد (`findMuwathaqProvisionedOwner`)؛ والرمز عبر
 * `EmailOtpService` (تجزئة · عدّاد محاولات · قفل · استعمال واحد)؛ ونجاح الرمز هو إثبات ملكية البريد. ردّ الرفض واحدٌ
 * لا يفرّق بين بريدٍ مجهول وحسابٍ مفعَّل ورمزٍ خاطئ، فلا يُستعمل المسار للتحقق من وجود الحسابات.
 */
class AccountActivationController extends Controller
{
    public function __construct(
        private readonly EmailOtpService $otp,
        private readonly TransactionPinService $pins,
        private readonly AuditService $audit,
        private readonly EmailIdentityService $identities,
    ) {}

    public function complete(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'email' => 'required|email|max:255',
            'otp' => 'required|digits:6',
            'password' => 'required|string|min:8|max:64|confirmed',
            'pin' => 'required|digits_between:4,6|confirmed',
        ]);
        if ($v->fails()) {
            return $this->fail('VALIDATION_FAILED', 'تحقّق من البريد والرمز وكلمة المرور ورمز PIN.', 422, $v->errors()->toArray());
        }
        $purpose = EmailOtpService::PURPOSE_ACCOUNT_ACTIVATION;
        if ($this->otp->channelsFor($purpose) === []) {
            return $this->fail('ACTIVATION_DISABLED', 'تفعيل الحساب بالبريد غير مفعّل حالياً.', 409);
        }

        $email = $this->identities->normalize((string) $request->input('email'));
        $owner = $this->identities->findMuwathaqProvisionedOwner($email);
        $challengeId = $owner ? (string) (DB::table('otp_challenges')
            ->where('identifier', $email)->where('purpose', $purpose)->where('user_id', $owner->id)
            ->whereNull('consumed_at')->where('expires_at', '>', now())
            ->orderByDesc('id')->value('challenge_id') ?? '') : '';
        if (! $owner || $challengeId === '') {
            return $this->invalid();
        }

        $password = (string) $request->input('password');
        try {
            $this->otp->verifyAndConsume($challengeId, $email, $purpose, (string) $request->input('otp'), function (object $row) use ($owner, $request, $password): void {
                $user = User::whereKey($row->user_id)->lockForUpdate()->first();
                if (! $user || ! $user->is($owner) || $this->identities->findMuwathaqProvisionedOwner((string) $row->identifier)?->is($user) !== true) {
                    throw new RuntimeException('EMAIL_IDENTITY_CHANGED');
                }
                $user->password = Hash::make($password);
                $this->pins->setPin($user, (string) $request->input('pin'));
                // نجاح الرمز = إثبات ملكية البريد لحسابٍ أنشأه موثّق؛ وحارس الهوية لا يسمح بالتوثيق إلا ضمن تفويضه.
                $this->identities->authorizeMutation($user, function () use ($user): void {
                    $user->is_email_verified = 1;
                    if (Schema::hasColumn('users', 'email_verified_at')) {
                        $user->email_verified_at = now();
                    }
                    $user->save();
                });
                $this->audit->record([
                    'actor_type' => 'user', 'actor_user_id' => $user->id, 'subject_type' => 'user', 'subject_id' => (string) $user->id,
                    'action' => 'ACCOUNT_ACTIVATED_BY_EMAIL_CODE', 'decision_code' => 'ACTIVATED', 'severity' => 'info',
                    'context' => ['challenge_id' => $row->challenge_id],
                ]);
            });
        } catch (\InvalidArgumentException $e) {
            return $this->fail('WEAK_PIN', $e->getMessage(), 422);
        } catch (RuntimeException) {
            return $this->invalid();
        }

        // عميل: يفتح حسابه بحمولة الدخول نفسها (LOGIN_OK) — يمرّ بالمتحكم ذاته فلا يتشعّب شكل الجواب.
        // تاجر/وكيل: التفعيل تمّ ويدخلان من بوابتيهما.
        $user = $owner->fresh();
        if ((int) $user->type === CUSTOMER_TYPE) {
            // الطلب JSON: `duplicate()` لا يبدّل جسمه، فيصل الدخول بلا role. نستبدل محتوى النسخة نفسها (مصدر الإدخال الفعليّ).
            $login = $request->duplicate();
            $login->replace(['role' => 'customer', 'phone' => (string) $user->phone, 'password' => $password]);
            $login->setUserResolver(fn () => null);
            $resp = app(UnifiedAuthController::class)->login($login);
            if ($resp->getStatusCode() === 200) {
                return $resp;
            }
            // التفعيل نجح؛ تعذّر الدخول التلقائي (قفل/جهاز) — يدخل العميل بهاتفه وكلمة مروره.
        }

        return new JsonResponse(['success' => true, 'code' => 'ACCOUNT_ACTIVATED', 'message' => 'تم تفعيل حسابك. سجّل الدخول.', 'errors' => (object) [], 'meta' => ['signed_in' => false]]);
    }

    private function invalid(): JsonResponse
    {
        return $this->fail('ACTIVATION_INVALID', 'الرمز غير صحيح أو انتهت صلاحيته، أو أن الحساب مفعّل مسبقاً. إن كان حسابك مفعّلاً فسجّل الدخول، وإلا اطلب رمزاً جديداً.', 422);
    }

    private function fail(string $code, string $message, int $status, array $errors = []): JsonResponse
    {
        return new JsonResponse(['success' => false, 'code' => $code, 'message' => $message, 'errors' => (object) $errors, 'meta' => []], $status);
    }
}
