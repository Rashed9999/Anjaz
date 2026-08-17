<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-FEE-TRUTH-015 — **نسخةٌ نشطةٌ واحدة، يفرضها الجدولُ لا الشيفرة.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمن:** `createVersion` تُعطّل القديمةَ ثمّ تُنشئ الجديدة داخل معاملةٍ
 * مع `lockForUpdate`. وهذا يحمي من **مسارين متزامنين على نسخةٍ قائمة**،
 * ولا يحمي من الحالة التي لا صفَّ فيها بعد:
 *
 *   مديران يفتحان «نسخة جديدة» لـ`SEND_MONEY` لأوّل مرّة
 *   كلاهما: `lockForUpdate()->first()` ⇒ **`null`** — ولا صفَّ ليُقفَل
 *   كلاهما: `version = 1`، `is_active = true`
 *
 * فتصير **نسختان نشطتان** للعمليّة نفسِها. و`activeScheme()` ترتّب
 * `orderByDesc('version')` ثمّ `first()` — والنسختان بالرقم نفسِه، فأيُّهما
 * تُطبَّق **يقرّره ترتيبُ المحرّك لا قرارُ أحد**. ويتغيّر بين استعلامين.
 *
 * **فالرسمُ المحصَّلُ يصير غيرَ محدَّد**، والمديرُ الذي غيّره يرى نسختَه
 * محفوظةً ولا يفهم لِمَ لا تُطبَّق أحياناً.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والقفلُ في الشيفرة لا يكفي مهما أُحكم**: مساراتٌ أخرى تكتب في الجدول
 * (‏بذورٌ، هجرات، إصلاحٌ يدويّ)، **والقاعدةُ وحدَها تحرس الجميع**.
 *
 *   `fee_one_active`   عمودٌ محسوبٌ فريد: `code:zone:applies_to` للنشط
 *                      وحدَه، و`NULL` لغيره — و`NULL` لا يتصادم، فتبقى
 *                      كلُّ النسخ القديمة كما هي.
 *   `fee_one_version`  ولا رقمَ نسخةٍ مكرّرٌ في المسار نفسِه.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fee_schemes')) {
            return;
        }

        // ══════════════════════════════════════════════════════════════
        // ① **يُنظَّف ما وقع قبل القيد** — وإلّا سقطت الهجرةُ على الإنتاج
        //    ولا تُقال العلّة. ويُبقى **الأحدثُ** نشطاً: هو ما قصده آخرُ
        //    من غيّر التسعيرة.
        $dupes = DB::table('fee_schemes')
            ->where('is_active', true)
            ->select('code', 'zone_code', 'applies_to')
            ->groupBy('code', 'zone_code', 'applies_to')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($dupes as $d) {
            $keepId = DB::table('fee_schemes')
                ->where('code', $d->code)
                ->where('zone_code', $d->zone_code)
                ->where('applies_to', $d->applies_to)
                ->where('is_active', true)
                ->orderByDesc('version')
                ->orderByDesc('id')
                ->value('id');

            DB::table('fee_schemes')
                ->where('code', $d->code)
                ->where('zone_code', $d->zone_code)
                ->where('applies_to', $d->applies_to)
                ->where('is_active', true)
                ->where('id', '!=', $keepId)
                ->update(['is_active' => false, 'effective_to' => now()]);
        }

        // ② ورقمُ نسخةٍ مكرّرٌ يُدفَع إلى رقمٍ حرّ.
        $vdupes = DB::table('fee_schemes')
            ->select('code', 'zone_code', 'applies_to', 'version')
            ->groupBy('code', 'zone_code', 'applies_to', 'version')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($vdupes as $d) {
            $ids = DB::table('fee_schemes')
                ->where('code', $d->code)
                ->where('zone_code', $d->zone_code)
                ->where('applies_to', $d->applies_to)
                ->where('version', $d->version)
                ->orderBy('id')
                ->pluck('id')
                ->all();

            array_shift($ids);   // الأوّلُ يبقى برقمه

            $next = (int) DB::table('fee_schemes')
                ->where('code', $d->code)
                ->where('zone_code', $d->zone_code)
                ->where('applies_to', $d->applies_to)
                ->max('version');

            foreach ($ids as $id) {
                DB::table('fee_schemes')->where('id', $id)->update(['version' => ++$next]);
            }
        }

        // ══════════════════════════════════════════════════════════════
        // ③ العمودُ المحسوب + القيدان.
        //
        // ولا يُفترض أنّ المحرّكَ يقبل الأعمدةَ المحسوبة: إن رفضها بقي
        // القيدُ الثاني — وهو ما يمنع أخطرَ الحالتين (‏رقما نسخةٍ متصادمان
        // يجعلان الترتيبَ عشوائيّاً) — **ويُقال ما لم يُنشأ لا يُسكت عنه**.
        if (! Schema::hasColumn('fee_schemes', 'active_key')) {
            try {
                DB::statement(
                    'ALTER TABLE fee_schemes ADD COLUMN active_key VARCHAR(120) '
                    . "AS (IF(is_active = 1, CONCAT(code, ':', zone_code, ':', applies_to), NULL)) STORED");

                DB::statement(
                    'ALTER TABLE fee_schemes ADD UNIQUE INDEX fee_one_active (active_key)');
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning(
                    'AMIAL-FEE-TRUTH-015: تعذّر إنشاء قيد «نسخةٌ نشطةٌ واحدة» — '
                    . $e->getMessage());
            }
        }

        try {
            DB::statement(
                'ALTER TABLE fee_schemes ADD UNIQUE INDEX fee_one_version '
                . '(code, zone_code, applies_to, version)');
        } catch (\Throwable $e) {
            // موجودٌ سلفاً — الهجرةُ تُعاد على قاعدةٍ مهاجَرة.
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('fee_schemes')) {
            return;
        }

        foreach (['fee_one_active', 'fee_one_version'] as $idx) {
            try {
                DB::statement("ALTER TABLE fee_schemes DROP INDEX {$idx}");
            } catch (\Throwable $e) {
                // لم يُنشأ.
            }
        }

        if (Schema::hasColumn('fee_schemes', 'active_key')) {
            try {
                DB::statement('ALTER TABLE fee_schemes DROP COLUMN active_key');
            } catch (\Throwable $e) {
                // لا شيء.
            }
        }
    }
};
