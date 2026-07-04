<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-SIGNATURE-001 — التوقيع الإلكتروني عند فتح الحساب.
 *
 * كالتوقيع/البصمة على الورق في البنوك التقليدية، لكن رقمياً: يُلتقط توقيع العميل
 * عند التسجيل ويُحفظ *مشفّراً* (عبر EncryptedFileStorage) كسجلّ قانوني للحساب.
 * نخزّن المسار المشفّر فقط + وقت الالتقاط (لا نخزّن الصورة في القاعدة).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'signature_encrypted_path')) {
                $table->string('signature_encrypted_path', 500)->nullable()->after('identification_image');
            }
            if (!Schema::hasColumn('users', 'signature_captured_at')) {
                $table->timestamp('signature_captured_at')->nullable()->after('signature_encrypted_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['signature_encrypted_path', 'signature_captured_at']);
        });
    }
};
