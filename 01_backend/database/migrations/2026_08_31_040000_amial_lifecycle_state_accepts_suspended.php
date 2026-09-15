<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-LIFECYCLE-SUSPEND-001 — **«إيقاف العميل» يكتب حالةً لا يقبلها العمود.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمنُ الذي قِيس:** `CustomerActionService::apply()` تكتب
 * `lifecycle_state = 'suspended'` (‏السطر ٨١)، و`CustomerStatusResolver`
 * تقرؤها (‏السطر ١١٢) — **والعمودُ `enum('active','inactive','deceased',
 * 'closed')`**. فالقيمةُ ليست فيه.
 *
 * وأثرُها يختلف بالوضع ولا يخلو من ضرر:
 *   · بوضعٍ صارم  → `1265 Data truncated` — يُوقف الإجراءَ برسالةٍ إنجليزيّة
 *   · بغير صارم  → **تُكتب فارغةً صامتةً**: الإدارةُ تقرأ «تمّ الإيقاف»
 *                   والعميلُ يعمل. وهي أخطرُ الحالتين.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولمَ بقي مستوراً منذ يوليو:** الحارسُ مكتوبٌ منذ `813b441`
 * (‏«wip: enforce suspended customer state — requires full verification»)
 * **بلا بادئة `test_` ولا `@test`، فلا يشغّله شيء**. وأوّلُ تشغيلٍ له —
 * حين أُضيفت البادئة — أسقطه في نفس اللحظة.
 *
 * **وحارسٌ لا يُنفَّذ ليس حارساً** (القاعدة الثانية): كُتب، ومرّ شهرٌ،
 * والعطلُ الذي بُني ليمسكه قائمٌ في الإنتاج.
 * ══════════════════════════════════════════════════════════════════════
 */
return new class extends Migration
{
    /** القيمةُ الجديدةُ **تُضاف** ولا يُعاد تعريفُ العمود من الصفر. */
    private const WITH = "enum('active','inactive','deceased','closed','suspended')";

    private const WITHOUT = "enum('active','inactive','deceased','closed')";

    public function up(): void
    {
        // **`ALTER` خامٌ عمداً** — `doctrine/dbal` ليس مطلوباً في المشروع،
        // و`$table->enum()->change()` بدونه يسقط. وتغييرُ نوعٍ في MySQL
        // ذرّيٌّ فلا يحتاج معاملة.
        DB::statement(
            'ALTER TABLE `users` MODIFY `lifecycle_state` '.self::WITH." NOT NULL DEFAULT 'active'");
    }

    public function down(): void
    {
        // **الموقوفون يعودون إلى `inactive` لا إلى `active`.**
        //
        // فتراجعٌ يُعيد موقوفاً إلى «فعّال» **يفتح حساباً أُوقف بقرار** —
        // وهو ضررٌ أشدُّ من التراجع نفسِه. و`inactive` أقربُ معنىً وأسلمُ
        // أثراً. (‏والقرارُ يُقرأ هنا ولا يُخمَّن يومَ الحاجة.)
        DB::table('users')->where('lifecycle_state', 'suspended')
            ->update(['lifecycle_state' => 'inactive']);

        DB::statement(
            'ALTER TABLE `users` MODIFY `lifecycle_state` '.self::WITHOUT." NOT NULL DEFAULT 'active'");
    }
};
