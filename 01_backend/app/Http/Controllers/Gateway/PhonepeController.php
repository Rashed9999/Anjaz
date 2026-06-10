<?php

namespace App\Http\Controllers\Gateway;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Traits\Processor;
use App\Models\PaymentRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class PhonepeController extends Controller
{
    use Processor;

    private mixed $config_values;
    private PaymentRequest $payment;

    public function __construct(PaymentRequest $payment)
    {
        $config = $this->payment_config('phonepe', 'payment_config');
        // AMIAL-FIX: قيم JSON فارغة كانت تسبب null dereference عند ضبط base_url
        if (!is_null($config) && $config->mode == 'live') {
            $this->config_values = json_decode($config->live_values);
            if ($this->config_values) {
                $this->config_values->base_url = "https://api.phonepe.com/apis/hermes";
            }
        } elseif (!is_null($config) && $config->mode == 'test') {
            $this->config_values = json_decode($config->test_values);
            if ($this->config_values) {
                $this->config_values->base_url = "https://api-preprod.phonepe.com/apis/pg-sandbox";
            }
        }
        $this->payment = $payment;
    }

    public function payment(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'payment_id' => 'required|uuid'
        ]);

        if ($validator->fails()) {
            return response()->json($this->response_formatter(GATEWAYS_DEFAULT_400, null, $this->error_processor($validator)), 400);
        }

        $payment_data = $this->payment::where(['id' => $request['payment_id']])->where(['is_paid' => 0])->first();
        if (!isset($payment_data)) {
            return response()->json($this->response_formatter(GATEWAYS_DEFAULT_204), 200);
        }
        $config = $this->config_values;
        $customer_data = json_decode($payment_data->payer_information, true);
        $payload = '{
                "merchantId": "' . $config->merchant_id . '",
                "merchantTransactionId": "' . $payment_data->id . '",
                "merchantUserId": "tt' . $payment_data->payer_id . '",
                "amount": ' . ($payment_data->payment_amount * 100) . ',
                "redirectUrl": "' . route('phonepe.redirect') . '",
                "redirectMode": "POST",
                "callbackUrl": "' . route('phonepe.callback') . '",
                "mobileNumber": "' . $customer_data['phone'] . '",
                "paymentInstrument": {
                  "type": "PAY_PAGE"
                }
              }';
        $check_sum = hash('sha256', base64_encode($payload) . "/pg/v1/pay{$config->salt_Key}") . "###{$config->salt_index}";

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'X-VERIFY' => $check_sum,
        ])
            ->post("{$config->base_url}/pg/v1/pay", ["request" => base64_encode($payload)]);
        $data = $response->json();

        if (isset($data['success']) && isset($data['data']['instrumentResponse']['redirectInfo']['url'])) {
            return redirect()->to($data['data']['instrumentResponse']['redirectInfo']['url']);
        }
        return response()->json($data, $response->status());
    }

    public function callback(Request $request)
    {
        if ($request->response) {
            $response = json_decode(base64_decode($request->response));
            if ($response?->success && $response?->data?->responseCode && $response->data->merchantTransactionId == "SUCCESS") {
                $this->payment::where(['id' => $response->data->merchantTransactionId])->update([
                    'payment_method' => 'phonepe',
                    'is_paid' => 1,
                    'transaction_id' => $response->data->transactionId,
                ]);
                $data = $this->payment::where(['id' => $response->data->merchantTransactionId])->first();

                if (isset($data) && function_exists($data->success_hook)) {
                    call_user_func($data->success_hook, $data);
                }

                return $this->payment_response($data, 'success');
            }
        }

        $payment_data = $this->payment::where(['id' => $response?->data?->merchantTransactionId])->first();
        if (isset($payment_data) && function_exists($payment_data->failure_hook)) {
            call_user_func($payment_data->failure_hook, $payment_data);
        }
        return $this->payment_response($payment_data, 'fail');

    }

    public function redirect(Request $request)
    {
        $config = $this->config_values;
        if ($request->code == "PAYMENT_SUCCESS" && $request->transactionId && $payment_data = $this->payment::where(['id' => $request->transactionId])->first()) {
            $response = Http::withHeaders([
                'X-MERCHANT-ID' => (string)$config->merchant_id,
                'Content-Type' => 'application/json',
                'X-VERIFY' => hash('sha256', "/pg/v1/status/{$config->merchant_id}/{$request->transactionId}{$config->salt_Key}") . "###{$config->salt_index}"
            ])->get("{$config->base_url}/pg/v1/status/{$config->merchant_id}/{$request->transactionId}");
            $response = $response->json();
            if (isset($response['code']) && $response['code'] == "PAYMENT_SUCCESS") {
                $payment_data->payment_method = 'phonepe';
                $payment_data->is_paid = 1;
                $payment_data->transaction_id = $response['data']['transactionId'];
                $payment_data->save();

                if (isset($payment_data) && function_exists($payment_data->success_hook)) {
                    call_user_func($payment_data->success_hook, $payment_data);
                }

                return $this->payment_response($payment_data, 'success');
            }
        }

        $payment_data = $this->payment::where(['id' => $request->transactionId])->first();
        if (isset($payment_data) && function_exists($payment_data->failure_hook)) {
            call_user_func($payment_data->failure_hook, $payment_data);
        }
        return $this->payment_response($payment_data, 'fail');
    }

}
