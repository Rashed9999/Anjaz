<?php

namespace App\Services;

use App\Models\LegalTerm;
use App\Models\User;
use App\Models\UserLegalAcceptance;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-LEGAL-001
 *
 * LegalTermsService — يدير دورة حياة سياسة الاستخدام.
 *
 * المسؤوليات (قسم 8 من الوثيقة):
 *   - معرفة الإصدار الحالي لكل locale
 *   - فحص هل user قبل أحدث إصدار
 *   - تسجيل قبول جديد
 *   - publish إصدار جديد (admin) → يطفئ القديم تلقائياً
 *
 * الـ middleware RequireTermsAcceptance يستخدم needsAcceptance() لتقرير
 * هل يرفض الطلب بـ TERMS_ACCEPTANCE_REQUIRED.
 */
class LegalTermsService
{
    public function __construct(
        private readonly AuditService $audit,
    ) {}

    /**
     * يعيد الإصدار الحالي للـ locale (مع fallback).
     */
    public function currentTerm(string $locale = 'ar'): ?LegalTerm
    {
        return LegalTerm::currentFor($locale);
    }

    /**
     * هل المستخدم يحتاج قبول الإصدار الحالي؟
     */
    public function needsAcceptance(User $user, string $locale = 'ar'): bool
    {
        $current = $this->currentTerm($locale);
        if (!$current) {
            // لا يوجد إصدار منشور → نقبل الجميع (افتراض آمن أثناء التهيئة)
            return false;
        }

        $accepted = UserLegalAcceptance::where('user_id', $user->id)
            ->where('legal_term_id', $current->id)
            ->exists();

        return !$accepted;
    }

    /**
     * يسجل قبول المستخدم. atomic — يستخدم UNIQUE constraint لمنع duplicates.
     *
     * @return UserLegalAcceptance|null الـ acceptance المُسجَّل (null إن قبله سابقاً)
     */
    public function accept(
        User $user,
        LegalTerm $term,
        ?string $ip = null,
        ?string $userAgent = null,
        ?string $deviceId = null,
    ): ?UserLegalAcceptance {
        // فحص مسبق سريع (بدون قفل) — السباق محسوم بـ UNIQUE constraint
        $existing = UserLegalAcceptance::where([
            'user_id' => $user->id,
            'legal_term_id' => $term->id,
        ])->first();

        if ($existing) {
            return null; // قبله مسبقاً
        }

        try {
            $acceptance = UserLegalAcceptance::create([
                'user_id' => $user->id,
                'legal_term_id' => $term->id,
                'accepted_version' => $term->version,
                'ip_address' => $ip,
                'user_agent' => $userAgent ? mb_substr($userAgent, 0, 255) : null,
                'device_id' => $deviceId,
                'accepted_at' => now(),
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // duplicate في race condition — نقرأ الموجود ونعيده
            if (str_contains($e->getMessage(), 'Duplicate') || str_contains((string)$e->getCode(), '23')) {
                return UserLegalAcceptance::where([
                    'user_id' => $user->id,
                    'legal_term_id' => $term->id,
                ])->first();
            }
            throw $e;
        }

        $this->audit->record([
            'actor_type' => 'user',
            'actor_user_id' => $user->id,
            'subject_type' => 'user',
            'subject_id' => (string)$user->id,
            'action' => 'TERMS_ACCEPTED',
            'decision_code' => 'TERMS_ACCEPTED',
            'reason' => "Accepted version {$term->version} ({$term->locale})",
            'severity' => 'info',
            'context' => [
                'term_id' => $term->id,
                'version' => $term->version,
                'locale' => $term->locale,
            ],
        ]);

        return $acceptance;
    }

    /**
     * Admin: ينشر إصدار جديد. يطفئ القديم في نفس الـ locale تلقائياً.
     *
     * @throws \InvalidArgumentException
     */
    public function publishNewVersion(
        string $version,
        string $locale,
        string $title,
        string $content,
        ?string $changelog,
        int $createdBy,
    ): LegalTerm {
        if (!preg_match('/^\d+(\.\d+)*$/', $version)) {
            throw new \InvalidArgumentException('Version must be like "1.0" or "2.1.3"');
        }

        return DB::transaction(function () use ($version, $locale, $title, $content, $changelog, $createdBy) {
            // أطفئ كل الإصدارات الحالية لهذا الـ locale
            LegalTerm::where('locale', $locale)
                ->where('is_current', true)
                ->update([
                    'is_current' => false,
                    'superseded_at' => now(),
                ]);

            // أنشئ الإصدار الجديد
            $term = LegalTerm::create([
                'version' => $version,
                'locale' => $locale,
                'title' => $title,
                'content' => $content,
                'is_current' => true,
                'effective_at' => now(),
                'changelog' => $changelog,
                'created_by' => $createdBy,
            ]);

            $this->audit->record([
                'actor_type' => 'admin',
                'actor_user_id' => $createdBy,
                'subject_type' => 'user',
                'subject_id' => null,
                'action' => 'TERMS_VERSION_PUBLISHED',
                'decision_code' => 'TERMS_PUBLISHED',
                'reason' => "New terms version {$version} ({$locale}) published",
                'severity' => 'notice',
                'context' => [
                    'term_id' => $term->id,
                    'version' => $version,
                    'locale' => $locale,
                ],
            ]);

            return $term;
        });
    }
}
