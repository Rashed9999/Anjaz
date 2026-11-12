<?php

namespace App\Support\Muwathaq;

use Illuminate\Http\JsonResponse;

/**
 * The public account-opening boundary.
 *
 * Identity evidence, an approval decision, and the primary account-opening
 * record belong to Muwathaq. Amial Pay only consumes Muwathaq's signed
 * provisioning event after that decision. Keeping the response in one place
 * prevents a legacy route from silently becoming a second creation channel.
 */
final class AccountOpeningBoundary
{
    public const CODE = 'ACCOUNT_CREATION_OWNED_BY_MUWATHAQ';

    /**
     * @param 'customer'|'merchant'|'agent' $subject
     */
    public function retired(string $subject): JsonResponse
    {
        $subject = in_array($subject, ['customer', 'merchant', 'agent'], true)
            ? $subject
            : 'customer';

        $baseUrl = rtrim((string) config('muwathaq.base_url'), '/');
        $path = match ($subject) {
            'merchant' => '/open-account?type=merchant',
            // An agent is never a public self-registration flow. This page
            // requires a Muwathaq staff session and enforces maker/checker.
            'agent' => '/accounts/new-agent',
            default => '/open-account?type=customer',
        };

        return response()->json([
            'success' => false,
            'code' => self::CODE,
            'message' => $subject === 'agent'
                ? 'إنشاء الوكيل يتم حصراً من موظف موثّق مخوّل؛ أميال باي لا ينشئ وكلاء.'
                : 'إنشاء الحساب يتم حصراً عبر موثّق؛ أميال باي لا ينشئ حسابات جديدة.',
            'account_opening_owner' => 'muwathaq',
            'subject_type' => $subject,
            'muwathaq_url' => $baseUrl . $path,
        ], 410);
    }
}
