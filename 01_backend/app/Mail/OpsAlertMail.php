<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * AMIAL-PROD-READINESS-003/005 — رسالة الإنذار التشغيلي.
 *
 * تُرسل مباشرةً ولا تُرحّل إلى طابور، لأن إنذار سقوط قاعدة البيانات
 * لا يجوز أن يعتمد على الطابور نفسه. كما تستخدم القالب التفصيلي ذاته
 * الذي يستخدمه Resend، حتى لا تصبح قناة SMTP الاحتياطية أقل معلومات.
 */
class OpsAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @param array<string,string> $payload */
    public function __construct(
        public readonly array $payload,
    ) {}

    public function build(): self
    {
        return $this
            ->subject($this->payload['subject'])
            ->view('emails.ops-alert-html', $this->payload)
            ->text('emails.ops-alert', $this->payload);
    }
}
