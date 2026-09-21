<?php

namespace Tests\Feature;

use App\Models\PendingTransfer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * AMIAL-SUPPORT-DIAGNOSTICS-001
 *
 * يحرس الأسئلة التي يبدأ بها العميل فعلياً لا أسماء الوحدات الداخلية:
 *
 *  1) «الحوالة ما وصلت ومعي رقم العملية»
 *     → transfer_ulid يجب أن يظهر في بحث الدعم قبل وجود Transaction نهائي.
 *
 *  2) «الدفع يقول إنترنت والنت عندي قوي»
 *     → X-Correlation-Id يبقى مع الرفض ويصل إلى سجل التدقيق ثم بحث الدعم.
 *
 *  3) «عندي جهاز جديد / فقدت الجهاز»
 *     → الدعم يرى الأجهزة وطلبات الاستعادة، لكنه لا يقطع الجلسات ولا يعتمد
 *       الاستعادة بنفسه.
 *
 *  4) «حوّلت لشخص غلط»
 *     → الدعم يفتح البلاغ الاحترازي، بينما الحسم المالي يبقى للنزاعات.
 */
class SupportDiagnosticJourneyGuardTest extends TestCase
{
    use RefreshDatabase;

    private function operator(string $roleCode): User
    {
        $user = User::factory()->create([
            'type' => ADMIN_TYPE,
            'zone_code' => 'SOUTH',
        ]);

        $roleId = DB::table('roles')
            ->whereNull('merchant_user_id')
            ->where('code', $roleCode)
            ->value('id');

        $this->assertNotNull($roleId, "الدور {$roleCode} غير مزروع");

        DB::table('admin_user_roles')->insert([
            'user_id' => $user->id,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user->fresh();
    }

    public function test_support_can_diagnose_but_cannot_perform_sensitive_account_actions(): void
    {
        $support = $this->operator('platform_support');

        $this->assertTrue($support->hasPlatformPermission('platform.customers.devices.view'));
        $this->assertTrue($support->hasPlatformPermission('platform.recovery.view'));
        $this->assertTrue($support->hasPlatformPermission('platform.wrong_transfer.claim.open'));

        $this->assertFalse($support->hasPlatformPermission('platform.customers.sessions'));
        $this->assertFalse($support->hasPlatformPermission('platform.recovery.approve'));
        $this->assertFalse($support->hasPlatformPermission('platform.recovery.reject'));
        $this->assertFalse($support->hasPlatformPermission('platform.disputes.decide'));
        $this->assertFalse($support->hasPlatformPermission('platform.customers.freeze'));
    }

    public function test_supervisor_can_decide_recovery_and_wrong_transfer_after_support_opens_case(): void
    {
        $supervisor = $this->operator('platform_supervisor');

        $this->assertTrue($supervisor->hasPlatformPermission('platform.recovery.view'));
        $this->assertTrue($supervisor->hasPlatformPermission('platform.recovery.approve'));
        $this->assertTrue($supervisor->hasPlatformPermission('platform.recovery.reject'));
        $this->assertTrue($supervisor->hasPlatformPermission('platform.disputes.decide'));
    }

    public function test_routes_encode_the_read_vs_act_permission_split(): void
    {
        $expected = [
            'admin.support-center.customers.devices' => 'platform:platform.customers.devices.view',
            'admin.support-center.wrong-transfer.open' => 'platform:platform.wrong_transfer.claim.open',
            'admin.amial.recovery.index' => 'platform:platform.recovery.view',
            'admin.amial.recovery.show' => 'platform:platform.recovery.view',
            'admin.amial.recovery.approve' => 'platform:platform.recovery.approve',
            'admin.amial.recovery.reject' => 'platform:platform.recovery.reject',
        ];

        foreach ($expected as $name => $middleware) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, "المسار {$name} غير مسجل");
            $this->assertContains(
                $middleware,
                $route->gatherMiddleware(),
                "{$name} لا يفرض {$middleware}",
            );
        }
    }

    public function test_support_search_finds_the_reference_customer_sees_while_transfer_is_still_holding(): void
    {
        $support = $this->operator('platform_support');
        $sender = User::factory()->create([
            'type' => CUSTOMER_TYPE,
            'phone' => '967771100001',
            'zone_code' => 'SOUTH',
        ]);
        $recipient = User::factory()->create([
            'type' => CUSTOMER_TYPE,
            'phone' => '967771100002',
            'zone_code' => 'SOUTH',
        ]);

        $ulid = (string) Str::ulid();
        PendingTransfer::create([
            'transfer_ulid' => $ulid,
            'sender_user_id' => $sender->id,
            'recipient_user_id' => $recipient->id,
            'amount' => '7500.0000',
            'fee' => '0.0000',
            'total_debited' => '7500.0000',
            'status' => 'holding',
            'releasable_at' => now()->addMinutes(2),
            'zone_code' => 'SOUTH',
        ]);

        Passport::actingAs($support, [], 'api');

        $response = $this->getJson('/api/v1/amial/admin/support/search?q=' . $ulid)
            ->assertOk()
            ->assertJsonPath('meta.pending_transfers.0.transfer_ulid', $ulid)
            ->assertJsonPath('meta.pending_transfers.0.status', 'holding')
            ->assertJsonPath('meta.pending_transfers.0.sender.user_id', $sender->id)
            ->assertJsonPath('meta.pending_transfers.0.recipient.user_id', $recipient->id);

        $this->assertStringContainsString(
            'لم تُسلَّم للمستلم بعد',
            (string) $response->json('meta.pending_transfers.0.diagnosis'),
        );
    }

    public function test_rejected_merchant_payment_is_traceable_by_the_same_reference_returned_to_the_app(): void
    {
        $customer = User::factory()->create([
            'type' => CUSTOMER_TYPE,
            'phone' => '967771100011',
            'zone_code' => 'SOUTH',
            'is_active' => 1,
        ]);
        DB::table('users')->where('id', $customer->id)->update([
            'transaction_pin' => Hash::make('1234'),
        ]);

        $trace = 'merchant-pay-support-001';

        $response = $this->actingAs($customer, 'api')
            ->withHeaders([
                'X-Correlation-Id' => $trace,
                'Idempotency-Key' => 'merchant-pay-support-idem-001',
            ])
            ->postJson('/api/v1/amial/merchant/pay', [
                'merchant_user_id' => 999999,
                'amount' => '500',
                'channel' => 'qr',
                'pin' => '9999',
            ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'PIN_INVALID');

        $this->assertSame($trace, $response->headers->get('X-Correlation-Id'));

        $this->assertDatabaseHas('audit_decisions', [
            'actor_user_id' => $customer->id,
            'action' => 'MERCHANT_PAYMENT_REJECTED',
            'decision_code' => 'PIN_INVALID',
            'correlation_id' => $trace,
        ]);

        $support = $this->operator('platform_support');
        Passport::actingAs($support, [], 'api');

        $this->getJson('/api/v1/amial/admin/support/search?q=' . $trace)
            ->assertOk()
            ->assertJsonPath('meta.diagnostic_events.0.decision_code', 'PIN_INVALID')
            ->assertJsonPath('meta.diagnostic_events.0.action', 'MERCHANT_PAYMENT_REJECTED');
    }

    public function test_same_trace_can_find_a_server_error_without_exposing_stack_or_message(): void
    {
        $trace = 'merchant-pay-server-500-001';

        DB::table('system_errors')->insert([
            'fingerprint' => hash('sha256', 'support-diagnostic-server-error'),
            'exception' => 'RuntimeException',
            'message' => 'internal database detail that support must not receive',
            'file' => '/var/www/private/Secret.php',
            'line' => 55,
            'method' => 'POST',
            'path' => 'api/v1/amial/merchant/pay',
            'request_id' => $trace,
            'status' => 500,
            'user_id' => null,
            'actor_type' => '2',
            'occurrences' => 1,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'status_flag' => 'open',
            'trace_head' => 'private stack trace',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $support = $this->operator('platform_support');
        Passport::actingAs($support, [], 'api');

        $response = $this->getJson('/api/v1/amial/admin/support/search?q=' . $trace)
            ->assertOk()
            ->assertJsonPath('meta.diagnostic_errors.0.http_status', 500)
            ->assertJsonPath('meta.diagnostic_errors.0.path', 'api/v1/amial/merchant/pay')
            ->assertJsonPath(
                'meta.diagnostic_errors.0.diagnosis',
                'الطلب وصل إلى الخادم وحدث خطأ تقني قبل اكتمال الاستجابة.',
            );

        $body = $response->getContent();
        $this->assertStringNotContainsString('internal database detail', $body);
        $this->assertStringNotContainsString('/var/www/private', $body);
        $this->assertStringNotContainsString('private stack trace', $body);
    }

    public function test_correlation_context_uses_one_reference_for_audit_and_system_error_tracking(): void
    {
        $middleware = file_get_contents(
            app_path('Http/Middleware/CorrelationContext.php')
        );

        $this->assertStringContainsString(
            "attributes->set('amial.correlation_id', \$correlationId)",
            $middleware,
        );
        $this->assertStringContainsString(
            "attributes->set('request_id', \$correlationId)",
            $middleware,
        );
        $this->assertStringContainsString(
            "headers->set('X-Request-Id', \$correlationId)",
            $middleware,
        );
    }

    public function test_recovery_link_from_support_is_filtered_to_the_selected_customer(): void
    {
        $controller = file_get_contents(
            app_path('Http/Controllers/Admin/AccountRecoveryController.php')
        );
        $view = file_get_contents(
            resource_path('views/admin-views/support/console.blade.php')
        );

        $this->assertStringContainsString(
            "\$request->query('user_id', 0)",
            $controller,
        );
        $this->assertStringContainsString(
            "\$query->where('user_id', \$userId)",
            $controller,
        );
        $this->assertStringContainsString(
            'RECOVERY_BASE}?status=all&user_id=' . '    {
        $view = file_get_contents(
            resource_path('views/admin-views/support/console.blade.php')
        );

        $this->assertIsString($view);
        $this->assertStringContainsString('CAN_DEVICES_VIEW', $view);
        $this->assertStringContainsString('CAN_DEVICE_CONTROL', $view);
        $this->assertStringContainsString('CAN_WRONG_TRANSFER_OPEN', $view);
        $this->assertStringContainsString('CAN_WRONG_TRANSFER_DECIDE', $view);
        $this->assertStringContainsString('بانتظار فريق النزاعات', $view);
        $this->assertStringContainsString('رقم عملية أو حوالة معلقة', $view);
        $this->assertStringContainsString('أثر رقم التتبع', $view);
        $this->assertStringContainsString('أعطال خادم مرتبطة بنفس الرقم', $view);
        $this->assertStringContainsString('لا تطلب من العميل إعادة الدفع', $view);
    }

    public function test_flutter_merchant_payment_preserves_a_diagnostic_reference_for_unknown_outcomes(): void
    {
        $api = file_get_contents(base_path('../02_flutter_app/lib/data/api/api_client.dart'));
        $controller = file_get_contents(
            base_path('../02_flutter_app/lib/features/merchant/controllers/merchant_pay_controller.dart')
        );
        $repo = file_get_contents(
            base_path('../02_flutter_app/lib/features/merchant/domain/repositories/merchant_pay_repo.dart')
        );

        $this->assertStringContainsString("requestHeaders['X-Correlation-Id'] = traceId", $api);
        $this->assertStringContainsString("'x-correlation-id': traceId", $api);
        $this->assertStringContainsString('تعذر تأكيد نتيجة الطلب', $api);

        $this->assertStringContainsString('lastDiagnosticId', $controller);
        $this->assertStringContainsString('لا تبدأ عملية دفع جديدة قبل التحقق', $controller);
        $this->assertStringNotContainsString("lastError.value = 'خطأ في الشبكة';", $controller);

        $this->assertStringContainsString('required String correlationId', $repo);
        $this->assertStringContainsString('correlationId: correlationId', $repo);
    }
}
 . '{p.id}',
            $view,
        );
    }

    public function test_support_ui_does_not_render_sensitive_buttons_without_their_permissions(): void
    {
        $view = file_get_contents(
            resource_path('views/admin-views/support/console.blade.php')
        );

        $this->assertIsString($view);
        $this->assertStringContainsString('CAN_DEVICES_VIEW', $view);
        $this->assertStringContainsString('CAN_DEVICE_CONTROL', $view);
        $this->assertStringContainsString('CAN_WRONG_TRANSFER_OPEN', $view);
        $this->assertStringContainsString('CAN_WRONG_TRANSFER_DECIDE', $view);
        $this->assertStringContainsString('بانتظار فريق النزاعات', $view);
        $this->assertStringContainsString('رقم عملية أو حوالة معلقة', $view);
        $this->assertStringContainsString('أثر رقم التتبع', $view);
    }

    public function test_flutter_merchant_payment_preserves_a_diagnostic_reference_for_unknown_outcomes(): void
    {
        $api = file_get_contents(base_path('../02_flutter_app/lib/data/api/api_client.dart'));
        $controller = file_get_contents(
            base_path('../02_flutter_app/lib/features/merchant/controllers/merchant_pay_controller.dart')
        );
        $repo = file_get_contents(
            base_path('../02_flutter_app/lib/features/merchant/domain/repositories/merchant_pay_repo.dart')
        );

        $this->assertStringContainsString("requestHeaders['X-Correlation-Id'] = traceId", $api);
        $this->assertStringContainsString("'x-correlation-id': traceId", $api);
        $this->assertStringContainsString('تعذر تأكيد نتيجة الطلب', $api);

        $this->assertStringContainsString('lastDiagnosticId', $controller);
        $this->assertStringContainsString('لا تبدأ عملية دفع جديدة قبل التحقق', $controller);
        $this->assertStringNotContainsString("lastError.value = 'خطأ في الشبكة';", $controller);

        $this->assertStringContainsString('required String correlationId', $repo);
        $this->assertStringContainsString('correlationId: correlationId', $repo);
    }
}
