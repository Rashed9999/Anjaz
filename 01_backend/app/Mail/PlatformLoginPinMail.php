<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * رمز دخول موظف المنصة. الرسالة تحمل PIN المؤقت فقط ولا تحمل كلمة المرور.
 */
class PlatformLoginPinMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $employeeName,
        public readonly string $pin,
        public readonly string $reason = 'issued',
    ) {
    }

    public function build(): self
    {
        $subject = $this->reason === 'reset'
            ? 'أميال باي — تم إصدار PIN دخول جديد'
            : 'أميال باي — PIN دخول موظف المنصة';

        return $this
            ->subject($subject)
            ->text('emails.platform-login-pin', [
                'employeeName' => $this->employeeName,
                'pin' => $this->pin,
                'reason' => $this->reason,
            ]);
    }
}
