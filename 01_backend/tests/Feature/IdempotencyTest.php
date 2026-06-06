<?php

namespace Tests\Feature;

use App\Exceptions\DuplicateTransactionException;
use App\Models\IdempotencyKey;
use App\Services\IdempotencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-REFACTOR-CORE-001
 *
 * IdempotencyTest — يتأكد من معيار القبول رقم 2:
 *   "لا توجد عملية مالية مكررة بسبب retry".
 *
 * يغطي AUDIT 1.6.
 */
class IdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private IdempotencyService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(IdempotencyService::class);
    }

    /** @test */
    public function first_request_with_new_key_is_marked_processing(): void
    {
        $result = $this->svc->begin(
            key: 'test-key-' . str_repeat('a', 20),
            userId: 1,
            endpoint: 'POST /api/v1/customer/send-money',
            requestBody: ['amount' => 100, 'phone' => '777111222'],
        );

        $this->assertSame('new', $result['status']);
        $this->assertDatabaseHas('idempotency_keys', [
            'key' => 'test-key-' . str_repeat('a', 20),
            'status' => 'processing',
        ]);
    }

    /** @test */
    public function second_request_with_same_key_same_body_returns_replay(): void
    {
        $key = 'replay-test-' . str_repeat('b', 16);
        $userId = 1;
        $endpoint = 'POST /api/v1/customer/send-money';
        $body = ['amount' => 100, 'phone' => '777111222'];

        // الطلب الأول
        $first = $this->svc->begin($key, $userId, $endpoint, $body);
        $this->assertSame('new', $first['status']);

        // إكمال الطلب الأول بنجاح
        $this->svc->complete(
            key: $key,
            userId: $userId,
            endpoint: $endpoint,
            httpStatus: 200,
            responseBody: ['success' => true, 'transaction_id' => 'TX_001'],
            transactionId: 'TX_001',
        );

        // الطلب الثاني بنفس الـ key و body
        $second = $this->svc->begin($key, $userId, $endpoint, $body);
        $this->assertSame('replay', $second['status']);
        $this->assertSame(200, $second['http_status']);
        $this->assertSame('TX_001', $second['transaction_id']);
        $this->assertSame(['success' => true, 'transaction_id' => 'TX_001'], $second['response']);
    }

    /** @test */
    public function second_request_same_key_different_body_throws_conflict(): void
    {
        $key = 'conflict-test-' . str_repeat('c', 16);
        $userId = 1;
        $endpoint = 'POST /api/v1/customer/send-money';

        $this->svc->begin($key, $userId, $endpoint, ['amount' => 100]);

        $this->expectException(DuplicateTransactionException::class);
        $this->svc->begin($key, $userId, $endpoint, ['amount' => 999]); // body مختلف
    }

    /** @test */
    public function request_in_progress_returns_in_progress_status(): void
    {
        $key = 'inprogress-' . str_repeat('d', 16);
        $userId = 1;
        $endpoint = 'POST /api/v1/customer/send-money';
        $body = ['amount' => 100];

        $this->svc->begin($key, $userId, $endpoint, $body);
        // لم نكمل بعد → status = processing

        $second = $this->svc->begin($key, $userId, $endpoint, $body);
        $this->assertSame('in_progress', $second['status']);
    }

    /** @test */
    public function previously_failed_request_blocks_retry_with_same_key(): void
    {
        $key = 'failed-test-' . str_repeat('e', 16);
        $userId = 1;
        $endpoint = 'POST /api/v1/customer/send-money';
        $body = ['amount' => 100];

        $this->svc->begin($key, $userId, $endpoint, $body);
        $this->svc->fail($key, $userId, $endpoint, 500, ['error' => 'something']);

        $retry = $this->svc->begin($key, $userId, $endpoint, $body);
        $this->assertSame('failed_previously', $retry['status']);
    }

    /** @test */
    public function key_validation_rejects_short_keys(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->begin('short', 1, 'endpoint', []);
    }

    /** @test */
    public function key_validation_rejects_special_chars(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->begin('key with spaces _____', 1, 'endpoint', []);
    }

    /** @test */
    public function body_hash_is_order_independent(): void
    {
        // نفس الـ body بترتيب مفاتيح مختلف يجب أن ينتج نفس الـ hash
        $key = 'order-test-' . str_repeat('f', 16);
        $userId = 1;
        $endpoint = 'POST /api/v1/customer/send-money';

        $this->svc->begin($key, $userId, $endpoint, ['amount' => 100, 'phone' => '111']);

        // الطلب الثاني بترتيب معكوس
        $second = $this->svc->begin($key, $userId, $endpoint, ['phone' => '111', 'amount' => 100]);

        // يجب أن يكون replay (ليس conflict) لأن الـ hash واحد
        $this->assertNotSame('new', $second['status'], 'Hash should be order-independent');
    }

    /** @test */
    public function different_users_with_same_key_are_independent(): void
    {
        $key = 'multiuser-' . str_repeat('g', 16);
        $endpoint = 'POST /api/v1/customer/send-money';

        $r1 = $this->svc->begin($key, userId: 1, endpoint: $endpoint, requestBody: ['amount' => 50]);
        $r2 = $this->svc->begin($key, userId: 2, endpoint: $endpoint, requestBody: ['amount' => 50]);

        $this->assertSame('new', $r1['status']);
        $this->assertSame('new', $r2['status']); // user مختلف = key منفصل
    }
}
