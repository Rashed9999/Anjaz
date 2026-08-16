<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\CentralLogics\WhatsappModule;

/**
 * AMIAL-PROD-READINESS-001 — **موضعٌ واحدٌ يُرفَع منه الإنذار.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمن — قِيس في تدقيق الجاهزيّة، وهو أخطرُ ما فيه:**
 *
 *   $ php artisan tinker --execute="var_export(config('amial.reconciliation.alert_numbers'));"
 *   array ( )
 *
 * المصالحةُ الليليّةُ تجري ٠٢:٠٠، وتفحص المحافظَ والدفترَ وخزائنَ النقد،
 * **وتكتشف الفرقَ فعلاً**. ثمّ تحاول الإنذارَ فتجد القائمةَ فارغةً،
 * فتطبع سطراً في ملفٍّ ولا أحدَ يقرؤه:
 *
 *   $this->warn('⚠️ وُجد فرقٌ ولا رقمَ إنذارٍ مضبوط');
 *
 * ومعها: `amial:health-check` يكتب `down` في جدولٍ ولا يُنذر،
 * و`ErrorTrackingService` يسجّل العطلَ ولا يُنذر. **فثلاثُ أدواتِ رصدٍ
 * تعمل، وثلاثتُها تكتب لمن يفتح اللوحةَ صدفة.**
 *
 * واختلالُ ثابتٍ ماليٍّ يُكتشَف الساعةَ الثانية ولا يوقظ أحداً يبقى
 * يتراكم حتّى يشتكي تاجر. **وهو نقضٌ لقاعدة المشروع نفسِها**: «صاحبُ
 * المشروع لم يعد جهازَ الرصد».
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولمَ خدمةٌ لا سطرٌ في كلّ موضع:**
 *
 * لأنّ العطلَ كان **الفرعَ الصامت** لا غيابَ الشيفرة: كلُّ موضعٍ يُنذر
 * كتب لنفسه «إن لم تكن هناك قناةٌ فاصمت». فيُنتزَع القرارُ من المواضع
 * ويُجمع هنا، **وقاعدتُه واحدة:**
 *
 *   **لا إنذارَ يسقط في الفراغ.** الأثرُ في `system_errors` **أوّلاً
 *   ودائماً** — فيُرى في مركز الأعطال بعدّادٍ وحالةٍ تُغلق — ثمّ تُجرَّب
 *   القناةُ الخارجيّة. فغيابُ الرقم يُضعف الإنذارَ ولا يُلغيه.
 *
 * **وغيابُ القناة نفسُه يُرفَع عطلاً** (`ops.alert_channel_missing`).
 * فالفجوةُ التي تُخفي الأعطالَ لا يجوز أن تكون هي أخفاها — وهي تظهر في
 * الصفحة نفسِها التي يفتحها المشرف.
 */
class OpsAlertService
{
    /** بصمةُ «لا قناةَ مضبوطة» — ثابتةٌ فلا تتكرّر صفوفُها. */
    public const NO_CHANNEL_KEY = 'ops.alert_channel_missing';

    /**
     * يرفع إنذاراً تشغيليّاً.
     *
     * @param  string  $key      مفتاحٌ ثابتٌ للنوع — منه البصمة، فالمتكرّرُ يُعدّ ولا يُكرَّر
     * @param  string  $title    سطرٌ واحدٌ يُقرأ في جدول الأعطال
     * @param  string  $detail   نصُّ رسالة القناة الخارجيّة — **بلا معرّفاتٍ ولا مبالغِ عميل**
     * @return bool  أَخرَجَ الإنذارُ من الخادم فعلاً؟ (لا «أَسُجِّل»)
     */
    public function raise(string $key, string $title, string $detail): bool
    {
        // ① الأثرُ أوّلاً — **قبل** أيّ محاولةِ إرسال. فقناةٌ ساقطةٌ لا
        //    تبتلع الحادثة، ولا يعتمد بقاءُ الأثر على نجاح الشبكة.
        $this->trace($key, $title, $detail);

        $to = array_values(array_filter(
            (array) config('amial.reconciliation.alert_numbers', [])));

        if ($to === []) {
            // ② **الفجوةُ تُرفَع عطلاً** — لا سطرَ تحذيرٍ في ملفّ.
            $this->trace(
                self::NO_CHANNEL_KEY,
                'لا قناةَ إنذارٍ خارجيّةٌ مضبوطة',
                'وقع إنذارٌ تشغيليٌّ ولا رقمَ يصله: اضبط AMIAL_RECON_ALERT_TO. '
                . 'وحتّى يُضبَط، لا يُعرف الانكسارُ إلّا بفتح هذه الصفحة.',
            );

            Log::warning('ops-alert: لا قناةَ خارجيّة', ['key' => $key, 'title' => $title]);

            return false;
        }

        $sent = false;

        foreach ($to as $number) {
            try {
                WhatsappModule::sendText((string) $number, $detail);
                $sent = true;
            } catch (\Throwable $e) {
                // **سقوطُ القناة لا يُسقط ما استدعاها** — الأثرُ محفوظٌ سلفاً.
                Log::warning('ops-alert: تعذّر الإرسال', [
                    'key' => $key, 'error' => $e->getMessage(),
                ]);
            }
        }

        return $sent;
    }

    /**
     * يكتب الحادثةَ في `system_errors` — الجدولَ نفسَه الذي يقرؤه مركزُ
     * الأعطال في اللوحة، **فلا مصدرَ ثانٍ للحقيقة**.
     *
     * والبصمةُ من المفتاح لا من الرسالة: عشرُ ليالٍ متتاليةٍ فيها فرقٌ
     * صفٌّ واحدٌ بعدّاده لا عشرةُ صفوفٍ تُغرق الجدول. (وهي قاعدةُ
     * `ErrorTrackingService` نفسُها.)
     */
    private function trace(string $key, string $title, string $detail): void
    {
        $fingerprint = hash('sha256', 'ops|' . $key);
        $now = now();

        try {
            $existing = DB::table('system_errors')
                ->where('fingerprint', $fingerprint)
                ->first();

            if ($existing) {
                DB::table('system_errors')->where('id', $existing->id)->update([
                    'occurrences' => DB::raw('occurrences + 1'),
                    'last_seen_at' => $now,
                    'message' => mb_substr($title . ' — ' . $detail, 0, 2000),
                    // **ما عاد بعد إغلاقه يُفتح ثانيةً.** وفرقٌ ماليٌّ
                    // أُقفل ثمّ عاد الليلةَ التالية أخطرُ من أوّل مرّة.
                    'status_flag' => $existing->status_flag === 'resolved'
                        ? 'open' : $existing->status_flag,
                    'updated_at' => $now,
                ]);

                return;
            }

            DB::table('system_errors')->insert([
                'fingerprint' => $fingerprint,
                // الصنفُ يُقرأ في اللوحة عبر `class_basename` — فيُكتب
                // اسمُ الحادثة لا اسمُ صنفِ PHP.
                'exception' => mb_substr($key, 0, 191),
                'message' => mb_substr($title . ' — ' . $detail, 0, 2000),
                'file' => null,
                'line' => null,
                'method' => null,
                'path' => null,
                'status' => null,
                'user_id' => null,
                'actor_type' => null,
                'occurrences' => 1,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'status_flag' => 'open',
                'trace_head' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (\Throwable $ignored) {
            // **المُنذِرُ لا يُسقط من استدعاه** — وهي قاعدةُ
            // `ErrorTrackingService` نفسُها: أداةُ المراقبة لا تُغيّر النتيجة.
        }
    }

    /** أَمضبوطةٌ قناةٌ خارجيّة؟ — تقرؤه اللوحةُ لتقول الفجوةَ صراحةً. */
    public static function hasExternalChannel(): bool
    {
        return array_filter((array) config('amial.reconciliation.alert_numbers', [])) !== [];
    }
}
