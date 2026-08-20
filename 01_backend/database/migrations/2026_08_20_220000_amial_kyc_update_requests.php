<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-KYC-UPDATE-REQUEST-001 — طلبُ تحديث الهوية حالةٌ قابلة للتدقيق،
 * لا Boolean صامت لا يقرأه أحد.
 *
 * لا نكرّر قرار اعتماد KYC نفسه في جدولٍ آخر: المصدر يبقى
 * `users.is_kyc_verified`. هذه الحقول تصف فقط لماذا خُفّضت صلاحية الحساب
 * ومتى ومن طلب منه إعادة تقديم الأدلة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'kyc_update_required')) {
                $table->boolean('kyc_update_required')->default(false)->index();
            }
            if (! Schema::hasColumn('users', 'kyc_update_requested_at')) {
                $table->timestamp('kyc_update_requested_at')->nullable();
            }
            if (! Schema::hasColumn('users', 'kyc_update_requested_by')) {
                $table->unsignedBigInteger('kyc_update_requested_by')->nullable()->index();
            }
            if (! Schema::hasColumn('users', 'kyc_update_previous_tier')) {
                // يُعرض للمراجع كي لا يعيد حساب فئة ٣ إلى فئة ٢ سهواً.
                $table->unsignedTinyInteger('kyc_update_previous_tier')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach ([
                'kyc_update_required',
                'kyc_update_requested_at',
                'kyc_update_requested_by',
                'kyc_update_previous_tier',
            ] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
