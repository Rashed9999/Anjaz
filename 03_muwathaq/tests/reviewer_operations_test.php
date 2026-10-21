<?php

declare(strict_types=1);

/**
 * عقد انحدار لواجهة موظفة الجلسة وقناة التدقيق.
 *
 * لا يحتاج قاعدة بيانات أو أسراراً: يفحص أن عناصر الأمان والربط التي لا
 * يجوز فقدها أثناء إعادة تصميم الواجهة ما زالت موصولة في المصدر.
 */
function requireContains(string $source, string $needle, string $message): void
{
    if (!str_contains($source, $needle)) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$index = file_get_contents($root.'/public/index.php');
$bootstrap = file_get_contents($root.'/src/bootstrap.php');
$core = file_get_contents(dirname($root).'/01_backend/app/Http/Controllers/Api/Internal/MuwathaqOperationsController.php');
$routes = file_get_contents(dirname($root).'/01_backend/routes/api/unified-auth.php');

if (!is_string($index) || !is_string($bootstrap) || !is_string($core) || !is_string($routes)) {
    fwrite(STDERR, "FAIL: reviewer operations source files could not be read\n");
    exit(1);
}

requireContains($index, 'موظفة الجلسة', 'session-worker sidebar label is required');
foreach (['all', 'new', 'customer_female', 'customer_male', 'merchant', 'agent', 'active', 'suspended', 'review'] as $list) {
    requireContains($index, "'/accounts?list={$list}'", "sidebar list {$list} is required");
}
foreach (['session_started', 'directory_opened', 'case_opened', 'evidence_opened', 'decision_recorded'] as $action) {
    requireContains($index, "'{$action}'", "audit action {$action} is required");
}
requireContains($index, 'findCaseForApplication', 'directory must resolve local case references');
if (str_contains($index, 'href="/cases/'.e($row[\'application_ulid\']')) {
    fwrite(STDERR, "FAIL: directory must not use Amial application ULID as a Muwathaq case URL\n");
    exit(1);
}

requireContains($bootstrap, 'muwathaq_reviewer_audit_outbox', 'encrypted audit outbox is required');
requireContains($bootstrap, 'final class CoreOperationsClient', 'signed Amial operations client is required');
requireContains($bootstrap, 'final class ReviewerAudit', 'reviewer audit dispatcher is required');
requireContains($bootstrap, 'Cipher::encrypt(json_encode($event', 'audit payload must be encrypted locally');

requireContains($core, 'function directory(Request $request)', 'directory API endpoint is required');
requireContains($core, 'function storeAuditEvent(Request $request)', 'audit API endpoint is required');
requireContains($core, "->only(['list', 'decision', 'delivery_status'])", 'audit context must be allowlisted');
requireContains($routes, 'MuwathaqOperationsController;', 'operations controller import is required');
requireContains($routes, '/internal/muwathaq/account-directory', 'signed directory route is required');
requireContains($routes, '/internal/muwathaq/reviewer-audit-events', 'signed audit route is required');

echo "Reviewer operations regression contract passed.\n";
