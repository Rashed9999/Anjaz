<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_bulk_import_rows', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_user_id')->index();
            $table->string('import_type', 32)->index();
            $table->string('file_sha256', 64);
            $table->unsignedInteger('row_number');
            $table->string('row_sha256', 64);
            $table->string('idempotency_key', 64)->unique();
            $table->string('result_reference', 120)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(
                ['merchant_user_id', 'import_type', 'file_sha256'],
                'bulk_import_owner_type_file'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_bulk_import_rows');
    }
};
