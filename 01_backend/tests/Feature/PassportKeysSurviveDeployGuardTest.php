<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AMIAL-PASSPORT-KEYS-PERSIST-001 — **كلُّ نشرةٍ كانت تطرد الجميع.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ما وصل صاحبَ المشروع:** ستُّ شاشاتٍ متتاليةٍ تقول «انتهت الجلسة —
 * سجّل الدخول من جديد»، وهو داخلٌ ولم يفعل شيئاً. وسابعةٌ («العملات»)
 * تعرض «لا عملات مضافة» — **وهي فشلت أيضاً وأخفت فشلَها فراغاً**.
 *
 * **والسببُ واحدٌ يصيب كلَّ مستخدمٍ في كلّ قطاع:**
 *
 *     الحجمُ الدائم    : amial_storage_prod → /var/www/html/storage/app
 *     مفاتيحُ Passport : /var/www/html/storage/oauth-private.key
 *
 * **المفاتيحُ خارجَ الحجم.** فمع كلّ نشرةٍ أو إعادةِ إقلاعٍ تختفي، فيولّدها
 * `entrypoint` جديدةً، **فيبطل كلُّ رمزِ دخولٍ صدر قبلها**. والتطبيقُ ما
 * زال يحمل الرمزَ القديم، فكلُّ نداءٍ محميٍّ يردّ ٤٠١.
 *
 * **والشرطُ كان مكتوباً ولم يُوصَل**: تعليقُ `entrypoint.prod.sh` يقول
 * بنصّه «تحتاج تثبيت storage على volume دائم حتى تبقى الرموز صالحة عبر
 * إعادات التشغيل» — والحجمُ يثبّت `storage/app` لا `storage`. **شرطٌ
 * مُعلَنٌ لا يحقّقه أحد** — وهو نمطُ العطل الذي يطارده المشروع كلُّه.
 *
 * **ولا يمسكه اختبارٌ ولا مُحلِّل**: الشيفرةُ سليمة، والمفاتيحُ تُولَّد
 * بنجاح في كلّ إقلاع. العطلُ في **بقائها**، ولا يظهر إلّا بعد نشرةٍ ثانية.
 */
class PassportKeysSurviveDeployGuardTest extends TestCase
{
    private function repoRoot(): string
    {
        return dirname(base_path());
    }

    private function read(string $rel): string
    {
        $path = base_path($rel);
        $this->assertFileExists($path, "مفقود: {$rel}");

        return (string) file_get_contents($path);
    }

    /** المسارُ الذي يجب أن تعيش فيه المفاتيح — تحت الحجم الدائم. */
    private const KEYS_DIR = 'storage/app/passport';

    // ═════════════════════════════════════════════════════════════════

    /**
     * **① Passport يقرأ مفاتيحَه من داخل الحجم الدائم.**
     */
    /** @test */
    public function passport_loads_its_keys_from_the_persistent_volume(): void
    {
        $provider = $this->read('app/Providers/AuthServiceProvider.php');

        $this->assertStringContainsString('loadKeysFrom', $provider,
            "**Passport لا يُوجَّه إلى موضعِ مفاتيحَ صريح** — فيقرأ من "
            ."`storage/` وهو خارجُ الحجم الدائم، فتُولَّد مفاتيحُ جديدةٌ مع "
            .'كلّ نشرة ويُطرَد كلُّ من سجّل الدخول.');

        $this->assertMatchesRegularExpression(
            "~storage_path\(\s*'app/passport'\s*\)~",
            $provider,
            '**موضعُ المفاتيح ليس تحت `storage/app`** — وهو الحجمُ الدائمُ '
            .'الوحيد. أيُّ موضعٍ آخر يذهب مع الحاوية.');

        // **والتوجيهُ مشروطٌ بوجودها** — توجيهٌ إلى مجلّدٍ فارغٍ يمنع
        // Passport من إيجاد مفتاحٍ فيسقط كلُّ دخولٍ برمز. (كسر سبعةَ
        // اختباراتٍ أوّلَ صياغة، فصار مشروطاً.)
        $this->assertStringContainsString("is_file(\$persistentKeys.'/oauth-private.key')", $provider,
            '**التوجيهُ غيرُ مشروطٍ بوجود المفاتيح** — فبيئةٌ مفاتيحُها في '
            .'الموضع الافتراضيّ يسقط فيها كلُّ دخول.');
    }

    /**
     * **② والحجمُ الدائمُ يغطّي ذلك الموضعَ فعلاً.**
     *
     * فتوجيهُ Passport إلى مسارٍ لا يحفظه أحدٌ يستبدل عطلاً بعطل.
     */
    /** @test */
    public function the_compose_volume_actually_covers_the_keys_path(): void
    {
        $compose = (string) file_get_contents($this->repoRoot().'/01_backend/docker-compose.prod.yml');
        $this->assertNotSame('', $compose, 'ملفُّ الإنتاج مفقود');

        $this->assertStringContainsString('/var/www/html/storage/app', $compose,
            '**لا حجمَ دائمٌ على `storage/app`** — والمفاتيحُ صارت فيه، '
            .'فتذهب مع الحاوية كما كانت.');
    }

    /**
     * **③ والإقلاعُ يولّدها في موضعها — ولا يعود إلى القديم.**
     *
     * **والملفّان معاً**: `entrypoint.sh` (ما ينشره Coolify) و
     * `entrypoint.prod.sh`. وقد تباعدا من قبل مرّتين وكلتاهما كلّفت عطلاً.
     */
    /** @test */
    public function both_entrypoints_generate_keys_in_the_persistent_path(): void
    {
        foreach (['docker/entrypoint.sh', 'docker/entrypoint.prod.sh'] as $rel) {
            $sh = $this->read($rel);

            $this->assertStringContainsString(self::KEYS_DIR, $sh,
                "**{$rel} لا يعرف موضعَ المفاتيح الدائم** — فيفحص المسارَ "
                .'القديم ويولّد مفاتيحَ جديدةً في كلّ إقلاع.');

            // ولا يبقى فحصٌ على المسار القديم يجعل التوليدَ يقع دائماً.
            $this->assertDoesNotMatchRegularExpression(
                '~\[\s*!\s*-f\s+storage/oauth-(private|public)\.key\s*\]~',
                $sh,
                "**{$rel} ما زال يفحص `storage/oauth-*.key`** — وهو المسارُ "
                .'الزائل، فيُقرأ «مفقودة» أبداً وتُولَّد جديدةً كلَّ مرّة.');
        }
    }

    /**
     * **④ والمفاتيحُ القديمةُ تُرحَّل ولا تُهجَر.**
     *
     * فنقلُ الموضع بلا ترحيلٍ يُبطل الرموزَ القائمةَ مرّةً أخرى — أي أنّ
     * إصلاحَ «طردٍ في كلّ نشرة» يبدأ بطردٍ إضافيّ لا لزوم له.
     */
    /** @test */
    public function existing_keys_are_migrated_not_abandoned(): void
    {
        foreach (['docker/entrypoint.sh', 'docker/entrypoint.prod.sh'] as $rel) {
            $sh = $this->read($rel);

            $this->assertStringContainsString('mv storage/oauth-private.key', $sh,
                "**{$rel} لا يرحّل المفاتيحَ القائمة** — فأوّلُ نشرةٍ بعد "
                .'هذا الإصلاح تُبطل رموزَ الجميع بلا داعٍ.');
        }
    }
}
