<?php

declare(strict_types=1);

require __DIR__.'/../src/bootstrap.php';

use Muwathaq\HttpError;
use Muwathaq\Policy;

/**
 * Regression test: approvals must never silently label an account as Yemen's
 * YE-AD (Aden) merely because the reviewer left residence unspecified.
 * Pure CLI test: no DB, credentials or production connection required.
 */
$case = ['verification_path' => 'women_progressive'];
$affirmed = ['verified_phone', 'verified_residence'];

foreach (['', ' ', 'AD', 'YE-XX'] as $unverified) {
    try {
        Policy::decisionContext($case, $affirmed, $unverified);
        fwrite(STDERR, "FAIL: unverified governorate was accepted.\n");
        exit(1);
    } catch (HttpError $e) {
        if ($e->errorCode !== 'INVALID_GOVERNORATE') {
            fwrite(STDERR, "FAIL: expected INVALID_GOVERNORATE, got {$e->errorCode}\n");
            exit(1);
        }
    }
}

$valid = Policy::decisionContext($case, $affirmed, 'YE-TA');
if (($valid['verified_residence_governorate'] ?? '') !== 'YE-TA') {
    fwrite(STDERR, "FAIL: explicit valid governorate was not preserved.\n");
    exit(1);
}

$ui = file_get_contents(__DIR__.'/../public/index.php');
if ($ui === false
    || str_contains($ui, "if (\$governorate === '') \$governorate = 'YE-AD'")
    || !str_contains($ui, '<option value="">اختر المحافظة')
) {
    fwrite(STDERR, "FAIL: reviewer UI may silently default to YE-AD.\n");
    exit(1);
}

echo "PASS: verified residence requires an explicit valid governorate.\n";
