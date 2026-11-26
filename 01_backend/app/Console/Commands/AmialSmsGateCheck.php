<?php

namespace App\Console\Commands;

use App\Services\Messaging\Providers\Sms\SmsGateProvider;
use Illuminate\Console\Command;

/**
 * AMIAL-MESSAGING-003 — مزوّدٌ لم يُجرَّب ليس مزوّداً.
 *
 * يفحص إعدادَ SMSGate والاتّصالَ والمصادقةَ وعددَ الأجهزة المسجَّلة
 * **دون إرسال رسالةٍ ودون أن يطبع سرّاً**. وبه يُتحقَّق من الإعداد قبل
 * تفعيل المزوّد في اللوحة.
 *
 * `--message-id=ID` يستعلم عن حالة رسالةٍ أُرسلت (Pending/Sent/Delivered/Failed).
 */
class AmialSmsGateCheck extends Command
{
    protected $signature = 'amial:sms-gate-check {--message-id= : حالةُ رسالةٍ بمعرّفها}';

    protected $description = 'يفحص إعدادَ SMSGate واتّصالَه ومصادقتَه دون إرسال رسالة';

    public function handle(SmsGateProvider $provider): int
    {
        if ($id = (string) $this->option('message-id')) {
            $state = $provider->messageState($id);

            if ($state === null) {
                $this->error('تعذّر الاستعلام (مزوّدٌ غيرُ مضبوط، أو معرّفٌ غيرُ معروف، أو انقطاع).');

                return self::FAILURE;
            }

            $this->line("الرسالة {$state['id']} — الحالة: {$state['state']}");

            foreach ($state['recipients'] as $r) {
                $this->line('  مستلم ' . $this->maskPhone((string) ($r['phoneNumber'] ?? '')) . ' — ' . ($r['state'] ?? '?'));
            }

            return self::SUCCESS;
        }

        $enabled = $provider->isEnabled();
        $this->line('مُفعَّل ومكتمل الإعداد: ' . ($enabled ? 'نعم' : 'لا — فعِّله من لوحة الإدارة ← إعدادات ← الرسائل القصيرة ← sms_gate'));

        $probe = $provider->probe();

        if (!$probe['ok']) {
            $this->error('الاتّصال/المصادقة فشل: ' . ($probe['error'] ?? 'غير معروف'));

            return self::FAILURE;
        }

        $this->info("الاتّصال والمصادقة سليمان (HTTP {$probe['status']}).");
        $this->line('الأجهزة المسجَّلة: ' . $probe['devices']);

        if ($probe['devices'] === 0) {
            $this->warn('لا جهازَ مسجَّلاً — لن تخرج رسالةٌ حتى يُسجَّل هاتفٌ بالتطبيق.');

            return self::FAILURE;
        }

        return $enabled ? self::SUCCESS : self::FAILURE;
    }

    private function maskPhone(string $p): string
    {
        return strlen($p) < 6 ? '****' : substr($p, 0, 5) . str_repeat('*', max(0, strlen($p) - 8)) . substr($p, -3);
    }
}
