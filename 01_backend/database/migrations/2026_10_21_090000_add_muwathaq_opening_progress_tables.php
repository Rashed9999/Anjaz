<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * بيانات إكمال فتح الحساب تبقى مستقلة عن KYC التاريخي.
 *
 * access token لا يمنح دخولاً للحساب ولا صلاحية مالية؛ يصل فقط إلى طلب فتح
 * حساب واحد ولمدة محدودة. المحتوى الحساس للعنوان يُشفّر قبل حفظه.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('muwathaq_account_opening_applications', function (Blueprint $table) {
            $table->string('opening_access_token_hash', 64)->nullable()->after('status');
            $table->timestamp('opening_access_expires_at')->nullable()->after('opening_access_token_hash');
            $table->timestamp('evidence_completed_at')->nullable()->after('opening_access_expires_at');
            $table->index('opening_access_expires_at', 'muwathaq_opening_access_expiry');
        });

        Schema::create('muwathaq_opening_evidences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('muwathaq_account_opening_applications')->cascadeOnDelete();
            $table->string('kind', 40);
            $table->string('state', 24)->default('submitted');
            $table->longText('payload_encrypted')->nullable();
            $table->string('file_encrypted_path', 500)->nullable();
            $table->string('file_mime', 100)->nullable();
            $table->string('file_sha256', 64)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->unique(['application_id', 'kind'], 'muwathaq_opening_evidence_kind_unique');
            $table->index(['state', 'submitted_at'], 'muwathaq_opening_evidence_state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('muwathaq_opening_evidences');

        Schema::table('muwathaq_account_opening_applications', function (Blueprint $table) {
            $table->dropIndex('muwathaq_opening_access_expiry');
            $table->dropColumn(['opening_access_token_hash', 'opening_access_expires_at', 'evidence_completed_at']);
        });
    }
};
