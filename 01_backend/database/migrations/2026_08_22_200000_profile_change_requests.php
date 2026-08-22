<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-PROFILE-CHANGE-001 — **طلبُ تحديث بيانات، لا زرُّ تعديل.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الطلب، بنصّه:** «لم أرَ أيّ زرّ تعديل ملفّ معلومات العميل... بالتأكيد
 * لا يجيب على الدعم الحصول على زرّ التعديل، ربّما وظيفة أخرى».
 *
 * **والحدسُ صائب، والقياسُ يؤيّده:**
 *
 *     مسارات تعديل بيانات العميل من اللوحة  →  صفر
 *     من يكتب `identification_number` اليوم →  العميلُ لنفسه فقط
 *
 * **وزرُّ «تعديل» في يد الدعم خطأٌ لا نقص:** من يستطيع تغييرَ رقم
 * الهويّة يستطيع **تحويلَ حسابٍ موثَّقٍ إلى شخصٍ آخر**، ثمّ يسحب رصيدَه.
 * والنموذجُ المصرفيُّ نفسُه يقولها: «أتعهّد بتحديث البيانات فور حدوث أيّ
 * تغيير» — **التحديثُ طلبٌ من صاحبه، لا تصرّفٌ من الموظّف.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **فثلاثةُ أدوارٍ لا دورٌ واحد:**
 *
 *   · **الدعمُ يفتح الطلبَ ويتابعه** — ولا يكتب قيمةً واحدة
 *   · **العميلُ يملأ القيمَ الجديدة** من التطبيق، ومعها وثيقةٌ داعمة
 *   · **مراجعٌ غيرُ من فتح الطلب يعتمد** — والمبدأُ الرباعيُّ قائمٌ
 *     في المشروع سلفاً، فيُطبَّق ها هنا أيضاً
 *
 * ══════════════════════════════════════════════════════════════════════
 * **و«قبل» تُخزَّن مع «بعد».**
 *
 * تخزينُ الجديد وحدَه يجعل السجلَّ يقول «غُيّر الاسم» ولا يقول من ماذا.
 * ومراجعةٌ بعد سنةٍ تحتاج ما كان لا ما صار — **وقراءتُه من الحساب حينها
 * تُخرج جواباً عن لحظةٍ غير اللحظة التي وقع فيها القرار**. (وهو الدرسُ
 * نفسُه من `zone_assignment_logs`.)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('profile_change_requests')) {
            return;
        }

        Schema::create('profile_change_requests', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('user_id')->comment('صاحبُ الملفّ');

            // **ومن فتح الطلب ليس بالضرورة صاحبَه**: الدعمُ يفتحه نيابةً
            // حين يتّصل العميلُ بمشكلة. فيُحفظ الاثنان.
            $table->unsignedBigInteger('opened_by')->nullable()
                ->comment('من فتح الطلب — موظّفُ دعمٍ أو العميلُ نفسُه');
            $table->string('opened_by_type', 20)->default('customer');

            $table->string('field', 60)->comment('الحقلُ المطلوبُ تغييره');

            // **«قبل» تُخزَّن مع «بعد»** — انظر شرحَ الهجرة.
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();

            $table->string('reason', 500)->nullable()
                ->comment('لماذا يُطلب التغيير — وطلبٌ بلا سببٍ لا يُراجَع');

            // PENDING_CUSTOMER — فُتح وينتظر العميلَ أن يملأ
            // PENDING_REVIEW   — مُلئ وينتظر مراجعاً
            // APPROVED · REJECTED · CANCELLED
            $table->string('status', 20)->default('PENDING_CUSTOMER');

            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_reason', 500)->nullable();

            // وثيقةٌ داعمةٌ حين يلزم — ورقمُ هويّةٍ يتغيّر بلا وثيقةٍ ليس
            // تحديثاً بل استبدالُ شخص.
            $table->unsignedBigInteger('supporting_document_id')->nullable();

            $table->timestamps();

            // **وأسماءُ الفهارس صريحةٌ لا مولَّدة** — حدُّ MySQL للمعرّف
            // ٦٤ محرفاً، والمولَّدُ من اسمِ جدولٍ طويلٍ وثلاثةِ أعمدةٍ
            // يتجاوزه فتُنشأ الجدولُ ويسقط الفهرس. (وقع في هذه الجلسة.)
            $table->index(['user_id', 'status'], 'pcr_user_status_idx');
            $table->index(['status', 'created_at'], 'pcr_status_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_change_requests');
    }
};
