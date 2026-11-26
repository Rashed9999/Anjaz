<?php

namespace App\Services\Messaging\Providers\Sms;

use App\Services\Messaging\AbstractProvider;
use App\Support\Phone;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * AMIAL-SMSGATE-OTP-001 — opt-in SMSGate private-server transport.
 *
 * SMSGate returns HTTP 202 for QUEUED messages. This is NOT evidence that the
 * modem sent a text. The legacy MessageProvider contract returns a boolean
 * for actual send success, so we verify the state before returning true.
 *
 * This adapter never connects to the public SMSGate cloud. Configure an HTTPS
 * private server in Coolify and keep the feature disabled until it is tested.
 */
final class PrivateSmsGateProvider extends AbstractProvider
{
    public function key(): string { return 'smsgate'; }

    public function channel(): string { return 'sms'; }

    public function priority(): int { return 5; }

    public function isEnabled(): bool
    {
        $c = (array) config('services.smsgate', []);
        $base = rtrim((string) ($c['base_url'] ?? ''), '/');

        return ($c['enabled'] ?? false) === true
            && filter_var($base, FILTER_VALIDATE_URL) !== false
            && str_starts_with($base, 'https://')
            && (string) ($c['username'] ?? '') !== ''
            && (string) ($c['password'] ?? '') !== '';
    }

    public function sendOtp(string $to, string $otp): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        $c = (array) config('services.smsgate', []);
        $base = rtrim((string) $c['base_url'], '/');
        $url = $base . '/api/3rdparty/v1/messages';
        $canonical = Phone::canonical($to);
        // This inexpensive local-SIM pilot is for Yemeni mobile numbers only.
        // Never accidentally route international SMS through a consumer SIM.
        if (preg_match('/^9677[0-9]{8}$/', $canonical) !== 1) {
            Log::warning('smsgate.otp.recipient_out_of_scope');
            return false;
        }
        $phone = '+' . $canonical;
        $body = [
            'phoneNumbers' => [$phone],
            'textMessage' => [
                'text' => $this->template($c, $otp, 'رمز التحقق من أميال باي: #OTP#'),
            ],
            'ttl' => max(60, min(600, (int) ($c['ttl_seconds'] ?? 300))),
            'withDeliveryReport' => true,
        ];
        $sim = (int) ($c['sim_number'] ?? 0);
        if ($sim > 0) {
            $body['simNumber'] = $sim;
        }

        try {
            $response = $this->client($c)->post($url, $body);
            if ($response->status() !== 202) {
                Log::warning('smsgate.otp.enqueue_rejected', ['http_status' => $response->status()]);
                return false;
            }

            $id = $response->json('id');
            if (! is_string($id) || $id === '' || strlen($id) > 128) {
                Log::warning('smsgate.otp.missing_message_id');
                return false;
            }

            // Acknowledgement is not delivery: wait briefly for modem status.
            $state = (string) $response->json('state', 'Pending');
            if ($this->isSent($state)) {
                return true;
            }
            if ($this->isFailed($state)) {
                return false;
            }

            $polls = max(1, min(8, (int) ($c['poll_attempts'] ?? 5)));
            for ($attempt = 0; $attempt < $polls; $attempt++) {
                // Keep tests fast and do not block for a full OTP lifetime.
                if ($attempt > 0) {
                    usleep(400000);
                }
                $status = $this->client($c)->get($url . '/' . rawurlencode($id));
                if (! $status->successful()) {
                    continue;
                }
                $state = (string) $status->json('state', 'Pending');
                if ($this->isSent($state)) {
                    return true;
                }
                if ($this->isFailed($state)) {
                    return false;
                }
            }

            // The phone may still send later. Never claim successful sending
            // just because the queue accepted an OTP.
            Log::warning('smsgate.otp.status_unconfirmed', [
                'message_id' => $id,
                'state' => preg_replace('/[^a-zA-Z]/', '', $state),
            ]);
            return false;
        } catch (\Throwable $e) {
            // Do not log exception messages: HTTP libraries may include
            // credentials, recipients or OTP bodies in error strings.
            Log::warning('smsgate.otp.transport_error', ['type' => class_basename($e)]);
            return false;
        }
    }

    private function client(array $c): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withBasicAuth((string) $c['username'], (string) $c['password'])
            ->acceptJson()
            ->asJson()
            ->connectTimeout(2)
            ->timeout(4);
    }

    private function isSent(string $state): bool
    {
        return in_array(strtolower($state), ['sent', 'delivered'], true);
    }

    private function isFailed(string $state): bool
    {
        return in_array(strtolower($state), ['failed', 'cancelled'], true);
    }
}
