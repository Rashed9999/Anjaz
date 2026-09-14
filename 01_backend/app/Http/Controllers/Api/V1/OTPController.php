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
    public function __construct(
        private PhoneVerification $phoneVerification
    ){}

    public function checkOtp(Request $request): JsonResponse
    {
        try {
            // AMIAL-OTP-ENV-001 — `env()` تُرجع null بعد `config:cache`،
            // فكان الرمزُ `1234` ثابتاً في الإنتاج. البابُ الواحد الآن هو
            // `OtpPolicy`: رقمُ العرض يأخذ الثابت، والحقيقيُّ عشوائيّاً.
            $otp = (string) app(\App\Services\Otp\OtpPolicy::class)
                ->codeFor((string) $request->user()->phone);

            DB::table('phone_verifications')->updateOrInsert(['phone' => $request->user()->phone], [
                'otp' => $otp,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if(addon_published_status('Gateways')){
                $response = SmsGateway::send($request->user()->phone,$otp);
            }else{
                $response = SmsModule::send($request->user()->phone, $otp);
            }

            return response()->json(['message' => 'success'], 200);
        } catch (\Exception $e) {
            return response()->json(['message' => 'failed'], 200);
        }
    }

    /**
     * AMIAL-OTP-BRUTEFORCE-001 — رمزٌ من أربعة أرقام له عداد وأجل وقفل.
     *
     * AMIAL-PHONE-OWNERSHIP-001 — هذا المسار خلف auth، ويرسل الرمز إلى
     * رقم الحساب نفسه. لذلك نجاحه ليس «الرمز صحيح» فقط: هو دليل ملكية
     * الهاتف، ويجب أن ينعكس على `is_phone_verified` التي يقرأها KYC Tier.
     */
    public function verifyOtp(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'otp' => 'required|min:4|max:4'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $phone = $request->user()->phone;
        $maxHits = (int) (Helpers::get_business_settings('maximum_otp_hit') ?? 5);
        $blockSeconds = (int) (Helpers::get_business_settings('temporary_block_time') ?? 600);
        $lifetime = (int) config('amial.otp.lifetime_seconds', 600);

        $row = $this->phoneVerification
            ->whereIn('phone', \App\Support\Phone::variants($phone))->first();

        if (! $row) {
            return response()->json(['errors' => [
                ['code' => 'otp', 'message' => 'لم يُطلب رمزُ تحقّقٍ لهذا الرقم']
            ]], 404);
        }

        if ($row->is_temp_blocked && $row->temp_block_time
            && Carbon::parse($row->temp_block_time)->diffInSeconds() < $blockSeconds) {
            $left = $blockSeconds - Carbon::parse($row->temp_block_time)->diffInSeconds();

            return response()->json(['errors' => [
                ['code' => 'otp_block_time',
                 'message' => 'تجاوزت عدد المحاولات. حاول بعد ' . Helpers::timeHumanReadableFormat($left)]
            ]], 403);
        }

        if ($row->is_temp_blocked) {
            $row->update(['otp_hit_count' => 0, 'is_temp_blocked' => 0, 'temp_block_time' => null]);
            $row->refresh();
        }

        if ($row->created_at && Carbon::parse($row->created_at)->diffInSeconds() > $lifetime) {
            $row->delete();

            return response()->json(['errors' => [
                ['code' => 'otp_expired', 'message' => 'انتهت صلاحية الرمز — اطلب رمزاً جديداً']
            ]], 410);
        }

        if (hash_equals((string) $row->otp, (string) $request['otp'])) {
            $user = $request->user();

            DB::transaction(function () use ($row, $user) {
                // القفل على صف الحساب يمنع سباق «رمز استُعمل مرتين» من أن
                // ينتج قرارين أو أثراً ناقصاً.
                $account = \App\Models\User::query()->lockForUpdate()->findOrFail($user->id);
                $account->is_phone_verified = 1;
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
                    'context' => ['channel' => 'sms'],
                ]);
            });

            return response()->json([
                'message' => 'تم التحقّق من ملكية رقم الهاتف',
                'is_phone_verified' => true,
            ], 200);
        }

        $hits = (int) $row->otp_hit_count + 1;

        if ($hits >= $maxHits) {
            $row->update([
                'otp_hit_count' => $hits,
                'is_temp_blocked' => 1,
                'temp_block_time' => now(),
            ]);

            return response()->json(['errors' => [
                ['code' => 'otp_block_time',
                 'message' => 'تجاوزت عدد المحاولات. حاول بعد '
                    . Helpers::timeHumanReadableFormat($blockSeconds)]
            ]], 403);
        }

        $row->update(['otp_hit_count' => $hits]);

        return response()->json(['errors' => [
            ['code' => 'otp',
             'message' => 'الرمز غير صحيح — بقيت ' . ($maxHits - $hits) . ' محاولة']
        ]], 404);
    }
}
