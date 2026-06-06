<?php

namespace App\Exceptions;

use Exception;

/**
 * AMIAL-REFACTOR-CORE-001
 *
 * يُرمى عند محاولة تعديل .env في runtime داخل أي environment ليس local/testing.
 *
 * السبب: setEnvironmentValue في Cash6 كانت تعمل في production runtime مما يفتح
 * ثغرة لتعديل APP_KEY/DB_PASSWORD/PURCHASE_CODE/إلخ. — راجع AUDIT_v0.6.md 1.8.
 *
 * نسمح فقط:
 *   - في environment = 'local' أو 'testing'.
 *   - أو لو APP_ALLOW_ENV_MUTATION=true صراحة (للنشر الأول فقط، يُلغى بعدها).
 */
class EnvironmentMutationBlockedException extends Exception
{
    public string $decisionCode = 'ENV_MUTATION_BLOCKED';

    public function __construct(
        public readonly string $attemptedKey,
        public readonly string $currentEnvironment,
        string $message = 'Environment mutation blocked in this environment',
    ) {
        parent::__construct($message);
    }
}
