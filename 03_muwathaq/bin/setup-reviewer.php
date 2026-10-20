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

$stty = trim((string) shell_exec('stty -g'));
if ($stty === '') {
    fwrite(STDERR, "An interactive terminal is required.\n");
    exit(1);
}

fwrite(STDOUT, "Reviewer password (minimum 12 characters): ");
shell_exec('stty -echo');
$password = trim((string) fgets(STDIN));
shell_exec('stty '.$stty);
fwrite(STDOUT, PHP_EOL);

if (mb_strlen($password) < 12) {
    fwrite(STDERR, "Password must contain at least 12 characters.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
if ($hash === false) {
    fwrite(STDERR, "Could not secure the password.\n");
    exit(1);
}

ReviewerCredentials::save($email, $hash);
fwrite(STDOUT, "Reviewer account saved securely.\n");
