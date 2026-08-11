<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\TwoFactorAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-2FA-001 (v1.8) — admin 2FA management.
 */
class Admin2FAController extends Controller
{
    public function __construct(
        private readonly TwoFactorAuthService $twoFactor,
    ) {}

    /**
     * **شاشةُ إدارة المصادقة الثنائية** — AMIAL-2FA-DOOR-001.
     *
     * كانت خمسُ نقاطٍ تعمل بلا شاشةٍ واحدة: لا مكانَ في اللوحة يفتحها،
     * ولا زرَّ يبدأ الإعداد. فالميزةُ مبنيّةٌ منذ v1.8 **ولا يستطيع أحدٌ
     * تفعيلها** إلّا بأداةٍ خارجيّة.
     */
    public function page(Request $request)
    {
        return view('admin-views.amial.security.two-factor');
    }

    /** POST /admin/amial/2fa/setup */
    public function setup(Request $request): JsonResponse
    {
        $admin = $request->user();

        if ($admin->two_factor_enabled) {
            return $this->error('ALREADY_ENABLED', '2FA مفعّل بالفعل', 422);
        }

        $result = $this->twoFactor->setup($admin);

        return $this->ok([
            'secret' => $result['secret'],
            'qr_uri' => $result['qr_uri'],
            'recovery_codes' => $result['recovery_codes'],
            'instructions' => 'امسح الرمز في تطبيق Google Authenticator أو Authy، '
                . 'ثم أكّد بالرمز المعروض. احفظ recovery codes في مكان آمن.',
        ], 'SETUP_INITIATED', 'تم بدء الإعداد');
    }

    /** POST /admin/amial/2fa/confirm */
    public function confirm(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'code' => 'required|string|size:6',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $confirmed = $this->twoFactor->confirm($request->user(), $request->input('code'));

        if (!$confirmed) {
            return $this->error('INVALID_CODE', 'الرمز غير صحيح، حاول مجدداً', 422);
        }

        return $this->ok([], 'CONFIRMED', 'تم تفعيل المصادقة الثنائية بنجاح');
    }

    /** POST /admin/amial/2fa/disable */
    public function disable(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'password' => 'required|string',
            'code' => 'required|string',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $admin = $request->user();

        // تأكيد كلمة المرور + 2FA code قبل التعطيل
        if (!Hash::check($request->input('password'), $admin->password)) {
            return $this->error('WRONG_PASSWORD', 'كلمة المرور غير صحيحة', 401);
        }

        if (!$this->twoFactor->verify($admin, $request->input('code'))) {
            return $this->error('INVALID_CODE', 'رمز التحقق غير صحيح', 401);
        }

        $this->twoFactor->disable($admin);
        return $this->ok([], 'DISABLED', 'تم تعطيل المصادقة الثنائية');
    }

    /** GET /admin/amial/2fa/status */
    public function status(Request $request): JsonResponse
    {
        $admin = $request->user();
        return $this->ok([
            'enabled' => (bool) $admin->two_factor_enabled,
            'confirmed_at' => $admin->two_factor_confirmed_at?->toIso8601String(),
        ]);
    }

    /** POST /admin/amial/2fa/regenerate-recovery-codes */
    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'code' => 'required|string|size:6',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $admin = $request->user();
        if (!$admin->two_factor_enabled) {
            return $this->error('NOT_ENABLED', '2FA غير مفعّل', 422);
        }

        if (!$this->twoFactor->verify($admin, $request->input('code'))) {
            return $this->error('INVALID_CODE', 'رمز التحقق غير صحيح', 401);
        }

        // أعد توليد + حفظ
        $codes = $this->twoFactor->generateRecoveryCodes();
        $admin->two_factor_recovery_codes = app(\App\Services\EncryptionService::class)
            ->encrypt(json_encode($codes));
        $admin->save();

        return $this->ok(['recovery_codes' => $codes],
            'REGENERATED', 'تم توليد رموز استرداد جديدة');
    }

    // ============================================================
    private function ok(array $meta, string $code = 'OK', string $message = 'OK'): JsonResponse
    {
        return new JsonResponse([
            'success' => true, 'code' => $code, 'message' => $message,
            'errors' => (object)[], 'meta' => $meta,
        ]);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse([
            'success' => false, 'code' => $code, 'message' => $message,
            'errors' => (object)[], 'meta' => (object)[],
        ], $status);
    }

    private function validationError($v): JsonResponse
    {
        return new JsonResponse([
            'success' => false, 'code' => 'VALIDATION_FAILED',
            'message' => 'بيانات غير صحيحة', 'errors' => $v->errors(), 'meta' => (object)[],
        ], 422);
    }
}
