<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array
     */
    // AMIAL-CLEANUP: أُزيلت استثناءات CSRF لعناوين بوّابات الدفع الميتة (6cash):
    // paypal/razorpay/create-payment… لا وجود لهذه المسارات في أميال باي.
    // ويبهوك واتساب يستثني CSRF على مستوى المسار نفسه (withoutMiddleware).
    protected $except = [];
}
