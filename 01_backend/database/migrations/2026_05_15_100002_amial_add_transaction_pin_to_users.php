<?php

/**
 * AMIAL-PIN-SECURITY-001
 * فصل transaction_pin عن password.
 *
 * قبل: pin_check() يقارن PIN بـ password — اختراق password = سرقة مال فورية.
 * بعد: transaction_pin hash منفصل + last_pin_change + pin_attempt counter.
 *
 * استراتيجية الـ migration الخلفية (backfill):
 * - نضيف الأعمدة الجديدة nullable.
 * - السيناريو الانتقالي: عند أول login بعد v0.6، نطلب من المستخدم تعيين PIN.
 *   حتى يفعل ذلك، transaction_pin يبقى null والـ TransactionPinService يلجأ
 *   لـ password كـ fallback **مع تسجيل تحذير** ودفع المستخدم لإنشاء PIN.
 *   هذا يضمن عدم كسر القاعدة الحالية من المستخدمين.
 * - بعد 30 يوم من النشر، يصبح transaction_pin إلزامي ويرفض الـ Service الـ fallback.
 *
 * هذا توافق مع وثيقة المتطلبات قسم 9 (PIN-SECURITY-AMIAL-001).
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // hash منفصل عن password (bcrypt مثل password لكن قيمة مختلفة)
            $table->string('transaction_pin', 191)->nullable()->after('password');

            // متى تم تعيين/تغيير الـ PIN آخر مرة — لـ audit و policy
            $table->timestamp('transaction_pin_set_at')->nullable()->after('transaction_pin');

            // عداد المحاولات الفاشلة (يصفر بنجاح أو مع marker)
            $table->unsignedTinyInteger('pin_failed_attempts')->default(0)->after('transaction_pin_set_at');

            // قفل مؤقت بعد محاولات متعددة (security hold)
            $table->timestamp('pin_locked_until')->nullable()->after('pin_failed_attempts');

            // علم: هل يجب على المستخدم تعيين PIN عند الـ next login؟
            $table->boolean('requires_pin_setup')->default(true)->after('pin_locked_until');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'transaction_pin',
                'transaction_pin_set_at',
                'pin_failed_attempts',
                'pin_locked_until',
                'requires_pin_setup',
            ]);
        });
    }
};
