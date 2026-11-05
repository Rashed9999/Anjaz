<?php

declare(strict_types=1);

require __DIR__.'/../src/bootstrap.php';

use Muwathaq\HttpError;
use Muwathaq\Policy;

// الاعتماد لا يجوز أن يضع عدن افتراضياً حين لم يثبت المراجع محل الإقامة.
$case = ['verification_path' => 'women_progressive'];
$affirmed = ['verified_phone', 'verified_residence'];

foreach (['', ' ', 'AD', 'YE-XX'] as $invalid) {
    try {
        Policy::decisionContext($case, $affirmed, $invalid);
        fwrite(STDERR, "FAIL: invalid governorate was accepted.\n");
        exit(1);
    } catch (HttpError $error) {
        if ($error->errorCode !== 'INVALID_GOVERNORATE') {
            fwrite(STDERR, "FAIL: expected INVALID_GOVERNORATE.\n");
            exit(1);
        }
    }
}

$valid = Policy::decisionContext($case, $affirmed, 'YE-TA');
if (($valid['verified_residence_governorate'] ?? '') !== 'YE-TA') {
    fwrite(STDERR, "FAIL: selected governorate was not preserved.\n");
    exit(1);
}

$ui = file_get_contents(__DIR__.'/../public/index.php');
if ($ui === false || str_contains($ui, "if (\$governorate === '') \$governorate = 'YE-AD'")
    || !str_contains($ui, '<option value="">اختر المحافظة')) {
    fwrite(STDERR, "FAIL: reviewer UI may default the governorate.\n");
    exit(1);
}
echo "PASS: explicit governorate is required for approval.\n";
