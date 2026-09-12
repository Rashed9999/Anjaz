<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-HELD-SALE-001 — **التذاكرُ المفتوحة: سلّةٌ تُعلَّق ولا تُلغى.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **من أين جاءت:** «التذاكر المفتوحة — السماح بحفظ وتعديل الطلبات قبل
 * إكمال عملية الدفع» في شاشة إعدادات المنافس، واختارها صاحبُ المشروع
 * بنداً ثانياً.
 *
 * **وقِيس ما هو قائمٌ قبل البناء:**
 *
 *   held_sales · parked_sales · open_tickets · sale_drafts  ← **لا وجودَ لأيٍّ منها**
 *   restaurant_orders                                        ← موجودٌ **للمطاعم وحدَها**
 *   وسلّةُ الكاشير: `RxList<CartLine>` في الذاكرة **فقط**
 *
 * فالبقّالُ الذي يقول له الزبون «انتظر، نسيتُ الحليب» أمامه بابان: **يُوقف
 * الطابورَ كلَّه**، أو **يُلغي السلّة** ويُعيد مسحَ عشرين صنفاً. وأسوأُ من
 * ذلك: **السلّةُ في الذاكرة وحدَها** — مكالمةٌ واردةٌ تُخرج التطبيقَ فتذهب.
 *
 * ─────────────────────────────────────────────────────────────────────
 * **① ولماذا في الخادم لا في الجهاز؟**
 *
 * ثلاثةُ أسباب مقيسة: **تنجو من إغلاق التطبيق** (وهي الحالةُ الأشيع)،
 * **وتُستأنَف من شبّاكٍ آخر** (الزبونُ انتقل إلى الصندوق الثاني)، **ويراها
 * المالكُ** فيعرف كم تذكرةً معلَّقةً تركها موظّفوه آخرَ اليوم.
 *
 * **② ولا تحجز مخزوناً — وهذا قرارٌ يُكتب لا يُسكت عنه.**
 *
 * التذكرةُ قد تبقى ساعاتٍ أو تُهجَر. وحجزُ المخزون لها **يُفرّغ الرفَّ
 * لبيعةٍ قد لا تقع**، فيُمنَع زبونٌ حاضرٌ يدفع الآن من أجل تذكرةٍ نسيها
 * صاحبُها. فالبضاعةُ تخرج **عند الدفع** كما هي اليوم، والمخزونُ يُفحص
 * هناك. (و`StockReservationService` قائمٌ في المشروع ويُستعمَل حيث يصحّ:
 * الحجزُ حتّى نجاح الدفع، وهو نافذةٌ ثوانٍ لا ساعات.)
 *
 * **③ والمالُ يُنسَب إلى ورديّة الدفع لا إلى ورديّة الفتح.**
 *
 * تذكرةٌ فُتحت في ورديّة أحمد ودفعها الزبونُ بعد تسليم الشبّاك لسالم:
 * **النقدُ في درج سالم** لأنّه هو من قبضه — و`shift_id` تُكتب في
 * `recordSale` عند الدفع. وتُحفظ ورديّةُ الفتح هنا **للأثر وحدَه**، فمن
 * فتح ليس دائماً من قبض.
 *
 * **④ والملكيّةُ للمنشأة لا للجهاز.**
 *
 * أيُّ كاشيرٍ يستأنف تذكرةَ زميله — وهذا عينُ الغرض: الزبونُ انتقل إلى
 * صندوقٍ آخر. **لكنّ اسمَ من فتحها يُحفَظ لقطةً** (كالورديّة بالحرف)، فلا
 * يضيع الأثرُ حين يُعاد تسميةُ موظّفٍ أو يُحذَف.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('held_sales', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_ulid', 26)->unique();
            $table->unsignedBigInteger('merchant_user_id')->index();

            // ④ من فتحها — والاسمُ لقطةٌ لا مرجع.
            $table->unsignedBigInteger('pos_user_id')->nullable()->index();
            $table->unsignedBigInteger('opened_by')->nullable();
            $table->string('opened_by_name', 190)->nullable();

            // ③ ورديّةُ الفتح — **للأثر وحدَه**، والمالُ لورديّة الدفع.
            $table->unsignedBigInteger('shift_id')->nullable()->index();

            // **اسمٌ يُميّزها في القائمة.** رقمٌ وحدَه لا يكفي: الكاشيرُ
            // يعود بعد نصف ساعةٍ إلى خمس تذاكرَ ولا يعرف أيُّها لِمن.
            $table->string('label', 120)->nullable();
            $table->string('customer_name', 190)->nullable();
            $table->string('customer_phone', 32)->nullable();

            // **الأصنافُ بصيغة سلّة الكاشير نفسِها** — فلا تحويلَ بين
            // شكلين يُسقط حقلاً في الطريق.
            $table->json('items');
            $table->decimal('total', 20, 4)->default(0);
            $table->string('notes', 500)->nullable();

            // open → resumed | voided
            $table->string('status', 16)->default('open')->index();
            $table->timestamp('resumed_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 300)->nullable();

            // **والبيعةُ التي وُلدت منها** — فيُعرف مصيرُ التذكرة.
            $table->string('sale_ulid', 26)->nullable()->index();

            $table->string('zone_code', 16)->nullable();
            $table->timestamps();

            $table->index(['merchant_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('held_sales');
    }
};
