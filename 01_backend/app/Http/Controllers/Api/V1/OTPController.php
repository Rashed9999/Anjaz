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
     * AMIAL-OTP-BRUTEFORCE-001 — **رمزٌ من أربعة أرقام بلا عدّادٍ ولا أجل.**
     *
     * ══════════════════════════════════════════════════════════════════
     * كانت الدالّة تسأل «أيوجد صفٌّ بهذا الهاتف وهذا الرمز؟» ثمّ تنتهي:
     *
     *   ① **لا عدّادَ محاولات.** والمساحةُ عشرةُ آلاف احتمال، والحدُّ
     *      العامّ على المسار `throttle:100,1` — أي **مئةُ محاولةٍ في
     *      الدقيقة**، فتُستنفد المساحةُ كلُّها في ساعةٍ ونصف. ولا شيء
     *      يُنبّه: كلُّ محاولةٍ خاطئةٍ ترجع ٤٠٤ عاديّة.
     *
     *   ② **ولا أجلَ للرمز.** العمودُ `created_at` مكتوبٌ ولا يُقرأ. فرمزٌ
     *      أُرسل قبل شهرٍ ولم يُستعمل **ما زال يُقبل اليوم**. ورسالةٌ
     *      قديمةٌ في هاتفٍ مسروقٍ تكفي.
     *
     * والأعمدةُ اللازمة موجودةٌ في الجدول منذ البداية — `otp_hit_count`
     * و`is_temp_blocked` و`temp_block_time` — ويستعملها مسارُ استعادة
     * كلمة المرور. **وهذا المسارُ وحدَه لا يقرؤها.** أي أنّ الحمايةَ
     * مبنيّةٌ ونصفُ موصولة.
     *
     * والعتباتُ من إعدادات المنصّة نفسِها التي يستعملها المسارُ الآخر —
     * فقيمتان لسياسةٍ واحدة تعني أنّ تشديدَ إحداهما لا يُشدّد الأخرى.
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

        // **والصفُّ يُطلب بكلّ صيغ الرقم** — أمسك هذا حارسُ
        // `DirectMoneyRequestTest` لحظةَ كتابة السطر. والرمزُ يُكتب
        // بصيغةٍ ويُقرأ بأخرى يعني رمزاً صحيحاً يُرفض: «لم يُطلب رمزٌ
        // لهذا الرقم» وقد أُرسل قبل ثوانٍ.
        $row = $this->phoneVerification
            ->whereIn('phone', \App\Support\Phone::variants($phone))->first();

        if (! $row) {
            return response()->json(['errors' => [
                ['code' => 'otp', 'message' => 'لم يُطلب رمزُ تحقّقٍ لهذا الرقم']
            ]], 404);
        }

        // ── الحظرُ المؤقّت: يُقال متى ينتهي، ولا يُترك المستعمل يخمّن ──
        if ($row->is_temp_blocked && $row->temp_block_time
            && Carbon::parse($row->temp_block_time)->diffInSeconds() < $blockSeconds) {
            $left = $blockSeconds - Carbon::parse($row->temp_block_time)->diffInSeconds();

            return response()->json(['errors' => [
                ['code' => 'otp_block_time',
                 'message' => 'تجاوزت عدد المحاولات. حاول بعد ' . Helpers::timeHumanReadableFormat($left)]
            ]], 403);
        }

        // انقضت مدّةُ الحظر ⇒ يُصفَّر العدّاد ويُستأنف.
        if ($row->is_temp_blocked) {
            $row->update(['otp_hit_count' => 0, 'is_temp_blocked' => 0, 'temp_block_time' => null]);
            $row->refresh();
        }

        // ── الأجل: رمزٌ مضى عليه أكثر من المدّة لا يُقبل ولو كان صحيحاً ──
        if ($row->created_at && Carbon::parse($row->created_at)->diffInSeconds() > $lifetime) {
            $row->delete();

            return response()->json(['errors' => [
                ['code' => 'otp_expired', 'message' => 'انتهت صلاحية الرمز — اطلب رمزاً جديداً']
            ]], 410);
        }

        // ── المطابقة ──
        //
        // **ومقارنةٌ ثابتةُ الزمن**: `===` على نصٍّ قصيرٍ تتوقّف عند أوّل
        // محرفٍ مختلف، وفرقُ الزمن يُقاس. وهو هجومٌ نظريٌّ على أربعة
        // أرقام، لكنّ الكلفة سطرٌ واحد.
        if (hash_equals((string) $row->otp, (string) $request['otp'])) {
            $row->delete();

            return response()->json(['message' => 'تم التحقّق من الرمز'], 200);
        }

        // ── محاولةٌ خاطئة: تُعدّ، وتُحظر عند العتبة ──
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
