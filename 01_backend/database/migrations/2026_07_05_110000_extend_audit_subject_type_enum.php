<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-OPS-CONSOLE-001 — توسيع subject_type في سجل التدقيق.
 *
 * اكتُشف باختبار متصفّح حقيقي: إنشاء تذكرة دعم كان يحاول تسجيل قرار تدقيق
 * بـ subject_type='support_ticket' غير الموجود في الـ enum، فيُقتطع ويفشل
 * الإدراج بصمت (non-blocking) — أي فقدان سجلات تدقيق. نضيف القيمة.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE audit_decisions
            MODIFY subject_type ENUM('user','transaction','wallet','merchant','session','pin','support_ticket')
            NOT NULL DEFAULT 'user'
        ");
    }

    public function down(): void
    {
        // ══════════════════════════════════════════════════════════════
        //  **تراجُعٌ لا يعمل ليس تراجعاً — AMIAL-DOCUMENTS-002.**
        //
        //  كان هذا السطر يُضيّق التعداد وفي الجدول صفوفٌ تحمل
        //  `support_ticket`، فيقع:
        //
        //      SQLSTATE[01000] 1265 Data truncated for column
        //      'subject_type' at row 1
        //
        //  وهو لا يمسّ الإنتاج (لا أحد يتراجع هناك)، **لكنّه يُسقط كلّ
        //  اختبارٍ يستعمل `DatabaseMigrations`** — وهو يتراجع بعد كلّ
        //  حالة. فسقط `ReceiptDocumentAfterCommitTest` بعد سبع تأكيداتٍ
        //  ناجحة، ورسالتُه تتحدّث عن عمودٍ لا علاقةَ له بالإيصالات.
        //
        //  فتُنقل الصفوفُ الخارجةُ عن التعداد الهدف أوّلاً. و`user` هي
        //  القيمة الافتراضيّة للعمود، فلا تُخترع قيمةٌ جديدة.
        // ══════════════════════════════════════════════════════════════
        DB::table('audit_decisions')
            ->whereNotIn('subject_type', [
                'user', 'transaction', 'wallet', 'merchant', 'session', 'pin',
            ])
            ->update(['subject_type' => 'user']);

        DB::statement("
            ALTER TABLE audit_decisions
            MODIFY subject_type ENUM('user','transaction','wallet','merchant','session','pin')
            NOT NULL DEFAULT 'user'
        ");
    }
};
