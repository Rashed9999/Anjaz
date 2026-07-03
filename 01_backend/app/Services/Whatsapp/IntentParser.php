<?php

namespace App\Services\Whatsapp;

/**
 * AMIAL-WA-002 — يحلّل رسالة المستخدم ويُعيد النيّة (نسخة مُحدَّثة).
 *
 * الإضافات عن v1:
 *   - INTENT_LINK_ACCOUNT (Section 4)
 *   - INTENT_SUPPORT (Section 29 — التواصل مع الدعم)
 *   - دعم "الذكاء اللغوي" الموسّع (Section 20): "بكم معي" تُعامَل كـ balance
 */
class IntentParser
{
    public const INTENT_MENU         = 'menu';
    public const INTENT_BALANCE      = 'balance';
    public const INTENT_TRANSFER     = 'transfer';
    public const INTENT_STATEMENT    = 'statement';
    public const INTENT_AGENT        = 'agent';
    public const INTENT_BILL_PAY     = 'bill_pay';
    public const INTENT_HELP         = 'help';
    public const INTENT_CANCEL       = 'cancel';
    public const INTENT_CONFIRM      = 'confirm';
    public const INTENT_REJECT       = 'reject';
    public const INTENT_NUMBER       = 'number';
    public const INTENT_RAW_PHONE    = 'raw_phone';
    public const INTENT_RAW_AMOUNT   = 'raw_amount';
    public const INTENT_LINK_ACCOUNT = 'link_account';   // AMIAL-WA-002
    public const INTENT_SUPPORT      = 'support';         // AMIAL-WA-002
    public const INTENT_PAY_CODE     = 'pay_code';         // AMIAL-WA-003 — Section 23
    public const INTENT_UNKNOWN      = 'unknown';

    private const KEYWORDS = [
        self::INTENT_LINK_ACCOUNT => [
            'ربط الحساب', 'ربط حسابي', 'ربط', 'link account', 'link my account',
            'تفعيل الحساب', 'فعّل حسابي',
        ],
        self::INTENT_SUPPORT => [
            'التواصل مع الدعم', 'الدعم', 'دعم', 'support', 'مساعدة فنية',
            'تواصل', 'مشكلة', 'شكوى',
        ],
        self::INTENT_BALANCE => [
            'رصيدي', 'رصيد', 'balance', 'bal', 'ر', '1',
            'الرصيد', 'رصيدك', 'كم رصيدي', 'اشوف رصيدي', 'بكم معي', 'بكم',
        ],
        self::INTENT_TRANSFER => [
            'حول', 'تحويل', 'ارسل', 'أرسل', 'transfer',
            'send', 'حوّل', 'ح', '2', 'ارسال', 'إرسال',
        ],
        self::INTENT_STATEMENT => [
            'كشف', 'كشف حساب', 'statement', 'history',
            'العمليات', 'المعاملات', 'ك', '3', 'سجل', 'عملياتي',
        ],
        self::INTENT_AGENT => [
            'وكيل', 'أقرب وكيل', 'agent', 'و', '4',
            'صراف', 'أقرب صراف', 'مكان سحب', 'سحب',
        ],
        self::INTENT_BILL_PAY => [
            'دفع', 'فاتورة', 'bill', 'pay', 'د', '5',
            'سدد', 'سدّد', 'فاتورة الكهرباء', 'فاتورة الماء',
        ],
        self::INTENT_HELP => [
            'مساعدة', 'help', 'م', '6', '0', 'مساعده',
            'ساعدني', '?', '؟',
        ],
        self::INTENT_MENU => [
            'مرحبا', 'هلا', 'hi', 'hello', 'قائمة', 'ابدأ',
            'start', 'menu', 'ابدا', 'بداية', 'الرئيسية',
            'رئيسي', 'السلام عليكم', 'اهلا', 'أهلا',
        ],
        self::INTENT_CONFIRM => [
            'نعم', 'تأكيد', 'موافق', 'yes', 'ok', 'okay',
            'تمام', 'صح', 'اوك', 'أكد',
        ],
        // AMIAL-FIX: أُزيلت كلمات الإلغاء (cancel/إلغاء…) من REJECT لتفادي التعارض
        // مع INTENT_CANCEL (كانت REJECT تسبقها فتبتلع "cancel"). REJECT الآن رفضٌ فقط.
        self::INTENT_REJECT => [
            'لا', 'no', 'رفض', 'رجعت', 'مو', 'مافي',
        ],
        self::INTENT_CANCEL => [
            'إلغاء', 'الغاء', 'cancel', 'وقف', 'أوقف',
            'رجوع', 'back', 'خروج', 'exit',
        ],
    ];

    public function parse(string $message): array
    {
        $cleaned = $this->clean($message);

        // رقم OTP محتمل (6 أرقام بالضبط) — يُترَك للمتصل ليقرّر السياق
        if (preg_match('/^\d{6}$/', $cleaned)) {
            return ['type' => 'otp_candidate', 'params' => ['otp' => $cleaned]];
        }

        // AMIAL-FIX: رابط طلب دفع (/req/CODE) إشارة قاطعة — يُفحَص مبكّراً قبل
        // extractPhone/extractAmount، وإلا يبتلع extractAmount أرقام الرمز فيُساء تصنيفه.
        if (preg_match('#/req/([A-Za-z2-9]{6})\b#', $message, $m)) {
            return ['type' => self::INTENT_PAY_CODE, 'params' => ['code' => strtoupper($m[1])]];
        }

        if ($phone = $this->extractPhone($cleaned)) {
            return ['type' => self::INTENT_RAW_PHONE, 'params' => ['phone' => $phone]];
        }

        if ($amount = $this->extractAmount($cleaned)) {
            return ['type' => self::INTENT_RAW_AMOUNT, 'params' => ['amount' => $amount]];
        }

        if ($inline = $this->parseInlineTransfer($cleaned)) {
            return ['type' => self::INTENT_TRANSFER, 'params' => $inline];
        }

        foreach (self::KEYWORDS as $intent => $keywords) {
            foreach ($keywords as $kw) {
                if ($cleaned === mb_strtolower($kw) || $cleaned === $kw) {
                    return ['type' => $intent, 'params' => []];
                }
            }
        }

        foreach (self::KEYWORDS as $intent => $keywords) {
            foreach ($keywords as $kw) {
                if (mb_strlen($kw) > 2 && mb_strpos($cleaned, mb_strtolower($kw)) !== false) {
                    return ['type' => $intent, 'params' => []];
                }
            }
        }

        // AMIAL-WA-003 — Section 23: رابط أو رمز طلب دفع (بعد كل
        // الكلمات المفتاحية، لتجنّب أيّ تصادم محتمل مع أمر معروف)
        if ($code = $this->extractPaymentRequestCode($message)) {
            return ['type' => self::INTENT_PAY_CODE, 'params' => ['code' => $code]];
        }

        return ['type' => self::INTENT_UNKNOWN, 'params' => []];
    }

    /**
     * يستخرج رمز طلب دفع (6 حروف من أبجدية PaymentRequestService الدقيقة)
     * من رابط كامل أو نصّ مباشر. أبجدية صارمة لتفادي تصادم مع كلمات أوامر.
     */
    public function extractPaymentRequestCode(string $rawMessage): ?string
    {
        $text = trim($rawMessage);

        if (preg_match('#/req/([A-Za-z2-9]{6})\b#', $text, $m)) {
            return strtoupper($m[1]);
        }

        if (preg_match('/^[ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjklmnpqrstuvwxyz23456789]{6}$/', $text)) {
            return strtoupper($text);
        }

        return null;
    }

    private function clean(string $msg): string
    {
        $msg = trim($msg);
        $msg = preg_replace('/[\x{064B}-\x{065F}]/u', '', $msg);
        $msg = mb_strtolower($msg);
        $msg = str_replace(['أ', 'إ', 'آ'], 'ا', $msg);
        return $msg;
    }

    public function extractPhone(string $text): ?string
    {
        $text = preg_replace('/[\s\-]/', '', $text);
        if (preg_match('/^(?:00967|967|\+967|0)?(7[0-9]{8})$/', $text, $m)) {
            return '967' . $m[1];
        }
        return null;
    }

    public function extractAmount(string $text): ?string
    {
        $text = strtr($text, ['٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4',
                               '٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);
        $text = preg_replace('/,/', '', $text);

        if (preg_match('/^(\d{3,8})(?:\s*(?:ريال|ر\.ي|ري|YER))?$/u', trim($text), $m)) {
            $amount = (int) $m[1];
            if ($amount >= 100 && $amount <= 10000000) {
                return (string) $amount;
            }
        }
        return null;
    }

    private function parseInlineTransfer(string $text): ?array
    {
        $pattern = '/(?:حول|ارسل|أرسل|send|transfer)?\s*(\d{3,8})\s*(?:لـ|ل|to|الى|إلى|لـِ)\s*(\d{7,12})/u';
        if (preg_match($pattern, $text, $m)) {
            $phone  = $this->extractPhone($m[2]);
            $amount = $this->extractAmount($m[1]);
            if ($phone && $amount) {
                return ['to_phone' => $phone, 'amount' => $amount];
            }
        }
        return null;
    }
}
