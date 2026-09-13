<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_user_roles', function (Blueprint $table): void {
            $table->boolean('suspended_by_staff_toggle')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('merchant_user_roles', function (Blueprint $table): void {
            $table->dropColumn('suspended_by_staff_toggle');
        });
    }
};
