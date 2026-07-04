<?php

namespace App\Support;

/**
 * AMIAL-PHONE-001 — توحيد صيغة الهاتف اليمني.
 *
 * كان في المشروع ثلاثة مطبّعات مختلفة (تسجيل يخزّن +967…، بعض الخدمات تُزيل رمز
 * الدولة، واتساب يُبقيه) → عدم تطابق يمنع التحويل/الدخول أحياناً. هذا المصدر
 * الموحّد الوحيد للحقيقة:
 *
 *   canonical('+967 77-100 0001') === '967771000001'   (967 + الرقم الوطني، بلا + بلا صفر)
 *   variants(...)                 === كل الصيغ المكافئة للبحث في عمود مخزّن بأيّ شكل
 */
final class Phone
{
    /** الشكل القانوني: 967 + الرقم الوطني (بلا +، بلا 00، بلا صفر بادئ، بلا رموز). */
    public static function canonical(string $phone): string
    {
        $p = preg_replace('/[\s\-\(\)]/', '', trim($phone));   // إزالة الفراغات والرموز
        $p = preg_replace('/^\+/', '', $p);                    // إزالة +
        if (str_starts_with($p, '00')) {
            $p = substr($p, 2);                                // 00967… → 967…
        }
        if (str_starts_with($p, '967')) {
            $p = substr($p, 3);                                // 967… → وطني
        }
        $p = ltrim($p, '0');                                   // إزالة صفر محلّي بادئ
        return '967' . $p;
    }

    /** الرقم الوطني فقط (بلا رمز دولة): 771000001. */
    public static function national(string $phone): string
    {
        return substr(self::canonical($phone), 3);
    }

    /**
     * كل الصيغ المكافئة — للبحث في عمود phone قد يكون مخزّناً بأيّ شكل تاريخي
     * (+967…، 00967…، 967…، وطني، أو وطني بصفر بادئ).
     *
     * @return string[]
     */
    public static function variants(string $phone): array
    {
        $c   = self::canonical($phone);   // 967771000001
        $nat = substr($c, 3);             // 771000001
        return array_values(array_unique([
            $c,          // 967771000001
            '+' . $c,    // +967771000001
            '00' . $c,   // 00967771000001
            $nat,        // 771000001
            '0' . $nat,  // 0771000001
        ]));
    }
}
