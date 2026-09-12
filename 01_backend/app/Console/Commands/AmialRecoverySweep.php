<?php

namespace App\Console\Commands;

use App\Services\WrongTransferRecoveryService;
use Illuminate\Console\Command;

/**
 * AMIAL-WRONG-TRANSFER-001 — **كنسُ الحجوز والذمم.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وهو ما يجعل الحجزَ إجراءً لا مصادرة.** بلا هذا الأمر يبقى مالُ
 * المستلِم محجوزاً إلى الأبد كلَّما نسي الدعمُ ملفّاً — و«سنراجعه
 * يدويّاً» تعني «لن يُراجَع».
 *
 * **وهو أيضاً نصفُ الجواب عن «صرفها عند التجّار»**: ما لم يُدرَك بالحجز
 * يُقتطَع من الوارد لاحقاً، ريالاً ريالاً، بلا تدخّلٍ من أحد.
 *
 * يظهر في : لوحة الإدارة ← 🔍 سجلّ تدقيق النظام (‏`WRONG_TRANSFER_
 * CLAIM_EXPIRED` و`WRONG_TRANSFER_RECEIVABLE_COLLECTED`) · وفي منصّة
 * الدعم ← تتبّعُ العمليّة ← «دعاوى التحويل الخاطئ».
 */
class AmialRecoverySweep extends Command
{
    protected $signature = 'amial:recovery-sweep';

    protected $description = 'AMIAL: يُفرج عن حجوز الدعاوى المنتهية مهلتُها، ويقتطع الذممَ من الوارد';

    public function handle(WrongTransferRecoveryService $service): int
    {
        $expired = $service->expireStale();
        $collected = $service->collectReceivables();

        // **يُطبَع دائماً وإن كان صفراً.** صمتٌ عند الصفر لا يفرّق بين
        // «لا شيءَ مستحقّ» و«الأمرُ لم يجرِ أصلاً». (القاعدة السابعة.)
        $this->info("أُفرج عن {$expired} حجزاً منتهياً · واقتُطع {$collected} من الذمم.");

        return self::SUCCESS;
    }
}
