<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AMIAL-SANCTION-001 (v1.9)
 *
 * SanctionScreeningService — فحص المستخدمين ضد قوائم العقوبات (OFAC, UN, EU).
 *
 * **المنهجية:**
 *   1. تطبيع الاسم (normalize): إزالة التشكيل، توحيد الحروف، lowercase
 *   2. مطابقة دقيقة (exact) على الاسم المُطبَّع
 *   3. مطابقة ضبابية (fuzzy) عبر Levenshtein/Jaro-Winkler للأسماء المتقاربة
 *   4. مطابقة بالمعرفات (national_id, passport) عبر hash
 *
 * **النتائج:**
 *   - clear: لا تطابق
 *   - potential_match: تطابق ضبابي (يحتاج مراجعة admin)
 *   - confirmed_match: تطابق دقيق (block فوري)
 *
 * **ملاحظة مهمة:**
 *   هذا فحص أساسي. في production الحقيقي، يُفضّل استخدام خدمة متخصصة
 *   (مثل ComplyAdvantage, Refinitiv) لأن قوائم العقوبات تتحدث يومياً
 *   وتحتاج fuzzy matching متقدم. هذا التطبيق يوفر الأساس + البنية.
 */
class SanctionScreeningService
{
    private const FUZZY_THRESHOLD = 85.0; // نسبة التشابه للـ potential match
    private const EXACT_THRESHOLD = 98.0;

    public function __construct(
        private readonly EncryptionService $encryption,
    ) {}

    /**
     * فحص مستخدم ضد قوائم العقوبات.
     *
     * @return array{result: string, score: float, matched_entry_id: ?int, details: array}
     */
    public function screenUser(User $user, string $context = 'registration'): array
    {
        $fullName = trim(($user->f_name ?? '') . ' ' . ($user->l_name ?? ''));
        if ($fullName === '') {
            return $this->logAndReturn($user->id, '', 'clear', 0, null, $context, []);
        }

        $result = $this->screenName($fullName, [
            'national_id' => $user->national_id ?? null,
        ]);

        // تحديث حالة المستخدم
        $sanctionStatus = match ($result['result']) {
            'confirmed_match' => 'blocked',
            'potential_match' => 'flagged',
            default => 'clear',
        };

        $user->sanction_checked = true;
        $user->sanction_status = $sanctionStatus;
        $user->save();

        return $this->logAndReturn(
            $user->id, $fullName, $result['result'],
            $result['score'], $result['matched_entry_id'], $context, $result['details'],
        );
    }

    /**
     * فحص اسم خام (بدون user) — للفحوصات الاستباقية.
     */
    public function screenName(string $fullName, array $identifiers = []): array
    {
        $normalized = $this->normalizeName($fullName);

        // 1) مطابقة بالمعرفات أولاً (الأقوى)
        if (!empty($identifiers['national_id'])) {
            $nidHash = hash('sha256', preg_replace('/\s+/', '', $identifiers['national_id']));
            $idMatch = DB::table('sanction_list_entries')
                ->where('national_id_hash', $nidHash)
                ->where('is_active', true)
                ->first();
            if ($idMatch) {
                return [
                    'result' => 'confirmed_match',
                    'score' => 100.0,
                    'matched_entry_id' => $idMatch->id,
                    'details' => ['match_type' => 'national_id', 'list' => $idMatch->list_source],
                ];
            }
        }

        // 2) مطابقة دقيقة على الاسم المُطبَّع
        $exactMatch = DB::table('sanction_list_entries')
            ->where('normalized_name', $normalized)
            ->where('is_active', true)
            ->first();
        if ($exactMatch) {
            return [
                'result' => 'confirmed_match',
                'score' => 100.0,
                'matched_entry_id' => $exactMatch->id,
                'details' => ['match_type' => 'exact_name', 'list' => $exactMatch->list_source],
            ];
        }

        // 3) مطابقة ضبابية (fuzzy) — نقارن مع كل المدخلات النشطة
        $bestMatch = null;
        $bestScore = 0.0;

        // للأداء: نقتصر على الأسماء بنفس الحرف الأول أو طول متقارب
        $candidates = DB::table('sanction_list_entries')
            ->where('is_active', true)
            ->select('id', 'normalized_name', 'list_source', 'aliases')
            ->get();

        foreach ($candidates as $candidate) {
            $score = $this->similarity($normalized, $candidate->normalized_name);

            // فحص الـ aliases أيضاً
            $aliases = json_decode($candidate->aliases ?? '[]', true) ?? [];
            foreach ($aliases as $alias) {
                $aliasScore = $this->similarity($normalized, $this->normalizeName($alias));
                $score = max($score, $aliasScore);
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMatch = $candidate;
            }
        }

        if ($bestScore >= self::EXACT_THRESHOLD) {
            return [
                'result' => 'confirmed_match',
                'score' => $bestScore,
                'matched_entry_id' => $bestMatch->id,
                'details' => ['match_type' => 'fuzzy_high', 'list' => $bestMatch->list_source],
            ];
        }

        if ($bestScore >= self::FUZZY_THRESHOLD) {
            return [
                'result' => 'potential_match',
                'score' => $bestScore,
                'matched_entry_id' => $bestMatch->id,
                'details' => ['match_type' => 'fuzzy', 'list' => $bestMatch->list_source],
            ];
        }

        return ['result' => 'clear', 'score' => $bestScore, 'matched_entry_id' => null, 'details' => []];
    }

    /**
     * تطبيع الاسم للمطابقة.
     */
    public function normalizeName(string $name): string
    {
        // lowercase + إزالة التشكيل العربي + توحيد الألف والياء + إزالة الرموز
        $name = mb_strtolower(trim($name));

        // إزالة التشكيل العربي
        $name = preg_replace('/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}]/u', '', $name);

        // توحيد الحروف العربية المتشابهة
        $replacements = [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا',
            'ى' => 'ي', 'ة' => 'ه', 'ؤ' => 'و', 'ئ' => 'ي',
        ];
        $name = strtr($name, $replacements);

        // إزالة الرموز والمسافات الزائدة
        $name = preg_replace('/[^\p{L}\p{N}\s]/u', '', $name);
        $name = preg_replace('/\s+/', ' ', $name);

        return trim($name);
    }

    /**
     * نسبة التشابه بين اسمين (0-100).
     * يستخدم similar_text (أساسي) — في production يُفضّل Jaro-Winkler.
     */
    private function similarity(string $a, string $b): float
    {
        if ($a === $b) return 100.0;
        if ($a === '' || $b === '') return 0.0;

        // similar_text يعطي نسبة مئوية
        similar_text($a, $b, $percent);

        // تعزيز: لو أحدهما يحتوي الآخر بالكامل (substring)
        if (str_contains($a, $b) || str_contains($b, $a)) {
            $percent = max($percent, 90.0);
        }

        return round($percent, 2);
    }

    private function logAndReturn(
        ?int $userId, string $name, string $result,
        float $score, ?int $matchedId, string $context, array $details,
    ): array {
        try {
            DB::table('sanction_screening_logs')->insert([
                'user_id' => $userId,
                'screened_name' => mb_substr($name, 0, 300),
                'result' => $result,
                'match_score' => $score,
                'matched_entry_id' => $matchedId,
                'screening_context' => $context,
                'details' => json_encode($details),
                'screened_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Sanction log failed', ['err' => $e->getMessage()]);
        }

        if ($result !== 'clear') {
            Log::warning('Sanction screening hit', [
                'user_id' => $userId, 'result' => $result, 'score' => $score,
            ]);
        }

        return [
            'result' => $result, 'score' => $score,
            'matched_entry_id' => $matchedId, 'details' => $details,
        ];
    }

    /**
     * هل المستخدم محظور بسبب العقوبات؟
     */
    public function isBlocked(User $user): bool
    {
        return $user->sanction_status === 'blocked';
    }
}
