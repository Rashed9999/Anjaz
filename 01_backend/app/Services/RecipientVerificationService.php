<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * AMIAL-RECIPIENT-VERIFY-001 (v2.6)
 *
 * RecipientVerificationService — حل مشكلة "التحويل بالغلط".
 *
 * **المشكلة (شائعة في اليمن):**
 *   المستخدم يكتب رقم هاتف، خطأ رقم واحد → المال يذهب لشخص آخر بلا رجعة.
 *
 * **الحل (نمط قياسي في تطبيقات الدفع):**
 *   1. قبل التحويل، العميل يستدعي verify-recipient برقم الهاتف
 *   2. النظام يُرجع الاسم المُقنّع (مثل: "أحمد م****") + token تأكيد
 *   3. العميل يرى الاسم، يؤكد بصرياً أنه الصحيح
 *   4. التحويل لا يُقبل إلا بـ token تأكيد صالح للرقم نفسه
 *
 * **لماذا الاسم مُقنّع؟**
 *   لحماية الخصوصية — لا نكشف الاسم الكامل لأي شخص يدخل رقماً عشوائياً
 *   (يمنع حصاد البيانات / enumeration)، لكن يكفي للتأكيد البصري.
 */
class RecipientVerificationService
{
    private const TOKEN_TTL_SECONDS = 300; // 5 دقائق

    /**
     * التحقق من مستلم وإرجاع اسم مُقنّع + token.
     *
     * @throws RuntimeException
     */
    public function verifyRecipient(string $phone, int $senderId): array
    {
        $identifier = trim($phone);
        $recipient = null;

        // AMIAL-ACCOUNT-NUMBER-001: ادعم رقم الحساب (8 أرقام + Luhn) كمعرّف مستلِم
        // إلى جانب الهاتف — كان البحث بالهاتف فقط فيفشل إدخال رقم الحساب.
        if (preg_match('/^\d{8}$/', $identifier)) {
            $recipient = app(AccountNumberService::class)
                ->resolveRecipient($identifier, allowPhone: false);
        }

        // هاتف: نبحث بكل الصيغ المكافئة (مهما كانت صيغة التخزين)
        if (!$recipient) {
            $recipient = User::whereIn('phone', \App\Support\Phone::variants($identifier))->first();
        }

        if (!$recipient) {
            throw new RuntimeException('لا يوجد مستخدم بهذا الرقم أو رقم الحساب');
        }
        $phone = $recipient->phone; // استخدم الصيغة المخزّنة الفعلية للكاش/العرض

        if ($recipient->id === $senderId) {
            throw new RuntimeException('لا يمكنك التحويل لنفسك');
        }

        // ══════════════════════════════════════════════════════════════
        // AMIAL-TRANSFER-KIND-001 — **نوعُ الحساب يُسأل، وكان لا يُسأل.**
        //
        // كشفه صاحبُ المشروع بالاستعمال: حوّل من حساب عميلٍ إلى **رقم
        // حساب وكيل** فنجح. ولم يكن في مسار التحويل كلِّه فحصٌ واحدٌ
        // لنوع المستلِم — لا هنا، ولا في المتحكّم، ولا في
        // `customer_send_money_transaction`.
        //
        // **وأثرُه ليس هفوةً بل خللٌ في اقتصاد الشبكة:**
        //
        //   · **فائضٌ وهميٌّ دائم**: التسويةُ تقرأ «الفعليّ» من محافظ
        //     الوكيل و«المتوقَّع» من تسويات المنصّة معه. فرصيدٌ يدخل من
        //     عميلٍ يرفع الأوّلَ ولا يرفع الثاني، **ولا يزول أبداً**.
        //     (وهو عطلُ القاعدة العاشرة من بابٍ آخر.)
        //   · **سحبٌ نقديٌّ بلا سجلِّ سحب**: يعطي الوكيلُ نقداً ويقول
        //     «حوّل لي» — فلا رسمَ ولا عمولةَ ولا قيدَ `cash_out` ولا
        //     حدَّ يوميّ ولا فحصَ غسلٍ لعمليّات السحب.
        //   · **فلوتٌ يُخلق خارج الخزانة** — يدخل الوكيلَ مقابلَ لا شيء.
        //
        // **والمنعُ هنا وفي مسار المال معاً** (القاعدة الرابعة): هذه
        // تُصدر الرمزَ، وتلك تُحرّك المال. وإغلاقُ بابٍ وترْكُ الآخر
        // مفتوحاً ليس إغلاقاً.
        //
        // **والتاجرُ يبقى** — قبضُه بالتحويل معاملةٌ مشروعة، وتُعلَن
        // للمرسِل بـ`is_merchant`.
        // ══════════════════════════════════════════════════════════════
        $this->assertReceivableKind($recipient);

        // ══════════════════════════════════════════════════════════════
        // فحصُ المنطقة التشغيليّة — **والرفضُ يقول ما الناقص.**
        //
        // كانت الرسالةُ «المستلم غير مؤهل لاستقبال التحويلات حالياً» في
        // كلّ حال. **وهي صادقةٌ حرفيّاً وصامتةٌ عن سببها**: لا تفرّق بين
        // حسابٍ **لم يُوثَّق بعد** وحسابٍ **منطقتُه غير مسندة**.
        //
        // والفرقُ هو كلُّ ما يحتاجه القارئ: الأوّلُ ينتظر مراجعةً تنتهي،
        // والثاني يحتاج استكمالاً إدارياً واضحاً. **فيظنّ المرسِلُ أنّ الحسابَ محظورٌ أو
        // مشبوه، ويظنّ صاحبُه أنّ التطبيقَ معطوب** — ويذهب كلاهما إلى
        // الدعم بلا معلومة.
        //
        // **و«غير معروف» ليس «مرفوضاً»** — القاعدة السابعة.
        $zone = $recipient->zone_code ?? 'UNKNOWN';

        if ((int) ($recipient->is_kyc_verified ?? 0) !== 1) {
            throw new RuntimeException(
                'حساب المستلم لم يُعتمد بعد — لا يستقبل تحويلات حتى تُراجع هويته وتُفعَّل.'
            );
        }

        // التحويل بين محفظتين حركة دفتر داخليّة، وسياسة المناطق تسمح بها
        // في كل منطقة معروفة. لا نعيد هنا حارس SOUTH القديم ونناقض المصدر
        // المركزي للسياسة؛ UNKNOWN فقط هو المانع لأن هوية الحساب ناقصة.
        if ($zone === 'UNKNOWN' || $zone === '') {
            throw new RuntimeException(
                'حساب المستلم موثّق لكن محافظته التشغيلية غير محددة. على الدعم إكمال اعتماد الحساب من طابور الهوية.'
            );
        }

        // توليد token يربط (المرسل + المستلم) لفترة قصيرة
        $token = (string) Str::ulid();
        $cacheKey = $this->tokenKey($senderId, $token);
        Cache::put($cacheKey, [
            'recipient_id' => $recipient->id,
            'recipient_phone' => $phone,
        ], self::TOKEN_TTL_SECONDS);

        return [
            'verification_token' => $token,
            'recipient_id' => $recipient->id,
            'masked_name' => $this->maskName($recipient),
            'masked_phone' => $this->maskPhone($phone),
            'is_merchant' => ($recipient->type ?? 2) == 3,
            'expires_in' => self::TOKEN_TTL_SECONDS,
        ];
    }

    /**
     * التأكد من صلاحية token قبل تنفيذ التحويل.
     * يُستدعى في بداية send_money.
     *
     * @throws RuntimeException عند token غير صالح أو لا يطابق المستلم
     */
    public function assertValidToken(int $senderId, string $token, int $expectedRecipientId): void
    {
        $cacheKey = $this->tokenKey($senderId, $token);
        $data = Cache::get($cacheKey);

        if (!$data) {
            throw new RuntimeException(
                'انتهت صلاحية تأكيد المستلم. تحقق من المستلم مجدداً قبل الإرسال.'
            );
        }

        if ((int)$data['recipient_id'] !== $expectedRecipientId) {
            throw new RuntimeException(
                'بيانات المستلم لا تطابق التأكيد. أعد التحقق من الرقم.'
            );
        }

        // استهلاك الـ token (single-use — يمنع إعادة استخدامه لتحويل آخر)
        Cache::forget($cacheKey);
    }

    /**
     * تقنيع الاسم: "أحمد محمد علي" → "أحمد م** ع**"
     * يكفي للتأكيد البصري دون كشف الاسم الكامل.
     */
    public function maskName(User $user): string
    {
        $first = trim($user->f_name ?? '');
        $last = trim($user->l_name ?? '');

        $parts = [];
        // الاسم الأول كاملاً (للتعرّف)
        if ($first !== '') {
            $parts[] = $first;
        }
        // باقي الأسماء: أول حرف + نجوم
        if ($last !== '') {
            $words = preg_split('/\s+/', $last);
            foreach ($words as $word) {
                if (mb_strlen($word) > 0) {
                    $parts[] = mb_substr($word, 0, 1) . str_repeat('*', max(1, mb_strlen($word) - 1));
                }
            }
        }

        $masked = implode(' ', $parts);
        return $masked !== '' ? $masked : 'مستخدم';
    }

    /**
     * تقنيع الرقم: "777123456" → "777****56"
     */
    public function maskPhone(string $phone): string
    {
        $len = mb_strlen($phone);
        if ($len <= 5) return $phone;
        $start = mb_substr($phone, 0, 3);
        $end = mb_substr($phone, -2);
        return $start . str_repeat('*', $len - 5) . $end;
    }

    private function normalizePhone(string $phone): string
    {
        // إزالة المسافات والرموز، توحيد الصيغة
        $phone = preg_replace('/[\s\-\(\)]/', '', trim($phone));
        // إزالة رمز الدولة لو موجود (+967 / 00967)
        $phone = preg_replace('/^(\+?967|00967)/', '', $phone);
        return $phone;
    }

    private function tokenKey(int $senderId, string $token): string
    {
        return "recipient_verify:{$senderId}:{$token}";
    }

    /**
     * **من يجوز أن يستقبل تحويلاً بين الأفراد.**
     *
     * والرسالةُ تُرشد إلى الطريق الصحيح لا تكتفي بالمنع: من أراد إيصالَ
     * مالٍ إلى وكيلٍ فطريقُه الشبّاك — إيداعٌ نقديٌّ له قيدُه وعمولتُه
     * وحدُّه. ورفضٌ صامتٌ يُرسل صاحبَه إلى الدعم بلا معلومة.
     *
     * @throws RuntimeException
     */
    public function assertReceivableKind(User $recipient): void
    {
        $this->assertKind($recipient, receiving: true);
    }

    /**
     * **ولا يُرسِل وكيلٌ تحويلاً فرديّاً كذلك.**
     *
     * وهو الوجهُ الآخرُ للثقب نفسِه: فلوتٌ يخرج من الوكيل إلى عميلٍ **بلا
     * قيدِ إيداعٍ نقديّ** — أي أنّ نقداً قُبض ولم يدخل النظام. فتضيع
     * عمولةُ الإيداع وحدُّه وأثرُه، وينقص الفعليُّ في التسوية بلا سبب.
     *
     * @throws RuntimeException
     */
    public function assertSendableKind(User $sender): void
    {
        $this->assertKind($sender, receiving: false);
    }

    private function assertKind(User $party, bool $receiving): void
    {
        $type = (int) ($party->type ?? CUSTOMER_TYPE);

        if (! $receiving) {
            if ($type === AGENT_TYPE) {
                throw new RuntimeException(
                    'حسابُ الوكيل لا يُرسل تحويلاً بين الأفراد. '
                    . 'صرفُ الرصيد للعميل يتمّ من الشبّاك سحباً أو إيداعاً '
                    . 'بإيصاله وعمولته.');
            }

            if ($type === ADMIN_TYPE) {
                throw new RuntimeException(
                    'حسابُ الإدارة لا يُرسل تحويلاً بين الأفراد — '
                    . 'تمويلُ الوكلاء من المركز المالي.');
            }

            return;
        }

        if ($type === AGENT_TYPE) {
            throw new RuntimeException(
                'هذا رقمُ حسابِ وكيل، ولا يستقبل تحويلاً بين الأفراد. '
                . 'للإيداع لدى الوكيل توجَّه إلى شبّاكه — تُسجَّل العمليّةُ '
                . 'إيداعاً نقديّاً بإيصالها.');
        }

        if ($type === ADMIN_TYPE) {
            throw new RuntimeException(
                'هذا حسابُ إدارةٍ في المنصّة ولا يستقبل تحويلات.');
        }
    }

}
