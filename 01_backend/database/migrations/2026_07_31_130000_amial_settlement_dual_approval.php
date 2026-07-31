<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-DUAL-APPROVAL-001 — موافقتان لا واحدة، فوق حدٍّ ماليّ.
 *
 * **ما كان قائماً:** فصلُ مهام — من أنشأ لا يعتمد، ومن قدّم لا يعتمد، ومن
 * اعتمد لا ينفّذ. وهو ضابطٌ صحيح لكنه غير الموافقة المزدوجة.
 *
 * **والفرق جوهريّ:** فصل المهام يمنع أن يقوم شخصٌ بخطوتين. والموافقة
 * المزدوجة تشترط أن يقرّر **شخصان** في الخطوة الواحدة. فتسويةٌ بمليون ريال
 * تخرج اليوم بتوقيع موظّفٍ واحد — وهو ما تمنعه كل الأنظمة المصرفية فوق حدٍّ
 * معيّن، لأن التواطؤ يحتاج اثنين، والخطأ الفرديّ يلتقطه ثانٍ.
 *
 * **ولماذا حدٌّ ماليّ لا اشتراطٌ دائم؟** موافقتان على كل تسوية صغيرة تُعطّل
 * العمل، فيُعتمد بالجملة بلا قراءة — ويصير الضابط طقساً. الحدّ يجعل التأنّي
 * حيث يستحقّ.
 *
 * ويُخزَّن عدد الموافقات المطلوبة **على الصفّ** لا يُحسب عند الاعتماد: لو
 * غُيّر الحدّ في الإعدادات بين الإنشاء والاعتماد لتغيّر عدد الموافقات على
 * تسويةٍ جارية — فتُعتمد بواحدة ما كان يلزمها اثنتان.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('settlements')) {
            return;
        }

        Schema::table('settlements', function (Blueprint $table) {
            if (!Schema::hasColumn('settlements', 'approvals_required')) {
                // يُثبَّت عند الإنشاء من الحدّ السائد وقتها.
                $table->unsignedTinyInteger('approvals_required')->default(1)->after('approved_by');
            }

            if (!Schema::hasColumn('settlements', 'second_approved_by')) {
                $table->unsignedBigInteger('second_approved_by')->nullable()->after('approvals_required');
            }

            if (!Schema::hasColumn('settlements', 'second_approved_at')) {
                $table->timestamp('second_approved_at')->nullable()->after('second_approved_by');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('settlements')) {
            return;
        }

        Schema::table('settlements', function (Blueprint $table) {
            foreach (['approvals_required', 'second_approved_by', 'second_approved_at'] as $col) {
                if (Schema::hasColumn('settlements', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
