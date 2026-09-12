<?php

namespace Tests\Support;

use Illuminate\Http\UploadedFile;

/**
 * AMIAL-KYC-DOSSIER-FIXTURE-001 — **ملفُّ فتح الحساب في موضعٍ واحد.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * شدّد صاحبُ المشروع إنشاءَ الحسابات من اللوحة: ثمانيةُ حقولِ «اعرف
 * عميلك» + إفصاحُ المنصب السياسيّ + إقرارُ صحّة البيانات + ثلاثُ صور،
 * وللتاجر ثلاثةٌ أخرى (سجلٌّ ومفوَّضٌ بالتوقيع).
 *
 * **وهو تشديدٌ سليمٌ في منتجٍ ماليّ.** لكنّه أسقط خمسَ تجهيزاتٍ في ثلاثة
 * ملفّاتٍ كانت تُنشئ حساباً بخمسة حقول.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولمَ موضعٌ واحد:** لو نُسخت الحقولُ في كلّ ملفّ، لَسقطت كلُّها ثانيةً
 * مع أوّل حقلٍ يُضاف — ولوجب تعديلُ خمسةِ مواضع. وهو **نفسُ العطل الذي
 * يُصلحه هذا العمل كلُّه**: حقيقةٌ واحدةٌ في مواضعَ متفرّقة.
 *
 * **والقيمُ تُقرأ من مصادرها** (`KycProfileFields`) لا تُكتب نصّاً — فقائمةٌ
 * مكتوبةٌ تشيخ: يُضاف مصدرُ دخلٍ في الشيفرة ولا يعرفه الاختبار أبداً.
 */
trait OpensAccountsFromHub
{
    /**
     * ملفُّ فتح حسابٍ كاملٌ ومقبول.
     *
     * @param  bool  $merchant  أيُضاف ما يخصّ التاجر (سجلّ ومفوَّض)؟
     * @return array<string,mixed>
     */
    protected function kycDossier(string $phone, bool $merchant = false): array
    {
        $tail = substr($phone, -6);

        $dossier = [
            'dial_country_code' => '+967',

            // **والقيمُ من الفهرس لا من الذاكرة** — أوّلُ صياغةٍ كتبتُها
            // استعملت `'تجارة'` و`'national_id'`، وكلاهما مرفوض.
            'gender' => 'male',
            'date_of_birth' => '1990-01-01',
            'identification_type' => 'nid',
            'identification_number' => '0100' . $tail,
            'address' => 'صنعاء — شارع الاختبار',
            'residence_district' => 'الصافية',
            'income_source' => \App\Support\Kyc\KycProfileFields::INCOME_SOURCES[1],
            'account_purpose' => \App\Support\Kyc\KycProfileFields::ACCOUNT_PURPOSES[2],

            // **والإفصاحُ يُقال ولا يُترك** — غيابُه ليس «لا»، وهو ما
            // يشترطه المتحكّم صراحةً.
            'is_pep' => 0,
            'declaration_accepted' => 1,

            'identity_front' => UploadedFile::fake()->image('front.jpg'),
            'identity_back' => UploadedFile::fake()->image('back.jpg'),
            'selfie' => UploadedFile::fake()->image('selfie.jpg'),
        ];

        if ($merchant) {
            $dossier += [
                'business_registration_number' => 'REG-' . $tail,
                'authorized_signatory_name' => 'صاحبُ المنشأة',
                'authorized_signatory_id' => '0100' . $tail,
            ];
        }

        return $dossier;
    }
}
