<?php

namespace App\Services\Kyc;

use App\Models\KycDocument;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-KYC-EXPIRY-001 — **تاريخُ انتهاءٍ يُجمَع ولا يُقرأ.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الطلب:** «لا يوجد تواريخ انتهاء هويّته أو تنبيهاً».
 *
 * وقِيس، فالتواريخُ **موجودةٌ وتُجمَع**، والعطلُ في القراءة لا في الجمع:
 *
 *     identification_expiry_date  →  صفرُ قُرّاءٍ في المشروع كلِّه
 *     document_expires_at         →  قارئٌ واحد: `completenessFor()`
 *     أوامرُ مجدولةٌ تعيد الفحص    →  صفر
 *
 * **فالانتهاءُ محروسٌ عند البوّابة ومهجورٌ بعدها**: هويّةٌ منتهيةٌ تمنع
 * **اعتماداً جديداً**، ولا تمسّ من اعتُمد أمس. ومن وُثِّق مرّةً وُثِّق
 * للأبد وإن انتهت وثيقتُه قبل سنتين.
 *
 * **وحقلٌ يُطلَب ولا يُقرأ أسوأ من غيابه**: يُوهم بأنّ الأمرَ مضبوطٌ فلا
 * يبحث أحد — وهو نمطُ العطل الأكثرُ تكراراً في هذا المشروع، واقعاً هنا
 * على إلزامٍ رقابيّ.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولا يُجمَّد المالُ آليّاً — يُنذَر ويقرّر إنسان.**
 *
 * تجميدٌ صامتٌ ليلاً على مئات الحسابات **أسوأ من الثغرة التي يسدّها**:
 * يشلّ عملاءَ لم يخطئوا، ويصل صاحبَ المشروع عبر مئة شكوى لا عبر شاشة.
 * (وهو الدرسُ نفسُه: حاجزٌ يشلّ عملاً سليماً أسوأ من ثغرةٍ تُكتشَف
 * بتدقيق.)
 *
 * فالخدمةُ **تَسِمُ ولا تمنع**: تُشعل `kyc_update_required` فيدخل الحسابُ
 * طابورَ المراجعة، وتكتب أثراً في سجلّ التدقيق. والمنعُ قرارُ مراجعٍ
 * ينظر في الحالة.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والعتباتُ ثلاثٌ لأنّ إنذاراً واحداً في اليوم صفر ليس إنذاراً.**
 *
 * وثيقةٌ رسميّةٌ في اليمن تُستخرج في أسابيع. فمن عُلم يومَ انتهائها لا
 * يجد وقتاً. ٦٠ · ٣٠ · ٧ — ثمّ «انتهت».
 */
class IdentityExpiryService
{
    /** أيّامُ الإنذار قبل الانتهاء — من الأبعد إلى الأقرب. */
    public const THRESHOLDS = [60, 30, 7];

    /** حالاتُ الهويّة — تُقرأ ولا تُخمَّن. */
    public const STATE_VALID = 'VALID';
    public const STATE_DUE = 'DUE';        // تقترب
    public const STATE_EXPIRED = 'EXPIRED';
    public const STATE_UNKNOWN = 'UNKNOWN'; // لا تاريخَ عندنا

    public function __construct(private readonly AuditService $audit)
    {
    }

    /**
     * حالةُ هويّة حسابٍ واحد — **ولا يُخترَع جوابٌ لِما لا يُعرف**.
     *
     * غيابُ التاريخ ليس «سارية». (القاعدة السابعة: «غير معروف» ليس صفراً،
     * وها هنا ليس «سليماً».)
     *
     * @return array{state:string, expires_at:?string, days:?int, source:?string}
     */
    public function stateOf(User $user, ?Carbon $now = null): array
    {
        $now = $now ?? now();

        // **ويُؤخذ الأقربُ من مصدرين لا الأوّلُ الذي يوجد.**
        //
        // للحساب تاريخٌ في ملفّه (`identification_expiry_date`) وتواريخُ
        // على وثائقه المعتمَدة (`document_expires_at`). وأخذُ أحدهما
        // اعتباطاً يُخرج «سارية» على حسابٍ إحدى وثيقتيه منتهية.
        $candidates = [];

        if (Schema::hasColumn('users', 'identification_expiry_date')
            && ! empty($user->identification_expiry_date)) {
            $candidates['profile'] = Carbon::parse($user->identification_expiry_date);
        }

        $docExpiry = KycDocument::where('user_id', $user->id)
            ->where('status', KycDocument::STATUS_APPROVED)
            ->whereNotNull('document_expires_at')
            ->min('document_expires_at');

        if ($docExpiry !== null) {
            $candidates['document'] = Carbon::parse($docExpiry);
        }

        if ($candidates === []) {
            return [
                'state' => self::STATE_UNKNOWN,
                'expires_at' => null,
                'days' => null,
                'source' => null,
            ];
        }

        $source = array_keys($candidates, min($candidates))[0];
        $expiry = min($candidates);

        // **والفرقُ بالأيّام يُقاس من بداية اليوم لا من لحظته** — وإلّا
        // صارت وثيقةٌ تنتهي اليومَ ظهراً «سارية» صباحاً و«منتهية» مساءً،
        // فيقرأ موظّفان الحالةَ نفسَها مختلفة.
        $days = (int) $now->copy()->startOfDay()->diffInDays($expiry->copy()->startOfDay(), false);

        if ($days < 0) {
            $state = self::STATE_EXPIRED;
        } elseif ($days <= max(self::THRESHOLDS)) {
            $state = self::STATE_DUE;
        } else {
            $state = self::STATE_VALID;
        }

        return [
            'state' => $state,
            'expires_at' => $expiry->toDateString(),
            'days' => $days,
            'source' => $source,
        ];
    }

    /**
     * جولةُ الرادار — تَسِم من انتهت هويّتُه وتُنذر من اقتربت.
     *
     * @return array{scanned:int, expired:int, due:int, flagged:int}
     */
    public function sweep(?Carbon $now = null): array
    {
        $now = $now ?? now();
        $stats = ['scanned' => 0, 'expired' => 0, 'due' => 0, 'flagged' => 0];

        // **ولا يُمسح إلّا من يعنيه الأمر**: الموثَّقون. فحسابٌ لم يُوثَّق
        // بعدُ ممنوعٌ أصلاً، وإنذارُه ضجيجٌ يُغرق الحقيقيَّ.
        User::query()
            ->where('is_kyc_verified', 1)
            ->chunkById(200, function ($users) use (&$stats, $now) {
                foreach ($users as $user) {
                    $stats['scanned']++;
                    $state = $this->stateOf($user, $now);

                    if ($state['state'] === self::STATE_EXPIRED) {
                        $stats['expired']++;

                        if ($this->flag($user, $state)) {
                            $stats['flagged']++;
                        }

                        continue;
                    }

                    if ($state['state'] === self::STATE_DUE
                        && in_array($state['days'], self::THRESHOLDS, true)) {
                        $stats['due']++;
                        $this->warn($user, $state);
                    }
                }
            });

        return $stats;
    }

    /**
     * وسمُ حسابٍ هويّتُه منتهية — **ولا يُكرَّر الوسمُ كلَّ ليلة**.
     *
     * فسجلُّ تدقيقٍ يمتلئ بالسطر نفسِه ثلاثين مرّةً في الشهر يُعوّد
     * القارئَ على التمرير، ويُغرق الحدثَ الحقيقيَّ.
     */
    private function flag(User $user, array $state): bool
    {
        if (! Schema::hasColumn('users', 'kyc_update_required')) {
            return false;
        }

        if ((int) ($user->kyc_update_required ?? 0) === 1) {
            return false;   // موسومٌ سلفاً — لا يُعاد
        }

        DB::table('users')->where('id', $user->id)->update(array_filter([
            'kyc_update_required' => 1,
            'kyc_update_requested_at' => now(),
            'kyc_update_previous_tier' => Schema::hasColumn('users', 'kyc_update_previous_tier')
                ? (int) ($user->kyc_tier ?? 2) : null,
        ], fn ($v) => $v !== null));

        $this->audit->record([
            'actor_type' => 'system',
            'actor_user_id' => null,
            'subject_type' => 'user',
            'subject_id' => (string) $user->id,
            'action' => 'KYC_IDENTITY_EXPIRED',
            'decision_code' => 'IDENTITY_EXPIRED',
            'reason' => 'انتهت وثيقةُ الهويّة في ' . $state['expires_at']
                . ' — وُسم الحسابُ لطلب تحديثٍ ولم يُجمَّد. القرارُ للمراجع.',
            'severity' => 'warning',
            'context' => [
                'expires_at' => $state['expires_at'],
                'days_overdue' => abs((int) $state['days']),
                'source' => $state['source'],
            ],
        ]);

        return true;
    }

    /** إنذارٌ قبل الانتهاء — أثرٌ يُقرأ، ولا وسمَ ولا منع. */
    private function warn(User $user, array $state): void
    {
        $this->audit->record([
            'actor_type' => 'system',
            'actor_user_id' => null,
            'subject_type' => 'user',
            'subject_id' => (string) $user->id,
            'action' => 'KYC_IDENTITY_EXPIRING',
            'decision_code' => 'IDENTITY_EXPIRING_' . $state['days'],
            'reason' => 'تنتهي وثيقةُ الهويّة بعد ' . $state['days']
                . ' يوماً (' . $state['expires_at'] . ')',
            'severity' => 'notice',
            'context' => [
                'expires_at' => $state['expires_at'],
                'days_left' => $state['days'],
                'source' => $state['source'],
            ],
        ]);
    }
}
