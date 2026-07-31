<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-DEVICE-TRUST-001 — من بوّابة جهازٍ واحد إلى سجلّ أجهزةٍ يُدار.
 *
 * **ما كان قائماً (وأخطأ تدقيقي في وصفه):** جدول `user_log_histories` يسجّل
 * فعلاً معرّف الجهاز وطرازه ونظامه وعنوانه، ويحتفظ بصفٍّ لكل جهاز استُعمل.
 * قلتُ في التقرير «لا سجلّ أجهزة» لأن بحثي طابق `device|session|fingerprint`
 * ولم يطابق اسم الجدول — والاسم لا يدلّ على محتواه.
 *
 * **وما ينقص فعلاً، وهو أدقّ وأضيق:**
 *   1. لا حظرَ جهاز. الوسيط `CheckDeviceId` يسأل «هل هذا الجهاز النشط؟» ولا
 *      يسأل «هل هو محظور؟». فمن سُرق جهازه لا يملك الدعم ما يمنعه به —
 *      إلّا بتجميد الحساب كلّه، وهو عقوبةٌ على الضحيّة.
 *   2. لا ثقةَ جهاز. فلا يُفرَّق بين جهاز العميل المعتاد وجهازٍ ظهر الليلة،
 *      وهذا التمييز أساسُ كشف الاستيلاء على الحسابات.
 *   3. لا أثر لمن حظر ولا لماذا. وحظرٌ بلا سببٍ مسجَّل قرارٌ لا يُراجَع.
 *
 * ولا يُنشأ جدولٌ موازٍ: جدولان يصفان الشيء نفسه يتفرّقان مع أوّل إضافة،
 * ويصير السؤال «أيّهما الصحيح؟» بلا جواب.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('user_log_histories')) {
            return;
        }

        Schema::table('user_log_histories', function (Blueprint $table) {
            if (!Schema::hasColumn('user_log_histories', 'is_trusted')) {
                // جهازٌ أقرّه صاحبه أو مضى عليه وقتٌ بلا شبهة.
                $table->boolean('is_trusted')->default(false)->after('is_active');
            }

            if (!Schema::hasColumn('user_log_histories', 'is_blocked')) {
                // يُمنع الجهاز ولو كان النشط — وهذا هو الفرق عن is_active.
                $table->boolean('is_blocked')->default(false)->after('is_trusted');
            }

            if (!Schema::hasColumn('user_log_histories', 'blocked_at')) {
                $table->timestamp('blocked_at')->nullable()->after('is_blocked');
            }

            if (!Schema::hasColumn('user_log_histories', 'blocked_by_user_id')) {
                $table->unsignedBigInteger('blocked_by_user_id')->nullable()->after('blocked_at');
            }

            if (!Schema::hasColumn('user_log_histories', 'block_reason')) {
                // السبب إلزاميّ في الخدمة لا في المخطّط: العمود يقبل null
                // للصفوف القديمة، والخدمة ترفض حظراً بلا سبب.
                $table->string('block_reason', 255)->nullable()->after('blocked_by_user_id');
            }

            if (!Schema::hasColumn('user_log_histories', 'last_seen_at')) {
                // `updated_at` يتغيّر لأي سبب؛ وهذا يُجيب سؤال الدعم بدقّة:
                // «متى استُعمل هذا الجهاز آخر مرّة؟»
                $table->timestamp('last_seen_at')->nullable()->after('block_reason');
            }

            if (!Schema::hasColumn('user_log_histories', 'app_version')) {
                $table->string('app_version', 32)->nullable()->after('last_seen_at');
            }
        });

        Schema::table('user_log_histories', function (Blueprint $table) {
            // يُسأل عنه في كل طلبٍ محميّ عبر CheckDeviceId.
            $indexes = collect(Schema::getIndexes('user_log_histories'))->pluck('name');
            if (!$indexes->contains('ulh_user_device_block_idx')) {
                $table->index(['user_id', 'device_id', 'is_blocked'], 'ulh_user_device_block_idx');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('user_log_histories')) {
            return;
        }

        Schema::table('user_log_histories', function (Blueprint $table) {
            $indexes = collect(Schema::getIndexes('user_log_histories'))->pluck('name');
            if ($indexes->contains('ulh_user_device_block_idx')) {
                $table->dropIndex('ulh_user_device_block_idx');
            }

            foreach ([
                'is_trusted', 'is_blocked', 'blocked_at', 'blocked_by_user_id',
                'block_reason', 'last_seen_at', 'app_version',
            ] as $col) {
                if (Schema::hasColumn('user_log_histories', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
