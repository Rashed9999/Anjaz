<?php

namespace App\Services\Whatsapp;

/**
 * AMIAL-WA-002 — يُنسّق ردود أميال لتناسب واتساب (نسخة كاملة مُحدَّثة).
 *
 * هذا الملف drop-in replacement لـ v1 — يحوي كل دوال v1 + إضافات
 * Section 4 (ربط الحساب) و Section 9 (تعدّد المستفيدين) و Section 26
 * (تحقّق إضافي) و Section 29 (دعم العملاء).
 */
class WhatsappMessageFormatter
{
    // ============ القائمة الرئيسية ============

    public function mainMenu(string $name = ''): string
    {
        $greeting = $name ? "أهلاً *{$name}* 👋" : 'أهلاً بك 👋';

        return "{$greeting}\n\n"
             . "🌟 *أميال باي*\n"
             . "─────────────────\n"
             . "1️⃣  رصيدي\n"
             . "2️⃣  تحويل مال\n"
             . "3️⃣  كشف حساب\n"
             . "4️⃣  أقرب وكيل\n"
             . "5️⃣  دفع فاتورة\n"
             . "6️⃣  مساعدة\n"
             . "─────────────────\n"
             . "اختر رقماً أو اكتب طلبك مباشرة 👇";
    }

    // ============ الرصيد ============

    public function balance(string $balance, string $pending = '0'): string
    {
        $bal = $this->money($balance);
        $msg = "💰 *رصيدك الحالي*\n"
             . "─────────────────\n"
             . "متاح:   *{$bal}*\n";

        if (bccomp($pending, '0', 2) > 0) {
            $msg .= "⏳ معلّق: " . $this->money($pending) . "\n";
        }

        $msg .= "─────────────────\n"
              . "_آخر تحديث: " . now()->format('H:i، d/m/Y') . "_\n\n"
              . "اكتب *قائمة* للعودة للقائمة.";

        return $msg;
    }

    // ============ التحويل ============

    public function askTransferPhone(): string
    {
        return "💸 *تحويل مال*\n\n"
             . "أدخل رقم هاتف المستقبل:\n"
             . "_مثال: 777123456_\n\n"
             . "اكتب *إلغاء* للرجوع.";
    }

    public function confirmRecipient(string $maskedName, string $phone): string
    {
        return "✅ تمّ التعرّف على المستقبل\n\n"
             . "الاسم:   *{$maskedName}*\n"
             . "الرقم:   {$this->maskPhone($phone)}\n\n"
             . "كم المبلغ الذي تريد تحويله؟\n"
             . "_مثال: 5000_\n\n"
             . "اكتب *إلغاء* للرجوع.";
    }

    /** AMIAL-WA-002 — Section 9: عرض قائمة عند تعدّد المستفيدين المطابقين. */
    public function multipleRecipientsFound(array $recipients): string
    {
        $msg = "👥 *وُجد أكثر من مستفيد مطابق*\n"
             . "─────────────────\n";
        foreach ($recipients as $i => $r) {
            $n    = $i + 1;
            $name = $r['masked_name'] ?? '***';
            $msg .= "{$n}. {$name}\n";
        }
        $msg .= "─────────────────\n"
              . "اكتب رقم المستفيد المطلوب، أو *إلغاء* للرجوع.";
        return $msg;
    }

    public function confirmTransfer(
        string $maskedName,
        string $toPhone,
        string $amount,
        string $fee,
        string $total
    ): string {
        return "📋 *تأكيد التحويل*\n"
             . "─────────────────\n"
             . "إلى:      *{$maskedName}*\n"
             . "الرقم:    {$this->maskPhone($toPhone)}\n"
             . "المبلغ:   *" . $this->money($amount) . "*\n"
             . "الرسوم:   " . $this->money($fee) . "\n"
             . "الإجمالي: *" . $this->money($total) . "*\n"
             . "─────────────────\n\n"
             . "⚡ للتأكيد أدخل *PIN* عبر الرابط الآمن:\n";
    }

    public function transferPinLink(string $url, int $expiresMinutes = 2): string
    {
        return "🔐 {$url}\n\n"
             . "_الرابط صالح {$expiresMinutes} دقيقتين فقط._\n"
             . "_لا تشاركه مع أحد._";
    }

    public function transferSuccess(
        string $maskedName,
        string $amount,
        string $newBalance,
        string $txRef
    ): string {
        return "✅ *تمّ التحويل بنجاح*\n"
             . "─────────────────\n"
             . "إلى:          *{$maskedName}*\n"
             . "المبلغ:       *" . $this->money($amount) . "*\n"
             . "رصيدك الجديد: " . $this->money($newBalance) . "\n"
             . "رقم العملية:  `{$txRef}`\n"
             . "─────────────────\n"
             . "_" . now()->format('H:i، d/m/Y') . "_";
    }

    public function transferFailed(string $reason): string
    {
        return "❌ *فشل التحويل*\n\n"
             . $reason . "\n\n"
             . "اكتب *قائمة* للمحاولة مجدّداً.";
    }

    // ============ كشف الحساب ============

    public function statement(array $transactions, string $balance): string
    {
        if (empty($transactions)) {
            return "📊 *كشف الحساب*\n\n"
                 . "لا توجد معاملات حديثة.\n\n"
                 . "رصيدك: *" . $this->money($balance) . "*";
        }

        $lines = "📊 *آخر العمليات*\n"
               . "─────────────────\n";

        foreach (array_slice($transactions, 0, 5) as $tx) {
            $sign   = $tx['direction'] === 'in' ? '⬆️ +' : '⬇️ -';
            $amount = $this->money($tx['amount']);
            $date   = \Carbon\Carbon::parse($tx['created_at'])->format('d/m');
            $note   = mb_substr($tx['description'] ?? '', 0, 30);
            $lines .= "{$sign}{$amount}  {$date}  _{$note}_\n";
        }

        $lines .= "─────────────────\n"
                . "الرصيد الحالي: *" . $this->money($balance) . "*\n\n"
                . "_لكشف PDF كامل، استخدم تطبيق أميال باي._";

        return $lines;
    }

    // ============ الوكيل ============

    public function agentList(array $agents): string
    {
        if (empty($agents)) {
            return "🏪 *أقرب وكيل*\n\n"
                 . "لم يُعثَر على وكلاء قريبين حالياً.\n"
                 . "تواصل معنا: " . config('app.support_phone', '967777000000');
        }

        $msg = "🏪 *أقرب الوكلاء إليك*\n"
             . "─────────────────\n";

        foreach (array_slice($agents, 0, 3) as $i => $a) {
            $n    = $i + 1;
            $name = $a['display_name'] ?? $a['name'] ?? 'وكيل';
            $dist = isset($a['distance_km']) ? round($a['distance_km'], 1) . " كم" : '';
            $phone = $a['phone'] ?? '';
            $msg  .= "{$n}. *{$name}* {$dist}\n";
            if ($phone) $msg .= "   📞 {$phone}\n";
        }

        $msg .= "─────────────────\n_أوقات العمل تختلف بين الوكلاء._";
        return $msg;
    }

    // ============ Section 4 — ربط الحساب (AMIAL-WA-002) ============

    public function notLinkedYet(): string
    {
        return "🔐 *مرحباً بك في أميال باي*\n\n"
             . "لاستخدام البوت، يجب ربط حسابك أوّلاً.\n\n"
             . "اكتب *ربط الحساب* للبدء.";
    }

    public function askLinkPhone(): string
    {
        return "🔗 *ربط الحساب*\n\n"
             . "أدخل رقم هاتفك المسجَّل في أميال باي:\n"
             . "_مثال: 777123456_\n\n"
             . "اكتب *إلغاء* للرجوع.";
    }

    public function linkOtpSent(): string
    {
        return "📩 أرسلنا رمز تحقّق إلى رقمك المسجَّل.\n\n"
             . "أدخل الرمز المكوّن من 6 أرقام:";
    }

    public function linkSuccess(string $name): string
    {
        return "✅ *تمّ ربط حسابك بنجاح!*\n\n"
             . "أهلاً *{$name}* 👋\n"
             . "يمكنك الآن استخدام جميع خدمات أميال باي عبر واتساب.\n\n"
             . $this->mainMenu();
    }

    public function linkFailed(string $reason): string
    {
        return "❌ {$reason}";
    }

    // ============ Section 26 — تحقّق إضافي ============

    public function extraVerificationRequired(): string
    {
        return "⚠️ *تحقّق أمني إضافي مطلوب*\n\n"
             . "لاحظنا نشاطاً غير معتاد على حسابك.\n"
             . "سنرسل رمز تحقّق إضافياً لإكمال هذه العملية.";
    }

    // ============ Section 22 — دفع الفواتير (AMIAL-WA-003) ============

    public function billPayServiceList(array $services): string
    {
        if (empty($services)) {
            return "⚠️ لا توجد خدمات دفع فواتير متاحة حالياً.\n\nاكتب *قائمة* للعودة.";
        }

        $msg = "🧾 *دفع فاتورة*\n"
             . "─────────────────\n"
             . "اختر الخدمة:\n\n";
        foreach ($services as $i => $s) {
            $msg .= ($i + 1) . "️⃣  {$s['display_name_ar']}\n";
        }
        $msg .= "─────────────────\n"
              . "اكتب رقم الخدمة، أو اسمها مباشرة (مثل: كهرباء).";
        return $msg;
    }

    public function billPayNoMatch(array $services): string
    {
        $msg = "❓ لم أتعرّف على هذه الخدمة.\n\n*الخدمات المتوفّرة:*\n";
        foreach ($services as $s) {
            $msg .= "• {$s['display_name_ar']}\n";
        }
        $msg .= "\nاكتب اسم الخدمة، أو *إلغاء* للرجوع.";
        return $msg;
    }

    public function askSubscriberAccount(string $serviceName): string
    {
        return "🧾 *{$serviceName}*\n\n"
             . "أدخل رقم الاشتراك أو الحساب:\n\n"
             . "اكتب *إلغاء* للرجوع.";
    }

    public function billInquiryFailed(string $reason): string
    {
        return "❌ *تعذّر التحقّق من الحساب*\n\n"
             . "{$reason}\n\n"
             . "تأكّد من الرقم وأعد المحاولة، أو اكتب *إلغاء*.";
    }

    public function askBillAmount(string $serviceName): string
    {
        return "💵 *{$serviceName}*\n\n"
             . "أدخل المبلغ الذي تريد دفعه:\n"
             . "_مثال: 2000_\n\n"
             . "اكتب *إلغاء* للرجوع.";
    }

    public function confirmBillPay(
        string $serviceName, string $account, string $amount, string $fee, string $total
    ): string {
        return "📋 *تأكيد دفع الفاتورة*\n"
             . "─────────────────\n"
             . "الخدمة:    *{$serviceName}*\n"
             . "الحساب:    {$account}\n"
             . "المبلغ:    *" . $this->money($amount) . "*\n"
             . "الرسوم:    " . $this->money($fee) . "\n"
             . "الإجمالي:  *" . $this->money($total) . "*\n"
             . "─────────────────\n\n"
             . "⚡ للتأكيد أدخل *PIN* عبر الرابط الآمن:\n";
    }

    public function billPaySuccess(string $serviceName, string $amount, string $orderRef): string
    {
        return "✅ *تمّ دفع الفاتورة بنجاح*\n"
             . "─────────────────\n"
             . "الخدمة:      *{$serviceName}*\n"
             . "المبلغ:      *" . $this->money($amount) . "*\n"
             . "رقم العملية: `{$orderRef}`\n"
             . "─────────────────\n"
             . "_" . now()->format('H:i، d/m/Y') . "_";
    }

    public function billPayPending(string $serviceName, string $orderRef): string
    {
        return "⏳ *طلبك قيد المعالجة*\n\n"
             . "الخدمة: *{$serviceName}*\n"
             . "رقم العملية: `{$orderRef}`\n\n"
             . "سنُرسل لك تأكيداً عند اكتمالها.";
    }

    public function billPayFailed(string $reason): string
    {
        return "❌ *فشل دفع الفاتورة*\n\n"
             . $reason . "\n\n"
             . "اكتب *قائمة* للمحاولة مجدّداً.";
    }

    // ============ Section 23 — طلبات الدفع عبر رابط/QR (AMIAL-WA-003) ============

    public function paymentRequestNotFound(): string
    {
        return "⚠️ لم يُعثَر على طلب الدفع، أو رمزه غير صحيح.\n\n"
             . "تأكّد من الرابط أو الرمز، أو اكتب *قائمة* للعودة.";
    }

    public function confirmPaymentRequest(
        string $requesterName, string $amount, ?string $note, string $code
    ): string {
        $msg = "📋 *طلب دفع*\n"
             . "─────────────────\n"
             . "من:       *{$requesterName}*\n"
             . "المبلغ:   *" . $this->money($amount) . "*\n";
        if ($note) {
            $msg .= "الوصف:    {$note}\n";
        }
        $msg .= "الرمز:    `{$code}`\n"
              . "─────────────────\n\n"
              . "⚡ للتأكيد والدفع أدخل *PIN* عبر الرابط الآمن:\n";
        return $msg;
    }

    public function paymentRequestPaySuccess(string $requesterName, string $amount, string $txId): string
    {
        return "✅ *تمّ الدفع بنجاح*\n"
             . "─────────────────\n"
             . "إلى:          *{$requesterName}*\n"
             . "المبلغ:       *" . $this->money($amount) . "*\n"
             . "رقم العملية:  `{$txId}`\n"
             . "─────────────────\n"
             . "_" . now()->format('H:i، d/m/Y') . "_";
    }

    public function paymentRequestPayFailed(string $reason): string
    {
        return "❌ *تعذّر الدفع*\n\n{$reason}\n\nاكتب *قائمة* للمحاولة مجدّداً.";
    }

    public function qrImageReceived(): string
    {
        return "📷 جاري قراءة الرمز...";
    }

    public function qrDecodeFailed(): string
    {
        return "❌ لم أستطع قراءة رمز QR في هذه الصورة.\n\n"
             . "تأكّد من وضوح الصورة، أو اكتب رمز/رابط الطلب نصّاً مباشرة.";
    }

    // ============ Section 29 — دعم العملاء ============

    public function askSupportMessage(): string
    {
        return "🎫 *التواصل مع الدعم*\n\n"
             . "اكتب وصفاً مختصراً لمشكلتك أو استفسارك:";
    }

    public function supportTicketCreated(string $ticketRef): string
    {
        return "🎫 *تمّ إنشاء طلب دعم*\n\n"
             . "رقم الطلب: `{$ticketRef}`\n\n"
             . "سيتواصل معك فريق الدعم قريباً.\n"
             . "للطوارئ: " . config('app.support_phone', '967777000000');
    }

    // ============ رسائل عامّة ============

    public function unknownCommand(): string
    {
        return "❓ لم أفهم طلبك.\n\n"
             . "اكتب *قائمة* لعرض الخيارات المتاحة،\n"
             . "أو *مساعدة* إن احتجت دعماً.";
    }

    public function sessionExpired(): string
    {
        return "⏰ انتهت الجلسة بسبب عدم النشاط.\n\n"
             . "اكتب *مرحبا* للبدء من جديد.";
    }

    public function userNotFound(): string
    {
        return "⚠️ لم يُعثَر على حسابك في أميال باي.\n\n"
             . "للتسجيل، حمّل التطبيق أو تواصل معنا:\n"
             . "📞 " . config('app.support_phone', '967777000000');
    }

    public function error(string $message = ''): string
    {
        $msg = "⚠️ حدث خطأ غير متوقّع.";
        if ($message) $msg .= "\n\n_{$message}_";
        $msg .= "\n\nاكتب *قائمة* للمحاولة مجدّداً.";
        return $msg;
    }

    public function welcome(string $name): string
    {
        return "👋 *مرحباً {$name}!*\n\n"
             . "أنت الآن متّصل بأميال باي عبر واتساب.\n"
             . "يمكنك إدارة محفظتك بسهولة.\n\n"
             . $this->mainMenu($name);
    }

    public function cancelConfirmed(): string
    {
        return "✅ تمّ الإلغاء.\n\nاكتب *قائمة* للعودة.";
    }

    public function help(): string
    {
        return "ℹ️ *مساعدة — أميال باي*\n\n"
             . "*الأوامر المتاحة:*\n"
             . "• _رصيدي_ — عرض رصيدك\n"
             . "• _حول_ — تحويل مال\n"
             . "• _كشف_ — آخر العمليات\n"
             . "• _وكيل_ — أقرب وكيل\n"
             . "• _دفع_ — دفع فاتورة\n"
             . "• _الدعم_ — التواصل مع فريق الدعم\n"
             . "• _إلغاء_ — إلغاء العملية الحالية\n\n"
             . "*للتواصل:*\n"
             . "📞 " . config('app.support_phone', '967777000000') . "\n"
             . "⏰ _24/7_";
    }

    public function insufficientBalance(string $available, string $required): string
    {
        return "❌ *رصيد غير كافٍ*\n\n"
             . "المطلوب:  *" . $this->money($required) . "*\n"
             . "المتاح:   " . $this->money($available) . "\n\n"
             . "اكتب *قائمة* للعودة.";
    }

    // ============ Helpers ============

    private function money(string $amount): string
    {
        $n = (float) $amount;
        return number_format($n, 0, '.', ',') . ' ريال';
    }

    private function maskPhone(string $phone): string
    {
        if (mb_strlen($phone) < 7) return $phone;
        return mb_substr($phone, 0, 5)
             . str_repeat('*', mb_strlen($phone) - 7)
             . mb_substr($phone, -2);
    }
}
