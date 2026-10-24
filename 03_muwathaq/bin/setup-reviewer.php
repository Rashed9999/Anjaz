<?php

declare(strict_types=1);

use Muwathaq\ReviewerCredentials;

require __DIR__.'/../src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command must run from the terminal.\n");
    exit(1);
}

fwrite(STDOUT, "Reviewer email: ");
$email = mb_strtolower(trim((string) fgets(STDIN)));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Enter a valid reviewer email.\n");
    exit(1);
}

function readHiddenPassword(string $prompt): string
{
    $stty = trim((string) shell_exec('stty -g'));
    if ($stty === '') {
        throw new RuntimeException('An interactive terminal is required.');
    }

    fwrite(STDOUT, $prompt);
    shell_exec('stty -echo');
    try {
        return trim((string) fgets(STDIN));
    } finally {
        shell_exec('stty '.$stty);
        fwrite(STDOUT, PHP_EOL);
    }
}

try {
    $password = readHiddenPassword("Reviewer password (minimum 12 characters): ");
    $confirmation = readHiddenPassword("Confirm reviewer password: ");
} catch (RuntimeException $error) {
    fwrite(STDERR, $error->getMessage().PHP_EOL);
    exit(1);
}

if (mb_strlen($password) < 12) {
    fwrite(STDERR, "Password must contain at least 12 characters.\n");
    exit(1);
}
if (!hash_equals($password, $confirmation)) {
    fwrite(STDERR, "Passwords do not match; no account was changed.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
if ($hash === false) {
    fwrite(STDERR, "Could not secure the password.\n");
    exit(1);
}

ReviewerCredentials::save($email, $hash);
fwrite(STDOUT, "Reviewer account saved securely.\n");
