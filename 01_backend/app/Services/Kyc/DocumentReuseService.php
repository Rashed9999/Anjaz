<?php

namespace App\Services\Kyc;

use App\Models\KycDocument;
use App\Models\User;

/**
 * AMIAL-KYC-REUSE-001 — **ورقةٌ واحدةٌ تفتح حسابين.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ما قِيس، ولم يُفترَض:**
 *
 *     KycDocumentService::store → 'content_sha256' => $sha    ← تُكتَب
 *     grep 'content_sha256' app/ → موضعُ الكتابة وحدَه         ← ولا قارئ
 *
 * فبصمةُ كلّ وثيقةٍ محسوبةٌ منذ اليوم الأوّل، **ولا سطرَ يقارنها بشيء**.
 * أي أنّ صورةَ هويّةٍ واحدةً تُرفَع لعشرين حساباً، وكلُّ حسابٍ يُراجَع
 * وحدَه فيبدو ملفُّه سليماً — **ولا خطأ في أيّ سجلّ**. وهذا هو بالضبط
 * أرخصُ طرق فتح الحسابات الوهميّة.
 *
 * **وحالتان مختلفتان جوهريّاً، وخلطُهما يُفقد التحذيرَ معناه:**
 *
 *   ① **البصمةُ نفسُها لدى شخصٍ آخر.** وثيقةُ هويّةٍ **تخصّ شخصاً
 *      واحداً بالتعريف** — فالتطابقُ فيها حتميُّ الدلالة: إمّا انتحالٌ
 *      وإمّا خطأُ رفعٍ جسيم. **فيُغلق الطريق.**
 *
 *      **وإثباتُ العنوان يُستثنى**: فاتورةُ كهرباءِ بيتٍ واحدٍ يرفعها
 *      الأبُ والابن، وهذا مشروعٌ تماماً. **فيُنبَّه ولا يُمنَع** —
 *      والحدُّ نفسُه الذي يحكم `KycOcrService`: الحتميُّ يُغلق،
 *      والاحتماليُّ يُنبّه ويترك القرار للإنسان.
 *
 *   ② **والبصمةُ نفسُها لدى الشخص نفسِه في نوعين مختلفين.** رفعَ الوجهَ
 *      والظهرَ صورةً واحدة: **الملفُّ مكتملٌ شكلاً وناقصٌ حقيقةً** —
 *      وجهٌ من الوثيقة لم يُقدَّم قطّ، والمراجعُ يرى عدّادَ «٣ من ٣»
 *      فيعتمد. **فيُغلق الطريق أيضاً**، وهذا حتميٌّ لا احتماليّ.
 *
 * **ولا يُعدّ تكراراً**: نسخةٌ أحدثُ من الوثيقة نفسِها لصاحبها في
 * **النوع نفسِه** — فهي إعادةُ رفعٍ مشروعة، وهي ما تطلبه المنصّةُ نفسُها
 * حين تقول «أعِد الرفع».
 *
 * يظهر في : لوحة الإدارة ← لوحة التحقّق (لافتةُ «لا يتمّ الاعتماد الآن»)
 * · وطابورُ مراجعة الهويّة. وفي التطبيق: لا — إشارةُ احتيالٍ لا تُعرَض
 * لمن قد يكون مصدرَها.
 *
 * @see \Tests\Feature\KycDocumentReuseGuardTest
 */
class DocumentReuseService
{
    /**
     * أنواعٌ تخصّ شخصاً واحداً بالتعريف — تطابقُها انتحال.
     *
     * **وإثباتُ العنوان ليس منها** عمداً: بيتٌ واحدٌ لأسرةٍ واحدة.
     */
    public const PERSONAL_TYPES = [
        KycDocument::TYPE_ID_FRONT,
        KycDocument::TYPE_ID_BACK,
        KycDocument::TYPE_PASSPORT,
        KycDocument::TYPE_SELFIE,
    ];

    /**
     * @return array{blockers:array<int,string>,warnings:array<int,string>,matches:array}
     */
    public function findingsFor(User $user): array
    {
        $mine = KycDocument::where('user_id', $user->id)
            ->whereNotNull('content_sha256')
            ->where('status', '!=', KycDocument::STATUS_SUPERSEDED)
            ->get(['id', 'doc_type', 'content_sha256']);

        if ($mine->isEmpty()) {
            return ['blockers' => [], 'warnings' => [], 'matches' => []];
        }

        $hashes = $mine->pluck('content_sha256')->unique()->values()->all();

        $others = KycDocument::whereIn('content_sha256', $hashes)
            ->where('status', '!=', KycDocument::STATUS_SUPERSEDED)
            ->get(['id', 'user_id', 'doc_type', 'content_sha256']);

        $blockers = [];
        $warnings = [];
        $matches = [];

        foreach ($mine->groupBy('content_sha256') as $hash => $group) {
            $sameHash = $others->where('content_sha256', $hash);

            // ① بصمةٌ لدى شخصٍ آخر.
            $foreign = $sameHash->filter(fn ($d) => (int) $d->user_id !== (int) $user->id);

            if ($foreign->isNotEmpty()) {
                $accounts = $foreign->pluck('user_id')->unique()->count();
                $types = $group->pluck('doc_type')->unique();
                $personal = $types->intersect(self::PERSONAL_TYPES);

                $label = $types->map(fn ($t) => KycDocument::TYPE_LABELS[$t] ?? $t)
                    ->implode('، ');

                $line = "الملفّ «{$label}» مرفوعٌ بعينه في {$accounts} "
                    .($accounts === 1 ? 'حسابٍ آخر' : 'حساباتٍ أخرى');

                if ($personal->isNotEmpty()) {
                    // **حتميُّ الدلالة** — وثيقةٌ شخصيّةٌ لا تخصّ اثنين.
                    $blockers[] = $line.' — ووثيقةٌ شخصيّةٌ لا تخصّ شخصين.';
                } else {
                    // **احتماليّ** — بيتٌ واحدٌ لأسرةٍ واحدة.
                    $warnings[] = $line.' — قد يكون مشروعاً (عنوانُ أسرةٍ واحدة)؛ راجِعه.';
                }

                $matches[] = [
                    'hash_head' => substr((string) $hash, 0, 12),
                    'label' => $label,
                    'other_accounts' => $foreign->pluck('user_id')->unique()->values()->all(),
                    'severity' => $personal->isNotEmpty() ? 'high' : 'medium',
                ];
            }

            // ② البصمةُ نفسُها في نوعين مختلفين لدى الشخص نفسِه.
            $ownTypes = $group->pluck('doc_type')->unique();

            if ($ownTypes->count() > 1) {
                $label = $ownTypes->map(fn ($t) => KycDocument::TYPE_LABELS[$t] ?? $t)
                    ->implode(' و');

                $blockers[] = "رُفعت الصورةُ نفسُها لـ«{$label}» — "
                    .'فوجهٌ من الوثيقة لم يُقدَّم، والعدّادُ يقول «مكتمل».';

                $matches[] = [
                    'hash_head' => substr((string) $hash, 0, 12),
                    'label' => $label,
                    'other_accounts' => [],
                    'severity' => 'high',
                ];
            }
        }

        return [
            'blockers' => array_values(array_unique($blockers)),
            'warnings' => array_values(array_unique($warnings)),
            'matches' => $matches,
        ];
    }
}
