<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\User;
use App\Services\LegalTermsService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CustomerTransferConcurrencyTest extends TestCase
{
    // Separate connections must see committed fixtures.
    use DatabaseMigrations;

    public function test_parallel_http_retries_and_distinct_transfers_preserve_the_daily_limit(): void
    {
        if (DB::getDriverName() !== 'mysql' || !function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires MySQL and pcntl.');
        }

        $sender = User::factory()->create(['transaction_pin' => Hash::make('1234')]);
        $recipient = User::factory()->create();
        foreach ([[$sender, '50000'], [$recipient, '0']] as [$user, $balance]) {
            EMoney::create(['user_id' => $user->id, 'current_balance' => $balance, 'zone_code' => 'SOUTH']);
        }
        $device = 'race-' . $sender->id;
        DB::table('user_log_histories')->insert([
            'user_id' => $sender->id, 'device_id' => $device, 'ip_address' => '10.0.0.1',
            'is_active' => 1, 'is_blocked' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $terms = app(LegalTermsService::class);
        if ($current = $terms->currentTerm('ar')) {
            $terms->accept($sender, $current);
        }
        foreach (['send_money_status' => '1', 'customer_send_money_limit' => json_encode([
            'status' => 1, 'total_transaction_amount_per_day' => '2000',
        ])] as $setting => $value) {
            DB::table('business_settings')->updateOrInsert(['key' => $setting], ['value' => $value]);
        }

        $send = function (string $key) use ($sender, $recipient, $device) {
            $response = $this->actingAs($sender, 'api')->withHeaders([
                'device-id' => $device, 'Idempotency-Key' => $key,
            ])->postJson('/api/v1/customer/send-money', [
                'phone' => $recipient->phone, 'amount' => '1000', 'pin' => '1234',
            ]);
            return [$response->status(), $response->json('transaction_id'), $response->json('message')];
        };

        $same = $this->race($send, ['parallel-transfer-key-a', 'parallel-transfer-key-a']);
        $this->assertEquals([200, 200], array_column($same, 0), json_encode($same));
        $this->assertNotEmpty($same[0][1]);
        $this->assertSame($same[0][1], $same[1][1]);

        $distinct = $this->race($send, ['parallel-transfer-key-b', 'parallel-transfer-key-c']);
        $this->assertEqualsCanonicalizing([200, 400], array_column($distinct, 0), json_encode($distinct));
        $this->assertDatabaseHas('transaction_limits', [
            'user_id' => $sender->id, 'type' => 'send_money', 'todays_count' => 2, 'todays_amount' => 2000,
        ]);
        $this->assertEquals(2000, EMoney::where('user_id', $recipient->id)->value('current_balance'));
        $this->assertEquals(2, DB::table('transactions')->where('user_id', $sender->id)
            ->where('transaction_type', SEND_MONEY)->count());
    }

    private function race(callable $send, array $keys): array
    {
        DB::purge();
        $workers = [];
        foreach ($keys as $key) {
            [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            $pid = pcntl_fork();
            $this->assertGreaterThanOrEqual(0, $pid);
            if ($pid === 0) {
                fclose($parent);
                fread($child, 1);
                try {
                    $result = $send($key);
                } catch (\Throwable $error) {
                    $result = [0, null, $error->getMessage()];
                }
                fwrite($child, json_encode($result) . "\n");
                fclose($child);
                exit(0);
            }
            fclose($child);
            stream_set_timeout($parent, 75);
            $workers[] = [$pid, $parent];
        }
        foreach ($workers as [$pid, $socket]) {
            fwrite($socket, '1');
        }
        $results = [];
        foreach ($workers as [$pid, $socket]) {
            $line = fgets($socket);
            fclose($socket);
            pcntl_waitpid($pid, $status);
            $this->assertNotFalse($line, 'Worker did not return a response');
            $results[] = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        }
        return $results;
    }
}
