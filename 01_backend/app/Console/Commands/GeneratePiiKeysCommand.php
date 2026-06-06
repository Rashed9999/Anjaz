<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * AMIAL-PII-ENCRYPTION-001 (v1.3)
 *
 * GeneratePiiKeysCommand — توليد مفاتيح encryption آمنة.
 *
 * **الاستخدام:**
 *   php artisan amial:generate-pii-keys
 *
 * يطبع الـ keys على الـ terminal. انسخها يدوياً إلى .env.
 * (لا نكتب .env تلقائياً لتجنب overwrites غير مقصودة)
 */
class GeneratePiiKeysCommand extends Command
{
    protected $signature = 'amial:generate-pii-keys';
    protected $description = 'Generate AES-256 keys for PII encryption (AMIAL-PII-ENCRYPTION-001)';

    public function handle(): int
    {
        $encKey = base64_encode(random_bytes(32));
        $bidxKey = base64_encode(random_bytes(32));

        $this->info("════════════════════════════════════════════════════════════════");
        $this->info("AMIAL PII Encryption Keys (v1.3)");
        $this->info("════════════════════════════════════════════════════════════════");
        $this->newLine();
        $this->warn("⚠️  أضف هذين السطرين إلى .env:");
        $this->newLine();
        $this->line("AMIAL_PII_ENCRYPTION_KEY={$encKey}");
        $this->line("AMIAL_PII_BLIND_INDEX_KEY={$bidxKey}");
        $this->newLine();
        $this->warn("⚠️  مهم جداً:");
        $this->line("  1. احفظهما في secret manager (AWS Secrets, Vault) — ليس في git");
        $this->line("  2. عمل backup آمن للـ keys — فقدانها = فقدان كل البيانات الـ encrypted");
        $this->line("  3. لا تغيرهما بعد ترحيل البيانات إلا عبر key rotation procedure");
        $this->newLine();
        $this->info("════════════════════════════════════════════════════════════════");

        return self::SUCCESS;
    }
}
