<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-SAFEPAY-CODE-001 — توسيع أنواع أحداث الدفع الآمن.
 *
 * العمود ENUM مغلق، وأحداث رمز التسليم الجديدة تقع خارجه فترميها MySQL
 * بـ «Data truncated for column event_type» — وسط معاملة مالية.
 *
 * ENUM هنا خيار مكلف: كل حدث جديد يحتاج هجرة. أُبقيه (تغييره إلى VARCHAR
 * يفقد التحقّق على مستوى القاعدة في جدول تدقيقي) لكن أُوسّعه بما يلزم.
 */
return new class extends Migration
{
    private const TYPES = [
        'created', 'seller_accepted', 'seller_rejected',
        'in_delivery_marked', 'delivered_marked',
        'buyer_confirmed', 'released_to_seller',
        'buyer_disputed', 'buyer_cancelled',
        'admin_resolved_release', 'admin_resolved_refund', 'admin_resolved_partial',
        'expired', 'attachment_added', 'note_added',
        // الجديدة
        'delivery_code_verified', 'delivery_code_failed', 'evidence_added',
    ];

    public function up(): void
    {
        $list = implode(',', array_map(fn ($t) => "'{$t}'", self::TYPES));
        DB::statement("ALTER TABLE safe_payment_events MODIFY event_type ENUM({$list}) NOT NULL");
    }

    public function down(): void
    {
        $old = array_slice(self::TYPES, 0, 15);
        $list = implode(',', array_map(fn ($t) => "'{$t}'", $old));
        DB::statement("ALTER TABLE safe_payment_events MODIFY event_type ENUM({$list}) NOT NULL");
    }
};
