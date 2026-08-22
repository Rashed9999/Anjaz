<?php

/**
 * AMIAL-MERCHANT-APPROVAL-001 — **إذنُ مديرٍ لمرّةٍ واحدة.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **العطل الذي أدخل هذا، وقد قِيس لا افتُرض:**
 *
 * `merchant_role_permissions.approval` عمودٌ **يُكتب ولا يقرؤه أحد**.
 * و`MerchantPermissionService::assert()` تعُدّ «يحتاج اعتماداً» رفضاً
 * وتُحيل من أراد المسارَ إلى `evaluate()` — **ولا مُنادي لها في المشروع
 * كلِّه**.
 *
 * فثلاثُ منحٍ مُسندةٍ في القوالب **لا تُستعمَل أبداً**:
 *
 *   · كاشيرُ التجزئة    → `retail.return.create`  — لا يُنشئ مرتجعاً
 *   · موظّفُ المستودع    → `retail.waste.record`   — لا يُسجّل هالكاً
 *   · مشرفُ ورديّة الوقود → `fuel.sale.cancel`      — لا يُلغي بيعة
 *
 * **ومنحةٌ تُعرَض في الشاشة وتُسنَد ولا تفتح باباً هي «مبنيٌّ ولا يُوصَل
 * إليه» في أخبث صوره** — لأنّ الشاشةَ تقول إنّها ممنوحة.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولمَ إذنٌ مسبقٌ لا طلبٌ يُنفَّذ لاحقاً:**
 *
 * `ApprovalService` القائمة تخزّن الحمولةَ وتُنفّذها لحظةَ الاعتماد. وهو
 * صحيحٌ لأفعال الإدارة على حسابٍ بعينه، **وخطرٌ هنا**: مرتجعٌ يُنشأ بعد
 * ساعتين على مخزونٍ تغيّر وسعرٍ تغيّر. والأسوأ أنّ سجلَّ التدقيق يقول
 * **إنّ المديرَ هو من أنشأ المرتجع** — وهو لم يره.
 *
 * فالإذنُ يُمنح، **والموظّفُ هو من ينفّذ**. فيبقى «من فعل» صادقاً، ويُقيَّد
 * معه «من أذن».
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وأربعةُ قيودٍ في البناء لا في النيّة:**
 *
 * ① **لمرّةٍ واحدة.** إذنٌ يبقى صالحاً إذنٌ دائم، وذاك منحُ صلاحيّةٍ
 *    بخطواتٍ زائدة.
 * ② **محدودُ المبلغ.** الإذنُ لمرتجعِ خمسمئةٍ لا يُجيز خمسين ألفاً.
 * ③ **مؤقَّت.** وإذنٌ بلا نهايةٍ صمتٌ مؤجَّل.
 * ④ **والآذنُ يملك ما يأذن به.** كاشيرٌ لا يعتمد لكاشير، ولا يعتمد أحدٌ
 *    لنفسه — فصلُ المُعِدّ عن المعتمِد يسقط بأيٍّ من الاثنين.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_permission_overrides', function (Blueprint $t) {
            $t->id();

            $t->unsignedBigInteger('merchant_user_id')->index();

            /** الموظّفُ الذي سيُنفّذ — **وهو من يُطلَب له، لا من يُنفَّذ عنه**. */
            $t->unsignedBigInteger('requested_by_user_id')->index();

            $t->string('permission_code', 64)->index();

            /**
             * سقفُ المبلغ الذي أُذن به — `null` لفعلٍ بلا مبلغ.
             *
             * **ويُقارَن بما يُنفَّذ فعلاً لا بما طُلب**: من طلب لخمسمئةٍ
             * ثمّ نفّذ بخمسين ألفاً يُردّ.
             */
            $t->decimal('max_amount', 20, 4)->nullable();

            /** **إلزاميّ** — إذنٌ بلا سببٍ لا يُراجَع. */
            $t->text('reason');

            /** pending · granted · rejected · consumed · expired */
            $t->string('status', 16)->default('pending')->index();

            $t->unsignedBigInteger('granted_by_user_id')->nullable();
            $t->text('decision_note')->nullable();
            $t->timestamp('decided_at')->nullable();

            /** **ومتى استُعمل** — فإذنٌ مُنح ولم يُستعمل أثرٌ يُقرأ. */
            $t->timestamp('consumed_at')->nullable();
            $t->decimal('consumed_amount', 20, 4)->nullable();

            $t->timestamp('expires_at');
            $t->timestamps();

            // البحثُ الحارّ: إذنٌ صالحٌ لهذا الموظّف على هذا الفعل.
            //
            // **والاسمُ يُعطى ولا يُترَك لِـLaravel.** الاسمُ المولَّد
            // `merchant_permission_overrides_requested_by_user_id_permission_code_status_index`
            // طولُه ٧٨ محرفاً وحدُّ MySQL ٦٤ — فيسقط `CREATE` **بعد أن
            // يُنشأ الجدول**، فتقول المحاولةُ التالية «الجدول موجود».
            //
            // **والرسالةُ الثانيةُ عرَضٌ لا سبب** — وقد أرسلتني خلف
            // «عمليّةٍ متوازيةٍ تُهاجر القاعدة» لا وجودَ لها. (وهو نمطُ
            // «حارسٌ يكذب» في ثوب رسالةِ خطأ.)
            $t->index(['requested_by_user_id', 'permission_code', 'status'],
                'mpo_staff_perm_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_permission_overrides');
    }
};
