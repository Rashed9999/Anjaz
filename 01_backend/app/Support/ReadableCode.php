<?php

namespace App\Support;

/**
 * AMIAL-RECEIPT-NUMBERS-001 — تجميع الأرقام وتطبيعها.
 *
 * رقم من 12 أو 16 خانة متّصلة لا يُقرأ ولا يُملى بلا خطأ. التجميع يجعله
 * قابلاً للنطق: «مئتان وستون، سبعمئة وستة وعشرون…» بدل سلسلة أرقام صمّاء.
 *
 * والعكس مطلوب أيضاً: العميل يكتب ما يراه — بمسافات أو شَرطات — فيجب أن
 * يجده البحث. `normalize` تزيل كل ما ليس رقماً أو حرفاً قبل المطابقة.
 */
final class ReadableCode
{
    /**
     * يجمع النصّ بمجموعات.
     *
     * الافتراضي أربعات. ورقم الإشعار (12 خانة) يُجمع 6+6 تلقائياً لأن
     * نصفه الأول تاريخ ونصفه الثاني تسلسل — تقسيمه بأربعات يكسر هذا
     * الحدّ فيبدو الرقم عشوائياً ويضيع معناه.
     */
    public static function group(?string $code, ?int $size = null): string
    {
        $clean = self::normalize((string) $code);

        if ($clean === '') {
            return (string) $code;
        }

        $size ??= (strlen($clean) === 12 && ctype_digit($clean)) ? 6 : 4;

        if ($size < 2) {
            return (string) $code;
        }

        // الأشكال القديمة (AMY-...-XXXX) تُترك كما هي: تجميعها يشوّهها
        // ولا يفيد، وهي موجودة على أوراق مطبوعة بالفعل.
        if (!ctype_digit($clean)) {
            return (string) $code;
        }

        return trim(implode(' ', str_split($clean, $size)));
    }

    /**
     * يزيل المسافات والشَرطات ويوحّد الحالة — للمطابقة في البحث.
     *
     * لا يزيل الحروف: الأكواد القديمة Base32، ومطابقتها يجب أن تبقى ممكنة.
     */
    public static function normalize(?string $input): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $input) ?? '');
    }

    /** هل يشبه رقم إشعار أو كود تحقّق (بأي من الشكلين)؟ */
    public static function looksLikeCode(?string $input): bool
    {
        $c = self::normalize($input);

        return (bool) preg_match('/^([0-9]{12,16}|AMY[0-9]{8}[A-Z0-9]{8}|[A-Z2-9]{16})$/', $c);
    }
}
