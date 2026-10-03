<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AMIAL-BILL-PAY-PENDING-UI-001
 *
 * حارس دائم: جواب HTTP ناجح لا يعني أن المزود أكد السداد. يجب أن تظل
 * pending_provider_confirmation حالة ثالثة ظاهرة للعميل، لا نجاحاً أخضر.
 */
class BillPayCustomerUiGuardTest extends TestCase
{
    public function test_pending_provider_confirmation_is_not_presented_as_success(): void
    {
        $root = dirname(base_path());

        $form = (string) file_get_contents(
            $root . '/02_flutter_app/lib/features/bill_pay/screens/bill_pay_form_screen.dart'
        );
        $sheet = (string) file_get_contents(
            $root . '/02_flutter_app/lib/common/widgets/amial_result_sheet.dart'
        );
        $history = (string) file_get_contents(
            $root . '/02_flutter_app/lib/features/bill_pay/screens/bill_pay_history_screen.dart'
        );

        $this->assertStringContainsString(
            'pendingWhen: (order) => order.isPending',
            $form,
            'شاشة السداد عادت تعتبر pending نجاحاً.'
        );
        $this->assertStringContainsString(
            '_Phase.pending',
            $sheet,
            'ورقة النتيجة لا تملك حالة pending مستقلة.'
        );
        $this->assertStringContainsString(
            "'pending_provider_confirmation'",
            $history,
            'سجل السداد لا يعرض حالة انتظار تأكيد المزود.'
        );
        $this->assertStringContainsString(
            'لا تُعِد الدفع',
            $history,
            'العميل لا يرى تحذير منع إعادة الدفع أثناء انتظار المزود.'
        );
    }

    public function test_bill_payment_has_a_dedicated_receipt_and_notification_routes(): void
    {
        $root = dirname(base_path());

        $service = (string) file_get_contents(app_path('Services/BillPayService.php'));
        $notifications = (string) file_get_contents(
            $root . '/02_flutter_app/lib/helper/notification_helper.dart'
        );

        $this->assertStringContainsString(
            "'receipt_type' => 'bill_payment'",
            $service,
            'السداد عاد يُصدر سند رسوم بدلاً من سند سداد فاتورة.'
        );
        foreach ([
            'bill_payment_success',
            'bill_payment_pending',
            'bill_payment_failed',
        ] as $type) {
            $this->assertStringContainsString(
                $type,
                $notifications,
                "إشعار السداد {$type} لا يصل إلى سجل عمليات السداد."
            );
        }
    }
}
