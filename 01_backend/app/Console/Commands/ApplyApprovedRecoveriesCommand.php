<?php

namespace App\Console\Commands;

use App\Models\AccountRecoveryRequest;
use App\Services\AccountRecoveryService;
use App\Services\OpsAlertService;
use Illuminate\Console\Command;

/**
 * AMIAL-RECOVERY-APPLY-001 — **مسارُ الإنقاذ كان يقفل الحسابَ الذي يُنقذه.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ما قِيس، ولم يُفترَض:**
 *
 *     AccountRecoveryService::applyApprovedChange
 *       @doc «يُستدعى من Job بعد انتهاء security_hold»
 *       المُنادُون في المشروع كلِّه:  **صفر**
 *
 * وعند الموافقة — من العميل نفسِه أو من الإدارة — يقع هذا كلُّه فوراً:
 *
 *     status = approved
 *     security_hold_until = الآن + N ساعة
 *     **كلُّ الرموز تُبطَل**   ← يُطرَد من جلسته
 *     **fcm_token يُمحى**     ← لا إشعارَ يصله
 *
 * **ثمّ لا شيء.** الرقمُ الجديد لا يُكتب أبداً. فصاحبُ الحساب:
 *
 *   · لا يدخل بالقديم — وقد فقده، وهو سببُ الطلب أصلاً
 *   · ولا بالجديد — لم يُسجَّل قطّ
 *   · ولا يصله إشعارٌ يخبره
 *
 * **فمسارُ الإنقاذ يقفل الحسابَ الذي بُني لإنقاذه**، بصمت، ولا يُصلَح
 * إلّا بيدٍ على القاعدة. وهذا في اليمن ليس نادراً: فقدُ الرقم واقعٌ
 * متكرّر، وهو بعينه ما تُبنى الاستعادةُ له.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولمَ لم يمسكه شيء:** الدالّةُ **مُختبَرةٌ** وخضراء — تعمل كما كُتبت.
 * ولا شيءَ في المشروع كان يسأل **من يناديها**. أخرجها جردُ ساهر
 * (`SERVICE_METHOD_UNREACHED`) لا مجموعةُ الاختبارات.
 *
 * **والمُهلةُ تُقرأ من الحساب لا من الطلب**: `security_hold_until` هناك
 * كُتبت، وهناك تُقرأ — فلا يُخمَّن انقضاؤها من طابعٍ زمنيٍّ آخر.
 *
 * يظهر في : لا شاشة — أمرٌ مجدولٌ كلَّ عشر دقائق. وأثرُه يُرى في:
 * التطبيق ← «أمان الحساب» (حدثُ `PHONE_CHANGED`) · ولوحة الإدارة ←
 * الامتثال والمخاطر ← مركز الأعطال حين يتعثّر تطبيقٌ مستحقّ.
 */
class ApplyApprovedRecoveriesCommand extends Command
{
    protected $signature = 'amial:recovery:apply-approved {--dry-run : يعرض ولا يُطبّق}';

    protected $description = 'تطبيقُ استعادات الحساب المعتمَدة بعد انقضاء مهلة الأمان';

    public function handle(AccountRecoveryService $service, OpsAlertService $alerts): int
    {
        $due = AccountRecoveryRequest::query()
            ->where('status', 'approved')
            ->orderBy('id')
            ->get();

        if ($due->isEmpty()) {
            $this->info('لا طلبَ معتمَداً ينتظر.');

            return self::SUCCESS;
        }

        $applied = 0;
        $waiting = 0;
        $stuck = [];

        foreach ($due as $request) {
            $user = \App\Models\User::find($request->user_id);

            if (! $user) {
                // **وطلبٌ لحسابٍ محذوفٍ يبقى «معتمَداً» إلى الأبد** فيُعدّ
                // متعثّراً في كلّ جولة. يُقال مرّةً ولا يُكرَّر بلا سبب.
                $stuck[] = "#{$request->id}: لا حسابَ للمعرّف {$request->user_id}";
                continue;
            }

            if ($user->security_hold_until && $user->security_hold_until->isFuture()) {
                $waiting++;
                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("سيُطبَّق: #{$request->id} · {$request->old_phone} → {$request->new_phone}");
                $applied++;
                continue;
            }

            if ($service->applyApprovedChange($request)) {
                $applied++;
                $this->info("✓ #{$request->id} · {$request->old_phone} → {$request->new_phone}");
                continue;
            }

            // **ورفضٌ صامتٌ هنا يُعيد العطلَ نفسَه بصورةٍ أصغر.** الدالّةُ
            // تُرجع `false` لأسبابٍ عدّة، وكلُّها تترك الحسابَ مقفولاً.
            $stuck[] = "#{$request->id}: رُفض التطبيقُ والمهلةُ منقضية";
        }

        $this->line("طُبّق {$applied} · ينتظر المهلةَ {$waiting} · متعثّر " . count($stuck));

        // ══════════════════════════════════════════════════════════════
        // **وحسابٌ عالقٌ لا يشتكي — صاحبُه يشتكي.** فيُرفع أثراً في مركز
        // الأعطال قبل أن يصل الهاتف، لا بعده.
        // ══════════════════════════════════════════════════════════════
        if ($stuck !== []) {
            $alerts->raise(
                'recovery.apply.stuck',
                'استعاداتُ حسابٍ معتمَدةٌ لم تُطبَّق بعد انقضاء المهلة',
                "عدد: " . count($stuck) . "\n" . implode("\n", array_slice($stuck, 0, 20))
                . "\n\nوصاحبُ كلِّ حسابٍ منها **مطرودٌ من جلسته ولا يدخل برقمٍ**: "
                . 'القديمُ فُقد والجديدُ لم يُكتب. يُعالَج يدويّاً من لوحة الإدارة '
                . '← استعادة الحسابات.',
            );
        }

        return self::SUCCESS;
    }
}
