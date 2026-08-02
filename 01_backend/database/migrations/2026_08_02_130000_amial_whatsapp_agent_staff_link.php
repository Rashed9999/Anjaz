<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-WA-AGENT-001 — واتساب لموظّفي شركات الصرافة.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الموظّف ليس `User`.** الصرّاف ومدير الفرع صفوفٌ في `agent_staff`: لا
 * محفظة لهم ولا حساب في التطبيق — المحفظة للفرع، والمسؤوليّة للدرج.
 *
 * وجدول الربط `whatsapp_linked_devices` مبنيٌّ على `user_id` وحده. فلا
 * يستطيع صرّافٌ أن يربط رقمه إطلاقاً، ولا أن يسأل البوت عن درجه.
 *
 * فيُضاف مالكٌ ثانٍ للربط: إمّا مستخدم (عميل/وكيل) وإمّا موظّف. ولا
 * يجتمعان في صفٍّ واحد — رقمٌ واحدٌ يمثّل شخصاً واحداً.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ورقم الواتساب فريدٌ على مستوى الجدول كلّه.**
 *
 * ولو سُمح لرقمٍ أن يكون عميلاً وموظّفاً معاً لصار سؤال «ما رصيدي؟»
 * غامضاً: أرصيدُ محفظته أم نقدُ درجه؟ والغموض في المال لا يُحلّ بالتخمين.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_linked_devices', function (Blueprint $table) {
            $table->unsignedBigInteger('agent_staff_id')->nullable()->after('user_id');
            $table->index('agent_staff_id', 'wa_link_staff_idx');
        });

        // `user_id` كان إلزاميّاً — والموظّف لا مستخدمَ له.
        Schema::table('whatsapp_linked_devices', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_linked_devices', function (Blueprint $table) {
            $table->dropIndex('wa_link_staff_idx');
            $table->dropColumn('agent_staff_id');
        });
    }
};
