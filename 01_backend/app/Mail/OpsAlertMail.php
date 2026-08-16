<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * AMIAL-PROD-READINESS-003 — **رسالةُ الإنذار التشغيليّ.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولمَ صنفٌ لا `Mail::raw()`:**
 *
 * `MailFake::raw()` **دالّةٌ فارغةٌ لا تُسجّل شيئاً** — فقناةٌ مبنيّةٌ بها
 * لا يمكن إثباتُ إرسالها في أيّ اختبار. وقناةُ إنذارٍ لا تُختبَر هي
 * بعينها ما تحاربه هذه الجولة: **يُظنّ أنّها تعمل حتّى الليلة التي
 * تُحتاج فيها.**
 *
 * فصارت `Mailable` — يمسكها `Mail::assertSent()`، ويُقاس وصولُها كما
 * يُقاس كلُّ شيءٍ آخر هنا.
 *
 * **ولا تُرحَّل في طابور** (`Queueable` غيرُ مُنفَّذ عمداً): الطابورُ على
 * قاعدة البيانات، وإنذارُ «القاعدةُ ساقطة» لا يُرسَل عبر القاعدة الساقطة.
 * فيُرسَل في الطلب نفسِه ولو أبطأ.
 *
 * ولا يحمل رقمَ عميلٍ ولا مبلغَ محفظةٍ بعينها — يقول «هناك فرق، افتح
 * اللوحة»، والتفصيلُ خلف الجلسة والصلاحيّة.
 */
class OpsAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $alertTitle,
        public readonly string $body,
    ) {}

    public function build(): self
    {
        return $this
            ->subject('أميال باي — ' . $this->alertTitle)
            ->text('emails.ops-alert', ['body' => $this->body]);
    }
}
