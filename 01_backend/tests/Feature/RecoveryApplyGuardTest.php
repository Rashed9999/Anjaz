<?php

namespace Tests\Feature;

use App\Models\AccountRecoveryRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

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
 * وعند الموافقة — من العميل أو من الإدارة، والمساران متطابقان — يقع هذا
 * **فوراً**:
 *
 *     status = approved · security_hold_until = الآن + N ساعة
 *     كلُّ الرموز تُبطَل   ← يُطرَد من جلسته
 *     fcm_token يُمحى     ← لا إشعارَ يصله
 *
 * **ثمّ لا شيء.** الرقمُ الجديد لا يُكتب أبداً. فصاحبُ الحساب لا يدخل
 * بالقديم — وقد فقده، وهو سببُ الطلب — ولا بالجديد، ولا يُخبَره أحد.
 *
 * **ولا يُصلَح إلّا بيدٍ على القاعدة.** وفقدُ الرقم في اليمن واقعٌ
 * متكرّر، وهو بعينه ما بُنيت الاستعادةُ له.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولمَ لم يمسكه شيء:** الدالّةُ **مُختبَرةٌ** وخضراء — تعمل كما كُتبت
 * بالضبط. ولا شيءَ كان يسأل **من يناديها**. أخرجها جردُ ساهر
 * (`SERVICE_METHOD_UNREACHED`)، لا ٣١٢٤ اختباراً ولا ٣٣ فحصاً.
 *
 * وهو الصنفُ نفسُه الذي أخرج في هذه الجلسة: حدَّ استلام التاجر، وقفلَ
 * رمز العمليّات، وحارسَ كودكس ضدّ الحساب القديم. **أربعةٌ في يومٍ واحد،
 * كلُّها «مبنيٌّ ومُختبَرٌ ولا يُوصَل إليه»** — وهو أخطرُ من الميّت:
 * الميّتُ لا يَعِد بشيء.
 */
class RecoveryApplyGuardTest extends TestCase
{
    use RefreshDatabase;

    private function approvedRequest(int $holdHours): array
    {
        $user = User::factory()->create([
            'type' => 2,
            'is_active' => 1,
            'phone' => '967777100001',
        ]);

        $user->security_hold_until = now()->addHours($holdHours);
        $user->security_hold_reason = 'phone_change_admin_approved';
        $user->saveQuietly();

        $req = AccountRecoveryRequest::create([
            'request_ulid' => (string) Str::ulid(),
            'user_id' => $user->id,
            'request_type' => 'phone_change_lost_phone',
            'old_phone' => '967777100001',
            'new_phone' => '967777100099',
            'status' => 'approved',
            'expires_at' => now()->addDays(7),
        ]);

        return [$user, $req];
    }

    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function an_approved_recovery_is_actually_applied_once_the_hold_expires(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **هذا هو العطلُ بعينه، مُصاغاً اختباراً.** الطلبُ معتمَدٌ
        // والمهلةُ انقضت — ولا شيءَ كان يكتب الرقمَ الجديد.
        // ══════════════════════════════════════════════════════════════
        [$user] = $this->approvedRequest(holdHours: -1);

        $this->artisan('amial:recovery:apply-approved')->assertExitCode(0);

        $this->assertSame('967777100099', $user->fresh()->phone,
            'مهلةُ الأمان انقضت والرقمُ الجديد لم يُكتب — '
            . 'فصاحبُ الحساب لا يدخل بالقديم ولا بالجديد');

        $this->assertNull($user->fresh()->security_hold_until,
            'بقيت مهلةُ الأمان بعد التطبيق — فالحسابُ ما زال مقيَّداً');
    }

    /** @test */
    public function a_hold_that_has_not_expired_is_left_alone(): void
    {
        // **والمهلةُ ليست شكليّة.** هي النافذةُ التي يستطيع فيها المالكُ
        // الحقيقيُّ أن يعترض إن كان الطلبُ من منتحل. فتطبيقٌ مبكِّرٌ
        // يُسلّم الحسابَ لمن طلبه.
        [$user] = $this->approvedRequest(holdHours: 12);

        $this->artisan('amial:recovery:apply-approved')->assertExitCode(0);

        $this->assertSame('967777100001', $user->fresh()->phone,
            'طُبّق التغييرُ قبل انقضاء مهلة الأمان — فنافذةُ الاعتراض أُلغيت');
    }

    /** @test */
    public function a_dry_run_changes_nothing(): void
    {
        // **وأداةٌ تدّعي المعاينةَ ثمّ تكتب أسوأ من غيابها.**
        [$user] = $this->approvedRequest(holdHours: -1);

        $this->artisan('amial:recovery:apply-approved', ['--dry-run' => true])
            ->assertExitCode(0);

        $this->assertSame('967777100001', $user->fresh()->phone,
            '`--dry-run` كتب في القاعدة');
    }

    /** @test */
    public function a_stuck_recovery_reaches_the_error_centre(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **وحسابٌ عالقٌ لا يشتكي — صاحبُه يشتكي.** فيُرفَع أثرٌ في مركز
        // الأعطال قبل أن يصل الهاتفُ لا بعده.
        //
        // **وصياغةُ الحالة صُحّحت بالقياس:** كُتبت أوّلَ مرّةٍ بطلبٍ
        // لحسابٍ محذوف — **والقاعدةُ تمنعه**: المفتاحُ الأجنبيُّ
        // `ON DELETE CASCADE`، فحذفُ المستخدم يحذف طلبَه. أي أنّ ذلك
        // الفرعَ دفاعيٌّ لا يقع اليوم.
        //
        // فالحالةُ الواقعةُ هي أن يرفض التطبيقُ نفسُه والمهلةُ منقضية —
        // ويُصنَع بخدمةٍ مزيَّفةٍ تُرجع `false`، فيُقاس الإنذارُ لا الحظّ.
        // ══════════════════════════════════════════════════════════════
        $this->approvedRequest(holdHours: -1);

        $this->swap(\App\Services\AccountRecoveryService::class,
            new class extends \App\Services\AccountRecoveryService {
                public function __construct() {}

                public function applyApprovedChange(
                    \App\Models\AccountRecoveryRequest $request): bool
                {
                    return false;
                }
            });

        $this->artisan('amial:recovery:apply-approved')->assertExitCode(0);

        $this->assertDatabaseHas('system_errors', [
            'fingerprint' => hash('sha256', 'ops|recovery.apply.stuck'),
        ]);
    }

    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function the_sweeper_is_actually_scheduled(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **وأمرٌ لا يُجدوَل ليس مبنيّاً.** هذا هو العطلُ الأصليُّ بصورةٍ
        // أخرى: دالّةٌ صحيحةٌ ينتظرها Job لم يُكتَب قطّ.
        //
        // ويُقرأ من الشيفرة بلا تعليقاتها — فالتعليقُ الذي يصف الجدولةَ
        // كان يُخفي غيابَها في حرّاسَ أُخرى بهذه الجلسة.
        // ══════════════════════════════════════════════════════════════
        $src = (string) file_get_contents(base_path('routes/console.php'));
        $src = preg_replace('~^[ \t]*//[^\n]*$~m', '', $src) ?? '';

        $this->assertMatchesRegularExpression(
            "~Schedule::command\('amial:recovery:apply-approved'\)~", $src,
            'الأمرُ غيرُ مجدول — فالاستعادةُ المعتمَدةُ تنتظر إلى الأبد');

        // **وكلَّ عشر دقائق لا كلَّ يوم**: المهلةُ تنقضي في لحظةٍ بعينها،
        // وعميلٌ مقفولٌ ينتظر يوماً كاملاً يتّصل بالدعم قبل أن يُفتَح.
        $at = strpos($src, "amial:recovery:apply-approved");
        $window = substr($src, $at, 200);

        $this->assertMatchesRegularExpression(
            '~everyTenMinutes|everyFiveMinutes|everyMinute|hourly~', $window,
            'الجدولةُ متباعدةٌ — وعميلٌ مقفولٌ ينتظرها');
    }

    /** @test */
    public function both_approval_doors_lead_to_the_same_sweeper(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **القاعدةُ الرابعة: ميزةٌ لها مدخلان تُختبَر من مدخليها.**
        //
        // الموافقةُ تقع من بابين — العميلُ نفسُه (`selfServiceApprove`)
        // والإدارة (`adminApprove`) — وكلاهما يكتب `approved` ويضع مهلةً
        // ويُبطل الرموز. فمكنسةٌ تقرأ حالةً واحدةً تغطّيهما، **ومكنسةٌ
        // تقرأ نوعَ الطلب تترك أحدَهما عالقاً**.
        // ══════════════════════════════════════════════════════════════
        $cmd = (string) file_get_contents(
            base_path('app/Console/Commands/ApplyApprovedRecoveriesCommand.php'));
        $cmd = preg_replace('~/\*.*?\*/~s', '', $cmd) ?? '';

        $this->assertStringContainsString("where('status', 'approved')", $cmd,
            'المكنسةُ لا تختار بالحالة — فقد تترك باباً عالقاً');

        $this->assertStringNotContainsString("request_type", $cmd,
            'المكنسةُ تُرشّح بنوع الطلب — والموافقةُ تأتي من بابين');
    }
}
