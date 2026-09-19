<?php

namespace App\Services\Kyc;

use App\Models\KycDocument;
use App\Models\User;
use App\Services\AuditService;
use DomainException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-LEGAL-NAME-001 — الاسم القانوني منذ التسجيل حتى الهوية.
 *
 * - التسجيل: اسم رباعي مُصرَّح به.
 * - إثبات السكن: الاسم الظاهر في المستند يُقارن قبل Tier 1.
 * - الهوية: الاسم الذي أقره المراجع يصبح verified_legal_name عند Tier 2.
 * - التاريخ لا يُمسح؛ يحتفظ به legal_name_events مشفراً.
 */
class LegalNameService
{
    public const STATUS_EXACT = 'exact';
    public const STATUS_STRONG = 'strong';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_MISMATCH = 'mismatch';
    public const STATUS_UNAVAILABLE = 'unavailable';

    public function __construct(private readonly AuditService $audit)
    {
    }

    /** @param array{given_name:string,father_name:string,grandfather_name:string,family_name:string} $parts */
    public function compose(array $parts): string
    {
        return implode(' ', array_map(
            fn (string $part) => $this->cleanPart($part),
            [
                $parts['given_name'],
                $parts['father_name'],
                $parts['grandfather_name'],
                $parts['family_name'],
            ],
        ));
    }

    public function declared(User $user): string
    {
        $stored = trim((string) ($user->declared_legal_name ?? ''));
        if ($stored !== '') {
            return $stored;
        }

        return trim(implode(' ', array_filter([
            trim((string) ($user->f_name ?? '')),
            trim((string) ($user->father_name ?? '')),
            trim((string) ($user->grandfather_name ?? '')),
            trim((string) ($user->family_name ?? $user->l_name ?? '')),
        ])));
    }

    public function registerDeclaredName(
        User $user,
        string $given,
        string $father,
        string $grandfather,
        string $family,
    ): string {
        $name = $this->compose([
            'given_name' => $given,
            'father_name' => $father,
            'grandfather_name' => $grandfather,
            'family_name' => $family,
        ]);

        $user->f_name = $this->cleanPart($given);
        $user->father_name = $this->cleanPart($father);
        $user->grandfather_name = $this->cleanPart($grandfather);
        $user->family_name = $this->cleanPart($family);
        // l_name يبقى لقب العائلة للتوافق مع عشرات الشاشات القديمة.
        $user->l_name = $this->cleanPart($family);
        $user->declared_legal_name = $name;
        $user->legal_name_status = 'declared';
        $user->save();

        $this->event(
            $user,
            'DECLARED_AT_REGISTRATION',
            'registration',
            null,
            $name,
            null,
            null,
            null,
            null,
        );

        return $name;
    }

    /**
     * @return array{status:string,score:int,declared:string,document:string,shared:int,declared_tokens:int,document_tokens:int}
     */
    public function compare(User|string $subject, ?string $documentName): array
    {
        $declared = $subject instanceof User ? $this->declared($subject) : trim($subject);
        $document = trim((string) $documentName);

        if ($declared === '' || $document === '') {
            return [
                'status' => self::STATUS_UNAVAILABLE,
                'score' => 0,
                'declared' => $declared,
                'document' => $document,
                'shared' => 0,
                'declared_tokens' => 0,
                'document_tokens' => 0,
            ];
        }

        $a = $this->tokens($declared);
        $b = $this->tokens($document);
        $shared = count(array_intersect($a, $b));
        $denominator = max(1, count(array_unique(array_merge($a, $b))));
        $score = (int) round(($shared / $denominator) * 100);

        if ($this->normalize($declared) === $this->normalize($document)) {
            $status = self::STATUS_EXACT;
            $score = 100;
        } else {
            $firstMatches = $a !== [] && $b !== [] && $a[0] === $b[0];
            $lastMatches = $a !== [] && $b !== [] && end($a) === end($b);

            if ($firstMatches && $lastMatches && $shared >= 2
                && count($a) === count($b)) {
                // نفس عدد الأجزاء مع اختلافات كتابة/ترتيب بسيطة: قوي.
                $status = self::STATUS_STRONG;
            } elseif ($shared >= 2 && ($firstMatches || $lastMatches)) {
                // مثال: المستند أسقط اسم الجد. لا نرفض، لكن نطلب ملاحظة
                // مراجعة بدلاً من تحويل النقص إلى تطابق قوي تلقائياً.
                $status = self::STATUS_PARTIAL;
            } else {
                $status = self::STATUS_MISMATCH;
            }
        }

        return [
            'status' => $status,
            'score' => $score,
            'declared' => $declared,
            'document' => $document,
            'shared' => $shared,
            'declared_tokens' => count($a),
            'document_tokens' => count($b),
        ];
    }

    /**
     * يعيد تكوين الاسم المُصرّح به بعد طلب تغيير رسمي لأحد أجزائه.
     * الاسم الموثق السابق لا يُمحى؛ يبقى مرجعاً تاريخياً حتى إعادة KYC.
     */
    public function rebuildDeclaredNameFromAccount(
        User $user,
        string $source,
        ?int $reviewerId = null,
        ?string $reason = null,
    ): string {
        $before = $this->declared($user);
        $after = $this->compose([
            'given_name' => (string) $user->f_name,
            'father_name' => (string) $user->father_name,
            'grandfather_name' => (string) $user->grandfather_name,
            'family_name' => (string) ($user->family_name ?: $user->l_name),
        ]);

        $user->declared_legal_name = $after;
        $user->legal_name_status = trim((string) ($user->verified_legal_name ?? '')) !== ''
            ? 'change_pending_reverification'
            : 'declared_changed';
        $user->save();

        if ($before !== $after) {
            $this->event(
                $user,
                'DECLARED_NAME_CHANGED',
                $source,
                $before,
                $after,
                null,
                $reviewerId,
                null,
                null,
                $reason,
            );
        }

        return $after;
    }

    /**
     * المراجع يكتب/يؤكد الاسم الظاهر في إثبات السكن. لا OCR وحده يقرر.
     *
     * @return array{status:string,score:int,declared:string,document:string,shared:int,declared_tokens:int,document_tokens:int}
     */
    public function confirmResidenceDocumentName(
        int $verificationId,
        User $reviewer,
        string $documentName,
        ?string $reviewNote = null,
    ): array {
        $row = DB::table('residence_verifications')->where('id', $verificationId)->first();
        if (!$row) {
            throw new DomainException('RESIDENCE_VERIFICATION_NOT_FOUND');
        }

        $user = User::findOrFail($row->user_id);
        $comparison = $this->compare($user, $documentName);

        if ($comparison['status'] === self::STATUS_UNAVAILABLE) {
            throw new DomainException('RESIDENCE_DOCUMENT_NAME_REQUIRED');
        }

        if ($comparison['status'] === self::STATUS_PARTIAL
            && mb_strlen(trim((string) $reviewNote)) < 10) {
            throw new DomainException('LEGAL_NAME_PARTIAL_MATCH_REQUIRES_NOTE');
        }

        DB::table('residence_verifications')->where('id', $verificationId)->update([
            'document_name' => mb_substr(trim($documentName), 0, 300),
            'name_match_status' => $comparison['status'],
            'name_match_score' => $comparison['score'],
            'name_review_note' => $reviewNote ? mb_substr(trim($reviewNote), 0, 500) : null,
            'name_confirmed_by' => $reviewer->id,
            'name_confirmed_at' => now(),
            'updated_at' => now(),
        ]);

        $this->event(
            $user,
            'RESIDENCE_NAME_COMPARED',
            'residence_proof',
            $comparison['declared'],
            $comparison['document'],
            (int) ($row->kyc_document_id ?? 0) ?: null,
            $reviewer->id,
            $comparison['status'],
            $comparison['score'],
            $reviewNote,
        );

        $this->audit->record([
            'actor_type' => 'admin',
            'actor_user_id' => $reviewer->id,
            'subject_type' => 'user',
            'subject_id' => (string) $user->id,
            'action' => 'LEGAL_NAME_RESIDENCE_COMPARED',
            'decision_code' => 'LEGAL_NAME_' . strtoupper($comparison['status']),
            'severity' => $comparison['status'] === self::STATUS_MISMATCH ? 'warning' : 'notice',
            // الأسماء نفسها لا نكررها في audit العام.
            'context' => [
                'verification_id' => $verificationId,
                'document_id' => $row->kyc_document_id,
                'match_status' => $comparison['status'],
                'match_score' => $comparison['score'],
            ],
        ]);

        return $comparison;
    }

    public function assertResidenceNameReady(int $verificationId): void
    {
        $row = DB::table('residence_verifications')->where('id', $verificationId)->first();
        if (!$row || empty($row->document_name) || empty($row->name_match_status)) {
            throw new DomainException('RESIDENCE_DOCUMENT_NAME_REQUIRED');
        }

        if ($row->name_match_status === self::STATUS_MISMATCH) {
            throw new DomainException('RESIDENCE_NAME_MISMATCH');
        }

        if ($row->name_match_status === self::STATUS_PARTIAL
            && mb_strlen(trim((string) ($row->name_review_note ?? ''))) < 10) {
            throw new DomainException('LEGAL_NAME_PARTIAL_MATCH_REQUIRES_NOTE');
        }
    }

    /**
     * الاسم الذي أقره المراجع داخل وثيقة الهوية. لا نعتمد OCR الخام.
     */
    public function confirmedIdentityName(User $user): ?string
    {
        if (!Schema::hasTable('kyc_documents')) {
            return null;
        }

        $docs = KycDocument::query()
            ->where('user_id', $user->id)
            ->whereIn('doc_type', [
                KycDocument::TYPE_ID_FRONT,
                KycDocument::TYPE_ID_BACK,
                KycDocument::TYPE_PASSPORT,
            ])
            ->where('status', KycDocument::STATUS_APPROVED)
            ->whereNotNull('verified_fields')
            ->orderByDesc('id')
            ->get();

        foreach ($docs as $doc) {
            try {
                $fields = json_decode(Crypt::decryptString($doc->verified_fields), true) ?: [];
            } catch (\Throwable) {
                continue;
            }

            $name = trim((string) ($fields['full_name'] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }

        return null;
    }

    /** @return array{status:string,score:int,declared:string,document:string,shared:int,declared_tokens:int,document_tokens:int} */
    public function identityComparison(User $user): array
    {
        return $this->compare($user, $this->confirmedIdentityName($user));
    }

    public function assertIdentityNameReady(User $user): array
    {
        $comparison = $this->identityComparison($user);

        if ($comparison['status'] === self::STATUS_UNAVAILABLE) {
            throw new DomainException('IDENTITY_CONFIRMED_NAME_REQUIRED');
        }
        if ($comparison['status'] === self::STATUS_MISMATCH) {
            throw new DomainException('IDENTITY_NAME_MISMATCH');
        }

        return $comparison;
    }

    public function verifyIdentityName(User $user, User $reviewer): string
    {
        $comparison = $this->assertIdentityNameReady($user);
        $name = $comparison['document'];

        $before = trim((string) ($user->verified_legal_name ?? ''));
        $user->verified_legal_name = $name;
        $user->legal_name_status = 'identity_verified';
        $user->legal_name_verified_at = now();
        $user->legal_name_locked_at = now();
        $user->save();

        $this->event(
            $user,
            'IDENTITY_NAME_VERIFIED',
            'identity_document',
            $before !== '' ? $before : $comparison['declared'],
            $name,
            null,
            $reviewer->id,
            $comparison['status'],
            $comparison['score'],
            null,
        );

        $this->audit->record([
            'actor_type' => 'admin',
            'actor_user_id' => $reviewer->id,
            'subject_type' => 'user',
            'subject_id' => (string) $user->id,
            'action' => 'LEGAL_NAME_IDENTITY_VERIFIED',
            'decision_code' => 'LEGAL_NAME_VERIFIED',
            'severity' => 'critical',
            'context' => [
                'match_status' => $comparison['status'],
                'match_score' => $comparison['score'],
            ],
        ]);

        return $name;
    }

    private function cleanPart(string $part): string
    {
        return preg_replace('/\s+/u', ' ', trim($part)) ?: '';
    }

    /** @return array<int,string> */
    private function tokens(string $name): array
    {
        $normalized = preg_replace('/\s+/u', ' ', $this->normalize($name)) ?: '';

        return array_values(array_filter(
            explode(' ', trim($normalized)),
            fn ($token) => mb_strlen($token) >= 2,
        ));
    }

    private function normalize(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $name = preg_replace('/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}]/u', '', $name) ?: $name;
        $name = strtr($name, [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا',
            'ى' => 'ي', 'ة' => 'ه', 'ؤ' => 'و', 'ئ' => 'ي',
        ]);
        $name = preg_replace('/[^\p{Arabic}\p{L}\s]/u', ' ', $name) ?: $name;
        $name = preg_replace('/\bعبد\s+(?=الله\b)/u', 'عبد', $name) ?: $name;

        return preg_replace('/\s+/u', ' ', trim($name)) ?: '';
    }

    private function event(
        User $user,
        string $eventType,
        string $source,
        ?string $oldName,
        ?string $newName,
        ?int $documentId,
        ?int $reviewerId,
        ?string $matchStatus,
        ?int $matchScore,
        ?string $reason,
    ): void {
        if (!Schema::hasTable('legal_name_events')) {
            return;
        }

        DB::table('legal_name_events')->insert([
            'user_id' => $user->id,
            'event_type' => $eventType,
            'source' => $source,
            'old_name_encrypted' => $oldName ? Crypt::encryptString($oldName) : null,
            'new_name_encrypted' => $newName ? Crypt::encryptString($newName) : null,
            'document_id' => $documentId,
            'reviewer_id' => $reviewerId,
            'match_status' => $matchStatus,
            'match_score' => $matchScore,
            'reason' => $reason ? mb_substr(trim($reason), 0, 500) : null,
            'created_at' => now(),
        ]);
    }
}
