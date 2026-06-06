<?php

/**
 * AMIAL-REPORTS-001 (v2.11)
 *
 * نظام التقارير والتصدير.
 *
 * **من الوثيقة الأصلية:**
 *   "تقارير الأداء والتصدير" + "التصدير CSV/Excel/PDF للتقارير الكبيرة عبر queue"
 *
 * **المبدأ:**
 *   التقارير الكبيرة تُولّد في الخلفية (queue) لئلا تبطئ النظام أو تستهلك ذاكرته.
 *   المستخدم يطلب التقرير → job يولّده → إشعار عند الجاهزية → تحميل.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_exports', function (Blueprint $table) {
            $table->id();
            $table->string('export_ulid', 26)->unique();

            $table->unsignedBigInteger('requested_by_user_id');
            $table->string('requester_type', 20)->default('user'); // user, merchant, admin

            // نوع التقرير
            $table->string('report_type', 50);
            // merchant_ledger, user_transactions, platform_performance, aml_compliance, agent_settlement

            $table->enum('format', ['csv', 'pdf', 'excel'])->default('csv');

            // معايير التقرير (التواريخ، الفلاتر)
            $table->json('parameters')->nullable();

            // الحالة
            $table->enum('status', ['pending', 'processing', 'ready', 'failed', 'expired'])
                ->default('pending')->index();

            // النتيجة
            $table->string('file_path', 500)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->integer('row_count')->nullable();
            $table->string('error_message', 500)->nullable();

            // انتهاء الصلاحية (التقارير تُحذف بعد فترة لتوفير المساحة)
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable();
            $table->integer('download_count')->default(0);

            $table->string('zone_code', 16)->default('SOUTH');
            $table->timestamps();

            $table->index(['requested_by_user_id', 'created_at']);
            $table->index(['report_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_exports');
    }
};
