<?php

declare(strict_types=1);

/**
 * فحص قناة قرار موثّق إلى أميال باي بلا إنشاء حساب أو تعديل بيانات.
 *
 * نجاح الفحص هو استلام 409 متعمّد: التوقيع والطريق صحيحان، لكن مرجع طلب
 * الاختبار غير موجود، لذلك يرفضه أميال باي بأمان.
 */
use Muwathaq\CoreDecisionClient;

require __DIR__.'/../src/bootstrap.php';

$case = [
    'core_application_ulid' => '01HZZZZZZZZZZZZZZZZZZZZZZZ',
    'decision' => 'under_review',
    'decision_reference' => 'MWT-CONNECTION-CHECK-0001',
    'decision_reason' => 'Non-mutating secure transport check.',
    'decision_context_json' => json_encode(['policy_version' => 'connection-check-v1'], JSON_THROW_ON_ERROR),
];

try {
    CoreDecisionClient::deliver($case);
    fwrite(STDERR, "Unexpected acceptance: stop and investigate the test reference.\n");
    exit(2);
} catch (RuntimeException $error) {
    if (str_contains($error->getMessage(), 'HTTP 409')) {
        echo "Verified: Muwathaq can reach Amial Pay through the signed decision channel.\n";
        exit(0);
    }

    fwrite(STDERR, "Verification failed: {$error->getMessage()}\n");
    exit(1);
}
