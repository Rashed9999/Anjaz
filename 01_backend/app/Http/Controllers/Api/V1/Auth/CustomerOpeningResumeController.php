<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\MuwathaqAccountOpeningApplication;
use App\Models\User;
use App\Services\Muwathaq\MuwathaqOpeningProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

/**
 * Restores a narrowly scoped evidence-completion token for an existing customer.
 * No auth:api token, wallet, financial session, or new user is ever issued.
 * The registered email must already be verified at registration.
 */
class CustomerOpeningResumeController extends Controller
{
    public function __construct(private readonly MuwathaqOpeningProgressService $progress) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validation = Validator::make($request->all(), [
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:200'],
        ]);
        if ($validation->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'أدخل البريد الإلكتروني وكلمة المرور الصحيحين.',
            ], 422)->header('Cache-Control', 'no-store');
        }

        $email = mb_strtolower(trim((string) $request->input('email')));
        $rateKey = 'customer-opening-resume:'.hash('sha256', $email.'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($rateKey, 5)) {
            return response()->json([
                'success' => false,
                'message' => 'محاولات كثيرة. حاول لاحقاً.',
            ], 429)->header('Cache-Control', 'no-store');
        }

        $users = User::query()->where('type', CUSTOMER_TYPE)
            ->where(function ($query) use ($email): void {
                $query->whereRaw('LOWER(TRIM(email)) = ?', [$email]);
                if (Schema::hasColumn('users', 'email_canonical')) {
                    $query->orWhere('email_canonical', $email);
                }
            })->limit(2)->get();

        // Duplicate verified emails are an incident, not a reason to pick
        // an arbitrary owner and give that owner access to KYC evidence.
        $user = $users->count() === 1 ? $users->first() : null;
        $opening = $user ? MuwathaqAccountOpeningApplication::query()
            ->where('user_id', $user->id)->where('subject_kind', 'customer')
            ->latest('id')->first() : null;

        if ($user === null || !$opening || (int) ($user->is_email_verified ?? 0) !== 1
            || $user->trashed()
            || (int) ($user->is_temp_blocked ?? 0) === 1
            || !Hash::check((string) $request->input('password'), (string) $user->password)) {
            RateLimiter::hit($rateKey, 900);
            return response()->json([
                'success' => false,
                'message' => 'تعذر استعادة الطلب؛ تحقق من بيانات الحساب أو حالته.',
            ], 403)->header('Cache-Control', 'no-store');
        }

        RateLimiter::clear($rateKey);
        $data = [
            'application_ulid' => $opening->application_ulid,
            'status' => $opening->status,
            'verification_path' => $opening->verification_path,
            'requested_tier' => $opening->requested_tier,
        ];

        if (!$opening->isFinal()) {
            $data['account_opening_access_token'] = $this->progress->issueAccessToken($opening);
            $data['account_opening_access_expires_at'] =
                $opening->fresh()->opening_access_expires_at?->toIso8601String();
        }

        return response()->json(['success' => true, 'data' => $data])
            ->header('Cache-Control', 'no-store');
    }
}
