<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use App\CentralLogics\helpers;
use App\CentralLogics\SmsModule;
use App\Models\PhoneVerification;
use Illuminate\Support\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Modules\Gateways\Traits\SmsGateway;
use Illuminate\Support\Facades\Validator;

class OTPController extends Controller
{
    public function __construct(private PhoneVerification $phoneVerification) {}

    public function checkOtp(Request $request): JsonResponse
    {
        $phone = (string) $request->user()->phone;
        $policy = app(\App\Services\Otp\OtpPolicy::class);

        // الرقم الحقيقي لا يُقال له «أرسلنا» إذا لم توجد قناة فعالة.
        // أرقام العرض لا تحتاج قناة: رمزها ثابت ومقصور عليها فقط.
        if ($policy->needsDelivery($phone) && ! $policy->deliveryReady()) {
            return response()->json([
                'success' => false,
                'code' => 'OTP_DELIVERY_UNAVAILABLE',
                'message' => $policy->unavailableMessage(),
            ], 503);
        }

        try {
            // OtpPolicy يصدر ستة أرقام للأرقام الحقيقية، ورمز العرض الافتراضي
            // ستة أيضاً. مسار verifyOtp أدناه يلتزم بالعقد نفسه.
            $otp = (string) $policy->codeFor($phone);

            DB::table('phone_verifications')->updateOrInsert(['phone' => $phone], [
                'otp' => $otp,
                'otp_hit_count' => 0,
                'is_temp_blocked' => 0,
                'temp_block_time' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($policy->needsDelivery($phone)) {
                $result = addon_published_status('Gateways')
                    ? SmsGateway::send($phone, $otp)
                    : SmsModule::send($phone, $otp);

                // مزود موجود لكنه فشل ليس نجاحاً. نحذف الرمز الذي لم يصل
                // حتى لا يبقى تحدٍ صالح لا يعرفه صاحبه.
                if (!in_array($result, ['success', true, 1], true)) {
                    DB::table('phone_verifications')->where('phone', $phone)->delete();

                    return response()->json([
                        'success' => false,
                        'code' => 'OTP_DELIVERY_FAILED',
                        'message' => 'تعذر إرسال رمز التحقق حالياً. حاول مرة أخرى لاحقاً.',
                    ], 502);
                }
            }

            return response()->json([
                'success' => true,
                'code' => 'OTP_SENT',
                'message' => 'تم إرسال رمز التحقق',
                // الإفصاح لأرقام العرض وحدها؛ الرقم الحقيقي لا يخرج رمزه.
                'demo_otp' => $policy->mayDisclose($phone) ? $otp : null,
                'digits' => 6,
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'code' => 'OTP_DELIVERY_FAILED',
                'message' => 'تعذر إرسال رمز التحقق حالياً. حاول مرة أخرى لاحقاً.',
            ], 503);
        }
    }

    /**
     * نجاح OTP = إثبات ملكية الهاتف، وهو بوابة Tier 1 في KYC التدريجي.
     * لا يفتح المال وحده: ResidenceVerification + حدود KycTier تبقى حارسة.
     */
    public function verifyOtp(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), ['otp' => 'required|digits:6']);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $phone = $request->user()->phone;
        $maxHits = (int) (Helpers::get_business_settings('maximum_otp_hit') ?? 5);
        $blockSeconds = (int) (Helpers::get_business_settings('temporary_block_time') ?? 600);
        $lifetime = (int) config('amial.otp.lifetime_seconds', 600);

        $row = $this->phoneVerification
            ->whereIn('phone', \App\Support\Phone::variants($phone))->first();

        if (!$row) {
            return response()->json(['errors' => [[
                'code' => 'otp', 'message' => 'لم يُطلب رمزُ تحقّقٍ لهذا الرقم'
            ]]], 404);
        }

        if ($row->is_temp_blocked && $row->temp_block_time
            && Carbon::parse($row->temp_block_time)->diffInSeconds() < $blockSeconds) {
            $left = $blockSeconds - Carbon::parse($row->temp_block_time)->diffInSeconds();
            return response()->json(['errors' => [[
                'code' => 'otp_block_time',
                'message' => 'تجاوزت عدد المحاولات. حاول بعد ' . Helpers::timeHumanReadableFormat($left)
            ]]], 403);
        }

        if ($row->is_temp_blocked) {
            $row->update(['otp_hit_count' => 0, 'is_temp_blocked' => 0, 'temp_block_time' => null]);
            $row->refresh();
        }

        if ($row->created_at && Carbon::parse($row->created_at)->diffInSeconds() > $lifetime) {
            $row->delete();
            return response()->json(['errors' => [[
                'code' => 'otp_expired', 'message' => 'انتهت صلاحية الرمز — اطلب رمزاً جديداً'
            ]]], 410);
        }

        if (hash_equals((string) $row->otp, (string) $request['otp'])) {
            $user = $request->user();

            DB::transaction(function () use ($row, $user) {
                $account = \App\Models\User::query()->lockForUpdate()->findOrFail($user->id);
                $account->is_phone_verified = 1;
                if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'kyc_tier')) {
                    $account->kyc_tier = max(1, (int) ($account->kyc_tier ?? 0));
                }
                if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'kyc_tier_updated_at')) {
                    $account->kyc_tier_updated_at = now();
                }
                $account->save();
                $row->delete();

                app(\App\Services\AuditService::class)->record([
                    'actor_type' => 'customer',
                    'actor_user_id' => (int) $account->id,
                    'subject_type' => 'user',
                    'subject_id' => (string) $account->id,
                    'action' => 'PHONE_OWNERSHIP_VERIFIED',
                    'decision_code' => 'PHONE_OTP_VERIFIED',
                    'severity' => 'info',
                    'context' => ['progressive_kyc_tier' => 1],
                ]);
            });

            return response()->json([
                'message' => 'تم التحقّق من ملكية رقم الهاتف',
                'is_phone_verified' => true,
                'kyc_tier' => 1,
                'next_step' => 'verify_residence',
            ], 200);
        }

        $hits = (int) $row->otp_hit_count + 1;
        if ($hits >= $maxHits) {
            $row->update([
                'otp_hit_count' => $hits,
                'is_temp_blocked' => 1,
                'temp_block_time' => now(),
            ]);
            return response()->json(['errors' => [[
                'code' => 'otp_block_time',
                'message' => 'تجاوزت عدد المحاولات. حاول بعد ' . Helpers::timeHumanReadableFormat($blockSeconds)
            ]]], 403);
        }

        $row->update(['otp_hit_count' => $hits]);
        return response()->json(['errors' => [[
            'code' => 'otp', 'message' => 'الرمز غير صحيح — بقيت ' . ($maxHits - $hits) . ' محاولة'
        ]]], 404);
    }
}
