<?php

namespace App\Console\Commands;

use App\Services\Kyc\IdentityExpiryService;
use Illuminate\Console\Command;

/**
 * AMIAL-KYC-EXPIRY-002 — `php artisan amial:kyc:scan-expiry`
 *
 * **وجولةٌ لم تقرأ حساباً واحداً تسقط.** صفرُ ممسوحين لا يعني «لا هويّةَ
 * منتهية» — يعني «لم أنظر». والفرقُ بينهما هو القاعدةُ السابعة، وقد
 * أرسل هذا المشروعَ ثلاثَ مرّاتٍ خلف عطلٍ لا وجودَ له.
 */
class ScanIdentityExpiryCommand extends Command
{
    protected $signature = 'amial:kyc:scan-expiry {--dry : يقيس ولا يَسِم}';

    protected $description = 'رادارُ انتهاء الهويّة — يُنذر قبلَه ويَسِم بعدَه، ولا يُجمّد';

    public function handle(IdentityExpiryService $expiry): int
    {
        if ($this->option('dry')) {
            $this->warn('جولةُ قياسٍ — لا وسمَ ولا أثر. (غيرُ منفَّذةٍ بعد)');

            return self::SUCCESS;
        }

        $s = $expiry->sweep();

        if ($s['scanned'] === 0) {
            // **ولا يُقرأ الصفرُ سلامة.** لا حسابَ موثَّقاً واحداً يعني
            // إمّا قاعدةً فارغةً وإمّا استعلاماً لا يصل — وكلاهما يُقال.
            $this->error('جولةٌ عمياء: صفرُ حساباتٍ موثَّقة. لا يُقرأ هذا «لا هويّةَ منتهية».');

            return self::FAILURE;
        }

        $this->info(sprintf(
            'رادارُ الهويّة — مُسح %d حساباً موثَّقاً · منتهية %d (وُسم %d) · تقترب %d',
            $s['scanned'], $s['expired'], $s['flagged'], $s['due'],
        ));

        return self::SUCCESS;
    }
}
