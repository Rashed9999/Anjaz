<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\EncryptionService;
use Illuminate\Console\Command;

/**
 * AMIAL-KYC-DUP-001 — **ما جُمع سلفاً يدخل الفحص.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * `national_id_blind_index` كان فارغاً لكلّ حساب، لأنّ خريطةَ التشفير
 * كانت على اسمٍ لا عمودَ له. وقد صُحّحت، **فالحساباتُ الجديدةُ تمتلئ
 * وحدَها — والقديمةُ لا**.
 *
 * وحسابٌ قديمٌ بلا بصمةٍ **لا يُمسَك تكرارُه أبداً**: يبحث المراجعُ عن
 * هويّةٍ فيقال له «غيرُ مسجَّلة» وهي مسجَّلةٌ منذ سنة. **وجوابٌ خاطئٌ
 * واثقٌ أسوأ من لا جواب** (القاعدة السابعة).
 *
 * ولا يمسّ الأمرُ رقماً قائماً: يقرأ `identification_number` كما هو
 * ويكتب الأعمدةَ المشتقّةَ منه وحدَها.
 *
 * يظهر في : لا شاشة — **أمرٌ يُشغَّل مرّةً بعد النشر**، وهذا سببُه.
 * ويُوصل إليه من : الطرفيّة. ونتيجتُه تظهر في لوحة التحقّق: بحثٌ صار
 * يجد ما كان يخفى.
 *
 * @see \Tests\Feature\IdentityLookupGuardTest
 */
class BackfillNationalIdIndexCommand extends Command
{
    protected $signature = 'amial:backfill-national-id
                            {--dry : يَعُدّ ولا يكتب}
                            {--chunk=200 : حجمُ الدفعة}';

    protected $description = 'يملأ بصمةَ رقم الهويّة للحسابات المسجَّلة قبل تصحيح خريطة التشفير';

    public function handle(EncryptionService $enc): int
    {
        $dry = (bool) $this->option('dry');
        $done = $skipped = $already = 0;

        User::query()
            ->whereNotNull('identification_number')
            ->where('identification_number', '!=', '')
            ->orderBy('id')
            ->chunkById((int) $this->option('chunk'), function ($users) use ($enc, $dry, &$done, &$skipped, &$already) {
                foreach ($users as $u) {
                    $raw = (string) ($u->getAttributes()['identification_number'] ?? '');
                    $index = $enc->blindIndex($raw, 'national_id');

                    // **رقمٌ لا يُنتج بصمةً يُعَدّ ويُقال** — لا يُبتلع.
                    // (هويّةٌ نصُّها حروفٌ أو أقصرُ من أن تُطابِق.)
                    if ($index === null) {
                        $skipped++;

                        continue;
                    }

                    if (($u->getAttributes()['national_id_blind_index'] ?? null) === $index) {
                        $already++;

                        continue;
                    }

                    if (! $dry) {
                        // المرورُ عبر السمة يكتب الثلاثةَ معاً — مشفَّراً
                        // وبصمةً وقناعاً — فلا يفترق عمودٌ عن أخويه.
                        $u->identification_number = $raw;
                        $u->save();
                    }

                    $done++;
                }
            });

        $this->info(($dry ? '[تجربة] ' : '')."مكتوبٌ: {$done} · مطابقٌ سلفاً: {$already} · متخطّىً: {$skipped}");

        if ($skipped > 0) {
            $this->warn("و{$skipped} حساباً رقمُ هويّته لا يُنتج بصمةً — لن يُمسَك تكرارُها. "
                .'راجعها يدويّاً.');
        }

        return self::SUCCESS;
    }
}
