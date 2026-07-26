<?php

namespace App\Services;

use App\Models\Receipt;
use App\Support\ArabicTafqit;

/**
 * AMIAL-NOTICE-001 — بناء «إشعار» موحّد لكل العمليات.
 *
 * الفكرة المعمارية المأخوذة من إشعارات شركات الصرافة اليمنية (العمقي وغيرها):
 * **وثيقة واحدة لكل أنواع العمليات**، يتغيّر فيها ثلاثة أشياء فقط:
 *
 *   1. العنوان   — «إشعار سحب» / «إشعار إيداع» / «إشعار تحويل» / «إشعار دفع»
 *   2. الافتتاحية — «قيدنا على حسابكم» للمدين، «أضفنا إلى حسابكم» للدائن
 *   3. البيان     — جملة سردية تصف العملية بتفاصيلها
 *
 * وما عدا ذلك ثابت: الترويسة، رقم الإشعار، رقم الحساب، المبلغ رقماً وحروفاً،
 * وعبارة «هذا الإشعار آلي ولا يحتاج إلى ختم أو توقيع».
 *
 * الفائدة: إضافة نوع عملية جديد لا تتطلّب قالباً جديداً — سطر في الخريطة
 * أدناه ودالة بيان. وهذا هو الفرق بين «إيصال لكل شاشة» و«نظام إشعارات».
 */
class ReceiptNoticeService
{
    /**
     * عنوان الإشعار حسب نوع العملية.
     * أي نوع غير مُعرَّف يقع على «إشعار عملية» — لا ينكسر شيء.
     */
    private const TITLES = [
        'cash_out'                => 'إشعار سحب',
        'withdraw'                => 'إشعار سحب',
        'cash_in'                 => 'إشعار إيداع',
        'add_money'               => 'إشعار إيداع',
        'send_money'              => 'إشعار تحويل',
        'received_money'          => 'إشعار تحويل',
        'pay_merchant'            => 'إشعار دفع',
        'pos_payment'             => 'إشعار دفع',
        'qr_payment'              => 'إشعار دفع',
        'bill_payment'            => 'إشعار سداد فاتورة',
        'refund'                  => 'إشعار استرجاع',
        'safe_payment_funded'     => 'إشعار حجز (دفع آمن)',
        'safe_payment_released'   => 'إشعار تحرير (دفع آمن)',
        'safe_payment_refunded'   => 'إشعار استرجاع (دفع آمن)',
        'family_fund_contribute'  => 'إشعار مساهمة',
        'donation'                => 'إشعار تبرع',
    ];

    /**
     * AMIAL-RECEIPT-TYPE-001 — اسم العملية صريحاً.
     *
     * العنوان الجانبي («إشعار تحويل») يصنّف المستند، لا العملية: «إشعار دفع»
     * واحد لدفع نقطة بيع ودفع QR ودفع تاجر. القارئ يحتاج اسم العملية نفسها
     * في حقل معنون، كما تفعل إشعارات الصرافة.
     */
    private const TYPE_LABELS = [
        'cash_out'                => 'سحب نقدي',
        'withdraw'                => 'طلب سحب',
        'cash_in'                 => 'إيداع نقدي',
        'add_money'               => 'شحن رصيد',
        'send_money'              => 'تحويل أموال',
        'received_money'          => 'تحويل وارد',
        'pay_merchant'            => 'دفع مشتريات',
        'pos_payment'             => 'دفع عبر نقطة بيع',
        'qr_payment'              => 'دفع بمسح رمز',
        'bill_payment'            => 'سداد فاتورة',
        'refund'                  => 'استرجاع مبلغ',
        'safe_payment_funded'     => 'حجز مبلغ (دفع آمن)',
        'safe_payment_released'   => 'تحرير مبلغ (دفع آمن)',
        'safe_payment_refunded'   => 'استرجاع مبلغ (دفع آمن)',
        'family_fund_contribute'  => 'مساهمة في صندوق عائلي',
        'donation'                => 'تبرع',
    ];

    /** عنوان الإشعار. */
    public function title(Receipt $receipt): string
    {
        return self::TITLES[$receipt->receipt_type] ?? 'إشعار عملية';
    }

    /**
     * اسم العملية للعرض في حقل «نوع العملية».
     *
     * أي نوع غير مُعرَّف يقع على «عملية مالية» لا على الرمز الخام — لا يجوز
     * أن يرى العميل `family_fund_contribute` في مستند رسمي.
     */
    public function typeLabel(Receipt $receipt): string
    {
        return self::TYPE_LABELS[$receipt->receipt_type] ?? 'عملية مالية';
    }

    /**
     * الافتتاحية — تتبع اتجاه القيد لا نوع العملية:
     * المدين يُقيَّد **على** الحساب، والدائن يُضاف **إلى** الحساب.
     */
    public function opening(Receipt $receipt): string
    {
        return $receipt->direction === 'debit'
            ? 'نود إشعاركم أننا قيدنا على حسابكم لدينا حسب التفاصيل التالية'
            : 'نود إشعاركم أننا أضفنا إلى حسابكم لدينا حسب التفاصيل التالية';
    }

    /** المبلغ بالحروف. */
    public function amountInWords(Receipt $receipt): string
    {
        return ArabicTafqit::yer($receipt->amount);
    }

    /**
     * البيان — الجملة السردية التي تصف العملية.
     *
     * تُبنى من الطرف المقابل والبيانات المرافقة (metadata)، وتنتهي دائماً
     * بـ«عبر تطبيق أميال باي» كما تفعل إشعارات الصرافة.
     */
    public function narrative(Receipt $receipt): string
    {
        $meta = is_array($receipt->metadata) ? $receipt->metadata : [];
        $party = $this->counterpartyName($receipt);
        // `transaction_no` accessor يستعلم جدول المعاملات — قد يرمي إن كانت
        // القاعدة غير متاحة لحظتها. الإشعار لا يجوز أن يفشل لأجل رقم مرجع.
        $ref = null;
        try {
            $ref = $receipt->transaction_no;
        } catch (\Throwable) {
            $ref = null;
        }
        $ref = $ref ?: $receipt->reference_transaction_id;

        $body = match ($receipt->receipt_type) {
            'cash_out', 'withdraw' => sprintf(
                'سحب نقدي بمبلغ %s%s%s',
                $this->money($receipt->amount),
                $party ? " عبر الوكيل ({$party})" : '',
                isset($meta['op_code']) ? " — رقم العملية {$meta['op_code']}" : '',
            ),
            'cash_in', 'add_money' => sprintf(
                'إيداع نقدي بمبلغ %s%s',
                $this->money($receipt->amount),
                $party ? " عبر الوكيل ({$party})" : '',
            ),
            'send_money' => sprintf(
                'تحويل مبلغ %s إلى %s',
                $this->money($receipt->amount),
                $party ?: 'حساب آخر',
            ),
            'received_money' => sprintf(
                'استلام مبلغ %s من %s',
                $this->money($receipt->amount),
                $party ?: 'حساب آخر',
            ),
            'pay_merchant', 'pos_payment', 'qr_payment' => sprintf(
                'دفع مبلغ %s لدى %s',
                $this->money($receipt->amount),
                $party ?: 'تاجر معتمد',
            ),
            'bill_payment' => sprintf(
                'سداد فاتورة %s بمبلغ %s',
                $meta['provider'] ?? $meta['service'] ?? '',
                $this->money($receipt->amount),
            ),
            'refund' => sprintf(
                'استرجاع مبلغ %s%s',
                $this->money($receipt->amount),
                $party ? " من {$party}" : '',
            ),
            'donation' => sprintf(
                'تبرع بمبلغ %s%s',
                $this->money($receipt->amount),
                $party ? " لصالح {$party}" : '',
            ),
            'family_fund_contribute' => sprintf(
                'مساهمة بمبلغ %s في %s',
                $this->money($receipt->amount),
                $meta['fund_name'] ?? 'صندوق مشترك',
            ),
            default => sprintf(
                'عملية بمبلغ %s%s',
                $this->money($receipt->amount),
                $party ? " مع {$party}" : '',
            ),
        };

        $body = trim(preg_replace('/\s+/u', ' ', $body));

        if ($ref) {
            $body .= " — رقم المرجع {$ref}";
        }

        return $body . ' — عبر تطبيق أميال باي';
    }

    /** اسم الطرف المقابل إن وُجد. */
    private function counterpartyName(Receipt $receipt): ?string
    {
        $meta = is_array($receipt->metadata) ? $receipt->metadata : [];

        foreach (['counterparty_name', 'merchant_name', 'agent_name', 'recipient_name'] as $key) {
            if (!empty($meta[$key])) {
                return (string) $meta[$key];
            }
        }

        try {
            $cp = $receipt->counterparty;
            if ($cp) {
                $name = trim(($cp->f_name ?? '') . ' ' . ($cp->l_name ?? ''));
                return $name !== '' ? $name : null;
            }
        } catch (\Throwable) {
            // العلاقة غير محمّلة أو المستخدم محذوف — البيان يظلّ صالحاً بدونها.
        }

        return null;
    }

    /** تنسيق مبلغ للعرض داخل البيان. */
    private function money(string|float|int|null $v): string
    {
        $n = is_numeric($v) ? (float) $v : 0.0;
        $s = number_format($n, 2, '.', ',');
        if (str_contains($s, '.')) {
            $s = rtrim(rtrim($s, '0'), '.');
        }

        return $s . ' ر.ي';
    }
}
