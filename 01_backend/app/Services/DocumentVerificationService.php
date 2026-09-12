<?php

namespace App\Services;

use App\Models\FuelSale;
use App\Models\Merchant;
use App\Models\MerchantSale;
use App\Models\Receipt;
use App\Models\User;
use Carbon\Carbon;

/**
 * AMIAL-DOC-VERIFY-001 — **رمزٌ واحدٌ يُتحقَّق منه، ويقول الحقيقةَ الحاليّة.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ثلاثةُ أعطالٍ قِيست، ولكلٍّ منها ثمنٌ يقع على من يحمل الورقة:**
 *
 *   ① **المستندُ الملغى يُجيب «لا إيصال صالح لهذا الرمز».**
 *      `ReceiptService::verifyByCode` يقتصر على `status='pdf_generated'`،
 *      **فالملغى والمزوَّرُ جوابُهما واحد**. والفرقُ بينهما هو كلُّ
 *      المسألة: من ألغى فاتورتَه بحقٍّ يُتَّهم بالتزوير، ومن زوَّر يجد
 *      لنفسه عذراً («لعلّها أُلغيت»). (القاعدة السابعة: الغيابُ يُقال
 *      بسببه، ولا يُخلط بغيره.)
 *
 *   ② **وسندُ الوقود يطبع «رمز التحقّق» ولا مُتحقِّقَ يقبله.**
 *      `pdf/fuel-sale-receipt` يطبع `substr(sale_ulid, -8)` تحت لافتة
 *      «رمز التحقّق» — **ولا مسارَ في المنصّة يفهمه**. فمن جرّبه وجد
 *      «غير صحيح»، فيقرأ سندَه الصحيحَ مزوَّراً. **ولافتةٌ تَعِد بما لا
 *      تفعل أسوأ من غيابها.**
 *
 *   ③ **والنتيجةُ تُكاش خمسَ دقائق.** فتُلغى فاتورةٌ ويبقى الورقُ
 *      «صحيحاً» خمسَ دقائقَ بعدها — وهي بالضبط الدقائقُ التي يُستعمل
 *      فيها.
 *
 * **وحدُّ ما يُعرَض:** نوعُ المستند ورقمُه ومُصدِرُه ومبلغُه ووقتُه
 * وحالتُه. **ولا اسمَ عميلٍ ولا هاتفَ ولا رصيد** — الصفحةُ عامّةٌ بلا
 * مصادقة، ومن يحمل الرمزَ ليس بالضرورة صاحبَ المستند.
 *
 * **والقديمُ لا يُكسَر:** الرموزُ المطبوعةُ سلفاً — رمزُ الوقود القصير
 * ورموزُ `receipts` — تبقى كلُّها مقبولة. فإيصالٌ في جيب سائقٍ منذ شهر
 * لا يصير باطلاً لأنّنا وحّدنا الصيغة.
 *
 * يظهر في : الموقع ← `/verify` (إدخالُ الرمز أو مسحُ QR) و`/v/{code}`
 * (من مسح رمز السند مباشرةً). وفي لوحة الإدارة: لا — هذه صفحةُ جمهور.
 *
 * @see \Tests\Feature\DocumentVerificationGuardTest
 */
class DocumentVerificationService
{
    /** توقيتُ مكّة — وهو ما يُطبع على المستندات. */
    public const TZ = 'Asia/Riyadh';

    /**
     * @return array{found:bool,authenticity:string,authenticity_label:string,
     *               doc_type:?string,doc_type_label:?string,document_number:?string,
     *               issuer:?string,amount:?string,currency:?string,
     *               issued_at:?string,state:?string,state_label:?string,source:?string,
     *               reason:?string}
     */
    public function verify(string $raw): array
    {
        $code = $this->normalize($raw);

        if ($code === '') {
            return $this->notFound('لم يُدخَل رمز');
        }

        // ① السجلُّ المركزيُّ أوّلاً — وفيه فواتيرُ التجّار وعمليّاتُ المنصّة.
        if ($receipt = Receipt::where('verification_code', $code)->first()) {
            return $this->fromReceipt($receipt);
        }

        // ② والرمزُ القصيرُ القديمُ للوقود يبقى مقبولاً — إيصالٌ مطبوعٌ
        //    منذ شهرٍ لا يصير باطلاً لأنّنا وحّدنا الصيغة.
        if (preg_match('/^[0-9A-Z]{8}$/', $code)) {
            $sale = FuelSale::whereRaw('UPPER(RIGHT(sale_ulid, 8)) = ?', [$code])->first();

            if ($sale) {
                return $this->fromFuelSale($sale);
            }
        }

        return $this->notFound('لا مستند بهذا الرمز');
    }

    /** الصيغةُ تُقبَل كما يقرؤها الإنسانُ على الورق: «1234-5678 9012». */
    public function normalize(string $raw): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($raw))) ?? '';
    }

    // ── مصادرُ المستندات ───────────────────────────────────────────────

    private function fromReceipt(Receipt $receipt): array
    {
        // ① **الملغى يُقال ملغىً، لا «غير موجود».**
        $voided = $receipt->status === 'voided';

        // والفاتورةُ التي وُلدت من بيعةٍ تُسأل عن حالتها الحيّة: بيعةٌ
        // أُلغيت أو استُرجعت بعد طباعة فاتورتها **لا تبقى «مكتملة»**.
        $sale = $this->saleBehind($receipt);
        [$state, $stateLabel] = $this->saleState($sale, $voided);

        $authenticity = match (true) {
            $voided => 'cancelled',
            $state === 'refunded' => 'refunded',
            default => 'authentic',
        };

        return [
            'found' => true,
            'authenticity' => $authenticity,
            'authenticity_label' => $this->authenticityLabel($authenticity),
            'doc_type' => $receipt->receipt_type,
            'doc_type_label' => $this->typeLabel($receipt->receipt_type),
            'document_number' => $receipt->receipt_number,
            'issuer' => $this->issuerOf($receipt, $sale),
            'amount' => (string) $receipt->amount,
            'currency' => 'ر.ي',
            'issued_at' => $this->mecca($receipt->issued_at ?? $receipt->created_at),
            'state' => $state,
            'state_label' => $stateLabel,
            'source' => 'receipt',
            'reason' => null,
        ];
    }

    /**
     * ② سندُ الوقود بالرمز القصير — **مقبولٌ ومقروءٌ كغيره.**
     *
     * ولا يُبنى له سجلٌّ ثانٍ: الصحّةُ تُقرأ من البيعة نفسِها، وهي مصدرُ
     * الحقيقة. (القاعدة السادسة: الرقمُ من مصدره لا من عمودٍ منسوخ.)
     */
    private function fromFuelSale(FuelSale $sale): array
    {
        $cancelled = in_array($sale->status, ['cancelled', 'voided', 'refunded'], true);

        return [
            'found' => true,
            'authenticity' => $sale->status === 'refunded'
                ? 'refunded' : ($cancelled ? 'cancelled' : 'authentic'),
            'authenticity_label' => $this->authenticityLabel(
                $sale->status === 'refunded' ? 'refunded' : ($cancelled ? 'cancelled' : 'authentic')),
            'doc_type' => 'fuel_sale',
            'doc_type_label' => 'سند بيع وقود',
            'document_number' => strtoupper(substr((string) $sale->sale_ulid, -12)),
            'issuer' => $this->merchantName((int) $sale->merchant_user_id),
            'amount' => (string) $sale->total_amount,
            'currency' => 'ر.ي',
            'issued_at' => $this->mecca($sale->created_at),
            'state' => (string) $sale->status,
            'state_label' => $this->stateLabel((string) $sale->status),
            'source' => 'fuel_legacy',
            'reason' => null,
        ];
    }

    // ── مساعدات ───────────────────────────────────────────────────────

    /** البيعةُ التي وُلدت منها الفاتورة — إن كانت فاتورةَ تاجر. */
    private function saleBehind(Receipt $receipt): ?MerchantSale
    {
        $ref = (string) ($receipt->reference_type ?? '');

        if (! str_contains(strtolower($ref), 'sale')) {
            return null;
        }

        $id = $receipt->reference_id;

        return $id ? MerchantSale::find($id) : null;
    }

    /** @return array{0:string,1:string} */
    private function saleState(?MerchantSale $sale, bool $voided): array
    {
        if ($voided) {
            return ['cancelled', 'ملغى'];
        }

        if (! $sale) {
            return ['completed', 'مكتمل'];
        }

        $status = (string) $sale->status;

        return [$status, $this->stateLabel($status)];
    }

    private function stateLabel(string $status): string
    {
        return match ($status) {
            'completed', 'credit_paid', 'paid' => 'مكتمل',
            'credit_unpaid' => 'غير مدفوع (آجل)',
            'partially_paid', 'partial' => 'مدفوع جزئيّاً',
            'refunded' => 'مسترجَع',
            'cancelled', 'voided' => 'ملغى',
            'pending' => 'قيد الإتمام',
            // **ولا يُخترَع معنىً لحالةٍ لا تُعرَف.** رمزٌ خامٌ يُوقف
            // القارئَ ليسأل، والترجمةُ المخترَعةُ تُمرّره واثقاً من معنىً
            // لم يقصده أحد. (درسُ سجلّ التدقيق.)
            default => $status !== '' ? "حالة غير مترجمة: {$status}" : 'حالة غير معروفة',
        };
    }

    private function authenticityLabel(string $a): string
    {
        return match ($a) {
            'authentic' => 'مستند أصلي',
            'cancelled' => 'مستند ملغى — لا يُعتدّ به',
            'refunded' => 'مستند مسترجَع',
            default => 'لا مستند بهذا الرمز',
        };
    }

    private function typeLabel(string $type): string
    {
        return match ($type) {
            'send_money' => 'تحويل أموال',
            'cash_in' => 'إيداع نقدي',
            'cash_out', 'withdraw' => 'سحب نقدي',
            'add_money' => 'إضافة رصيد',
            'pay_merchant' => 'دفع لتاجر',
            'pos_payment' => 'فاتورة نقطة بيع',
            'qr_payment' => 'دفع عبر رمز QR',
            'refund' => 'استرجاع',
            'fee_charge' => 'رسوم',
            'donation' => 'تبرّع',
            'bank_settlement' => 'تسوية بنكيّة',
            default => 'عملية مالية',
        };
    }

    /**
     * **اسمُ المنشأة المُصدِرة — لا اسمُ العميل.**
     *
     * ومن لا منشأةَ له (تحويلٌ بين عميلين) مُصدِرُه المنصّة، ويُقال ذلك
     * صراحةً: فراغٌ في خانة المُصدِر يُقرأ نقصاً في المستند.
     */
    private function issuerOf(Receipt $receipt, ?MerchantSale $sale): string
    {
        if ($sale) {
            return $this->merchantName((int) $sale->merchant_user_id);
        }

        return 'أميال باي';
    }

    private function merchantName(int $merchantUserId): string
    {
        $store = Merchant::where('user_id', $merchantUserId)->value('store_name');

        if ($store) {
            return (string) $store;
        }

        $u = User::find($merchantUserId);
        $name = $u ? trim(($u->f_name ?? '').' '.($u->l_name ?? '')) : '';

        return $name !== '' ? $name : 'منشأة غير مسمّاة';
    }

    /** ووقتُ مكّة يُقال بتوقيته لا خاماً — فالمستندُ يُقرأ في اليمن. */
    private function mecca(?Carbon $at): ?string
    {
        return $at?->copy()->setTimezone(self::TZ)->format('Y-m-d H:i');
    }

    private function notFound(string $reason): array
    {
        return [
            'found' => false,
            'authenticity' => 'not_found',
            'authenticity_label' => $this->authenticityLabel('not_found'),
            'doc_type' => null, 'doc_type_label' => null, 'document_number' => null,
            'issuer' => null, 'amount' => null, 'currency' => null,
            'issued_at' => null, 'state' => null, 'state_label' => null,
            'source' => null, 'reason' => $reason,
        ];
    }
}
