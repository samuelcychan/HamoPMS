<?php

namespace Modules\Payment\Contracts;

use Modules\Payment\Data\PaymentIntentRequest;
use Modules\Payment\Data\PaymentIntentResult;

interface PaymentGateway
{
    public function name(): string;

    public function createPaymentIntent(PaymentIntentRequest $request): PaymentIntentResult;
}
