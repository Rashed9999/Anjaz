<?php

namespace App\Http\Middleware;

use App\Models\UserLogHistory;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * AMIAL-DEVICE-TRUST-001 — بوّابة الجهاز.
 *
 * كانت تسأل سؤالاً واحداً: «هل هذا الجهاز هو النشط لهذا المستخدم؟» ولا تسأل
 * «هل هو محظور؟». فمن سُرق جهازه لم يكن أمام الدعم إلّا تجميد الحساب كلّه —
 * عقوبةٌ على الضحيّة، تمنعه من ماله بينما هو من يستحقّ الحماية.
 *
 * فصار الحظر يُفحص **قبل** النشاط: جهازٌ محظور يُمنع ولو كان هو النشط. وهذا
 * هو الفرق بين العَلَمين، ولولاه لكان `is_blocked` عموداً يُكتب ولا يُقرأ.
 */
class CheckDeviceId
{
    public function handle(Request $request, Closure $next): mixed
    {
        if ($request->ip() == "::1") {
            return $next($request);
        }

        $deviceId = $request->header('device-id');
        if ($deviceId == '') {
            abort(response()->json(response_formatter(DEFAULT_400), 400));
        }

        $userId = $request->user()->id;

        // يُجلب الجهاز بلا شرط `is_active` عمداً: الشرط القديم كان يُخفي
        // الجهاز المحظور غير النشط فيسقط في الفرع العامّ بـ403 — وهو ردٌّ
        // صحيح بالمصادفة. والمقصود أن يُعرف سببُ المنع لا أن يُوافق رقمه.
        $device = UserLogHistory::where('user_id', $userId)
            ->where('device_id', $deviceId)
            ->first();

        // الحظر يسبق النشاط: لولا ذلك لمرّ الجهاز المسروق ما دام النشط.
        if ($device && $device->is_blocked) {
            Log::warning('Blocked device attempted access', [
                'user_id' => $userId,
                'device_id' => $deviceId,
                'ip' => $request->ip(),
                'reason' => $device->block_reason,
            ]);

            abort(response()->json(response_formatter(DEFAULT_403), 403));
        }

        if ($device && $device->is_active) {
            $this->touchLastSeen($device);

            return $next($request);
        }

        abort(response()->json(response_formatter(DEFAULT_403), 403));
    }

    /**
     * أثرُ الاستعمال يُكتب هنا لا عند الدخول وحده.
     *
     * المستخدم يدخل مرّةً ويظلّ يعمل ساعات، فتاريخُ الدخول لا يجيب سؤال
     * الدعم: «متى استُعمل هذا الجهاز آخر مرّة؟»
     *
     * ويُكتب بتباعدِ دقيقة وبلا لمس `updated_at`: كل طلبٍ محميّ يمرّ من هنا،
     * وكتابةٌ في كلٍّ منها تحوّل بوّابةً خفيفة إلى حِملٍ على قاعدة البيانات.
     */
    private function touchLastSeen(UserLogHistory $device): void
    {
        $last = $device->last_seen_at;

        if ($last && $last->diffInSeconds(now()) < 60) {
            return;
        }

        UserLogHistory::where('id', $device->id)->update(['last_seen_at' => now()]);
    }
}
