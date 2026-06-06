<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\PendingTransfer;
use App\Models\User;
use App\Services\PendingTransferService;
use App\Services\TransactionPinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * AMIAL-TRANSFER-COOLDOWN-001 (v2.7) — اختبارات نافذة الإلغاء + PIN.
 */
class PendingTransferServiceTest extends TestCase
{
    use RefreshDatabase;

    private PendingTransferService $service;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('amial.encryption.pii_key', base64_encode(random_bytes(32)));
        config()->set('amial.encryption.blind_index_key', base64_encode(random_bytes(32)));
        $this->service = app(PendingTransferService::class);
    }

    private function makeUser(string $balance, string $pin = '1234', array $extra = []): User
    {
        $user = User::factory()->create(array_merge([
            'zone_code' => 'SOUTH',
            'kyc_tier' => 3,
            'sanction_status' => 'clear',
            'sanction_checked' => true,
            'transaction_pin' => Hash::make($pin),
            'pin_failed_attempts' => 0,
            'pin_locked_until' => null,
        ], $extra));
        EMoney::create(['user_id' => $user->id, 'current_balance' => $balance, 'zone_code' => 'SOUTH']);
        return $user;
    }

    /** @test */
    public function initiate_holds_money_and_creates_pending()
    {
        $sender = $this->makeUser('10000', '1234');
        $recipient = $this->makeUser('0');

        $pending = $this->service->initiate($sender, $recipient, '1000', '1234');

        $this->assertEquals('holding', $pending->status);
        $this->assertEquals('1000.0000', (string)$pending->amount);

        // المرسل خُصم منه فوراً (محجوز)
        $senderWallet = EMoney::where('user_id', $sender->id)->first();
        $this->assertEquals('9000.0000', (string)$senderWallet->current_balance);

        // المستلم لم يستلم بعد (ضمن النافذة)
        $recipientWallet = EMoney::where('user_id', $recipient->id)->first();
        $this->assertEquals('0.0000', (string)$recipientWallet->current_balance);
    }

    /** @test */
    public function initiate_rejects_wrong_pin()
    {
        $sender = $this->makeUser('10000', '1234');
        $recipient = $this->makeUser('0');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PIN');
        $this->service->initiate($sender, $recipient, '1000', '9999'); // PIN خاطئ
    }

    /** @test */
    public function wrong_pin_does_not_deduct_money()
    {
        $sender = $this->makeUser('10000', '1234');
        $recipient = $this->makeUser('0');

        try {
            $this->service->initiate($sender, $recipient, '1000', '0000');
        } catch (\RuntimeException $e) {}

        // الرصيد لم يُمَس
        $this->assertEquals('10000.0000',
            (string)EMoney::where('user_id', $sender->id)->value('current_balance'));
    }

    /** @test */
    public function cancel_within_window_refunds_fully()
    {
        $sender = $this->makeUser('10000', '1234');
        $recipient = $this->makeUser('0');

        $pending = $this->service->initiate($sender, $recipient, '1000', '1234', fee: '10');
        // خُصم 1010 (1000 + 10 رسوم)
        $this->assertEquals('8990.0000',
            (string)EMoney::where('user_id', $sender->id)->value('current_balance'));

        $cancelled = $this->service->cancel($pending, $sender);

        $this->assertEquals('cancelled', $cancelled->status);
        // استُرد المبلغ الكامل (شامل الرسوم)
        $this->assertEquals('10000.0000',
            (string)EMoney::where('user_id', $sender->id)->value('current_balance'));
    }

    /** @test */
    public function only_sender_can_cancel()
    {
        $sender = $this->makeUser('10000', '1234');
        $recipient = $this->makeUser('0');
        $stranger = $this->makeUser('5000');

        $pending = $this->service->initiate($sender, $recipient, '1000', '1234');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('غير مصرح');
        $this->service->cancel($pending, $stranger);
    }

    /** @test */
    public function release_delivers_to_recipient_after_window()
    {
        $sender = $this->makeUser('10000', '1234');
        $recipient = $this->makeUser('0');

        // نافذة صفرية (للاختبار)
        $pending = $this->service->initiate($sender, $recipient, '1000', '1234', cooldownSeconds: 0);

        // تسليم
        $released = $this->service->release($pending);

        $this->assertEquals('completed', $released->status);
        // المستلم استلم
        $this->assertEquals('1000.0000',
            (string)EMoney::where('user_id', $recipient->id)->value('current_balance'));
    }

    /** @test */
    public function cannot_cancel_after_completion()
    {
        $sender = $this->makeUser('10000', '1234');
        $recipient = $this->makeUser('0');

        $pending = $this->service->initiate($sender, $recipient, '1000', '1234', cooldownSeconds: 0);
        $this->service->release($pending);

        // محاولة إلغاء بعد التسليم
        $this->expectException(\RuntimeException::class);
        $this->service->cancel($pending->fresh(), $sender);
    }

    /** @test */
    public function release_refunds_if_recipient_no_longer_eligible()
    {
        $sender = $this->makeUser('10000', '1234');
        $recipient = $this->makeUser('0');

        $pending = $this->service->initiate($sender, $recipient, '1000', '1234', cooldownSeconds: 0);

        // المستلم يُحظر خلال النافذة
        $recipient->forceFill(['sanction_status' => 'blocked'])->save();

        $released = $this->service->release($pending);

        $this->assertEquals('failed', $released->status);
        // المرسل استُرد ماله
        $this->assertEquals('10000.0000',
            (string)EMoney::where('user_id', $sender->id)->value('current_balance'));
        // المستلم لم يستلم
        $this->assertEquals('0.0000',
            (string)EMoney::where('user_id', $recipient->id)->value('current_balance'));
    }

    /** @test */
    public function cannot_transfer_to_self()
    {
        $user = $this->makeUser('10000', '1234');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('لنفسك');
        $this->service->initiate($user, $user, '1000', '1234');
    }

    /** @test */
    public function release_all_due_processes_multiple()
    {
        $sender = $this->makeUser('10000', '1234');
        $r1 = $this->makeUser('0');
        $r2 = $this->makeUser('0');

        $this->service->initiate($sender, $r1, '1000', '1234', cooldownSeconds: 0);
        $this->service->initiate($sender, $r2, '2000', '1234', cooldownSeconds: 0);

        $count = $this->service->releaseAllDue();
        $this->assertEquals(2, $count);

        $this->assertEquals('1000.0000', (string)EMoney::where('user_id', $r1->id)->value('current_balance'));
        $this->assertEquals('2000.0000', (string)EMoney::where('user_id', $r2->id)->value('current_balance'));
    }
}
