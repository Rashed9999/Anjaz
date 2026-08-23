<?php

namespace Tests\Feature;

use App\Services\PiiAccessAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-PII-BURST-001 — **كاشفُ تسريبٍ مبنيٌّ ولا يناديه أحد.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * `PiiAccessAuditService::checkSuspiciousActivity` تكشف نمطَ السحب —
 * أكثرَ من ٥٠ سجلَّ بياناتٍ شخصيّةٍ في ساعة، أو ٣٠ عميلاً مختلفاً.
 * **وقِيس أنّ لا مُنادِيَ لها في المشروع كلِّه.**
 *
 * والمنصّةُ تحمل صورَ هويّاتِ عملاءَ يمنيّين وأرقامَهم وعناوينَهم. وموظّفٌ
 * يسحبها دفعةً واحدةً هو التهديدُ الذي كُتبت له — **ولم تُسأل مرّةً**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وليست تكراراً لـ`InsiderWatchService`، والفرقُ هو بيتُ القصيد:**
 *
 *     InsiderWatch  →  حدودٌ **يوميّة**  (١٥٠ مشاهدة/يوم · ٢٠٠ بحث)
 *     هذه           →  حدودٌ **ساعيّة** (٥٠ سجلّاً · ٣٠ شخصاً)
 *
 * وحدٌّ يوميٌّ لا يمسك من سحب خمسين سجلّاً في عشر دقائق ثمّ توقّف —
 * **وتلك بصمةُ التسريب لا الاستعمال**. فالكاشفان يكمّلان ولا يكرّران.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وثلاثةُ شروطٍ في التوصيل:**
 *
 *   ① **عند نقطة الكتابة** — كلُّ قراءةِ PII تمرّ بـ`logAccess`، فلا
 *      بابَ ثانٍ يُنسى. (القاعدةُ الرابعة: ميزةٌ لها مدخلان تُختبَر من
 *      مدخليها — وهذه لها مدخلٌ واحدٌ عمداً.)
 *
 *   ② **ولا يُغرَق مركزُ الأعطال** — المفتاحُ يحمل الفاعلَ والساعة،
 *      فصفٌّ واحدٌ يُحدَّث لا ألفُ صفّ.
 *
 *   ③ **والرصدُ لا يُسقط القراءة.** رميٌ هنا يمنع موظّفاً من خدمة
 *      عميل — وحاجزٌ يشلّ عملاً سليماً يُطفَأ عند أوّل شكوى.
 */
class PiiBurstGuardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * **والفاعلُ مستخدمٌ حقيقيّ** — `actor_user_id` مفتاحٌ أجنبيّ،
     * ورقمٌ اعتباطيٌّ يسقط بـ1452. (قِيس ولم يُفترَض.)
     */
    private function actor(): int
    {
        return (int) \App\Models\User::factory()->create(['type' => 1])->id;
    }

    private function reads(int $actor, int $count, int $subjects): void
    {
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'actor_user_id' => $actor,
                'subject_type' => 'user',
                'subject_id' => 1000 + ($i % $subjects),
                'field_name' => 'identification_number',
                'access_type' => 'view',
                'created_at' => now()->subMinutes(5),
            ];
        }

        DB::table('pii_access_logs')->insert($rows);
    }

    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function a_burst_raises_an_operations_alert(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **هذا هو العطلُ بعينه.** الكاشفُ كان يعمل ولا يُسأل، فالسحبُ
        // يقع ولا يعلم به أحد — **ولا خطأَ في أيّ سجلّ**: كلُّ قراءةٍ
        // مشروعةٌ على حدة.
        // ══════════════════════════════════════════════════════════════
        $a = $this->actor();
        $this->reads(actor: $a, count: 60, subjects: 40);

        app(PiiAccessAuditService::class)
            ->logAccess($a, 'user', 2000, 'identification_number');

        $this->assertDatabaseHas('system_errors', [
            'fingerprint' => hash('sha256', 'ops|pii.burst.' . $a . '.' . now()->format('YmdH')),
        ]);
    }

    /** @test */
    public function ordinary_use_raises_nothing(): void
    {
        // **وإنذارٌ كاذبٌ يُعوّد القارئَ على التجاهل** — ثمّ لا يراه يومَ
        // يصدق. فموظّفُ دعمٍ يفتح خمسةَ ملفّاتٍ لا يُوقظ أحداً.
        $a = $this->actor();
        $this->reads(actor: $a, count: 5, subjects: 5);

        app(PiiAccessAuditService::class)
            ->logAccess($a, 'user', 2000, 'phone');

        $this->assertDatabaseMissing('system_errors', [
            'fingerprint' => hash('sha256', 'ops|pii.burst.' . $a . '.' . now()->format('YmdH')),
        ]);
    }

    /** @test */
    public function a_burst_does_not_flood_the_error_centre(): void
    {
        // **وصفٌّ لكلّ قراءةٍ يُغرق الشاشةَ فيُخفي ما عداه.** المفتاحُ
        // يحمل الفاعلَ والساعة، فيُحدَّث الصفُّ القائم.
        $a = $this->actor();
        $this->reads(actor: $a, count: 60, subjects: 40);

        $svc = app(PiiAccessAuditService::class);

        for ($i = 0; $i < 5; $i++) {
            $svc->logAccess($a, 'user', 3000 + $i, 'identification_number');
        }

        $this->assertSame(1, DB::table('system_errors')
            ->where('fingerprint', hash('sha256', 'ops|pii.burst.' . $a . '.' . now()->format('YmdH')))
            ->count(), 'صفٌّ لكلّ قراءة — فمركزُ الأعطال يُغرَق');
    }

    /** @test */
    public function the_read_still_succeeds_when_watching_fails(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **والرصدُ لا يُسقط ما يرصده.** لو رمى الكاشفُ لمنع موظّفاً من
        // فتح ملفِّ عميلٍ يقف أمامه — وذاك حاجزٌ يشلّ عملاً سليماً،
        // ويُطفَأ عند أوّل شكوى فيذهب الرصدُ كلُّه معه.
        // ══════════════════════════════════════════════════════════════
        $this->swap(\App\Services\OpsAlertService::class,
            new class extends \App\Services\OpsAlertService {
                public function note(string $key, string $title, string $detail): void
                {
                    throw new \RuntimeException('قناةُ الإنذار ساقطة');
                }
            });

        $a = $this->actor();
        $this->reads(actor: $a, count: 60, subjects: 40);

        app(PiiAccessAuditService::class)
            ->logAccess($a, 'user', 4000, 'identification_number');

        // **والقراءةُ نفسُها تُسجَّل** — وهي الأثرُ الذي لا يجوز فقدُه.
        $this->assertDatabaseHas('pii_access_logs', [
            'actor_user_id' => $a,
            'subject_id' => 4000,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function the_write_chokepoint_calls_the_detector(): void
    {
        // **ويُقرأ من الشيفرة بلا تعليقاتها** — فتعليقٌ يذكر الاسمَ أخفى
        // غيابَه في حارسٍ آخرَ بهذه الجلسة.
        $s = (string) file_get_contents(app_path('Services/PiiAccessAuditService.php'));
        $s = preg_replace('~/\*.*?\*/~s', '', $s) ?? '';
        $s = preg_replace('~^[ \t]*//[^\n]*$~m', '', $s) ?? '';

        $fn = substr($s, (int) strpos($s, 'function logAccess'), 4000);

        $this->assertStringContainsString('checkSuspiciousActivity', $fn,
            'نقطةُ الكتابة لا تسأل الكاشف — فالسحبُ يقع ولا يعلم به أحد');
    }
}
