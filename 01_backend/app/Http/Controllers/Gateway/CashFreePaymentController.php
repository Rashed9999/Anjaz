<?php

namespace App\Http\Controllers\Gateway;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Traits\Processor;
use App\Models\PaymentRequest;
use Illuminate\Support\Facades\Validator;

class CashFreePaymentController extends Controller
{
    use Processor;
    private $config_values;
    private $base_url;
    private $production_status;
    private $client_id;
    private $client_secret;
    private PaymentRequest $payment;
    public function __construct(PaymentRequest $payment)
    {
        $config = $this->payment_config('cashfree', 'payment_config');
        if (!is_null($config) && $config->mode == 'live') {
            $this->config_values = json_decode($config->live_values);
        } elseif (!is_null($config) && $config->mode == 'test') {
            $this->config_values = json_decode($config->test_values);
        }
        if ($config && isset($this->config_values) && $this->config_values) {
            $this->base_url = ($config->mode == 'test') ? 'https://sandbox.cashfree.com/pg' : 'https://api.cashfree.com/pg';
            $this->production_status = ($config->mode == 'test') ? 'sandbox' : 'production';
            $this->client_id = $this->config_values->client_id;
            $this->client_secret = $this->config_values->client_secret;
        }
        $this->payment = $payment;
    }

    public function payment(Request $req)
    {
        $validator = Validator::make($req->all(), [
            'payment_id' => 'required|uuid'
        ]);

        if ($validator->fails()) {
            return response()->json($this->response_formatter(GATEWAYS_DEFAULT_400, null, $this->error_processor($validator)), 400);
        }

        $data = $this->payment::where(['id' => $req['payment_id']])->where(['is_paid' => 0])->first();

        if (!isset($data)) {
            return response()->json($this->response_formatter(GATEWAYS_DEFAULT_204), 200);
        }
        $url = $this->base_url.'/orders';
        $amount = $data->payment_amount;
        $payer_information = json_decode($data['payer_information']);

        $info_data = [
            'customer_details' => [
                'customer_id' => strval(rand(100000, 9999999)),
                'customer_phone' => $payer_information->phone,
            ],
            'order_meta' => [
                'return_url' => url("/payment/cashfree/callback/?order_id={order_id}&&?payment_id={$data->id}"),
            ],
            'order_id' => $data['attribute_id'],
            'order_currency' => 'INR',
            'order_amount' => $amount,
        ];

        $headers = [
            'accept: application/json',
            'content-type: application/json',
            'x-api-version: 2022-09-01',
            'x-client-id:' . $this->client_id,
            'x-client-secret:' . $this->client_secret,
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($info_data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $response = curl_exec($ch);
        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $response_data = json_decode($response);

        if ($http_status >= 400) {
            dump($response_data->message);
        } else {
            $session_id = $response_data->payment_session_id;
            $order_id = $response_data->order_id;
            $production_status = $this->production_status;
            return view('Gateways::payment.cash-free', compact('data','session_id', 'order_id', 'production_status'));
        }
    }
}
