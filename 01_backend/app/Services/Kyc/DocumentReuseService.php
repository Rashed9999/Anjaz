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
 *   ②ب **ورقمُ الهويّة نفسُه لدى شخصٍ آخر.** والبصمةُ وحدَها لا تكفي:
 *      **من صوّر بطاقتَه ثانيةً بزاويةٍ أخرى أنتج بصمةً مختلفةً تماماً
 *      فمرّ**. ورقمُ الهويّة هو الثابتُ الذي لا تُغيّره إعادةُ التصوير —
 *      وهو مقروءٌ في `verified_fields` بإقرار المراجع، أو في
 *      `users.identification_number`. فيُقارَن.
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

        // **ولا يُخرَج مبكّراً قبل فحص الرقم.** حسابٌ لم يرفع وثيقةً بعدُ
        // قد يحمل رقمَ هويّةٍ منتحَلاً في حقله — وأوّلُ صياغةٍ هنا خرجت
        // قبل الفحص، فبقي البابُ مفتوحاً لمن لم يرفع شيئاً.
        if ($mine->isEmpty()) {
            $blockers = [];
            $matches = [];

            foreach ($this->idNumberClashes($user) as $clash) {
                $blockers[] = $clash['line'];
                $matches[] = $clash['match'];
            }

            return ['blockers' => $blockers, 'warnings' => [], 'matches' => $matches];
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

        // ②ب ورقمُ الهويّة — الثابتُ الذي لا تُغيّره إعادةُ التصوير.
        foreach ($this->idNumberClashes($user) as $clash) {
            $blockers[] = $clash['line'];
            $matches[] = $clash['match'];
        }

        return [
            'blockers' => array_values(array_unique($blockers)),
            'warnings' => array_values(array_unique($warnings)),
            'matches' => $matches,
        ];
    }

    /**
     * **رقمُ الهويّة نفسُه في حسابٍ آخر.**
     *
     * والبصمةُ لا تمسك هذا: صورتان مختلفتان لبطاقةٍ واحدةٍ بصمتان
     * مختلفتان. **والرقمُ واحد.**
     *
     * ويُقرأ من موضعين: `users.identification_number` (‏ما أدخله
     * المستعملُ أو الموظّف)، و`kyc_documents.verified_fields` (‏**ما
     * أقرّه المراجعُ بعد أن قرأ الوثيقة**) — والثاني أوثق، فيُقرآن معاً.
     *
     * **والأرقامُ العربيّةُ-الهنديّةُ تُوحَّد قبل المقارنة** — وبدونه
     * يُقرأ «١٢٣٤» و«1234» رقمين مختلفين، فيمرّ الانتحالُ من أوسع أبوابه.
     *
     * @return array<int,array{line:string,match:array}>
     */
    private function idNumberClashes(User $user): array
    {
        $numbers = $this->idNumbersOf($user);

        if ($numbers === []) {
            return [];
        }

        $out = [];

        foreach ($numbers as $number) {
            $others = User::where('id', '!=', $user->id)
                ->whereNotNull('identification_number')
                ->where('identification_number', '!=', '')
                ->pluck('identification_number', 'id')
                ->filter(fn ($n) => $this->digits((string) $n) === $number);

            if ($others->isEmpty()) {
                continue;
            }

            $count = $others->count();

            $out[] = [
                'line' => "رقمُ الهويّة نفسُه مسجَّلٌ في {$count} "
                    .($count === 1 ? 'حسابٍ آخر' : 'حساباتٍ أخرى')
                    .' — ورقمُ الهويّة لا يخصّ شخصين، ولا تُغيّره إعادةُ التصوير.',
                'match' => [
                    'hash_head' => 'id:'.mb_substr($number, 0, 4).'…',
                    'label' => 'رقم الهويّة',
                    'other_accounts' => $others->keys()->map(fn ($k) => (int) $k)->values()->all(),
                    'severity' => 'high',
                ],
            ];
        }

        return $out;
    }

    /**
     * أرقامُ الهويّة المعروفةُ لهذا الحساب — من الحقل ومن إقرار المراجع.
     *
     * @return array<int,string>
     */
    private function idNumbersOf(User $user): array
    {
        $out = [];

        $field = $this->digits((string) ($user->identification_number ?? ''));

        if ($field !== '') {
            $out[] = $field;
        }

        KycDocument::where('user_id', $user->id)
            ->whereNotNull('verified_fields')
            ->where('status', '!=', KycDocument::STATUS_SUPERSEDED)
            ->get(['verified_fields'])
            ->each(function (KycDocument $d) use (&$out) {
                // **وهو مشفَّرٌ لا مصفوفة** — `KycOcrService` يكتبه
                // `Crypt::encryptString(json_encode(...))` لأنّه يحمل
                // الاسمَ ورقمَ الهويّة. وأوّلُ صياغةٍ هنا قرأته مصفوفةً
                // **فلم يُقرأ إقرارُ المراجع إطلاقاً** — وهو أوثقُ
                // المصادر، إذ هو ما رآه إنسانٌ في الوثيقة.
                $fields = $this->decodeVerified($d);

                if ($fields === []) {
                    return;
                }

                foreach (['national_id', 'id_number', 'identification_number'] as $key) {
                    $v = $fields[$key] ?? null;
                    $v = is_array($v) ? ($v['value'] ?? null) : $v;
                    $v = $this->digits((string) ($v ?? ''));

                    if ($v !== '') {
                        $out[] = $v;
                    }
                }
            });

        // **ورقمٌ قصيرٌ جدّاً لا يُقارَن** — «1» و«12» تصادفاتٌ لا انتحال،
        // ومنعُ حسابٍ عليها حاجزٌ يشلّ عملاً سليماً.
        return array_values(array_unique(array_filter(
            $out, fn (string $n) => mb_strlen($n) >= 5)));
    }

    /**
     * فكُّ تشفير إقرار المراجع.
     *
     * **ولا يرمي أبداً**: وثيقةٌ مشفَّرةٌ بمفتاحٍ قديمٍ لا يجوز أن تُسقط
     * فحصَ الحسابات كلَّه — يُتخطّى سطرُها ويُسجَّل، ويمضي الفحص.
     * (وهو الحدُّ نفسُه الذي يحكم `KycOcrService`: عطلُ محرّكٍ مساعدٍ
     * شأنُنا لا شأنُ العميل.)
     *
     * @return array<string,mixed>
     */
    private function decodeVerified(KycDocument $doc): array
    {
        $raw = $doc->verified_fields;

        if (! $raw) {
            return [];
        }

        if (is_array($raw)) {
            return $raw;
        }

        try {
            return json_decode(\Illuminate\Support\Facades\Crypt::decryptString((string) $raw), true) ?: [];
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    /** الأرقامُ وحدَها، والعربيّةُ-الهنديّةُ تُحوَّل — وإلّا مرّ الانتحال. */
    private function digits(string $raw): string
    {
        $arabic = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        $latin = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

        $s = str_replace($persian, $latin, str_replace($arabic, $latin, $raw));

        return preg_replace('/\D+/', '', $s) ?? '';
    }
}
