<?php

namespace Tests\Feature;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use PDOException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * AMIAL-API-ERROR-SHIELD-001 — عكس التسريب الذي وصل إلى تطبيق التاجر.
 *
 * الحارس لا يكتفي بأن يكون APP_DEBUG=false؛ خطأ ضبط البيئة يجب ألا يحوّل
 * SQL إلى رسالة عميل. التفاصيل مكانها system_errors والسجل الداخلي فقط.
 */
class ApiExceptionDisclosureGuardTest extends TestCase
{
    /** @test */
    public function a_database_exception_never_reaches_an_api_client(): void
    {
        config()->set('app.debug', true);

        $request = Request::create('/api/v1/amial/cashier/shift/open', 'POST');
        $request->attributes->set('request_id', 'shift-open-guard-01');

        $exception = new QueryException(
            'mysql',
            'insert into cashier_shifts (pos_device_id) values (?)',
            [5],
            new PDOException("SQLSTATE[42S22]: Column not found: 1054 Unknown column 'pos_device_id'"),
        );

        $response = app(ExceptionHandler::class)->render($request, $exception);
        $payload = json_decode((string) $response->getContent(), true);

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('SERVER_UNAVAILABLE', $payload['code'] ?? null);
        $this->assertSame('shift-open-guard-01', $payload['meta']['request_id'] ?? null);

        $body = (string) $response->getContent();
        foreach (['SQLSTATE', 'pos_device_id', 'cashier_shifts', 'insert into', 'mysql'] as $secret) {
            $this->assertStringNotContainsString($secret, $body,
                "تسرّبت تفاصيل قاعدة البيانات إلى العميل: {$secret}");
        }
        $this->assertArrayNotHasKey('debug', $payload);
    }

    /** @test */
    public function a_five_hundred_http_exception_never_reuses_its_raw_message(): void
    {
        $request = Request::create('/api/v1/amial/cashier/shift/open', 'POST');

        $response = app(ExceptionHandler::class)->render(
            $request,
            new HttpException(500, 'Connection: mysql Host: internal-db /var/www/html/vendor'),
        );

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringContainsString('حدثت مشكلة في الخادم', (string) $response->getContent());
        $this->assertStringNotContainsString('internal-db', (string) $response->getContent());
        $this->assertStringNotContainsString('/var/www/html', (string) $response->getContent());
    }

    /** @test */
    public function a_prepared_api_response_cannot_bypass_the_disclosure_shield(): void
    {
        $request = Request::create('/api/v1/amial/cashier/shift/open', 'POST');

        $response = app(ExceptionHandler::class)->render(
            $request,
            new HttpResponseException(new JsonResponse([
                'message' => 'SQLSTATE[42S22] Unknown column pos_device_id on mysql',
                'debug' => ['host' => 'internal-db'],
            ], 422)),
        );

        $body = (string) $response->getContent();
        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringNotContainsString('SQLSTATE', $body);
        $this->assertStringNotContainsString('internal-db', $body);
        $this->assertStringContainsString('تحقّق من البيانات', $body);
    }

    /** @test */
    public function production_startup_applies_migrations_before_accepting_traffic(): void
    {
        $entrypoint = file_get_contents(base_path('docker/entrypoint.sh'));

        $this->assertStringContainsString('AMIAL-SHIFT-SCHEMA-GATE-001', $entrypoint);
        $this->assertStringContainsString('until php artisan migrate --force', $entrypoint);
        $this->assertStringContainsString('لن تبدأ الخدمة ببنية قديمة', $entrypoint);
    }
}
