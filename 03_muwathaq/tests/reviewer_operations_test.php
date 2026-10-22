<?php

declare(strict_types=1);

/**
 * عقد انحدار لتجربة موثّق المبسطة:
 * نقطة دخول واحدة، طابور واحد، ثم التفاصيل وقرار اعتماد/رفض فقط.
 */
function requireContains(string $source, string $needle, string $message): void
{
    if (!str_contains($source, $needle)) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function requireNotContains(string $source, string $needle, string $message): void
{
    if (str_contains($source, $needle)) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$muwathaqRoot = dirname(__DIR__);
$repoRoot = dirname($muwathaqRoot);
$index = file_get_contents($muwathaqRoot.'/public/index.php');
$bootstrap = file_get_contents($muwathaqRoot.'/src/bootstrap.php');
$sidebar = file_get_contents($repoRoot.'/01_backend/resources/views/admin-views/amial/partials/_sidebar.blade.php');
$core = file_get_contents($repoRoot.'/01_backend/app/Http/Controllers/Api/Internal/MuwathaqOperationsController.php');
$routes = file_get_contents($repoRoot.'/01_backend/routes/api/unified-auth.php');

if (!is_string($index) || !is_string($bootstrap) || !is_string($sidebar) || !is_string($core) || !is_string($routes)) {
    fwrite(STDERR, "FAIL: required Muwathaq source files could not be read\n");
    exit(1);
}

requireContains($index, 'موظفة الجلسة', 'reviewer identity must remain visible');
requireContains($index, 'الحسابات الجديدة', 'the single review queue must remain the landing page');
requireContains($index, "status IN ('submitted','under_review','needs_more_info')", 'queue must contain only unresolved review cases');
requireContains($index, 'href="/cases/', 'a queue item must open its local details view');
requireContains($index, 'اعتماد الحساب', 'approval option is required');
requireContains($index, 'رفض الحساب', 'rejection option is required');
requireNotContains($index, '<aside class="sidebar">', 'the old multi-list sidebar must not return');
requireNotContains($index, 'href="/accounts?', 'the old account-directory navigation must stay hidden');
requireContains($index, "in_array($path, ['/accounts', '/audit'], true)", 'old UI URLs must redirect to the one queue');
requireContains($index, "['approved', 'rejected']", 'reviewer endpoint must accept only approval or rejection');
requireContains($index, "redirect('/');", 'a decision must return to the one queue');

foreach (['session_started', 'case_opened', 'evidence_opened', 'decision_recorded'] as $action) {
    requireContains($index, "'{$action}'", "audit action {$action} is required");
}
requireContains($bootstrap, 'muwathaq_reviewer_audit_outbox', 'encrypted audit outbox is required');
requireContains($bootstrap, 'final class ReviewerAudit', 'reviewer audit dispatcher is required');
requireContains($bootstrap, 'Cipher::encrypt(json_encode($event', 'audit payload must be encrypted locally');

requireContains($sidebar, "config('muwathaq.base_url')", 'admin button must use the configured Muwathaq URL');
requireContains($sidebar, 'الدخول إلى نظام موثّق', 'admin must have a Muwathaq entry button');
requireContains($sidebar, 'الاعتماد التشغيلي بعد قرار موثّق', 'final activation must be clearly distinguished from review');
requireNotContains($sidebar, 'مراجعة ملفات التوثيق السابقة', 'legacy KYC menu must remain hidden');

requireContains($core, 'function storeAuditEvent(Request $request)', 'signed audit API endpoint is required');
requireContains($core, "->only(['list', 'decision', 'delivery_status'])", 'audit context must be allowlisted');
requireContains($routes, '/internal/muwathaq/reviewer-audit-events', 'signed audit route is required');

echo "Muwathaq simplified-review regression contract passed.\n";
