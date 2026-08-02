<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-WA-AGENT-002 — التنبيهات الصادرة: مفتاحُ إيقافٍ بيد صاحب الرقم.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **تنبيهٌ لا يُوقَف يُوقِفه صاحبُه بحظر الرقم.**
 *
 * ومن يحظر رقم البوت لا يفقد التنبيهات وحدها: يفقد الاستعلام كلّه —
 * «كم في درجي؟» و«ما حالة تسويتي؟». فيصير الحلُّ الذي اختاره لضجيجٍ
 * واحد قطعاً للقناة كلّها، ولا تعرف الإدارة أنّها قُطعت.
 *
 * فيُفصل الاتّجاهان: `alerts_enabled=false` يُسكت الصادر ويُبقي الوارد.
 * والافتراضُ مفتوحٌ لأنّ من ربط رقمه إنّما ربطه ليُبلَّغ.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولماذا عمودٌ في صفّ الربط لا جدولُ تفضيلات؟**
 *
 * لأنّ التفضيل يموت بموت القناة: يُفكّ الربط فيذهب معه. وجدولٌ منفصل
 * يترك صفوفاً يتيمة تُعاد على رقمٍ صار لشخصٍ آخر.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_linked_devices', function (Blueprint $table) {
            $table->boolean('alerts_enabled')->default(true)->after('agent_staff_id');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_linked_devices', function (Blueprint $table) {
            $table->dropColumn('alerts_enabled');
        });
    }
};
