<?php

namespace App\Services\Muwathaq;

use App\Models\MuwathaqAccountOpeningApplication;
use DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * AMIAL-MUWATHAQ-REGISTRATION-CONTRACT-001
 *
 * موثّق هو مصدر سياسة فتح الحساب. لا تُقرر شاشة أو متحكم أميال باي
 * المتطلبات بنفسها؛ بل يطلبان عقداً موقعاً داخلياً وفق نوع الحساب
 * والجنس/القطاع، ثم يطبقان النسخة التي أعادها موثّق.
 */
class MuwathaqRegistrationContractService
{
    /** @return array<string,mixed> */
    public function resolve(string $accountType, ?string $gender = null, ?string $businessType = null): array
    {
        $selector = [
            'subject_kind' => strtolower(trim($accountType)),
            'gender' => $gender === null ? null : strtolower(trim($gender)),
            'business_type' => $businessType === null ? null : trim($businessType),
        ];

        $this->validateSelector($selector);

        $key = 'muwathaq:registration-contract:'.hash('sha256', json_encode($selector));

        return Cache::remember($key, now()->addMinutes(5), function () use ($selector): array {
            if (!$this->isConfigured()) {
                throw new DomainException('سياسة فتح الحساب غير متاحة لأن اتصال موثّق غير مضبوط.');
            }

            $body = json_encode(['selector' => $selector], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $timestamp = (string) time();
            $signature = hash_hmac('sha256', $timestamp.'.'.$body, MuwathaqTransportSecrets::amialToMuwathaq());

            try {
                $response = Http::acceptJson()
                    ->withHeaders([
                        'X-Amial-Timestamp' => $timestamp,
                        'X-Amial-Signature' => $signature,
                        'Content-Type' => 'application/json',
                    ])
                    ->timeout((int) config('muwathaq.timeout_seconds', 10))
                    ->send('POST', rtrim((string) config('muwathaq.base_url'), '/').'/api/v1/internal/registration-contract', [
                        'body' => $body,
                    ]);
            } catch (\Throwable $e) {
                throw new DomainException('تعذر الوصول إلى سياسة موثّق لفتح الحساب.', previous: $e);
            }

            if (!$response->successful() || !is_array($response->json('data'))) {
                throw new DomainException('موثّق لم يعد عقد فتح حساب صالحاً.');
            }

            $contract = $response->json('data');
            $this->validateContract($contract, $selector);

            return $contract;
        });
    }

    public function assertMatchesApplication(array $contract, MuwathaqAccountOpeningApplication $application): void
    {
        if (($contract['subject_kind'] ?? null) !== $application->subject_kind
            || ($contract['verification_path'] ?? null) !== $application->verification_path
            || ($contract['policy_version'] ?? null) === '') {
            throw new DomainException('عقد فتح الحساب لا يطابق المسار الذي حدده موثّق.');
        }
    }

    /** @param array<string,mixed> $selector */
    private function validateSelector(array $selector): void
    {
        if (!in_array($selector['subject_kind'], ['customer', 'merchant'], true)) {
            throw new DomainException('نوع فتح الحساب غير مدعوم.');
        }

        if ($selector['subject_kind'] === 'customer'
            && !in_array($selector['gender'], ['male', 'female'], true)) {
            throw new DomainException('يجب تحديد جنس العميل لاختيار سياسة فتح الحساب.');
        }

        if ($selector['subject_kind'] === 'merchant' && $selector['business_type'] === '') {
            throw new DomainException('قطاع المنشأة مطلوب لاختيار لوحة التاجر.');
        }
    }

    /** @param array<string,mixed> $contract @param array<string,mixed> $selector */
    private function validateContract(array $contract, array $selector): void
    {
        foreach (['policy_version', 'subject_kind', 'verification_path', 'registration_fields', 'review_requirements'] as $key) {
            if (!array_key_exists($key, $contract)) {
                throw new DomainException('عقد موثّق ناقص: '.$key);
            }
        }

        if ($contract['subject_kind'] !== $selector['subject_kind']) {
            throw new DomainException('موثّق أعاد عقداً لنوع حساب مختلف.');
        }

        if (!is_array($contract['registration_fields']) || !is_array($contract['review_requirements'])) {
            throw new DomainException('صيغة عقد موثّق غير صالحة.');
        }
    }

    private function isConfigured(): bool
    {
        return trim((string) config('muwathaq.base_url')) !== ''
            && MuwathaqTransportSecrets::amialToMuwathaq() !== '';
    }
}
