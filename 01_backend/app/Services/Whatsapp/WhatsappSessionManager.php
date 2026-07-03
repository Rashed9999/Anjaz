<?php

namespace App\Services\Whatsapp;

use Illuminate\Support\Facades\Cache;

/**
 * AMIAL-WA-002 — إدارة جلسات WhatsApp Bot (نسخة مُحدَّثة وفق Section 5).
 *
 * التحديثات عن v1:
 *   - مدّة الجلسة 30 دقيقة (كانت 10) — وفق نصّ المواصفة حرفياً
 *   - touchActivity() لتسجيل last_activity في كل تفاعل
 *   - مدّة جلسة الربط (OTP) منفصلة تماماً وأقصر — تُدار في
 *     WhatsappAccountLinkingService، لا علاقة لها بهذا الملف
 *
 * بنية الجلسة:
 *   step          : المرحلة الحالية
 *   data          : بيانات جُمعت خلال المحادثة
 *   lang          : لغة المستخدم
 *   started_at    : وقت البدء
 *   last_activity : آخر نشاط (Section 5)
 */
class WhatsappSessionManager
{
    private const TTL_MINUTES = 30; // AMIAL-WA-002 — وفق المواصفة (كانت 10)
    private const PREFIX = 'wa_session:';

    // ============ خطوات المحادثة (v1) ============
    public const STEP_MENU               = 'menu';
    public const STEP_TRANSFER_PHONE     = 'transfer_awaiting_phone';
    public const STEP_TRANSFER_AMOUNT    = 'transfer_awaiting_amount';
    public const STEP_TRANSFER_CONFIRM   = 'transfer_awaiting_confirm';
    public const STEP_TRANSFER_PIN_SENT  = 'transfer_pin_sent';
    public const STEP_STATEMENT_RANGE    = 'statement_awaiting_range';
    public const STEP_AGENT_SEARCH       = 'agent_awaiting_location';

    // ============ خطوات جديدة (v2 — Section 4 + 9) ============
    public const STEP_LINK_PHONE         = 'link_awaiting_phone';
    public const STEP_LINK_OTP           = 'link_awaiting_otp';
    public const STEP_TRANSFER_PICK_RECIPIENT = 'transfer_awaiting_recipient_pick';

    // ============ خطوات جديدة (v3 — AMIAL-WA-003 — Section 22 + 23) ============
    public const STEP_BILLPAY_PICK_SERVICE = 'billpay_awaiting_service';
    public const STEP_BILLPAY_ACCOUNT      = 'billpay_awaiting_account';
    public const STEP_BILLPAY_AMOUNT       = 'billpay_awaiting_amount';
    public const STEP_BILLPAY_PIN_SENT     = 'billpay_pin_sent';
    public const STEP_PAYREQ_AWAITING_CODE = 'payreq_awaiting_code';
    public const STEP_PAYREQ_PIN_SENT      = 'payreq_pin_sent';

    // ============ الـ API ============

    public function get(string $phone): ?array
    {
        $raw = Cache::get(self::PREFIX . $phone);
        return $raw ? (array) $raw : null;
    }

    public function set(string $phone, array $session): void
    {
        Cache::put(
            self::PREFIX . $phone,
            $session,
            now()->addMinutes(self::TTL_MINUTES),
        );
    }

    public function clear(string $phone): void
    {
        Cache::forget(self::PREFIX . $phone);
    }

    public function getOrCreate(string $phone): array
    {
        return $this->get($phone) ?? [
            'step'          => self::STEP_MENU,
            'data'          => [],
            'lang'          => 'ar',
            'started_at'    => now()->toIso8601String(),
            'last_activity' => now()->toIso8601String(),
        ];
    }

    public function advance(string $phone, string $step, array $data = []): void
    {
        $session           = $this->getOrCreate($phone);
        $session['step']   = $step;
        $session['data']   = array_merge($session['data'] ?? [], $data);
        $session['last_activity'] = now()->toIso8601String();
        $this->set($phone, $session);
    }

    /** AMIAL-WA-002 — يحدّث آخر نشاط دون تغيير الخطوة (Section 5). */
    public function touchActivity(string $phone): void
    {
        $session = $this->get($phone);
        if (!$session) return;
        $session['last_activity'] = now()->toIso8601String();
        $this->set($phone, $session);
    }

    public function getData(string $phone, string $key, mixed $default = null): mixed
    {
        $session = $this->get($phone);
        return $session['data'][$key] ?? $default;
    }

    public function isAt(string $phone, string $step): bool
    {
        return ($this->get($phone)['step'] ?? self::STEP_MENU) === $step;
    }

    public function currentStep(string $phone): string
    {
        return $this->get($phone)['step'] ?? self::STEP_MENU;
    }
}
