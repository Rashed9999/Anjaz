<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('control_center_policies', function (Blueprint $table) {
            $table->string('feature_key', 50)->primary();
            $table->string('mode', 20);
            $table->unsignedInteger('revision')->default(1);
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();
        });

        // Preserve the effective legacy state. A preexisting disabled flag
        // cannot silently become RUNNING as a side effect of this migration.
        $wasEnabled = Schema::hasTable('feature_flags')
            ? DB::table('feature_flags')->where('key', 'safe_payment')->value('enabled')
            : null;

        DB::table('control_center_policies')->insert([
            'feature_key' => 'safe_payment',
            'mode' => $wasEnabled === null || (bool) $wasEnabled ? 'RUNNING' : 'PAUSE_NEW',
            'revision' => 1,
            'changed_by' => null,
            'reason' => 'Initial policy: preserved pre-migration Safe Payment intake state.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('control_center_policies');
    }
};
