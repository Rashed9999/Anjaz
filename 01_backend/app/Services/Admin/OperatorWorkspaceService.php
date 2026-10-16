<?php

namespace App\Services\Admin;

use App\Models\ApprovalRequest;
use App\Models\KycDocument;
use App\Models\ReconciliationCase;
use App\Models\SecurityAlert;
use App\Services\SystemHealthService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-WORKSPACE-002 — **مساحةُ العمل تقول ما ينتظر، لا ما هو متاح.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * كانت الصفحةُ **فهرسَ روابطَ** لا أكثر: عشرةُ تبويباتٍ وخمسون بطاقة،
 * وكلُّها تقول «هذا موجود». **ولا واحدةٌ تقول إنّ اثنَي عشرَ طلبَ هويّةٍ
 * تنتظر منذ الصباح.** فيفتح الموظّفُ الصفحةَ ولا يعرف من أين يبدأ، ويبقى
 * ما ينتظر منتظراً حتّى يشكو صاحبُه.
 *
 * **وأربعةُ حدودٍ تحكم كلَّ رقمٍ هنا** (‏`amial-admin-command`: «Never
 * hardcode financial or operational KPIs»):
 *
 *   ① **كلُّ رقمٍ من مصدره.** لا ثابتَ مكتوبٌ ولا عيّنة: طلباتُ الهويّة
 *      من `kyc_documents`، والأمنيّةُ من `security_alerts`، وهكذا.
 *
 *   ② **وما لا يُصرَّح به لا يُعرَض صفراً.** موظّفٌ بلا صلاحيّةِ الهويّة
 *      يرى «غير مصرَّح» لا «٠». **وصفرٌ يُقرأ «فحصنا فلم نجد»**، فيمرّ
 *      الطابورُ كلُّه أمام عينٍ تظنّه فارغاً. (القاعدة السابعة.)
 *
 *   ③ **وجدولٌ غيرُ موجودٍ يُقال ولا يُبتلَع.** هجرةٌ لم تجرِ على خادمٍ
 *      تُخرج «غير متاح» لا صفراً — وهي الحالةُ نفسُها بالضبط.
 *
 *   ④ **والبطاقةُ تفتح ما تعدّ.** رقمٌ لا يُنقر ليس مؤشّراً: من رأى ١٢
 *      طلباً يجب أن يصل إليها بضغطةٍ واحدة. (القاعدة التاسعة.)
 *
 * يظهر في : لوحة الإدارة ← 🗂️ مساحة العمل.
 *
 * @see \Tests\Feature\OperatorWorkspaceGuardTest
 */
class OperatorWorkspaceService
{
    /** «لم يُنظَر» — وهي ليست صفراً. */
    public const UNKNOWN = null;

    public function __construct(private SystemHealthService $health) {}

    /**
     * البطاقاتُ الأربع: ما ينتظر قراراً الآن.
     *
     * @param  callable(?string):bool  $can
     * @return array<int,array{key:string,title:string,count:?int,note:?string,url:string,tone:string,icon:string}>
     */
    public function cards(callable $can): array
    {
        return [
            $this->card('kyc', 'طلبات هوية بحاجة مراجعة', 'platform.customers.kyc.view',
                route('admin.amial.kyc.page'), 'warning', 'tio-user-add',
                fn () => $this->pendingKyc(), $can),

            $this->card('aml', 'بلاغات امتثال مفتوحة', 'platform.audit.view',
                route('admin.amial.aml.page'), 'danger', 'tio-shield-outlined',
                fn () => $this->openCompliance(), $can),

            $this->card('security', 'تنبيهات أمنية', 'platform.audit.view',
                route('admin.amial.security-events.index'), 'danger', 'tio-warning',
                fn () => $this->openSecurity(), $can),

            $this->card('ops', 'مهام تشغيل', 'platform.ops.view',
                route('admin.amial.ops.index'), 'success', 'tio-checkmark-square',
                fn () => $this->openOps(), $can),
        ];
    }

    /**
     * صحّةُ النظام — **وتُقاس بالعمل لا بالإعداد** (`SystemHealthService`).
     *
     * ولا تُبنى ثانيةٌ فوقها: الخدمةُ قائمةٌ ومحروسة.
     */
    public function health(): array
    {
        try {
            $all = $this->health->checkAll();
        } catch (\Throwable $e) {
            report($e);

            // **وفشلُ الفاحص ليس «سليم».** صفحةٌ خضراءُ فوق راصدٍ ميّتٍ
            // أسوأ من حمراء.
            return ['status' => 'unknown', 'rows' => [], 'note' => 'تعذّر فحصُ الصحّة — الراصدُ نفسُه لا يجيب'];
        }

        $labels = [
            'database' => 'قاعدة البيانات',
            'queue' => 'الطوابير',
            'cache' => 'الذاكرة المؤقّتة',
            'storage' => 'التخزين',
            'disk' => 'القرص',
            'php' => 'PHP',
        ];

        $rows = [];

        foreach ($labels as $key => $label) {
            $c = $all['checks'][$key] ?? null;

            $rows[] = [
                'label' => $label,
                'status' => $c['status'] ?? 'unknown',
                'detail' => $c['message'] ?? ($c['detail'] ?? null),
            ];
        }

        return ['status' => $all['status'] ?? 'unknown', 'rows' => $rows, 'note' => null];
    }

    /**
     * «يحتاج تدخّلاً الآن» — **طابورٌ واحدٌ من كلّ المصادر، أقدمُه أوّلاً.**
     *
     * وترتيبُه **بالعمر** لا بالنوع: أقدمُ ما ينتظر هو أخطرُ ما ينتظر،
     * ومن رتّب بالنوع ترك طلباً عمرُه ثلاثةَ أيّامٍ أسفلَ آخرَ عمرُه دقيقة.
     *
     * @param  callable(?string):bool  $can
     */
    public function queue(callable $can, int $limit = 8): array
    {
        $rows = [];

        if ($can('platform.customers.kyc.view') && Schema::hasTable('kyc_documents')) {
            KycDocument::where('status', KycDocument::STATUS_PENDING)
                ->orderBy('created_at')->limit($limit)
                ->get(['id', 'user_id', 'created_at'])
                ->each(function ($d) use (&$rows) {
                    $rows[] = [
                        'type' => 'طلب هوية',
                        'ref' => 'IDR-'.str_pad((string) $d->id, 6, '0', STR_PAD_LEFT),
                        'at' => $d->created_at,
                        'state' => 'بحاجة مراجعة',
                        'tone' => 'warning',
                        'url' => route('admin.amial.kyc.page'),
                    ];
                });
        }

        if ($can('platform.audit.view') && Schema::hasTable('aml_alerts')) {
            DB::table('aml_alerts')->whereNotIn('status', ['closed', 'dismissed', 'resolved'])
                ->orderBy('created_at')->limit($limit)
                ->get(['id', 'alert_ulid', 'severity', 'created_at'])
                ->each(function ($a) use (&$rows) {
                    $rows[] = [
                        'type' => 'بلاغ امتثال',
                        'ref' => 'COM-'.str_pad((string) $a->id, 6, '0', STR_PAD_LEFT),
                        'at' => $a->created_at,
                        'state' => $a->severity === 'high' || $a->severity === 'critical' ? 'عالٍ' : 'بحاجة مراجعة',
                        'tone' => $a->severity === 'high' || $a->severity === 'critical' ? 'danger' : 'warning',
                        'url' => route('admin.amial.aml.page'),
                    ];
                });
        }

        if ($can('platform.audit.view') && Schema::hasTable('security_alerts')) {
            SecurityAlert::whereIn('status', ['open', 'pending', 'new'])
                ->orderBy('created_at')->limit($limit)
                ->get(['id', 'severity', 'created_at'])
                ->each(function ($s) use (&$rows) {
                    $rows[] = [
                        'type' => 'تنبيه أمني',
                        'ref' => 'SEC-'.str_pad((string) $s->id, 6, '0', STR_PAD_LEFT),
                        'at' => $s->created_at,
                        'state' => in_array($s->severity, ['high', 'critical'], true) ? 'عالٍ' : 'بحاجة مراجعة',
                        'tone' => in_array($s->severity, ['high', 'critical'], true) ? 'danger' : 'warning',
                        'url' => route('admin.amial.security-events.index'),
                    ];
                });
        }

        if ($can('platform.approvals.decide') && Schema::hasTable('approval_requests')) {
            ApprovalRequest::where('status', 'pending')
                ->orderBy('created_at')->limit($limit)
                ->get(['id', 'created_at'])
                ->each(function ($a) use (&$rows) {
                    $rows[] = [
                        'type' => 'مهمة تشغيل',
                        'ref' => 'OPS-'.str_pad((string) $a->id, 6, '0', STR_PAD_LEFT),
                        'at' => $a->created_at,
                        'state' => 'بحاجة مراجعة',
                        'tone' => 'warning',
                        'url' => route('admin.amial.ops.index'),
                    ];
                });
        }

        // الأقدمُ أوّلاً — وهو أخطرُ ما ينتظر.
        usort($rows, fn ($a, $b) => ($a['at'] <=> $b['at']));

        return array_slice($rows, 0, $limit);
    }

    // ── العدّادات — كلٌّ من مصدره ───────────────────────────────────────

    private function pendingKyc(): ?int
    {
        if (! Schema::hasTable('kyc_documents')) {
            return self::UNKNOWN;
        }

        // **العدُّ بالأشخاص لا بالورق**: صاحبُ خمسِ وثائقَ طلبٌ واحد،
        // وعدُّ الورق يُضخّم الطابورَ خمسةَ أضعافٍ ويُفزع بلا سبب.
        return (int) KycDocument::where('status', KycDocument::STATUS_PENDING)
            ->distinct()->count('user_id');
    }

    private function openCompliance(): ?int
    {
        if (! Schema::hasTable('aml_alerts')) {
            return self::UNKNOWN;
        }

        return (int) DB::table('aml_alerts')
            ->whereNotIn('status', ['closed', 'dismissed', 'resolved'])->count();
    }

    private function openSecurity(): ?int
    {
        if (! Schema::hasTable('security_alerts')) {
            return self::UNKNOWN;
        }

        return (int) SecurityAlert::whereIn('status', ['open', 'pending', 'new'])->count();
    }

    /**
     * مهامُّ التشغيل: موافقاتٌ تنتظر **وقضايا مصالحةٍ مفتوحة**.
     *
     * والثانيةُ فرقٌ ماليٌّ وجدته المصالحةُ الليليّةُ ولم يُغلق — وهي
     * أولى بالنظر من كثيرٍ ممّا يُعرَض.
     */
    private function openOps(): ?int
    {
        $has = Schema::hasTable('approval_requests');
        $hasCases = Schema::hasTable('reconciliation_cases');

        if (! $has && ! $hasCases) {
            return self::UNKNOWN;
        }

        return ($has ? ApprovalRequest::where('status', 'pending')->count() : 0)
            + ($hasCases ? ReconciliationCase::whereNotIn('status', ['resolved', 'closed'])->count() : 0);
    }

    /**
     * @param  callable(?string):bool  $can
     * @param  callable():?int  $count
     */
    private function card(
        string $key, string $title, string $permission, string $url,
        string $tone, string $icon, callable $count, callable $can,
    ): array {
        // ② ما لا يُصرَّح به لا يُعرَض صفراً.
        if (! $can($permission)) {
            return ['key' => $key, 'title' => $title, 'count' => null,
                'note' => 'غير مصرَّح', 'url' => null, 'tone' => 'muted', 'icon' => $icon];
        }

        try {
            $n = $count();
        } catch (\Throwable $e) {
            report($e);
            $n = self::UNKNOWN;
        }

        return [
            'key' => $key, 'title' => $title, 'count' => $n,
            // ③ وجدولٌ غيرُ موجودٍ يُقال ولا يُبتلَع.
            'note' => $n === self::UNKNOWN ? 'غير متاح — لم يُفحَص' : null,
            'url' => $url, 'tone' => $tone, 'icon' => $icon,
        ];
    }
}
